<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\HealthController;
use app\models\NotificationTemplate;
use app\models\Runner;
use app\models\RunnerGroup;
use app\services\NotificationDispatcher;
use app\tests\integration\DbTestCase;
use app\tests\integration\services\RecordingChannel;
use yii\console\ExitCode;

/**
 * Integration tests for the console HealthController.
 *
 * Verifies that health/check reports accurate status for database, Redis,
 * migrations, RBAC roles, runtime directories, and user accounts.
 */
class HealthControllerTest extends DbTestCase
{
    private function makeController(): HealthController
    {
        return new class ('health', \Yii::$app) extends HealthController {
            /** @var string */
            public string $captured = '';

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
        };
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
