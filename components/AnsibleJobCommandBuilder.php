<?php

declare(strict_types=1);

namespace app\components;

/**
 * Builds the ansible-playbook command array from a Job's runner payload.
 *
 * Handles inventory resolution, playbook path construction, docker wrapping,
 * and all optional flags (verbosity, become, forks, limit, tags, extra-vars).
 */
class AnsibleJobCommandBuilder
{
    /**
     * Build the full command, optionally wrapped in `docker run`.
     *
     * @param array<string, mixed> $payload
     * @return string[]
     */
    public function build(array $payload): array
    {
        $ansibleCmd = $this->buildAnsibleCommand($payload);

        $runnerMode = $_ENV['RUNNER_MODE'] ?? 'local';
        if ($runnerMode === 'docker') {
            return $this->wrapInDocker($ansibleCmd, $payload);
        }

        return $ansibleCmd;
    }

    /**
     * @param array<string, mixed> $payload
     * @return string[]
     */
    public function buildAnsibleCommand(array $payload): array
    {
        $cmd = ['ansible-playbook'];

        $inventoryArg = $this->resolveInventoryArg((int)($payload['inventory_id'] ?? 0));
        if ($inventoryArg !== null) {
            $cmd[] = '-i';
            $cmd[] = $inventoryArg;
        }

        $cmd[] = $this->resolvePlaybookPath($payload);

        $this->addPlaybookOptions($cmd, $payload);

        return $cmd;
    }

    /**
     * Append optional playbook flags (verbosity, forks, become, limit, tags, extra-vars).
     *
     * @param string[] $cmd
     * @param array<string, mixed> $payload
     */
    public function addPlaybookOptions(array &$cmd, array $payload): void
    {
        $this->addVerbosityFlag($cmd, (int)($payload['verbosity'] ?? 0));
        $this->addBecomeFlags($cmd, $payload);

        $optionMap = [
            'forks' => '--forks',
            'limit' => '--limit',
            'tags' => '--tags',
            'skip_tags' => '--skip-tags',
            'extra_vars' => '--extra-vars',
        ];

        foreach ($optionMap as $key => $flag) {
            if (!empty($payload[$key])) {
                $cmd[] = $flag;
                $cmd[] = $key === 'forks' ? (string)(int)($payload[$key] ?? 0) : (string)($payload[$key] ?? '');
            }
        }
    }

    /**
     * Wrap an ansible-playbook command in `docker run --rm` for container isolation.
     *
     * @param string[] $ansibleCmd
     * @param array<string, mixed> $payload
     * @return string[]
     */
    public function wrapInDocker(array $ansibleCmd, array $payload): array
    {
        $image = $_ENV['RUNNER_DOCKER_IMAGE'] ?? 'cytopia/ansible:latest';
        $projectPath = $this->resolveProjectPath($payload);

        $dockerCmd = [
            'docker', 'run', '--rm',
            '--user', posix_getuid() . ':' . posix_getgid(),
            '-v', $projectPath . ':/workspace:ro',
            '-v', sys_get_temp_dir() . ':' . sys_get_temp_dir(),
            '--workdir', '/workspace',
            $image,
        ];

        foreach ($ansibleCmd as $i => $part) {
            if ($i === 0) {
                continue;
            }
            if (str_starts_with($part, $projectPath)) {
                $dockerCmd[] = '/workspace/' . ltrim(substr($part, strlen($projectPath)), '/');
            } else {
                $dockerCmd[] = $part;
            }
        }

        return $dockerCmd;
    }

    /**
     * @param string[] $cmd
     */
    private function addVerbosityFlag(array &$cmd, int $verbosity): void
    {
        if ($verbosity > 0) {
            $cmd[] = '-' . str_repeat('v', min($verbosity, 5));
        }
    }

    /**
     * @param string[] $cmd
     * @param array<string, mixed> $payload
     */
    private function addBecomeFlags(array &$cmd, array $payload): void
    {
        if (empty($payload['become'])) {
            return;
        }

        $cmd[] = '--become';
        $cmd[] = '--become-method';
        $cmd[] = (string)($payload['become_method'] ?? 'sudo');
        $cmd[] = '--become-user';
        $cmd[] = (string)($payload['become_user'] ?? 'root');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function resolveProjectPath(array $payload): string
    {
        /** @var \app\models\Project|null $project */
        $project = \app\models\Project::findOne($payload['project_id'] ?? 0);
        return $project?->local_path ?? '/tmp/ansilume/projects';
    }

    private function resolveInventoryArg(int $inventoryId): ?string
    {
        /** @var \app\models\Inventory|null $inventory */
        $inventory = \app\models\Inventory::findOne($inventoryId);
        if ($inventory === null) {
            return null;
        }
        if ($inventory->inventory_type === \app\models\Inventory::TYPE_STATIC) {
            return $this->writeInventoryTempFile($inventory->content ?? '');
        }
        return $inventory->source_path;
    }

    private function writeInventoryTempFile(string $content): string
    {
        $path = sys_get_temp_dir() . '/ansilume_inv_' . uniqid('', true) . '.yml';
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolvePlaybookPath(array $payload): string
    {
        $base = $this->resolveProjectPath($payload);
        return rtrim($base, '/') . '/' . ltrim((string)($payload['playbook'] ?? 'site.yml'), '/');
    }
}
