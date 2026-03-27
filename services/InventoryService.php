<?php

declare(strict_types=1);

namespace app\services;

use app\models\Inventory;
use app\models\Project;
use yii\base\Component;

/**
 * Resolves Ansible inventory content into structured host/group data
 * by calling `ansible-inventory --list`.
 */
class InventoryService extends Component
{
    /** @var int Timeout in seconds for ansible-inventory execution. */
    public int $timeout = 30;

    /**
     * Parse an inventory and return structured host/group data.
     *
     * @return array{groups: array, hosts: array, error: ?string}
     */
    public function resolve(Inventory $inventory): array
    {
        $empty = ['groups' => [], 'hosts' => [], 'error' => null];

        if (!$this->isAvailable()) {
            return array_merge($empty, ['error' => 'ansible-inventory is not installed on this server.']);
        }

        return match ($inventory->inventory_type) {
            Inventory::TYPE_STATIC  => $this->resolveStatic($inventory),
            Inventory::TYPE_FILE    => $this->resolveFile($inventory),
            Inventory::TYPE_DYNAMIC => $this->resolveFile($inventory),
            default                 => array_merge($empty, ['error' => "Unknown inventory type: {$inventory->inventory_type}"]),
        };
    }

    /**
     * Parse inventory and cache results in the database.
     *
     * @return array{groups: array, hosts: array, error: ?string}
     */
    public function resolveAndCache(Inventory $inventory): array
    {
        $result = $this->resolve($inventory);

        $inventory->parsed_hosts = $result['error'] === null
            ? json_encode(['groups' => $result['groups'], 'hosts' => $result['hosts']], JSON_UNESCAPED_SLASHES)
            : null;
        $inventory->parsed_error = $result['error'];
        $inventory->parsed_at    = time();
        $inventory->save(false, ['parsed_hosts', 'parsed_error', 'parsed_at']);

        return $result;
    }

    /**
     * Return cached parse results, or null if not yet parsed.
     *
     * @return array{groups: array, hosts: array, error: ?string}|null
     */
    public function getCached(Inventory $inventory): ?array
    {
        if ($inventory->parsed_at === null) {
            return null;
        }

        if ($inventory->parsed_error !== null) {
            return ['groups' => [], 'hosts' => [], 'error' => $inventory->parsed_error];
        }

        $data = json_decode($inventory->parsed_hosts ?? '{}', true) ?: [];
        return [
            'groups' => $data['groups'] ?? [],
            'hosts'  => $data['hosts'] ?? [],
            'error'  => null,
        ];
    }

    protected function resolveStatic(Inventory $inventory): array
    {
        $content = $inventory->content;
        if (empty(trim((string)$content))) {
            return ['groups' => [], 'hosts' => [], 'error' => 'Inventory content is empty.'];
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'ansilume_inv_');
        if ($tmpFile === false) {
            return ['groups' => [], 'hosts' => [], 'error' => 'Failed to create temp file.'];
        }

        try {
            file_put_contents($tmpFile, $content);
            chmod($tmpFile, 0600);
            return $this->runAnsibleInventory($tmpFile);
        } finally {
            @unlink($tmpFile);
        }
    }

    protected function resolveFile(Inventory $inventory): array
    {
        $project = $inventory->project;
        if ($project === null) {
            return ['groups' => [], 'hosts' => [], 'error' => 'No project assigned to this inventory.'];
        }

        $projectPath = $this->resolveProjectPath($project);
        if ($projectPath === null || !is_dir($projectPath)) {
            return ['groups' => [], 'hosts' => [], 'error' => 'Project workspace not found — sync the project first.'];
        }

        $inventoryPath = $projectPath . '/' . ltrim((string)$inventory->source_path, '/');
        $realInventory = realpath($inventoryPath);
        $realProject   = realpath($projectPath);

        // Path traversal protection
        if ($realInventory === false || $realProject === false || !str_starts_with($realInventory, $realProject)) {
            return ['groups' => [], 'hosts' => [], 'error' => 'Invalid inventory path.'];
        }

        if (!file_exists($realInventory)) {
            return ['groups' => [], 'hosts' => [], 'error' => "Inventory file not found: {$inventory->source_path}"];
        }

        return $this->runAnsibleInventory($realInventory, $projectPath);
    }

    /**
     * @return array{groups: array, hosts: array, error: ?string}
     */
    protected function runAnsibleInventory(string $inventoryPath, ?string $cwd = null): array
    {
        $cmd = ['ansible-inventory', '--list', '-i', $inventoryPath];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return ['groups' => [], 'hosts' => [], 'error' => 'Failed to start ansible-inventory process.'];
        }

        fclose($pipes[0]);

        // Read with timeout
        $stdout = '';
        $stderr = '';
        $deadline = time() + $this->timeout;

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $read = array_filter([$pipes[1], $pipes[2]], fn($p) => is_resource($p));
            if (empty($read)) {
                break;
            }

            $write = $except = [];
            $remaining = max(1, $deadline - time());
            if (time() > $deadline) {
                proc_terminate($process, 15);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return ['groups' => [], 'hosts' => [], 'error' => 'ansible-inventory timed out.'];
            }

            $changed = @stream_select($read, $write, $except, $remaining);
            if ($changed === false) {
                break;
            }

            foreach ($read as $pipe) {
                $chunk = fread($pipe, 65536);
                if ($chunk === false || $chunk === '') {
                    if (feof($pipe)) {
                        fclose($pipe);
                    }
                    continue;
                }
                if ($pipe === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
        }

        // Close any remaining open pipes
        if (is_resource($pipes[1])) {
            fclose($pipes[1]);
        }
        if (is_resource($pipes[2])) {
            fclose($pipes[2]);
        }

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $errMsg = trim($stderr ?: $stdout);
            return ['groups' => [], 'hosts' => [], 'error' => "ansible-inventory failed (exit {$exitCode}): {$errMsg}"];
        }

        return $this->parseOutput($stdout);
    }

    /**
     * Parse the JSON output of `ansible-inventory --list`.
     *
     * @return array{groups: array, hosts: array, error: ?string}
     */
    private function parseOutput(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return ['groups' => [], 'hosts' => [], 'error' => 'Failed to parse ansible-inventory output.'];
        }

        // Extract host variables from _meta.hostvars
        $hostvars = $data['_meta']['hostvars'] ?? [];

        // Extract groups (everything except _meta)
        $groups = [];
        foreach ($data as $groupName => $groupData) {
            if ($groupName === '_meta') {
                continue;
            }
            if (!is_array($groupData)) {
                continue;
            }
            $groups[$groupName] = [
                'hosts'    => $groupData['hosts'] ?? [],
                'children' => $groupData['children'] ?? [],
                'vars'     => $groupData['vars'] ?? [],
            ];
        }

        // Build flat host list with variables
        $hosts = [];
        foreach ($hostvars as $hostname => $vars) {
            $hosts[$hostname] = $vars;
        }

        // Also pick up hosts not in _meta (shouldn't happen but be safe)
        foreach ($groups as $groupData) {
            foreach ($groupData['hosts'] as $host) {
                if (!isset($hosts[$host])) {
                    $hosts[$host] = [];
                }
            }
        }

        ksort($groups);
        ksort($hosts);

        return ['groups' => $groups, 'hosts' => $hosts, 'error' => null];
    }

    protected function resolveProjectPath(Project $project): ?string
    {
        if ($project->scm_type === Project::SCM_TYPE_MANUAL) {
            return !empty($project->local_path) ? $project->local_path : null;
        }

        /** @var ProjectService $projectService */
        $projectService = \Yii::$app->get('projectService');
        return $projectService->localPath($project);
    }

    protected function isAvailable(): bool
    {
        exec('which ansible-inventory 2>/dev/null', $out, $code);
        return $code === 0;
    }
}
