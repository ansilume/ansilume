<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\helpers\FileHelper;
use app\services\notification\ChannelDefinition;
use app\services\notification\ChannelRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use yii\base\InvalidConfigException;

/**
 * The channels of the installation's own are checked when Ansilume starts,
 * in every process (spec section 6; docs/notifications.md, "Channels of your
 * own"): the web and the console application build the dispatcher at their
 * start (their bootstrap), which reads config/notification-channels.php and
 * refuses a definition it cannot take. A child PHP process starts each
 * application as the yii script and web/index.php do (production mode, PHP's
 * errors only logged, as the app image's php.ini has it), from a copy of
 * config/ beside which the case puts its config/notification-channels.php:
 * - a console command, the health check as the compose files run it, ends
 *   with exit code 1 before it runs, and prints "Error: " and what is wrong
 *   to STDERR, which the container's log shows: the entrypoint's migrations,
 *   the workers and the schedule-runner do not come up, and the health check
 *   fails;
 * - the web application cannot be built, with the same error;
 * - a good definition starts both, with its channel after the built-in ones.
 *
 * Regression: the definitions were checked only when something first used
 * the dispatcher. The app, the workers, the migrations and health/check
 * started green while every event was dropped, the Notifications pages and
 * the API answered 500, and queued deliveries failed as if the worker had
 * stopped. A file that returned no array, or held a syntax error, stopped the
 * process while its config loaded, before Yii's error handler existed, so its
 * message reached PHP's error log only, not the container's log.
 */
final class ChannelRegistryAtStartTest extends TestCase
{
    /**
     * Arguments: the repository root, the copy of config/, the log file and
     * the mode: "health" runs php yii health/check, "console" and "web"
     * build that application and print as JSON the error that stopped its
     * start ("stopped"), or whether it built the dispatcher when it started
     * ("built") and the channels it has, or the error of their first use
     * ("failed"). The web request's script lies in the test's directory, so
     * that nothing the web application might publish reaches web/ of the
     * checkout, and no error page is rendered: the probe catches every error.
     */
    private const PROBE = <<<'PHP'
        <?php

        declare(strict_types=1);

        [, $root, $configDir, $logFile, $mode] = $argv;
        define('YII_DEBUG', false);
        define('YII_ENV', 'prod');
        require $root . '/vendor/autoload.php';
        \Dotenv\Dotenv::createUnsafeImmutable($root)->safeLoad();
        require $root . '/vendor/yiisoft/yii2/Yii.php';

        if ($mode === 'web') {
            // A request as nginx hands it to php-fpm.
            $_SERVER['SCRIPT_FILENAME'] = dirname($configDir) . '/web/index.php';
            $_SERVER['SCRIPT_NAME'] = '/index.php';
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/health';
            $_SERVER['HTTP_HOST'] = 'nginx';
        }
        $config = require $configDir . ($mode === 'web' ? '/web.php' : '/console.php');
        $config['basePath'] = $root;
        $config['components']['log']['targets'][0]['logFile'] = $logFile;
        if ($mode === 'health') {
            // As the health check of the compose files runs it: php yii health/check.
            $_SERVER['argv'] = ['yii', 'health/check'];
            $exitCode = (new yii\console\Application($config))->run();
            exit($exitCode);
        }
        try {
            $application = $mode === 'web' ? new yii\web\Application($config) : new yii\console\Application($config);
        } catch (\Throwable $e) {
            echo json_encode(['stopped' => get_class($e) . ': ' . $e->getMessage()]);
            exit(0);
        }
        $built = $application->has('notificationDispatcher', true);
        try {
            $channels = array_keys($application->get('notificationDispatcher')->definitions());
        } catch (\Throwable $e) {
            echo json_encode(['built' => $built, 'failed' => get_class($e) . ': ' . $e->getMessage()]);
            exit(0);
        }
        echo json_encode(['built' => $built, 'channels' => $channels]);

        PHP;

    /** A channel of one's own, defined right. */
    private const GOOD = [
        'acme' => [
            'label' => 'Acme Pager',
            'class' => ConfiguredTestChannel::class,
            'requiredKeys' => ['endpoint'],
            'urlSchemes' => ['endpoint' => ['https']],
        ],
    ];

    /** A definition file that PHP cannot parse: a comma is missing. */
    private const SYNTAX_ERROR = "<?php\n\nreturn ['acme' => ['label' => 'Acme Pager' 'class' => 'app\\custom\\AcmePagerChannel']];\n";

    private string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/ansilume-channels-at-start-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/config', 0o700, true);
        $this->directory = (string)realpath($this->directory);
        foreach (glob(self::root() . '/config/*.php') ?: [] as $file) {
            // The installation's own file of this checkout, if any, stays out.
            if (basename($file) !== basename(ChannelRegistry::CONFIG_FILE)) {
                copy($file, $this->directory . '/config/' . basename($file));
            }
        }
        file_put_contents($this->directory . '/probe.php', self::PROBE);
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->directory);
        parent::tearDown();
    }

    /**
     * Each kind of definition file that is wrong, the class of its error and
     * its message; FILE_NOT_AN_ARRAY's %s is the file, and the syntax
     * error's message is the parser's.
     *
     * @return array<string, array{0: string, 1: class-string<\Throwable>, 2: string}>
     */
    public static function wrongFiles(): array
    {
        $class = ConfiguredTestChannel::class;

        return [
            'regression: a mistyped class' => [
                self::returning(['acme' => ['label' => 'Acme Pager', 'class' => 'app\custom\AcmePagerChanel']]),
                InvalidConfigException::class,
                sprintf(ChannelDefinition::UNKNOWN_CLASS, 'acme', 'app\custom\AcmePagerChanel'),
            ],
            'regression: an unknown setting' => [
                self::returning(['acme' => ['label' => 'Acme Pager', 'class' => $class, 'secretKey' => ['api_key']]]),
                InvalidConfigException::class,
                sprintf(ChannelDefinition::UNKNOWN_SETTING, 'acme', 'secretKey'),
            ],
            'regression: a missing label' => [
                self::returning(['acme' => ['class' => $class]]),
                InvalidConfigException::class,
                sprintf(ChannelDefinition::LABEL_MISSING, 'acme'),
            ],
            'regression: a built-in name' => [
                self::returning(['webhook' => ['label' => 'Acme Pager', 'class' => $class]]),
                InvalidConfigException::class,
                sprintf(ChannelRegistry::NAME_TAKEN, 'webhook'),
            ],
            'regression: a file that returns no array' => [
                "<?php\n\nreturn 'acme';\n",
                InvalidConfigException::class,
                ChannelRegistry::FILE_NOT_AN_ARRAY,
            ],
            'regression: a syntax error' => [self::SYNTAX_ERROR, \ParseError::class, ''],
        ];
    }

    /**
     * A console command does not run: the process ends with exit code 1,
     * "Error: " and the message on STDERR, the error in the application log
     * and nothing in PHP's error log alone, as Yii's error handler ends it.
     * The health check is that command here.
     *
     * @param class-string<\Throwable> $class
     */
    #[DataProvider('wrongFiles')]
    public function testAWrongFileStopsEveryConsoleCommandAndTheHealthCheckFails(string $code, string $class, string $message): void
    {
        $expected = $this->messageOf($this->channels($code), $message);

        $run = $this->start('health');

        $this->assertSame(1, $run['exit'], $run['stdout'] . $run['stderr']);
        $this->assertSame('Error: ' . $expected . "\n", $run['stderr']);
        $this->assertSame('', $run['stdout'], 'the health check did not run');
        $this->assertStringContainsString('[error][' . $class . '] ', $run['log']);
        $this->assertStringContainsString($expected, $run['log']);
        $this->assertSame('', $run['phpErrors'], 'Yii\'s error handler ended the process, not a fatal error that only PHP\'s error log shows');
    }

    /**
     * The web application cannot be built: its start throws the error, which
     * Yii's error handler answers with a 500 and logs, not the first page
     * that uses the channels.
     *
     * @param class-string<\Throwable> $class
     */
    #[DataProvider('wrongFiles')]
    public function testAWrongFileStopsTheWebApplication(string $code, string $class, string $message): void
    {
        $expected = $this->messageOf($this->channels($code), $message);

        $run = $this->start('web');

        $this->assertSame(0, $run['exit'], $run['stdout'] . $run['stderr']);
        $this->assertSame(['stopped' => $class . ': ' . $expected], json_decode($run['stdout'], true), $run['stderr']);
    }

    /**
     * A good definition: both applications start, with the dispatcher built
     * at their start and the channel after the built-in ones.
     */
    public function testAGoodDefinitionStartsBothApplicationsWithItsChannel(): void
    {
        $this->channels(self::returning(self::GOOD));

        foreach (['console', 'web'] as $mode) {
            $run = $this->start($mode);
            $this->assertSame(0, $run['exit'], $run['stderr']);
            $this->assertSame(
                ['built' => true, 'channels' => [...array_keys(ChannelRegistry::BUILT_IN), 'acme']],
                json_decode($run['stdout'], true),
                $mode . ': ' . $run['stdout'] . $run['stderr']
            );
        }
    }

    /**
     * Without the file both applications start with the built-in channels.
     */
    public function testWithoutTheFileBothApplicationsStartWithTheBuiltInChannels(): void
    {
        foreach (['console', 'web'] as $mode) {
            $run = $this->start($mode);
            $this->assertSame(0, $run['exit'], $run['stderr']);
            $this->assertSame(
                ['built' => true, 'channels' => array_keys(ChannelRegistry::BUILT_IN)],
                json_decode($run['stdout'], true),
                $mode . ': ' . $run['stdout'] . $run['stderr']
            );
        }
    }

    /**
     * A definition file that returns $channels.
     *
     * @param array<string, mixed> $channels
     */
    private static function returning(array $channels): string
    {
        return "<?php\n\nreturn " . var_export($channels, true) . ";\n";
    }

    /**
     * Puts $code into the copy of config/ as config/notification-channels.php.
     *
     * @return string the file
     */
    private function channels(string $code): string
    {
        $file = $this->directory . '/config/' . basename(ChannelRegistry::CONFIG_FILE);
        file_put_contents($file, $code);

        return $file;
    }

    /**
     * The message of the case: $message with the file for its %s, or for no
     * message the parser's error of the file's code.
     */
    private function messageOf(string $file, string $message): string
    {
        if ($message !== '') {
            return str_contains($message, '%s') ? sprintf($message, $file) : $message;
        }
        try {
            $tokens = token_get_all((string)file_get_contents($file), TOKEN_PARSE);
        } catch (\ParseError $e) {
            return $e->getMessage();
        }
        $this->fail(sprintf('The code of the case parses, into %d tokens.', count($tokens)));
    }

    /**
     * Runs the probe in $mode, as a production container runs PHP: its
     * errors are logged, here to a file of the test, and not displayed.
     *
     * @return array{exit: int, stdout: string, stderr: string, log: string, phpErrors: string}
     */
    private function start(string $mode): array
    {
        $root = self::root();
        $log = $this->directory . '/app-' . $mode . '.log';
        $phpErrors = $this->directory . '/php-errors-' . $mode . '.log';
        $process = proc_open(
            [
                PHP_BINARY,
                '-d', 'display_errors=0',
                '-d', 'log_errors=1',
                '-d', 'error_log=' . $phpErrors,
                $this->directory . '/probe.php',
                $root,
                $this->directory . '/config',
                $log,
                $mode,
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            getenv()
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [
            'exit' => $exit,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'log' => is_file($log) ? (string)file_get_contents($log) : '',
            'phpErrors' => is_file($phpErrors) ? (string)file_get_contents($phpErrors) : '',
        ];
    }

    private static function root(): string
    {
        return dirname(__DIR__, 4);
    }
}
