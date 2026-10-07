<?php

declare(strict_types=1);

namespace app\tests\unit\commands;

use app\commands\RunnerController;
use app\components\RunnerHttpClient;
use app\components\RunnerVaultMode;
use app\helpers\FileHelper;
use app\tests\unit\TemporaryEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * RunnerController::executeJob applies the payload's vault_password_source:
 * it runs a real process (sh instead of ansible-playbook) and records what
 * the runner posts back, so the merge order, the command prefix, the cleanup
 * and the fail-closed path are checked end to end.
 */
class RunnerControllerVaultModeTest extends TestCase
{
    /** Prints the vault settings the playbook process would see. */
    private const PROBE = 'printf "pwfile=%s;idlist=%s;ask=%s;idmatch=%s" "$ANSIBLE_VAULT_PASSWORD_FILE"'
        . ' "$ANSIBLE_VAULT_IDENTITY_LIST" "$ANSIBLE_ASK_VAULT_PASS" "${ANSIBLE_VAULT_ID_MATCH-unset}"';

    private string $dir;

    /** @var list<array{path: string, body: array<string, mixed>}> */
    private array $posts = [];

    private TemporaryEnvironment $environment;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runner_vault_job_' . uniqid('', true);
        mkdir($this->dir, 0o700, true);
        // A vault setting on the runner host: forwarded like every ANSIBLE_*.
        $this->environment = new TemporaryEnvironment(['ANSIBLE_VAULT_ID_MATCH' => 'True']);
    }

    protected function tearDown(): void
    {
        $this->environment->restore();
        FileHelper::removeDirectory($this->dir);
    }

    public function testAnsilumeOnlyRunsTheJobIsolatedAndRemovesTheDecoy(): void
    {
        $this->executeJob($this->controller(), $this->payload(['vault_password_source' => 'ansilume']));

        $this->assertMatchesRegularExpression(
            '#^pwfile=(/[^;]+/ansilume_vault_decoy_[^;/]+);idlist=\1;ask=False;idmatch=$#',
            $this->stdout(),
            'the decoy beats the Token credential, the prompt is off and vault_id_match is cleared'
        );
        preg_match('#^pwfile=([^;]+);#', $this->stdout(), $match);
        $this->assertStringContainsString('ansilume_vault_decoy_', $match[1]);
        $this->assertFileDoesNotExist($match[1], 'the decoy is removed after the run');
        $this->assertSame(['exit_code' => 0, 'has_changes' => false, 'timed_out' => false], $this->completion());
    }

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
     * @param array<string, mixed> $extra
     */
    public function testTheLegacyModeKeepsTheEnvironmentAsBefore(array $extra): void
    {
        $this->executeJob($this->controller(), $this->payload($extra));

        $this->assertSame('pwfile=/srv/repo/vault-pass.sh;idlist=;ask=;idmatch=True', $this->stdout());
        $this->assertSame(0, $this->completion()['exit_code']);
    }

    /**
     * Fail closed: when the decoy cannot be created the job fails with the
     * reason and the command never runs with the repository's settings.
     * Nothing was written yet, so nothing is left behind.
     */
    public function testAnUnusableDecoyDirectoryFailsTheJobWithoutRunningIt(): void
    {
        $inventories = $this->inventoryTempFiles();

        $this->executeJob(
            $this->controller($this->dir . '/missing'),
            $this->payload(['vault_password_source' => 'ansilume', 'inventory_type' => 'static', 'inventory_content' => "localhost\n"])
        );

        $this->assertSame('', $this->stdout(), 'the command must not run');
        $logs = $this->postsTo('/api/runner/v1/jobs/7/logs');
        $this->assertCount(1, $logs);
        $this->assertSame('stderr', $logs[0]['stream']);
        $this->assertSame(0, $logs[0]['sequence']);
        $this->assertStringContainsString("could not neutralise the repository's vault settings, so the job did not run", (string)$logs[0]['content']);
        $this->assertStringContainsString($this->dir . '/missing', (string)$logs[0]['content']);
        $this->assertSame(['exit_code' => 1, 'has_changes' => false], $this->completion());
        $this->assertSame($inventories, $this->inventoryTempFiles(), 'no inventory temp file was written');
    }

    // -- Helpers ------------------------------------------------------------------

    private function controller(?string $decoyDirectory = null): RunnerController
    {
        $http = $this->createMock(RunnerHttpClient::class);
        $http->method('post')->willReturnCallback(function (string $path, array $body): array {
            $this->posts[] = ['path' => $path, 'body' => $body];
            return ['ok' => true];
        });

        return new class ('runner', \Yii::$app, $http, $decoyDirectory) extends RunnerController {
            public function __construct($id, $module, RunnerHttpClient $http, private readonly ?string $decoyDirectory)
            {
                parent::__construct($id, $module);
                $this->http = $http;
            }

            protected function vaultMode(array $payload): RunnerVaultMode
            {
                return $this->decoyDirectory === null
                    ? parent::vaultMode($payload)
                    : RunnerVaultMode::fromPayload($payload, $this->decoyDirectory);
            }

            public function stdout($string): int
            {
                return 0;
            }

            public function stderr($string): int
            {
                return 0;
            }
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function executeJob(RunnerController $controller, array $payload): void
    {
        $method = new \ReflectionMethod(RunnerController::class, 'executeJob');
        $method->setAccessible(true);
        $method->invoke($controller, 7, $payload);
    }

    /**
     * A manual project whose Token credential names ANSIBLE_VAULT_PASSWORD_FILE.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra): array
    {
        return $extra + [
            'job_id' => 7,
            'scm_type' => 'manual',
            'project_path' => $this->dir,
            'inventory_type' => 'file',
            'command' => ['sh', '-c', self::PROBE],
            'credentials' => [[
                'credential_type' => 'token',
                'username' => null,
                'env_var_name' => 'ANSIBLE_VAULT_PASSWORD_FILE',
                'secrets' => ['token' => '/srv/repo/vault-pass.sh'],
            ]],
            'timeout_minutes' => 1,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function postsTo(string $path): array
    {
        $bodies = [];
        foreach ($this->posts as $post) {
            if ($post['path'] === $path) {
                $bodies[] = $post['body'];
            }
        }

        return $bodies;
    }

    private function stdout(): string
    {
        $out = '';
        foreach ($this->postsTo('/api/runner/v1/jobs/7/logs') as $body) {
            if (($body['stream'] ?? '') === 'stdout') {
                $out .= (string)$body['content'];
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function completion(): array
    {
        $completions = $this->postsTo('/api/runner/v1/jobs/7/complete');
        $this->assertCount(1, $completions);

        return $completions[0];
    }

    /**
     * Inventory files the runner writes (InventoryService on the server uses
     * the same prefix with tempnam() names, so those are left out).
     *
     * @return list<string>
     */
    private function inventoryTempFiles(): array
    {
        $files = glob(sys_get_temp_dir() . '/ansilume_inv_*.yml') ?: [];

        return array_values(preg_grep('#/ansilume_inv_[0-9a-f]{13,}\.\d+\.yml$#', $files) ?: []);
    }
}
