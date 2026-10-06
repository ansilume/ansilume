<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\components\VaultIsolation;
use app\helpers\FileHelper;
use app\services\AnsibleInventoryRunner;
use app\tests\unit\TemporaryEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Regression: server-side inventory parsing honoured a repository's
 * ansible.cfg vault settings. It ran the repo's vault password script (code
 * execution in the app container) and cached decrypted group_vars that every
 * viewer could read.
 *
 * A fake ansible-inventory first on PATH records its environment.
 */
class AnsibleInventoryRunnerVaultTest extends TestCase
{
    private string $dir;
    private TemporaryEnvironment $env;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/inv_vault_' . uniqid('', true);
        mkdir($this->dir . '/bin', 0o700, true);
        $this->env = new TemporaryEnvironment([
            'PATH' => $this->dir . '/bin:' . (getenv('PATH') ?: '/usr/bin:/bin'),
            // An operator's server-side vault password must not apply either.
            'ANSIBLE_VAULT_PASSWORD_FILE' => '/etc/server-vault-password',
        ]);
    }

    protected function tearDown(): void
    {
        $this->env->restore();
        FileHelper::removeDirectory($this->dir);
    }

    private function fakeInventory(string $body): void
    {
        file_put_contents(
            $this->dir . '/bin/ansible-inventory',
            "#!/bin/sh\nenv > " . escapeshellarg($this->dir . '/env.dump') . "\n" . $body . "\n"
        );
        chmod($this->dir . '/bin/ansible-inventory', 0o700);
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

    public function testRunHandsAnsibleOnlyTheDecoy(): void
    {
        $this->fakeInventory("echo '{}'");

        $result = (new AnsibleInventoryRunner())->run('/p/hosts.yml');

        $this->assertNull($result['error']);
        $env = $this->dumpedEnv();
        $decoy = $env['ANSIBLE_VAULT_PASSWORD_FILE'];
        $this->assertStringContainsString('ansilume_vault_decoy_', $decoy);
        $this->assertSame($decoy, $env['ANSIBLE_VAULT_IDENTITY_LIST']);
        $this->assertSame('False', $env['ANSIBLE_ASK_VAULT_PASS']);
        $this->assertArrayNotHasKey('ANSIBLE_VARS_ENABLED', $env);
        $this->assertFileDoesNotExist($decoy, 'the decoy is removed after the run');
    }

    public function testTheDecoyIsRemovedAfterATimeout(): void
    {
        $this->fakeInventory('sleep 5');
        $runner = new AnsibleInventoryRunner();
        $runner->timeout = 1;

        $result = $runner->run('/p/hosts.yml');

        $this->assertSame('ansible-inventory timed out.', $result['error']);
        $this->assertFileDoesNotExist($this->dumpedEnv()['ANSIBLE_VAULT_PASSWORD_FILE']);
    }

    public function testRunWithoutVarsPluginsLoadsNoVarsPlugin(): void
    {
        $this->fakeInventory("echo '{}'");

        (new AnsibleInventoryRunner())->runWithoutVarsPlugins('/p/hosts.yml');

        $env = $this->dumpedEnv();
        $this->assertSame(AnsibleInventoryRunner::VARS_PLUGINS_DISABLED, $env['ANSIBLE_VARS_ENABLED']);
        $this->assertStringContainsString('ansilume_vault_decoy_', $env['ANSIBLE_VAULT_PASSWORD_FILE']);
    }

    public function testFailuresKeepStderrAndTheExitCode(): void
    {
        $this->fakeInventory("echo 'Decryption failed (no vault secrets were found that could decrypt)' >&2\nexit 4");

        $result = (new AnsibleInventoryRunner())->run('/p/hosts.yml');

        $this->assertSame(4, $result['exit_code']);
        $this->assertStringContainsString('Decryption failed', $result['stderr']);
        $this->assertStringStartsWith('ansible-inventory failed (exit 4): Decryption failed', (string)$result['error']);
    }

    public function testWithoutIsolationNothingRuns(): void
    {
        $this->fakeInventory("echo '{}'");
        $runner = new class () extends AnsibleInventoryRunner {
            protected function vaultIsolation(): VaultIsolation
            {
                return new VaultIsolation('/nonexistent/' . uniqid('', true));
            }
        };

        $result = $runner->run('/p/hosts.yml');

        $this->assertStringContainsString('refusing to run Ansible without it', (string)$result['error']);
        $this->assertFileDoesNotExist($this->dir . '/env.dump', 'ansible-inventory must not start');
    }
}
