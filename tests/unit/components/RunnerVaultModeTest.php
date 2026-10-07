<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\RunnerVaultMode;
use app\components\VaultIsolation;
use app\helpers\FileHelper;
use PHPUnit\Framework\TestCase;

/**
 * How the runner turns the payload's vault_password_source into the command
 * and environment of ansible-playbook. The real-ansible proof lives in
 * tests/integration/components/RunnerVaultModeAnsibleTest.php.
 */
class RunnerVaultModeTest extends TestCase
{
    private const COMMAND = ['ansible-playbook', '-i', 'hosts.yml', 'site.yml', '--vault-password-file', '/tmp/ansilume_vault_x'];

    private string $dir;

    /** @var list<RunnerVaultMode> */
    private array $modes = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runner_vault_mode_' . uniqid('', true);
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->modes as $mode) {
            $mode->cleanup();
        }
        FileHelper::removeDirectory($this->dir);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mode(array $payload, ?string $envBinary = null): RunnerVaultMode
    {
        $mode = $envBinary === null
            ? RunnerVaultMode::fromPayload($payload, $this->dir)
            : RunnerVaultMode::fromPayload($payload, $this->dir, $envBinary);

        return $this->modes[] = $mode;
    }

    /**
     * @return list<string>
     */
    private function decoys(): array
    {
        return glob($this->dir . '/ansilume_vault_decoy_*') ?: [];
    }

    // -- Repository settings apply (legacy behaviour) -----------------------------

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function legacyPayloadProvider(): array
    {
        return [
            'older server without the field' => [[]],
            'repository' => [['vault_password_source' => 'repository']],
            'null' => [['vault_password_source' => null]],
            'unknown value' => [['vault_password_source' => 'none']],
            'other case' => [['vault_password_source' => 'Ansilume']],
            'padded' => [['vault_password_source' => ' ansilume']],
            'not a string' => [['vault_password_source' => true]],
            'a list' => [['vault_password_source' => ['ansilume']]],
        ];
    }

    /**
     * Compatibility: a new runner talking to an older server, and any value
     * this runner does not know, keep the behaviour from before 2.8.
     *
     * @dataProvider legacyPayloadProvider
     * @param array<string, mixed> $payload
     */
    public function testAnythingButAnsilumeLeavesCommandAndEnvironmentAlone(array $payload): void
    {
        $env = ['PATH' => '/usr/bin', 'ANSIBLE_VAULT_PASSWORD_FILE' => '/srv/vault-pass.sh'];
        $mode = $this->mode($payload);

        $this->assertFalse($mode->isolates());
        $this->assertSame(['command' => self::COMMAND, 'env' => $env], $mode->apply(self::COMMAND, $env));
        $this->assertSame([], $this->decoys(), 'no decoy without isolation');
        $mode->cleanup();
    }

    public function testTheLegacyModeNeedsNoEnvBinary(): void
    {
        $mode = $this->mode(['vault_password_source' => 'repository'], '/nonexistent/env');

        $this->assertSame(self::COMMAND, $mode->apply(self::COMMAND, [])['command']);
    }

    // -- Ansilume only ------------------------------------------------------------

    public function testAnsilumeOnlyNeutralisesTheRepositoryVaultSettings(): void
    {
        $mode = $this->mode(['vault_password_source' => 'ansilume']);

        $applied = $mode->apply(self::COMMAND, ['PATH' => '/usr/bin', 'LANG' => 'C.UTF-8']);

        $this->assertTrue($mode->isolates());
        $this->assertSame(['/usr/bin/env', 'ANSIBLE_VAULT_ID_MATCH=', ...self::COMMAND], $applied['command']);
        [$decoy] = $this->decoys();
        $this->assertSame([
            'PATH' => '/usr/bin',
            'LANG' => 'C.UTF-8',
            VaultIsolation::ENV_PASSWORD_FILE => $decoy,
            VaultIsolation::ENV_IDENTITY_LIST => $decoy,
            VaultIsolation::ENV_ASK => 'False',
        ], $applied['env']);
        $this->assertSame('600', substr(sprintf('%o', fileperms($decoy)), -3));
    }

    /**
     * Security: the isolation is merged last, so vault variables from a Token
     * credential or from the runner host cannot bring the repository's
     * password script back.
     */
    public function testTheIsolationWinsOverEarlierVaultVariables(): void
    {
        $mode = $this->mode(['vault_password_source' => 'ansilume']);

        $env = $mode->apply(self::COMMAND, [
            'ANSIBLE_VAULT_PASSWORD_FILE' => '/srv/repo/vault-pass.sh',
            'ANSIBLE_VAULT_IDENTITY_LIST' => 'repo@/srv/repo/vault-pass.sh',
            'ANSIBLE_ASK_VAULT_PASS' => 'True',
        ])['env'];

        [$decoy] = $this->decoys();
        $this->assertSame($decoy, $env['ANSIBLE_VAULT_PASSWORD_FILE']);
        $this->assertSame($decoy, $env['ANSIBLE_VAULT_IDENTITY_LIST']);
        $this->assertSame('False', $env['ANSIBLE_ASK_VAULT_PASS']);
    }

    /**
     * vault_id_match cannot be cleared through the environment array:
     * proc_open() drops the empty value and any non-empty value turns
     * matching on. The env(1) prefix clears it even when the runner host or a
     * credential set it.
     */
    public function testTheCommandRunsWithAnEmptyVaultIdMatch(): void
    {
        $probe = ['sh', '-c', 'printf "%s|%s|%s|%s" "${ANSIBLE_VAULT_ID_MATCH-unset}" "$ANSIBLE_VAULT_PASSWORD_FILE" "$ANSIBLE_VAULT_IDENTITY_LIST" "$ANSIBLE_ASK_VAULT_PASS"'];
        $env = [
            'PATH' => (string)getenv('PATH'),
            'ANSIBLE_VAULT_ID_MATCH' => 'True',
            'ANSIBLE_VAULT_PASSWORD_FILE' => '/srv/repo/vault-pass.sh',
            'ANSIBLE_ASK_VAULT_PASS' => 'True',
        ];

        $isolated = $this->mode(['vault_password_source' => 'ansilume'])->apply($probe, $env);
        [$decoy] = $this->decoys();
        $this->assertSame("|{$decoy}|{$decoy}|False", $this->runProbe($isolated['command'], $isolated['env']));

        $legacy = $this->mode(['vault_password_source' => 'repository'])->apply($probe, $env);
        $this->assertSame('True|/srv/repo/vault-pass.sh||True', $this->runProbe($legacy['command'], $legacy['env']));
    }

    public function testTheCommandBecomesAListInEveryMode(): void
    {
        $command = [3 => 'ansible-playbook', 7 => 'site.yml'];

        $this->assertSame(['ansible-playbook', 'site.yml'], $this->mode([])->apply($command, [])['command']);
        $this->assertSame(
            ['/usr/bin/env', 'ANSIBLE_VAULT_ID_MATCH=', 'ansible-playbook', 'site.yml'],
            $this->mode(['vault_password_source' => 'ansilume'])->apply($command, [])['command']
        );
    }

    /**
     * The decoy is created when the mode is prepared, before the job writes
     * any temp file, so a failure needs no cleanup; apply() reuses it.
     */
    public function testTheDecoyIsCreatedUpFrontAndReused(): void
    {
        $mode = $this->mode(['vault_password_source' => 'ansilume']);
        $this->assertCount(1, $this->decoys());

        $first = $mode->apply([], [])['env'][VaultIsolation::ENV_PASSWORD_FILE];
        $second = $mode->apply([], [])['env'][VaultIsolation::ENV_PASSWORD_FILE];

        $this->assertSame($first, $second);
        $this->assertSame([$first], $this->decoys());
    }

    public function testCleanupRemovesTheDecoyAndIsIdempotent(): void
    {
        $mode = $this->mode(['vault_password_source' => 'ansilume']);
        $this->assertCount(1, $this->decoys());

        $mode->cleanup();
        $this->assertSame([], $this->decoys());
        $mode->cleanup();
        $this->assertSame([], $this->decoys());
    }

    // -- Fail closed --------------------------------------------------------------

    /**
     * The runner must fail the job instead of running Ansible with the
     * repository's vault settings when the decoy cannot be created.
     */
    public function testAnUnusableDecoyDirectoryFailsWithAClearReason(): void
    {
        $missing = $this->dir . '/missing';

        try {
            RunnerVaultMode::fromPayload(['vault_password_source' => 'ansilume'], $missing);
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith(
                "This project uses only Ansilume's vault password, but the runner could not neutralise the repository's vault settings, so the job did not run: ",
                $e->getMessage()
            );
            $this->assertStringContainsString('refusing to run Ansible without it.', $e->getMessage());
            $this->assertStringEndsWith("Check that the runner user can write to {$missing}.", $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    public function testTheDecoyGoesToTheSystemTempDirectoryByDefault(): void
    {
        $mode = RunnerVaultMode::fromPayload(['vault_password_source' => 'ansilume']);
        $this->modes[] = $mode;

        $decoy = $mode->apply([], [])['env'][VaultIsolation::ENV_PASSWORD_FILE];
        $this->assertSame(sys_get_temp_dir(), dirname($decoy), 'the system temp directory is the default');
    }

    public function testAMissingEnvBinaryFailsBeforeAnyDecoyExists(): void
    {
        try {
            RunnerVaultMode::fromPayload(['vault_password_source' => 'ansilume'], $this->dir, $this->dir . '/no-env');
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('so the job did not run: ', $e->getMessage());
            $this->assertStringEndsWith($this->dir . '/no-env is missing or not executable.', $e->getMessage());
        }
        $this->assertSame([], $this->decoys());
    }

    public function testANonExecutableEnvBinaryIsRefused(): void
    {
        $file = $this->dir . '/env';
        file_put_contents($file, "#!/bin/sh\n");
        chmod($file, 0o644);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($file . ' is missing or not executable.');

        RunnerVaultMode::fromPayload(['vault_password_source' => 'ansilume'], $this->dir, $file);
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     */
    private function runProbe(array $command, array $env): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir, $env);
        $this->assertIsResource($process);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $err);

        return $out;
    }
}
