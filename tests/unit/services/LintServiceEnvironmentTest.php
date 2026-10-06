<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\components\VaultIsolation;
use app\services\LintService;
use app\tests\unit\TemporaryEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Regression: LintService started ansible-lint with array_merge(getenv(), ...).
 * In the app and queue-worker containers that environment holds
 * APP_SECRET_KEY, DB_PASSWORD, RUNNER_BOOTSTRAP_SECRET and more, and lint runs
 * repository-controlled code (for example a vault password script referenced
 * from the repo's ansible.cfg). A repo author could therefore read the
 * server's master secrets just by getting a template linted.
 *
 * The test puts a fake ansible-lint first on PATH that dumps its environment.
 */
class LintServiceEnvironmentTest extends TestCase
{
    private string $dir;
    private TemporaryEnvironment $env;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/lint_env_' . uniqid('', true);
        mkdir($this->dir . '/bin', 0o700, true);
        mkdir($this->dir . '/project', 0o700, true);
        file_put_contents(
            $this->dir . '/bin/ansible-lint',
            "#!/bin/sh\nenv > " . escapeshellarg($this->dir . '/env.dump') . "\n"
        );
        chmod($this->dir . '/bin/ansible-lint', 0o700);

        $path = getenv('PATH') ?: '/usr/bin:/bin';
        $this->env = new TemporaryEnvironment([
            'PATH' => $this->dir . '/bin:' . $path,
            'APP_SECRET_KEY' => 'leak-canary-app',
            'DB_PASSWORD' => 'leak-canary-db',
            'RUNNER_BOOTSTRAP_SECRET' => 'leak-canary-bootstrap',
            'ANSIBLE_STDOUT_CALLBACK' => 'yaml',
            // A server-side vault password must not reach lint either.
            'ANSIBLE_VAULT_PASSWORD_FILE' => '/etc/server-vault-password',
        ]);
    }

    protected function tearDown(): void
    {
        $this->env->restore();
        foreach (['bin/ansible-lint', 'env.dump'] as $file) {
            \app\helpers\FileHelper::safeUnlink($this->dir . '/' . $file);
        }
        foreach (['project/.ansible', 'project', 'bin', ''] as $sub) {
            \app\helpers\FileHelper::safeRmdir(rtrim($this->dir . '/' . $sub, '/'));
        }
    }

    public function testAnsibleLintDoesNotInheritServerSecrets(): void
    {
        $service = new class () extends LintService {
            /** @return array{0: string, 1: int} */
            public function runExecute(string $cwd): array
            {
                return $this->execute(null, $cwd);
            }
        };

        [, $exitCode] = $service->runExecute($this->dir . '/project');

        $this->assertSame(0, $exitCode, 'the fake ansible-lint must have run');
        $dump = (string)file_get_contents($this->dir . '/env.dump');
        $this->assertStringNotContainsString('leak-canary', $dump);
        $this->assertStringNotContainsString('APP_SECRET_KEY=', $dump);
        $this->assertStringNotContainsString('DB_PASSWORD=', $dump);
        $this->assertStringNotContainsString('RUNNER_BOOTSTRAP_SECRET=', $dump);
    }

    /**
     * @return array<string, string>
     */
    private function dumpedEnv(): array
    {
        $env = [];
        foreach (explode("\n", (string)file_get_contents($this->dir . '/env.dump')) as $line) {
            if (str_contains($line, '=')) {
                [$name, $value] = explode('=', $line, 2);
                $env[$name] = $value;
            }
        }

        return $env;
    }

    /**
     * Regression: lint honoured the repository's ansible.cfg vault settings,
     * so it ran the repo's vault password script inside the server container.
     */
    public function testAnsibleLintOnlyGetsTheVaultDecoy(): void
    {
        $service = new class () extends LintService {
            /** @return array{0: string, 1: int} */
            public function runExecute(string $cwd): array
            {
                return $this->execute(null, $cwd);
            }
        };

        $service->runExecute($this->dir . '/project');

        $env = $this->dumpedEnv();
        $decoy = $env['ANSIBLE_VAULT_PASSWORD_FILE'];
        $this->assertStringContainsString('ansilume_vault_decoy_', $decoy);
        $this->assertSame($decoy, $env['ANSIBLE_VAULT_IDENTITY_LIST']);
        $this->assertSame('False', $env['ANSIBLE_ASK_VAULT_PASS']);
        $this->assertFileDoesNotExist($decoy, 'the decoy is removed after lint');
    }

    public function testLintDoesNotRunWithoutVaultIsolation(): void
    {
        $service = new class () extends LintService {
            /** @return array{0: string, 1: int} */
            public function runExecute(string $cwd): array
            {
                return $this->execute(null, $cwd);
            }

            protected function vaultIsolation(): VaultIsolation
            {
                return new VaultIsolation('/nonexistent/' . uniqid('', true));
            }
        };

        [$output, $exitCode] = $service->runExecute($this->dir . '/project');

        $this->assertSame(-1, $exitCode);
        $this->assertStringStartsWith('Lint did not run: Could not prepare vault isolation', $output);
        $this->assertFileDoesNotExist($this->dir . '/env.dump', 'ansible-lint must not start');
    }

    public function testAnsibleLintKeepsWhatItNeeds(): void
    {
        $service = new class () extends LintService {
            /** @return array<string, string> */
            public function envFor(string $cwd): array
            {
                return $this->buildProcessEnv($cwd);
            }
        };

        $env = $service->envFor($this->dir . '/project');

        $this->assertSame(sys_get_temp_dir(), $env['HOME']);
        $this->assertSame($this->dir . '/project/.ansible', $env['ANSIBLE_HOME']);
        $this->assertStringStartsWith($this->dir . '/bin:', $env['PATH']);
        $this->assertSame('yaml', $env['ANSIBLE_STDOUT_CALLBACK'], 'operator Ansible settings still apply');
    }
}
