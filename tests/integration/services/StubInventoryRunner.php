<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\services\AnsibleInventoryRunner;

/**
 * An AnsibleInventoryRunner that runs no process: run() answers the output
 * it was given, as `ansible-inventory --list` prints it, with exit code 0,
 * or the error it was given, with exit code 1.
 */
final class StubInventoryRunner extends AnsibleInventoryRunner
{
    public function __construct(private readonly string $stdout, private readonly ?string $error = null)
    {
        parent::__construct();
    }

    /**
     * The output for $count hosts of the group web, each with two
     * variables: from about 1,000 hosts on, its parse is longer than TEXT
     * holds (65,535 bytes).
     */
    public static function withHosts(int $count): self
    {
        $hostvars = [];
        for ($i = 1; $i <= $count; $i++) {
            $hostvars[sprintf('host-%04d.example.com', $i)] = ['ansible_host' => sprintf('10.0.%d.%d', intdiv($i, 256), $i % 256), 'role' => 'web'];
        }
        $output = ['_meta' => ['hostvars' => $hostvars], 'all' => ['children' => ['ungrouped', 'web']], 'web' => ['hosts' => array_keys($hostvars)]];

        return new self((string)json_encode($output, JSON_UNESCAPED_SLASHES));
    }

    /**
     * A run of ansible-inventory that failed with $error.
     */
    public static function failing(string $error): self
    {
        return new self('', $error);
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function run(string $inventoryPath, ?string $cwd = null): array
    {
        return ['stdout' => $this->stdout, 'stderr' => '', 'exit_code' => $this->error === null ? 0 : 1, 'error' => $this->error];
    }
}
