<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\HealthController;
use app\helpers\FileHelper;
use app\models\NotificationTemplate;
use app\models\Runner;
use app\models\RunnerGroup;
use app\services\NotificationDispatcher;
use app\tests\integration\DbTestCase;
use app\tests\integration\services\RecordingChannel;
use app\tests\unit\RunsUnprivileged;
use yii\console\ExitCode;

/**
 * Integration tests for the console HealthController.
 *
 * Verifies that health/check reports accurate status for database, Redis,
 * migrations, RBAC roles, runtime directories, the log file, and user
 * accounts.
 */
class HealthControllerTest extends DbTestCase
{
    use RunsUnprivileged;

    /** A directory of the test's own, for a stand-in of the log file. */
    private string $directory = '';

    protected function tearDown(): void
    {
        if ($this->directory !== '') {
            FileHelper::removeDirectory($this->directory);
        }
        parent::tearDown();
    }

    /**
     * A controller whose output is captured; $logFile and $runtimeDirs
     * stand in for runtime/logs/app.log and the runtime directories.
     *
     * @param list<string>|null $runtimeDirs
     */
    private function makeController(?string $logFile = null, ?array $runtimeDirs = null): HealthController
    {
        return new class ('health', \Yii::$app, $logFile, $runtimeDirs) extends HealthController {
            /** @var string */
            public string $captured = '';

            /**
             * @param list<string>|null $standInDirs
             */
            public function __construct(
                string $id,
                \yii\base\Module $module,
                private ?string $standInLog,
                private ?array $standInDirs,
            ) {
                parent::__construct($id, $module);
            }

            public function stdout($string): int
            {
                $this->captured .= $string;
                return 0;
            }

            public function stderr($string): int
            {
                $this->captured .= $string;
                return 0;
            }

            protected function logFile(): string
            {
                return $this->standInLog ?? parent::logFile();
            }

            protected function runtimeDirs(): array
            {
                return $this->standInDirs ?? parent::runtimeDirs();
            }
        };
    }

    /**
     * A stand-in for runtime/logs/app.log with $mode, in a directory of the
     * test's own that others may enter, as the user unprivileged() runs as.
     * Created where the tests run as root, it is root's.
     */
    private function standInLogFile(?int $mode): string
    {
        $this->directory = sys_get_temp_dir() . '/health-log-file-' . uniqid('', true);
        mkdir($this->directory, 0o755);
        $file = $this->directory . '/app.log';
        if ($mode !== null) {
            file_put_contents($file, "2026-10-26 08:00:00 [-][-][-][info][yii\\db\\Connection::open] Opening DB connection\n");
            chmod($file, $mode);
        }

        return $file;
    }

    public function testCheckReturnsOkWhenHealthy(): void
    {
        $ctrl = $this->makeController();
        $result = $ctrl->actionCheck();

        $this->assertSame(ExitCode::OK, $result);
        $this->assertStringContainsString('[health] db: ok', $ctrl->captured);
        $this->assertStringContainsString('[health] redis: ok', $ctrl->captured);
        $this->assertStringContainsString('[health] status: ok', $ctrl->captured);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function redisAuthRejectionProvider(): array
    {
        return [
            'wrong password' => ["-WRONGPASS invalid username-password pair or user is disabled.\r\n", 'WRONGPASS'],
            'server without a password' => [
                "-ERR AUTH <password> called without any password configured for the default user."
                    . " Are you sure your configuration is correct?\r\n",
                'called without any password configured',
            ],
        ];
    }

    /**
     * A rejected REDIS_PASSWORD must show up as a failing Redis check that
     * names the real cause, and nothing the check prints or logs may contain
     * the password.
     *
     * Regression: the cache's cluster auto-detection swallowed the failed
     * AUTH, so the check reported a misleading "NOAUTH Authentication
     * required" from the next command instead.
     *
     * @dataProvider redisAuthRejectionProvider
     */
    public function testRedisAuthFailureIsReportedWithoutThePassword(string $authReply, string $expectedCause): void
    {
        // Like a real server: AUTH is rejected, every later command gets NOAUTH.
        $server = \app\tests\FakeRedisServer::start("-NOAUTH Authentication required.\r\n", true, $authReply);
        $previousCache = \Yii::$app->get('cache');
        $previousLogger = \Yii::getLogger();
        $logger = new \yii\log\Logger();
        \Yii::setLogger($logger);
        // The production cache definition, pointed at the fake server.
        $cache = (require dirname(__DIR__, 3) . '/config/console.php')['components']['cache'];
        $cache['redis'] = \app\components\RedisSettings::fromEnvironment([
            'REDIS_HOST' => '127.0.0.1',
            'REDIS_PORT' => (string)$server->port,
            'REDIS_PASSWORD' => 'canary-redis-4711',
        ])->connectionConfig();
        \Yii::$app->set('cache', $cache);
        $ctrl = $this->makeController();
        try {
            $result = $ctrl->actionCheck();
        } finally {
            \Yii::$app->set('cache', $previousCache);
            \Yii::setLogger($previousLogger);
            $server->stop();
        }
        $logged = array_map(
            static fn (array $m): string => is_string($m[0]) ? $m[0] : \yii\helpers\VarDumper::dumpAsString($m[0]),
            $logger->messages
        );

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $result);
        $this->assertMatchesRegularExpression('/\[health\] redis: error .*' . preg_quote($expectedCause, '/') . '/', $ctrl->captured);
        $this->assertStringContainsString(\app\components\RedactingRedisConnection::REDACTED_NOTE, $ctrl->captured);
        $this->assertStringNotContainsString('NOAUTH', $ctrl->captured);
        $this->assertStringNotContainsString('canary-redis-4711', $ctrl->captured . implode("\n", $logged));
    }

    public function testCheckReportsRbacRoles(): void
    {
        $ctrl = $this->makeController();
        $ctrl->actionCheck();

        $this->assertStringContainsString('[health] rbac: ok', $ctrl->captured);
    }

    public function testCheckReportsRuntimeDirs(): void
    {
        $ctrl = $this->makeController();
        $ctrl->actionCheck();

        $this->assertStringContainsString('[health] dirs: ok', $ctrl->captured);
    }

    /**
     * Regression: run as www-data, as the health checks of the app and the
     * queue-worker run it, the check said "dirs: ok" while root owned
     * runtime/logs/app.log, which www-data cannot append to: Yii drops every
     * line of a process that cannot append to its log file, so every error
     * of the web UI and the API was lost, and the container stayed healthy.
     * A root process leaves the file so when it creates it (a root health
     * check of a compose file of 2.13.1 or older, a console command run as
     * root), until the next start of the container hands it back to
     * www-data. The check now names the file and the fix, and fails.
     */
    public function testRegressionALogFileTheUserCannotWriteMakesTheCheckFail(): void
    {
        $logFile = $this->standInLogFile(0o444);
        $ctrl = $this->makeController($logFile);

        $result = self::unprivileged(static fn (): int => $ctrl->actionCheck());

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $result, $ctrl->captured);
        $this->assertStringContainsString(
            sprintf(HealthController::DIRS_NOT_WRITABLE, sprintf(HealthController::LOG_FILE_NOT_WRITABLE, $logFile, $this->directory)),
            $ctrl->captured
        );
        $this->assertStringContainsString(
            "[health] dirs: not writable — {$logFile} (lines logged to it are lost; the next start of the container"
            . " hands it back to www-data, or run in the container: chown -R www-data:www-data {$this->directory})\n",
            $ctrl->captured,
            'the file and the fix'
        );
        $this->assertStringNotContainsString(HealthController::DIRS_OK, $ctrl->captured);
        $this->assertStringContainsString("[health] status: degraded\n", $ctrl->captured);
    }

    public function testALogFileTheUserCanWriteKeepsTheCheckHealthy(): void
    {
        $logFile = $this->standInLogFile(0o666);
        $ctrl = $this->makeController($logFile);

        $result = self::unprivileged(static fn (): int => $ctrl->actionCheck());

        $this->assertSame(ExitCode::OK, $result, $ctrl->captured);
        $this->assertStringContainsString(HealthController::DIRS_OK, $ctrl->captured);
        $this->assertStringNotContainsString('not writable', $ctrl->captured);
        $this->assertSame(
            "2026-10-26 08:00:00 [-][-][-][info][yii\\db\\Connection::open] Opening DB connection\n",
            file_get_contents($logFile),
            'the check writes nothing to it'
        );
    }

    /**
     * Before the first line is logged there is no log file: www-data
     * creates it in runtime/logs, which the check finds writable. The check
     * creates none either: as root it would create it as root's.
     */
    public function testAMissingLogFileKeepsTheCheckHealthyAndIsNotCreated(): void
    {
        $logFile = $this->standInLogFile(null);
        $ctrl = $this->makeController($logFile);

        $result = self::unprivileged(static fn (): int => $ctrl->actionCheck());

        $this->assertSame(ExitCode::OK, $result, $ctrl->captured);
        $this->assertStringContainsString(HealthController::DIRS_OK, $ctrl->captured);
        $this->assertFileDoesNotExist($logFile);
    }

    /**
     * A directory where the log file belongs cannot take a log line either
     * (SplFileObject refuses a directory with a LogicException): it is named
     * as the file that cannot be written, and the check does not stop.
     */
    public function testALogFileThatIsADirectoryIsNamedAsNotWritable(): void
    {
        $logFile = $this->standInLogFile(null);
        mkdir($logFile);
        $ctrl = $this->makeController($logFile);

        $result = $ctrl->actionCheck();

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $result, $ctrl->captured);
        $this->assertStringContainsString(
            sprintf(HealthController::DIRS_NOT_WRITABLE, sprintf(HealthController::LOG_FILE_NOT_WRITABLE, $logFile, $this->directory)),
            $ctrl->captured
        );
        $this->assertStringContainsString("[health] users: ", $ctrl->captured, 'the checks after it ran');
    }

    /**
     * A runtime directory that cannot be written (here one that does not
     * exist) and the log file are named in the one line.
     */
    public function testEveryPathTheUserCannotWriteIsNamedInTheDirsLine(): void
    {
        $logFile = $this->standInLogFile(0o444);
        $missing = $this->directory . '/projects';
        $ctrl = $this->makeController($logFile, [$this->directory, $missing]);

        $result = self::unprivileged(static fn (): int => $ctrl->actionCheck());

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $result, $ctrl->captured);
        $this->assertStringContainsString(
            sprintf(
                HealthController::DIRS_NOT_WRITABLE,
                $missing . ', ' . sprintf(HealthController::LOG_FILE_NOT_WRITABLE, $logFile, $this->directory)
            ),
            $ctrl->captured
        );
    }

    /**
     * The check reads the files the images write: the runtime directories
     * of the entrypoints and the log file of config/web.php and
     * config/console.php (@runtime/logs/app.log).
     */
    public function testTheCheckReadsTheRuntimeDirectoriesAndTheLogFileOfTheImages(): void
    {
        $ctrl = new class ('health', \Yii::$app) extends HealthController {
            /**
             * @return array{dirs: list<string>, log: string}
             */
            public function paths(): array
            {
                return ['dirs' => $this->runtimeDirs(), 'log' => $this->logFile()];
            }
        };

        $this->assertSame(
            [
                'dirs' => ['/var/www/runtime', '/var/www/runtime/projects', '/var/www/runtime/artifacts', '/var/www/runtime/logs', '/var/www/web/assets'],
                'log' => '/var/www/runtime/logs/app.log',
            ],
            $ctrl->paths()
        );
        foreach (['web', 'console'] as $name) {
            $config = require dirname(__DIR__, 3) . "/config/{$name}.php";
            $logFiles = array_map(
                static fn (array $target): string => \Yii::createObject($target)->logFile,
                $config['components']['log']['targets']
            );
            $this->assertContains('/var/www/runtime/logs/app.log', $logFiles, "config/{$name}.php");
        }
    }

    public function testCheckReportsMigrations(): void
    {
        $ctrl = $this->makeController();
        $ctrl->actionCheck();

        $this->assertStringContainsString('[health] migrations: ok', $ctrl->captured);
    }

    public function testCheckReportsUserCount(): void
    {
        $ctrl = $this->makeController();
        $ctrl->actionCheck();

        // Test DB may or may not have users — just check the line exists
        $this->assertMatchesRegularExpression('/\[health\] users: /', $ctrl->captured);
    }

    // -------------------------------------------------------------------------
    // actionCheckRunners — offline / recovered transition notifications
    // -------------------------------------------------------------------------

    /**
     * Wire a NotificationDispatcher with a RecordingChannel for CHANNEL_EMAIL
     * and register it as the app's `notificationDispatcher` service so
     * actionCheckRunners() picks it up via Yii::$app->get().
     */
    private function installRecordingDispatcher(): RecordingChannel
    {
        $channel = new RecordingChannel();
        $dispatcher = new NotificationDispatcher();
        $dispatcher->setChannel(NotificationTemplate::CHANNEL_EMAIL, $channel);
        \Yii::$app->set('notificationDispatcher', $dispatcher);
        return $channel;
    }

    private function markRunnerOffline(Runner $runner): void
    {
        $runner->last_seen_at = time() - (RunnerGroup::STALE_AFTER + 60);
        $runner->save(false);
    }

    private function markRunnerOnline(Runner $runner): void
    {
        $runner->last_seen_at = time();
        $runner->save(false);
    }

    public function testCheckRunnersFiresOfflineNotificationOnce(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $runner = $this->createRunner($group->id, $user->id);
        $this->markRunnerOffline($runner);

        $template = $this->createNotificationTemplate($user->id);
        $template->events = NotificationTemplate::EVENT_RUNNER_OFFLINE;
        $template->save(false);

        $channel = $this->installRecordingDispatcher();
        $ctrl = $this->makeController();

        $result = $ctrl->actionCheckRunners();

        $this->assertSame(ExitCode::OK, $result);
        $this->assertCount(1, $channel->calls);
        $this->assertSame($template->id, $channel->calls[0]['template']->id);
        $this->assertStringContainsString('OFFLINE', $ctrl->captured);

        $runner->refresh();
        $this->assertNotNull($runner->offline_notified_at);
    }

    public function testCheckRunnersDoesNotRefireOfflineNotificationOnSubsequentRuns(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $runner = $this->createRunner($group->id, $user->id);
        $this->markRunnerOffline($runner);

        $template = $this->createNotificationTemplate($user->id);
        $template->events = NotificationTemplate::EVENT_RUNNER_OFFLINE;
        $template->save(false);

        $channel = $this->installRecordingDispatcher();

        $this->makeController()->actionCheckRunners();
        $this->makeController()->actionCheckRunners();
        $this->makeController()->actionCheckRunners();

        // Idempotency: only the first sweep should have fired.
        $this->assertCount(1, $channel->calls);
    }

    public function testCheckRunnersFiresRecoveredNotificationWhenRunnerComesBack(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $runner = $this->createRunner($group->id, $user->id);
        $this->markRunnerOffline($runner);

        $offlineTpl = $this->createNotificationTemplate($user->id);
        $offlineTpl->events = NotificationTemplate::EVENT_RUNNER_OFFLINE;
        $offlineTpl->save(false);

        $recoveredTpl = $this->createNotificationTemplate($user->id);
        $recoveredTpl->events = NotificationTemplate::EVENT_RUNNER_RECOVERED;
        $recoveredTpl->save(false);

        $channel = $this->installRecordingDispatcher();

        // First sweep: detect offline.
        $this->makeController()->actionCheckRunners();
        $this->assertCount(1, $channel->calls);
        $this->assertSame($offlineTpl->id, $channel->calls[0]['template']->id);

        // Runner comes back; second sweep fires recovered.
        $this->markRunnerOnline($runner);
        $ctrl = $this->makeController();
        $ctrl->actionCheckRunners();

        $this->assertCount(2, $channel->calls);
        $this->assertSame($recoveredTpl->id, $channel->calls[1]['template']->id);
        $this->assertStringContainsString('RECOVERED', $ctrl->captured);

        $runner->refresh();
        $this->assertNull($runner->offline_notified_at);
    }

    public function testCheckRunnersIsSilentForHealthyRunner(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $runner = $this->createRunner($group->id, $user->id);
        $this->markRunnerOnline($runner);

        $offlineTpl = $this->createNotificationTemplate($user->id);
        $offlineTpl->events = NotificationTemplate::EVENT_RUNNER_OFFLINE;
        $offlineTpl->save(false);

        $channel = $this->installRecordingDispatcher();
        $ctrl = $this->makeController();
        $ctrl->actionCheckRunners();

        $this->assertCount(0, $channel->calls);
        $this->assertSame('', $ctrl->captured);
    }

    public function testCheckRunnersOfflineNotificationCarriesRunnerContext(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $runner = $this->createRunner($group->id, $user->id);
        $this->markRunnerOffline($runner);

        $template = $this->createNotificationTemplate($user->id);
        $template->events = NotificationTemplate::EVENT_RUNNER_OFFLINE;
        $template->save(false);

        $channel = $this->installRecordingDispatcher();
        $this->makeController()->actionCheckRunners();

        $this->assertCount(1, $channel->calls);
        $vars = $channel->calls[0]['variables'];
        $this->assertSame((string)$runner->id, $vars['runner.id']);
        $this->assertSame($runner->name, $vars['runner.name']);
    }
}
