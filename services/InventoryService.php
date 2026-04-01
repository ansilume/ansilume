<?php

declare(strict_types=1);

namespace app\services;

use app\models\Inventory;
use app\models\Project;
use yii\base\Component;

/**
 * Resolves Ansible inventory content into structured host/group data.
 *
 * Delegates the actual `ansible-inventory --list` execution to
 * {@see AnsibleInventoryRunner} and focuses on resolution strategy,
 * caching, and output parsing.
 */
class InventoryService extends Component
{
    /** @var int Timeout in seconds for ansible-inventory execution. */
    public int $timeout = 30;

    private ?AnsibleInventoryRunner $_runner = null;

    /**
     * Inject a custom runner (useful for testing).
     */
    public function setRunner(AnsibleInventoryRunner $runner): void
    {
        $this->_runner = $runner;
    }

    /**
     * Lazy-create the runner with the configured timeout.
     */
    protected function runner(): AnsibleInventoryRunner
    {
        if ($this->_runner !== null) {
            return $this->_runner;
        }

        $runner = new AnsibleInventoryRunner();
        $runner->timeout = $this->timeout;
        $this->_runner = $runner;

        return $runner;
    }

    /**
     * Parse an inventory and return structured host/group data.
     *
     * @return array{groups: array<string, mixed>, hosts: array<string, mixed>, error: string|null}
     */
    public function resolve(Inventory $inventory): array
    {
        if (!$this->runner()->isAvailable()) {
            return ['groups' => [], 'hosts' => [], 'error' => 'ansible-inventory is not installed on this server.'];
        }

        return match ($inventory->inventory_type) {
            Inventory::TYPE_STATIC => $this->resolveStatic($inventory),
            Inventory::TYPE_FILE => $this->resolveFile($inventory),
            Inventory::TYPE_DYNAMIC => $this->resolveFile($inventory),
            default => ['groups' => [], 'hosts' => [], 'error' => "Unknown inventory type: {$inventory->inventory_type}"],
        };
    }

    /**
     * Parse inventory and cache results in the database.
     *
     * @return array{groups: array<string, mixed>, hosts: array<string, mixed>, error: string|null}
     */
    public function resolveAndCache(Inventory $inventory): array
    {
        /** @var array{groups: array<string, mixed>, hosts: array<string, mixed>, error: string|null} $result */
        $result = $this->resolve($inventory);

        $inventory->parsed_hosts = $result['error'] === null
            ? (json_encode(['groups' => $result['groups'], 'hosts' => $result['hosts']], JSON_UNESCAPED_SLASHES) ?: null)
            : null;
        $inventory->parsed_error = $result['error'];
        $inventory->parsed_at = time();
        $inventory->save(false, ['parsed_hosts', 'parsed_error', 'parsed_at']);

        return $result;
    }

    /**
     * Return cached parse results, or null if not yet parsed.
     *
     * @return array{groups: array<string, mixed>, hosts: array<string, mixed>, error: string|null}|null
     */
    public function getCached(Inventory $inventory): ?array
    {
        if ($inventory->parsed_at === null) {
            return null;
        }

        if ($inventory->parsed_error !== null) {
            return ['groups' => [], 'hosts' => [], 'error' => $inventory->parsed_error];
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($inventory->parsed_hosts ?? '{}', true) ?: [];
        return [
            'groups' => $data['groups'] ?? [],
            'hosts' => $data['hosts'] ?? [],
            'error' => null,
        ];
    }

    /**
     * @return array{groups: array<string, mixed>, hosts: array<string, mixed>, error: string|null}
     */
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
            return $this->runAndParse($tmpFile);
        } finally {
            \app\helpers\FileHelper::safeUnlink($tmpFile);
        }
    }

    /**
     * @return array{groups: array<string, mixed>, hosts: array<string, mixed>, error: string|null}
     */
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

        return $this->runAndParse($realInventory, $projectPath);
    }

    /**
     * Validate that the inventory path is inside the project directory (path traversal protection).
     * Returns the resolved real path, or null if validation fails.
     */
    protected function validateInventoryPath(string $projectPath, string $sourcePath): ?string
    {
        $inventoryPath = $projectPath . '/' . ltrim($sourcePath, '/');
        $realInventory = realpath($inventoryPath);
        $realProject = realpath($projectPath);

        if ($realInventory === false || $realProject === false || !str_starts_with($realInventory, $realProject)) {
            return null;
        }

        return $realInventory;
    }

    /**
     * Run the inventory through the runner and parse the JSON output.
     *
     * @return array{groups: array<string, mixed>, hosts: array<string, mixed>, error: string|null}
     */
    protected function runAndParse(string $inventoryPath, ?string $cwd = null): array
    {
        $result = $this->runner()->run($inventoryPath, $cwd);

        if ($result['error'] !== null) {
            return ['groups' => [], 'hosts' => [], 'error' => $result['error']];
        }

        return $this->parseOutput($result['stdout'] ?? '');
    }

    /**
     * Parse the JSON output of `ansible-inventory --list`.
     *
     * @return array{groups: array<string, mixed>, hosts: array<string, mixed>, error: string|null}
     */
    protected function parseOutput(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return ['groups' => [], 'hosts' => [], 'error' => 'Failed to parse ansible-inventory output.'];
        }

        $groups = $this->extractGroups($data);
        $hosts = $this->extractHosts($data, $groups);

        ksort($groups);
        ksort($hosts);

        return ['groups' => $groups, 'hosts' => $hosts, 'error' => null];
    }

    /**
     * Extract group definitions from ansible-inventory JSON (everything except _meta).
     *
     * @param array<string, mixed> $data
     * @return array<string, array{hosts: array<int, string>, children: array<int, string>, vars: array<string, mixed>}>
     */
    protected function extractGroups(array $data): array
    {
        $groups = [];
        foreach ($data as $groupName => $groupData) {
            if ($groupName === '_meta' || !is_array($groupData)) {
                continue;
            }
            $groups[$groupName] = [
                'hosts' => $groupData['hosts'] ?? [],
                'children' => $groupData['children'] ?? [],
                'vars' => $groupData['vars'] ?? [],
            ];
        }
        return $groups;
    }

    /**
     * Build a flat host list from _meta.hostvars, filling in any hosts
     * referenced by groups but missing from _meta.
     *
     * @param array<string, mixed> $data
     * @param array<string, array{hosts: array<int, string>, children: array<int, string>, vars: array<string, mixed>}> $groups
     * @return array<string, mixed>
     */
    protected function extractHosts(array $data, array $groups): array
    {
        $hosts = [];
        /** @var array<string, mixed> $meta */
        $meta = $data['_meta'] ?? [];
        /** @var array<string, mixed> $hostvars */
        $hostvars = $meta['hostvars'] ?? [];
        foreach ($hostvars as $hostname => $vars) {
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
}
