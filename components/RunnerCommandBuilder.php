<?php

declare(strict_types=1);

namespace app\components;

/**
 * Builds the ansible-playbook command array from a job payload.
 */
class RunnerCommandBuilder
{
    /**
     * @param array<string, mixed> $payload
     * @return string[]
     */
    public function build(array $payload): array
    {
        $cmd = ['ansible-playbook'];

        $this->addInventoryArgs($cmd, $payload);

        $cmd[] = (string)($payload['playbook_path'] ?? '');

        $this->addPlaybookOptions($cmd, $payload);
        $this->addCheckModeFlags($cmd, $payload);

        return $cmd;
    }

    /**
     * @param string[] $cmd
     * @param array<string, mixed> $payload
     */
    private function addInventoryArgs(array &$cmd, array $payload): void
    {
        if (($payload['inventory_type'] ?? '') === 'static') {
            $cmd[] = '-i';
            $cmd[] = '__INVENTORY_TMP__';
        } elseif (!empty($payload['inventory_path'])) {
            $cmd[] = '-i';
            $cmd[] = (string)$payload['inventory_path'];
        }
    }

    /**
     * @param string[] $cmd
     * @param array<string, mixed> $payload
     */
    private function addPlaybookOptions(array &$cmd, array $payload): void
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
    private function addCheckModeFlags(array &$cmd, array $payload): void
    {
        if (!empty($payload['check_mode'])) {
            $cmd[] = '--check';
            $cmd[] = '--diff';
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
}
