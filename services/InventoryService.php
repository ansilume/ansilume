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
            \app\helpers\FileHelper::safeUnlink($tmpFile);
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

        $realInventory = $this->validateInventoryPath($projectPath, (string)$inventory->source_path);
        if ($realInventory === null) {
            return ['groups' => [], 'hosts' => [], 'error' => 'Invalid inventory path.'];
        }

        if (!file_exists($realInventory)) {
            return ['groups' => [], 'hosts' => [], 'error' => "Inventory file not found: {$inventory->source_path}"];
        }

        return $this->runAnsibleInventory($realInventory, $projectPath);
    }

    /**
     * Validate that the inventory path is inside the project directory (path traversal protection).
     * Returns the resolved real path, or null if validation fails.
     */
    protected function validateInventoryPath(string $projectPath, string $sourcePath): ?string
    {
        $inventoryPath = $projectPath . '/' . ltrim($sourcePath, '/');
        $realInventory = realpath($inventoryPath);
        $realProject   = realpath($projectPath);

        if ($realInventory === false || $realProject === false || !str_starts_with($realInventory, $realProject)) {
            return null;
        }

        return $realInventory;
    }

    /**
     * @return array{groups: array, hosts: array, error: ?string}
     */
    protected function runAnsibleInventory(string $inventoryPath, ?string $cwd = null): array
    {
        $cmd = ['ansible-inventory', '--list', '-i', $inventoryPath];

        $process = $this->openProcess($cmd, $pipes, $cwd);
        if ($process === null) {
            return ['groups' => [], 'hosts' => [], 'error' => 'Failed to start ansible-inventory process.'];
        }

        [$stdout, $stderr, $timedOut] = $this->readProcessOutput($pipes, $process);

        if ($timedOut) {
            return ['groups' => [], 'hosts' => [], 'error' => 'ansible-inventory timed out.'];
        }

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $errMsg = trim($stderr ?: $stdout);
            return ['groups' => [], 'hosts' => [], 'error' => "ansible-inventory failed (exit {$exitCode}): {$errMsg}"];
        }

        return $this->parseOutput($stdout);
    }

    /**
     * Open a subprocess and return the process resource + pipes.
     * Returns null if proc_open fails.
     *
     * @param resource[] &$pipes
     * @return resource|null
     */
    protected function openProcess(array $cmd, ?array &$pipes, ?string $cwd = null)
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return $process;
    }

    /**
     * Read stdout/stderr from a subprocess with timeout handling.
     *
     * @param resource[] $pipes
     * @param resource   $process
     * @return array{0: string, 1: string, 2: bool} [stdout, stderr, timedOut]
     */
    protected function readProcessOutput(array $pipes, $process): array
    {
        $stdout   = '';
        $stderr   = '';
        $deadline = time() + $this->timeout;

        while (true) {
            $read = array_filter([$pipes[1], $pipes[2]], fn($p) => is_resource($p));
            if (empty($read)) {
                break;
            }

            if (time() > $deadline) {
                $this->killProcess($process, $read);
                return [$stdout, $stderr, true];
            }

            $write = $except = [];
            $remaining = max(1, $deadline - time());

            // stream_select emits E_WARNING on signal interruption (SIGCHLD) — not actionable
            $changed = @stream_select($read, $write, $except, $remaining); // @phpcs:ignore
            if ($changed === false) {
                break;
            }

            $this->drainPipes($read, $pipes[1], $stdout, $stderr);
        }

        $this->closePipes($pipes);

        return [$stdout, $stderr, false];
    }

    /**
     * Parse the JSON output of `ansible-inventory --list`.
     *
     * @return array{groups: array, hosts: array, error: ?string}
     */
    /**
     * Terminate a timed-out process and close its pipes.
     *
     * @param resource   $process
     * @param resource[] $openPipes
     */
    protected function killProcess($process, array $openPipes): void
    {
        proc_terminate($process, 15);
        foreach ($openPipes as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
        proc_close($process);
    }

    /**
     * Read available data from ready pipes into stdout/stderr buffers.
     *
     * @param resource[] $readyPipes  Pipes returned by stream_select
     * @param resource   $stdoutPipe  Reference pipe to distinguish stdout from stderr
     */
    protected function drainPipes(array $readyPipes, $stdoutPipe, string &$stdout, string &$stderr): void
    {
        foreach ($readyPipes as $pipe) {
            $chunk = fread($pipe, 65536);
            if ($chunk === false || $chunk === '') {
                if (feof($pipe)) {
                    fclose($pipe);
                }
                continue;
            }
            if ($pipe === $stdoutPipe) {
                $stdout .= $chunk;
            } else {
                $stderr .= $chunk;
            }
        }
    }

    /**
     * Close any pipes that are still open.
     *
     * @param resource[] $pipes
     */
    protected function closePipes(array $pipes): void
    {
        if (is_resource($pipes[1])) {
            fclose($pipes[1]);
        }
        if (is_resource($pipes[2])) {
            fclose($pipes[2]);
        }
    }

    protected function parseOutput(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return ['groups' => [], 'hosts' => [], 'error' => 'Failed to parse ansible-inventory output.'];
        }

        $groups = $this->extractGroups($data);
        $hosts  = $this->extractHosts($data, $groups);

        ksort($groups);
        ksort($hosts);

        return ['groups' => $groups, 'hosts' => $hosts, 'error' => null];
    }

    /**
     * Extract group definitions from ansible-inventory JSON (everything except _meta).
     */
    protected function extractGroups(array $data): array
    {
        $groups = [];
        foreach ($data as $groupName => $groupData) {
            if ($groupName === '_meta' || !is_array($groupData)) {
                continue;
            }
            $groups[$groupName] = [
                'hosts'    => $groupData['hosts'] ?? [],
                'children' => $groupData['children'] ?? [],
                'vars'     => $groupData['vars'] ?? [],
            ];
        }
        return $groups;
    }

    /**
     * Build a flat host list from _meta.hostvars, filling in any hosts
     * referenced by groups but missing from _meta.
     */
    protected function extractHosts(array $data, array $groups): array
    {
        $hosts = [];
        foreach (($data['_meta']['hostvars'] ?? []) as $hostname => $vars) {
            $hosts[$hostname] = $vars;
        }

        foreach ($groups as $groupData) {
            foreach ($groupData['hosts'] as $host) {
                if (!isset($hosts[$host])) {
                    $hosts[$host] = [];
                }
            }
        }

        return $hosts;
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
