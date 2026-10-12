<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\RunnerController;
use app\components\PlaybookEnvironment;
use app\components\RunnerHttpClient;
use app\components\RunnerProcessExecutor;
use app\tests\unit\components\SilentController;
use app\tests\unit\TemporaryEnvironment;
use PHPUnit\Framework\TestCase;
use yii\helpers\FileHelper;

/**
 * Regression (review V2-INV): Ansible loads group_vars/ and host_vars/ from
 * the directory of an inventory, whoever owns them. The runner wrote a
 * static inventory straight into its temp directory, which on a host
 * without PrivateTmp other local users share, so anyone could plant
 * <tmp>/group_vars/all.yml or <tmp>/host_vars/<host>.yml and add variables,
 * connection variables included, to every job with a static inventory.
 *
 * Runs real ansible-playbook through RunnerController::executeJob, as the
 * runner does, with such files planted in the runner's temp directory (the
 * tempDirectory() seam). The job now gets its own directory, and the
 * planted variables do not reach it.
 */
class RunnerControllerJobDirectoryAnsibleTest extends TestCase
{
    private const GROUP_MARKER = 'planted-group-value-4242';
    private const HOST_MARKER = 'planted-host-value-4243';

    private const PLAYBOOK = <<<'YAML'
        ---
        - name: Show what the inventory brought
          hosts: all
          gather_facts: false
          tasks:
            - name: Show the planted variables
              ansible.builtin.debug:
                msg: "group={{ planted_group_var | default('undefined') }};host={{ planted_host_var | default('undefined') }};inventory_dir={{ inventory_dir }};"
        YAML;

    private string $dir;

    /** The runner's temp directory: shared and sticky, like /tmp. */
    private string $temp;

    private TemporaryEnvironment $environment;

    /** @var list<array{path: string, body: array<string, mixed>}> */
    private array $posts = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->hasBinary('ansible-playbook')) {
            $this->markTestSkipped('ansible-playbook is not installed.');
        }
        $this->dir = sys_get_temp_dir() . '/runner_job_directory_ansible_' . uniqid('', true);
        $this->temp = $this->dir . '/tmp';
        mkdir($this->dir . '/project', 0o755, true);
        mkdir($this->dir . '/home', 0o700, true);
        mkdir($this->temp);
        chmod($this->temp, 0o1777);
        file_put_contents($this->dir . '/project/site.yml', self::PLAYBOOK . "\n");

        // What another local user puts into the shared temp directory.
        $this->plant('group_vars/all.yml', 'planted_group_var: ' . self::GROUP_MARKER . "\n");
        $this->plant('host_vars/localhost.yml', 'planted_host_var: ' . self::HOST_MARKER . "\n");

        // Keep Ansible's own files inside the test directory: the default
        // HOME is the runtime directory the dev runners share.
        $this->environment = new TemporaryEnvironment([
            'ANSIBLE_HOME' => $this->dir . '/home/.ansible',
            'ANSIBLE_LOCAL_TEMP' => $this->dir . '/home/tmp',
            'ANSIBLE_REMOTE_TEMP' => $this->dir . '/home/remote-tmp',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->environment)) {
            $this->environment->restore();
        }
        if (isset($this->dir)) {
            FileHelper::removeDirectory($this->dir);
        }
        parent::tearDown();
    }

    public function testVariablesPlantedInTheTempDirectoryDoNotReachAJobWithAStaticInventory(): void
    {
        $this->executeJob([
            'job_id' => 7,
            'scm_type' => 'manual',
            'project_path' => $this->dir . '/project',
            'inventory_type' => 'static',
            'inventory_content' => "localhost ansible_connection=local\n",
            'command' => ['ansible-playbook', '-i', '__INVENTORY_TMP__', 'site.yml'],
            'credentials' => [],
            'timeout_minutes' => 2,
        ]);
        $output = $this->jobLog();

        $this->assertSame(0, $this->completion()['exit_code'] ?? null, $output);
        $this->assertStringNotContainsString(self::GROUP_MARKER, $output, 'group_vars/ planted next to the job files reached the job');
        $this->assertStringNotContainsString(self::HOST_MARKER, $output, 'host_vars/ planted next to the job files reached the job');
        $this->assertStringContainsString('group=undefined;host=undefined;', $output);

        $jobDirectory = preg_quote($this->temp . '/ansilume_job_' . getmypid() . '_', '#') . '[0-9a-f]{32}';
        $this->assertSame(1, preg_match('#inventory_dir=(' . $jobDirectory . ');#', $output, $match), "the inventory is in the job's own directory: {$output}");
        $this->assertDirectoryDoesNotExist($match[1], 'the directory is removed after the job');
        $this->assertSame(['group_vars', 'host_vars'], array_values(array_diff(scandir($this->temp) ?: [], ['.', '..'])));
    }

    /**
     * Control: next to an inventory in the temp directory itself, Ansible
     * does pick the planted files up. Without this, the test above could
     * pass because the planted files are never read at all.
     */
    public function testAnInventoryNextToThePlantedFilesPicksTheirVariablesUp(): void
    {
        file_put_contents($this->temp . '/inventory.yml', "localhost ansible_connection=local\n");
        $posts = [];
        $http = $this->createMock(RunnerHttpClient::class);
        $http->method('post')->willReturnCallback(static function (string $path, array $body) use (&$posts): array {
            $posts[] = ['path' => $path, 'body' => $body];

            return ['ok' => true];
        });
        $executor = new RunnerProcessExecutor($http, new SilentController('runner', \Yii::$app));

        [$exitCode] = $executor->run(
            1,
            ['ansible-playbook', '-i', $this->temp . '/inventory.yml', 'site.yml'],
            ['project_path' => $this->dir . '/project'],
            PlaybookEnvironment::build(getenv() ?: [], $this->dir . '/tasks.ndjson'),
            2
        );
        $this->posts = $posts;
        $output = $this->jobLog();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('group=' . self::GROUP_MARKER . ';host=' . self::HOST_MARKER . ';inventory_dir=' . $this->temp . ';', $output);
    }

    // -- Helpers ------------------------------------------------------------------

    /**
     * Writes $content to $relative in the runner's temp directory, as
     * another user's file when the test runs as root.
     */
    private function plant(string $relative, string $content): void
    {
        $path = $this->temp . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755);
        }
        file_put_contents($path, $content);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            chown(dirname($path), 4711);
            chown($path, 4711);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function executeJob(array $payload): void
    {
        $http = $this->createMock(RunnerHttpClient::class);
        $http->method('post')->willReturnCallback(function (string $path, array $body): array {
            $this->posts[] = ['path' => $path, 'body' => $body];

            return ['ok' => true];
        });
        $controller = new class ('runner', \Yii::$app, $http, $this->temp) extends RunnerController {
            public function __construct($id, $module, RunnerHttpClient $http, private readonly string $temp)
            {
                parent::__construct($id, $module);
                $this->http = $http;
            }

            protected function tempDirectory(): string
            {
                return $this->temp;
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

        $method = new \ReflectionMethod(RunnerController::class, 'executeJob');
        $method->setAccessible(true);
        $method->invoke($controller, (int)$payload['job_id'], $payload);
    }

    /**
     * The job log without colours.
     */
    private function jobLog(): string
    {
        $output = '';
        foreach ($this->posts as $post) {
            if (str_ends_with($post['path'], '/logs')) {
                $output .= (string)($post['body']['content'] ?? '');
            }
        }

        return (string)preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]/', '', $output);
    }

    /**
     * @return array<string, mixed>
     */
    private function completion(): array
    {
        foreach ($this->posts as $post) {
            if (str_ends_with($post['path'], '/complete')) {
                return $post['body'];
            }
        }
        $this->fail('the job was not completed');
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
