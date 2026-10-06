<?php

declare(strict_types=1);

namespace app\commands;

use app\helpers\FileHelper;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\Project;

/**
 * Seeds a manual project whose ansible.cfg points at a committed vault
 * password file, for the vault isolation specs:
 *
 * - inventories/vault.spec.ts parses INVENTORY (encrypted group_vars plus an
 *   inline vault value) and SOURCE_INVENTORY (a whole-file encrypted
 *   inventory) and checks that PLAINTEXT never shows up.
 * - projects/lint-vault.spec.ts and job-templates/rbac.spec.ts check the
 *   neutral lint verdict of a playbook with encrypted vars_files.
 *
 * The fixture files are generated with ansible-vault on every seed. Without
 * ansible-vault the seeder skips, and the specs skip as well.
 */
class E2eVaultFixtureSeeder
{
    public const PROJECT = 'e2e-vault-project';
    public const INVENTORY = 'e2e-vault-inventory';
    public const SOURCE_INVENTORY = 'e2e-vault-source-inventory';
    public const TEMPLATE = 'e2e-vault-template';
    public const PLAINTEXT = 'E2E-VAULT-PLAINTEXT-MARKER';

    private const FIXTURE_SECRET = 'e2e-vault-fixture-secret';

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    public function seed(int $userId, int $runnerGroupId): void
    {
        $dir = sys_get_temp_dir() . '/ansilume-e2e-vault-project';
        try {
            $this->writeFixtures($dir);
        } catch (\RuntimeException $e) {
            ($this->logger)('  Vault fixtures skipped: ' . $e->getMessage() . "\n");
            return;
        }

        $project = $this->ensureProject($dir, $userId);
        $inventory = $this->ensureInventory(self::INVENTORY, 'inventory/hosts.yml', $project->id, $userId);
        $this->ensureInventory(self::SOURCE_INVENTORY, 'encrypted-hosts.yml', $project->id, $userId);
        $this->ensureTemplate($dir, $project->id, $inventory->id, $runnerGroupId, $userId);
        ($this->logger)("  Seeded vault fixture project {$project->name} (ID {$project->id}).\n");
    }

    private function writeFixtures(string $dir): void
    {
        FileHelper::removeDirectory($dir);
        if (!mkdir($dir . '/inventory/group_vars', 0o755, true)) {
            throw new \RuntimeException("cannot create {$dir}");
        }
        $this->write($dir, 'ansible.cfg', "[defaults]\nvault_password_file = vault-pass.txt\n");
        $this->write($dir, 'vault-pass.txt', self::FIXTURE_SECRET . "\n");
        $this->write($dir, 'inventory/group_vars/all.yml', 'e2e_vault_plaintext: ' . self::PLAINTEXT . "\n");
        $this->encrypt($dir, 'inventory/group_vars/all.yml');
        $inline = trim($this->vault(['encrypt_string', self::PLAINTEXT, '--name', 'inline_secret']));
        $this->write(
            $dir,
            'inventory/hosts.yml',
            "all:\n  hosts:\n    e2e-vault-host:\n      ansible_host: 192.0.2.10\n      "
                . str_replace("\n", "\n      ", $inline) . "\n"
        );
        $this->write($dir, 'encrypted-hosts.yml', "all:\n  hosts:\n    e2e-vault-source-host:\n      note: " . self::PLAINTEXT . "\n");
        $this->encrypt($dir, 'encrypted-hosts.yml');
        $this->write($dir, 'vault.yml', 'e2e_vault_plaintext: ' . self::PLAINTEXT . "\n");
        $this->encrypt($dir, 'vault.yml');
        $this->write(
            $dir,
            'site.yml',
            "---\n- name: E2E vault site\n  hosts: all\n  vars_files:\n    - vault.yml\n  tasks:\n"
                . "    - name: Show the value\n      ansible.builtin.debug:\n        msg: \"{{ e2e_vault_plaintext }}\"\n"
        );
    }

    private function write(string $dir, string $relative, string $content): void
    {
        $path = $dir . '/' . $relative;
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException("cannot write {$path}");
        }
        // php-fpm runs as www-data and the seeder as root.
        chmod($path, 0o644);
    }

    private function encrypt(string $dir, string $relative): void
    {
        $this->vault(['encrypt', $dir . '/' . $relative]);
        // ansible-vault rewrites the file as 0600; php-fpm must still read it.
        chmod($dir . '/' . $relative, 0o644);
    }

    /**
     * Runs ansible-vault from outside the fixture, so its ansible.cfg plays
     * no part.
     *
     * @param list<string> $args subcommand first
     */
    private function vault(array $args): string
    {
        $secretFile = tempnam(sys_get_temp_dir(), 'e2e_vault_');
        if ($secretFile === false) {
            throw new \RuntimeException('cannot create a temp file');
        }
        file_put_contents($secretFile, self::FIXTURE_SECRET);
        try {
            $command = [
                'ansible-vault',
                $args[0],
                '--vault-password-file',
                $secretFile,
                ...array_slice($args, 1),
            ];
            $process = proc_open(
                $command,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                sys_get_temp_dir(),
                ['PATH' => (string)getenv('PATH'), 'HOME' => sys_get_temp_dir()]
            );
            if (!is_resource($process)) {
                throw new \RuntimeException('ansible-vault is not available');
            }
            $out = (string)stream_get_contents($pipes[1]);
            $err = (string)stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0) {
                throw new \RuntimeException('ansible-vault failed: ' . trim($err));
            }
        } finally {
            FileHelper::safeUnlink($secretFile);
        }

        return $out;
    }

    private function ensureProject(string $dir, int $userId): Project
    {
        $project = Project::findOne(['name' => self::PROJECT]) ?? new Project();
        $project->name = self::PROJECT;
        $project->description = 'E2E project with vault-encrypted files and a committed vault password';
        $project->scm_type = Project::SCM_TYPE_MANUAL;
        $project->local_path = $dir;
        $project->status = 'synced';
        $project->lint_output = null;
        $project->lint_exit_code = null;
        $project->lint_at = null;
        $project->created_by = $userId;
        $project->save(false);

        return $project;
    }

    private function ensureInventory(string $name, string $sourcePath, int $projectId, int $userId): Inventory
    {
        $inventory = Inventory::findOne(['name' => $name]) ?? new Inventory();
        $inventory->name = $name;
        $inventory->inventory_type = Inventory::TYPE_FILE;
        $inventory->project_id = $projectId;
        $inventory->source_path = $sourcePath;
        // Every run starts unparsed, so the specs exercise a fresh parse.
        $inventory->parsed_hosts = null;
        $inventory->parsed_error = null;
        $inventory->parsed_at = null;
        $inventory->created_by = $userId;
        $inventory->save(false);

        return $inventory;
    }

    private function ensureTemplate(string $dir, int $projectId, int $inventoryId, int $runnerGroupId, int $userId): void
    {
        $template = JobTemplate::findOne(['name' => self::TEMPLATE]) ?? new JobTemplate();
        $template->name = self::TEMPLATE;
        $template->description = 'E2E template whose playbook loads vault-encrypted vars_files';
        $template->project_id = $projectId;
        $template->inventory_id = $inventoryId;
        $template->runner_group_id = $runnerGroupId;
        $template->playbook = 'site.yml';
        $template->verbosity = 0;
        $template->forks = 5;
        $template->become = false;
        $template->timeout_minutes = 30;
        $template->created_by = $userId;
        $template->lint_exit_code = 2;
        $template->lint_at = time();
        $template->lint_output = 'internal-error: Unexpected error code 1 from execution of: ansible-playbook --syntax-check site.yml'
            . "\nERROR! Decryption failed (no vault secrets were found that could decrypt) on {$dir}/vault.yml";
        $template->save(false);
    }
}
