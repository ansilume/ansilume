<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\LintVerdict;
use app\helpers\FileHelper;
use app\models\Inventory;
use app\models\Project;
use app\services\InventoryService;
use app\services\LintService;
use app\tests\integration\DbTestCase;

/**
 * Regression, with the real ansible-inventory and ansible-lint: a project's
 * ansible.cfg made the server run the repository's vault password script
 * (code execution in the app container) and decrypt vaulted group_vars into
 * the inventory cache that every viewer can read.
 */
class ServerSideVaultIsolationTest extends DbTestCase
{
    private const PASSWORD = 'repo-vault-secret';
    private const PLAINTEXT = 'E2E-VAULT-PLAINTEXT-MARKER';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ansible-inventory', 'ansible-vault'] as $binary) {
            if (!$this->hasBinary($binary)) {
                $this->markTestSkipped("{$binary} is not installed.");
            }
        }
        $this->dir = sys_get_temp_dir() . '/vault_isolation_' . uniqid('', true);
        mkdir($this->dir . '/inventory/group_vars', 0o755, true);
        file_put_contents($this->dir . '/inventory/hosts.yml', "all:\n  hosts:\n    web1:\n      ansible_host: 192.0.2.10\n");
        file_put_contents($this->dir . '/vault-pass.sh', "#!/bin/sh\ntouch " . escapeshellarg($this->dir . '/SCRIPT-RAN') . "\necho " . self::PASSWORD . "\n");
        chmod($this->dir . '/vault-pass.sh', 0o755);
        file_put_contents($this->dir . '/vault-pass.txt', self::PASSWORD . "\n");
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            FileHelper::removeDirectory($this->dir);
        }
        parent::tearDown();
    }

    private function hasBinary(string $name): bool
    {
        foreach (explode(':', (string)getenv('PATH')) as $dir) {
            if ($dir !== '' && is_executable($dir . '/' . $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Encrypts a file in place, from outside the project so its ansible.cfg
     * plays no part.
     */
    private function encrypt(string $relative, string $content): void
    {
        file_put_contents($this->dir . '/' . $relative, $content);
        $this->vault(['encrypt', $this->dir . '/' . $relative]);
    }

    /**
     * @param list<string> $args
     */
    private function vault(array $args): string
    {
        $password = tempnam(sys_get_temp_dir(), 'vault_pw_');
        $this->assertNotFalse($password);
        file_put_contents($password, self::PASSWORD);
        try {
            $process = proc_open(
                ['ansible-vault', ...array_slice($args, 0, 1), '--vault-password-file', $password, ...array_slice($args, 1)],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                sys_get_temp_dir(),
                ['PATH' => (string)getenv('PATH'), 'HOME' => sys_get_temp_dir()]
            );
            $this->assertIsResource($process);
            $out = (string)stream_get_contents($pipes[1]);
            $err = (string)stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $err);
        } finally {
            unlink($password);
        }

        return $out;
    }

    private function cfg(string $content): void
    {
        file_put_contents($this->dir . '/ansible.cfg', "[defaults]\n" . $content . "\n");
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFileInventory(string $sourcePath = 'inventory/hosts.yml'): array
    {
        $user = $this->createUser('vault');
        $project = new Project();
        $project->name = 'vault-isolation-' . uniqid();
        $project->scm_type = Project::SCM_TYPE_MANUAL;
        $project->local_path = $this->dir;
        $project->status = 'synced';
        $project->created_by = (int)$user->id;
        $project->save(false);

        $inventory = new Inventory();
        $inventory->name = 'vault-isolation-' . uniqid();
        $inventory->inventory_type = Inventory::TYPE_FILE;
        $inventory->project_id = $project->id;
        $inventory->source_path = $sourcePath;
        $inventory->created_by = (int)$user->id;
        $inventory->save(false);

        $service = new InventoryService();
        $result = $service->resolveAndCache($inventory);
        $inventory->refresh();
        $result['cache'] = (string)$inventory->parsed_hosts . (string)$inventory->parsed_error;

        return $result;
    }

    private function assertNothingLeaked(array $result): void
    {
        $this->assertStringNotContainsString(self::PLAINTEXT, (string)json_encode($result));
        $this->assertFileDoesNotExist($this->dir . '/SCRIPT-RAN', "the repository's vault password script ran on the server");
    }

    public function testAVaultPasswordScriptNeverRunsAndGroupVarsStayEncrypted(): void
    {
        $this->cfg('vault_password_file = vault-pass.sh');
        $this->encrypt('inventory/group_vars/all.yml', 'secret_var: ' . self::PLAINTEXT . "\n");

        $result = $this->parseFileInventory();

        $this->assertNothingLeaked($result);
        $this->assertNull($result['error']);
        $this->assertArrayHasKey('web1', $result['hosts'], 'hosts are still listed');
        $this->assertSame([InventoryService::NOTICE_VARS_SKIPPED], $result['notices']);
    }

    public function testACommittedPasswordFileIsIgnored(): void
    {
        $this->cfg('vault_password_file = vault-pass.txt');
        $this->encrypt('inventory/group_vars/all.yml', 'secret_var: ' . self::PLAINTEXT . "\n");

        $this->assertNothingLeaked($this->parseFileInventory());
    }

    public function testAVaultIdentityListWithIdMatchingIsIgnored(): void
    {
        $this->cfg("vault_identity_list = repo@vault-pass.sh\nvault_id_match = True");
        $this->encrypt('inventory/group_vars/all.yml', 'secret_var: ' . self::PLAINTEXT . "\n");

        $result = $this->parseFileInventory();

        $this->assertNothingLeaked($result);
        $this->assertArrayHasKey('web1', $result['hosts']);
    }

    public function testAnEncryptedInventorySourceIsReportedAsSuch(): void
    {
        $this->cfg('vault_password_file = vault-pass.sh');
        $this->encrypt('encrypted-hosts.yml', "all:\n  hosts:\n    db1:\n      note: " . self::PLAINTEXT . "\n");

        $result = $this->parseFileInventory('encrypted-hosts.yml');

        $this->assertNothingLeaked($result);
        $this->assertStringStartsWith(InventoryService::ERROR_ENCRYPTED_SOURCE, (string)$result['error']);
    }

    public function testAnInlineVaultValueIsMaskedInTheCache(): void
    {
        $inline = $this->vault(['encrypt_string', self::PLAINTEXT, '--name', 'inline_secret']);
        file_put_contents(
            $this->dir . '/inventory/hosts.yml',
            "all:\n  hosts:\n    web1:\n      ansible_host: 192.0.2.10\n      " . str_replace("\n", "\n      ", trim($inline)) . "\n"
        );

        $result = $this->parseFileInventory();

        $this->assertNothingLeaked($result);
        $this->assertSame('[vault-encrypted]', $result['hosts']['web1']['inline_secret']);
        $this->assertStringNotContainsString('ANSIBLE_VAULT', $result['cache'], 'no ciphertext in the cache either');
    }

    public function testARepositoryWithoutVaultParsesAsBefore(): void
    {
        $result = $this->parseFileInventory();

        $this->assertNull($result['error']);
        $this->assertSame('192.0.2.10', $result['hosts']['web1']['ansible_host']);
        $this->assertSame([], $result['notices']);
    }

    public function testLintNeverRunsTheScriptAndReportsVaultFilesNeutrally(): void
    {
        if (!$this->hasBinary('ansible-lint')) {
            $this->markTestSkipped('ansible-lint is not installed.');
        }
        $this->cfg('vault_password_file = vault-pass.sh');
        $this->encrypt('vault.yml', 'secret_var: ' . self::PLAINTEXT . "\n");
        file_put_contents(
            $this->dir . '/site.yml',
            "---\n- name: Site\n  hosts: all\n  vars_files:\n    - vault.yml\n  tasks:\n    - name: Show\n      ansible.builtin.debug:\n        msg: \"{{ secret_var }}\"\n"
        );
        $service = new class () extends LintService {
            /** @return array{0: string, 1: int} */
            public function lint(string $playbook, string $cwd): array
            {
                return $this->execute($playbook, $cwd);
            }
        };

        [$output, $exitCode] = $service->lint('site.yml', $this->dir);

        $this->assertFileDoesNotExist($this->dir . '/SCRIPT-RAN', "lint ran the repository's vault password script");
        $this->assertStringNotContainsString(self::PLAINTEXT, $output);
        $this->assertSame(LintVerdict::VAULT_SKIPPED, LintVerdict::of($exitCode, $output), $output);
    }
}
