<?php

declare(strict_types=1);

namespace app\tests\integration\components;

use app\components\CredentialInjector;
use app\components\PlaybookEnvironment;
use app\components\RunnerHttpClient;
use app\components\RunnerProcessExecutor;
use app\components\RunnerVaultMode;
use app\helpers\FileHelper;
use app\models\Credential;
use app\tests\unit\components\SilentController;
use PHPUnit\Framework\TestCase;

/**
 * Runs real ansible-playbook the way the runner does (PlaybookEnvironment,
 * CredentialInjector, RunnerVaultMode, RunnerProcessExecutor with stdin
 * closed) against a repository whose ansible.cfg brings its own vault
 * password script, asks for a vault password and turns vault id matching on.
 *
 * 'ansilume' (Ansilume only) must decrypt with the job template's vault
 * password alone: the repository's script never runs and nothing prompts.
 * 'repository' and a payload without the field (older server) keep the
 * behaviour from before 2.8.
 */
class RunnerVaultModeAnsibleTest extends TestCase
{
    private const ANSILUME_PASSWORD = 'ansilume-template-vault-password';
    private const REPO_PASSWORD = 'repository-script-password';
    private const PLAINTEXT = 'RUNNER-VAULT-PLAINTEXT-MARKER';

    private const HOSTILE_CFG = "vault_password_file = vault-pass.sh\nask_vault_pass = True\nvault_id_match = True";

    private string $dir;
    private string $project;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ansible-playbook', 'ansible-vault'] as $binary) {
            if (!$this->hasBinary($binary)) {
                $this->markTestSkipped("{$binary} is not installed.");
            }
        }
        $this->dir = sys_get_temp_dir() . '/runner_vault_mode_' . uniqid('', true);
        $this->project = $this->dir . '/project';
        mkdir($this->project, 0o755, true);
        mkdir($this->dir . '/home', 0o700, true);

        file_put_contents(
            $this->project . '/vault-pass.sh',
            "#!/bin/sh\ntouch " . escapeshellarg($this->dir . '/SCRIPT-RAN') . "\necho " . self::REPO_PASSWORD . "\n"
        );
        chmod($this->project . '/vault-pass.sh', 0o755);
        file_put_contents($this->project . '/vault.yml', 'secret_var: ' . self::PLAINTEXT . "\n");
        $this->encryptWithAnsilumePassword('vault.yml', 'prod');
        $this->assertStringStartsWith('$ANSIBLE_VAULT;1.2;AES256;prod', (string)file_get_contents($this->project . '/vault.yml'));
        file_put_contents(
            $this->project . '/site.yml',
            "---\n- name: Read a vaulted variable\n  hosts: localhost\n  gather_facts: false\n  vars_files:\n    - vault.yml\n"
            . "  tasks:\n    - name: Show it\n      ansible.builtin.debug:\n        msg: \"secret={{ secret_var }}\"\n"
        );
        file_put_contents(
            $this->project . '/plain.yml',
            "---\n- name: No vault content\n  hosts: localhost\n  gather_facts: false\n"
            . "  tasks:\n    - name: Say hello\n      ansible.builtin.debug:\n        msg: plain-playbook-ran\n"
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            FileHelper::removeDirectory($this->dir);
        }
        parent::tearDown();
    }

    // -- Ansilume only ------------------------------------------------------------

    public function testAnsilumeOnlyDecryptsWithTheTemplatePasswordAndIgnoresTheRepository(): void
    {
        $this->cfg(self::HOSTILE_CFG);

        [$exitCode, $output] = $this->runPlaybook(['vault_password_source' => 'ansilume'], self::ANSILUME_PASSWORD);

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('secret=' . self::PLAINTEXT, $output);
        $this->assertScriptNeverRan();
        $this->assertStringNotContainsStringIgnoringCase('prompt', $output);
    }

    /**
     * Without a template vault password nothing can open the file: the
     * repository's script does not step in.
     */
    public function testAnsilumeOnlyWithoutATemplatePasswordCannotFallBackToTheRepository(): void
    {
        $this->cfg('vault_password_file = vault-pass.sh');

        [$exitCode, $output] = $this->runPlaybook(['vault_password_source' => 'ansilume'], null);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('Decryption failed', $output);
        $this->assertStringNotContainsString(self::PLAINTEXT, $output);
        $this->assertScriptNeverRan();
    }

    /**
     * The decoy must not break playbooks without vault content, even when
     * the repository asks for a vault password at start.
     */
    public function testAnsilumeOnlyRunsPlaybooksWithoutVaultContent(): void
    {
        $this->cfg(self::HOSTILE_CFG);

        [$exitCode, $output] = $this->runPlaybook(['vault_password_source' => 'ansilume'], null, 'plain.yml');

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('plain-playbook-ran', $output);
        $this->assertScriptNeverRan();
    }

    // -- Ansilume and repository (behaviour before 2.8) ---------------------------

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function legacyPayloadProvider(): array
    {
        return [
            'repository' => [['vault_password_source' => 'repository']],
            'older server without the field' => [[]],
        ];
    }

    /**
     * @dataProvider legacyPayloadProvider
     * @param array<string, mixed> $payload
     */
    public function testTheRepositoryPasswordScriptStillRunsNextToTheTemplatePassword(array $payload): void
    {
        $this->cfg('vault_password_file = vault-pass.sh');

        [$exitCode, $output] = $this->runPlaybook($payload, self::ANSILUME_PASSWORD);

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('secret=' . self::PLAINTEXT, $output);
        $this->assertFileExists($this->dir . '/SCRIPT-RAN', "the repository's vault settings apply as before");
    }

    /**
     * Why 'ansilume' clears the prompt: runners close stdin, so a repository
     * with ask_vault_pass fails every job.
     */
    public function testARepositoryAskingForAVaultPasswordFailsAsBefore(): void
    {
        $this->cfg('ask_vault_pass = True');

        [$exitCode, $output] = $this->runPlaybook(['vault_password_source' => 'repository'], self::ANSILUME_PASSWORD);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('EOFError', $output);
    }

    /**
     * Why 'ansilume' clears vault_id_match: the template password is labelled
     * 'default' and is never tried on the 'prod' file.
     */
    public function testRepositoryVaultIdMatchingKeepsTheTemplatePasswordOutAsBefore(): void
    {
        $this->cfg('vault_id_match = True');

        [$exitCode, $output] = $this->runPlaybook(['vault_password_source' => 'repository'], self::ANSILUME_PASSWORD);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('Decryption failed', $output);
        $this->assertStringNotContainsString(self::PLAINTEXT, $output);
    }

    // -- Helpers ------------------------------------------------------------------

    /**
     * Builds and runs the command exactly like RunnerController::executeJob:
     * playbook environment, credential arguments and environment, then the
     * vault mode last.
     *
     * @param array<string, mixed> $payload
     * @return array{0: int, 1: string} exit code and the plain job log
     */
    private function runPlaybook(array $payload, ?string $vaultPassword, string $playbook = 'site.yml'): array
    {
        $env = PlaybookEnvironment::build(getenv() ?: [], $this->dir . '/tasks.ndjson');
        // Keep Ansible's own files inside the test directory: the default
        // HOME is the runtime directory the dev runners share.
        $env = array_merge($env, [
            'HOME' => $this->dir . '/home',
            'ANSIBLE_HOME' => $this->dir . '/home/.ansible',
            'ANSIBLE_LOCAL_TEMP' => $this->dir . '/home/tmp',
        ]);
        $credentials = $vaultPassword === null ? [] : [[
            'credential_type' => Credential::TYPE_VAULT,
            'username' => null,
            'env_var_name' => null,
            'secrets' => ['vault_password' => $vaultPassword],
        ]];
        $injection = (new CredentialInjector())->injectAll($credentials);
        $cmd = array_merge(['ansible-playbook', '-i', 'localhost,', '-c', 'local', $playbook], $injection->args);
        $env = array_merge($env, $injection->env);
        $mode = RunnerVaultMode::fromPayload($payload);
        ['command' => $cmd, 'env' => $env] = $mode->apply($cmd, $env);

        $posts = [];
        $http = $this->createMock(RunnerHttpClient::class);
        $http->method('post')->willReturnCallback(static function (string $path, array $body) use (&$posts): array {
            $posts[] = $body;
            return ['ok' => true];
        });
        try {
            $executor = new RunnerProcessExecutor($http, new SilentController('runner', \Yii::$app));
            [$exitCode] = $executor->run(1, $cmd, ['project_path' => $this->project], $env, 2);
        } finally {
            CredentialInjector::cleanup($injection->tempFiles);
            $mode->cleanup();
        }
        $output = implode('', array_map(static fn (array $body): string => (string)($body['content'] ?? ''), $posts));

        return [$exitCode, (string)preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]/', '', $output)];
    }

    private function cfg(string $defaults): void
    {
        file_put_contents($this->project . '/ansible.cfg', "[defaults]\n" . $defaults . "\n");
    }

    /**
     * Encrypts in place from outside the project, so its ansible.cfg plays
     * no part.
     */
    private function encryptWithAnsilumePassword(string $relative, string $vaultId): void
    {
        $password = $this->dir . '/encrypt-password';
        file_put_contents($password, self::ANSILUME_PASSWORD);
        $process = proc_open(
            ['ansible-vault', 'encrypt', '--vault-id', $vaultId . '@' . $password, $this->project . '/' . $relative],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->dir,
            ['PATH' => (string)getenv('PATH'), 'HOME' => $this->dir . '/home']
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $err);
        unlink($password);
    }

    private function assertScriptNeverRan(): void
    {
        $this->assertFileDoesNotExist($this->dir . '/SCRIPT-RAN', "the repository's vault password script ran");
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
}
