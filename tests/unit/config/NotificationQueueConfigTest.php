<?php

declare(strict_types=1);

namespace app\tests\unit\config;

use app\components\AllowlistQueueSerializer;
use app\components\OutboundHttpClient;
use app\services\notification\MattermostApi;
use app\services\notification\MattermostChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use yii\queue\redis\Queue as RedisQueue;

/**
 * The notification queue, the component notificationQueue: the web and the
 * console push to it, and the notification worker (worker/start
 * --queue=notificationQueue) runs it. It is a Redis queue on a channel of
 * its own, so that a notification never waits behind a project sync of the
 * queue-worker, with the safeguards of the queue: Ansilume's allowlist
 * serializer, and the shared Redis settings with REDIS_PASSWORD
 * (RedisConfigTest). Its jobs are deliveries: a delivery makes at most
 * MattermostApi::MAX_REQUESTS requests of the outbound HTTP client (the
 * Mattermost channel's: a check of its token and channel after a change,
 * an edit, its retry, the lookup of the post and a new post), which the
 * channel enforces, so its TTR outlasts that many of the client's timeouts
 * and the channel's pauses. The console logs its jobs with
 * QueueLogBehavior (ErrorHandlingConfigTest).
 */
final class NotificationQueueConfigTest extends TestCase
{
    /** The channel of the notification queue: the web pushes to it, the notification worker listens on it. */
    private const CHANNEL = 'ansilume-notifications';

    /** The channel of the tests: no worker of the stack runs it. */
    private const TEST_CHANNEL = 'ansilume-test-notifications';

    /** The TTR of a notification: the time a delivery may take before Redis hands its message out again. */
    private const TTR = 120;


    /**
     * @return array<string, array{0: string}>
     */
    public static function configProvider(): array
    {
        return ['web' => ['web.php'], 'console' => ['console.php'], 'test' => ['test.php']];
    }

    #[DataProvider('configProvider')]
    public function testEveryApplicationHasTheNotificationQueue(string $file): void
    {
        $queue = $this->queue($file, 'notificationQueue');

        $this->assertSame(RedisQueue::class, ltrim((string)($queue['class'] ?? ''), '\\'));
        $this->assertSame(AllowlistQueueSerializer::class, $queue['serializer'] ?? null);
        $this->assertSame(self::TTR, $queue['ttr'] ?? null);
        $this->assertIsArray($queue['redis'] ?? null, 'a connection of its own, with the shared settings');
    }

    /**
     * The web pushes the notifications where the notification worker of the
     * console listens.
     */
    public function testTheWebPushesWhereTheNotificationWorkerListens(): void
    {
        $this->assertSame(self::CHANNEL, $this->queue('web.php', 'notificationQueue')['channel'] ?? null);
        $this->assertSame(self::CHANNEL, $this->queue('console.php', 'notificationQueue')['channel'] ?? null);
    }

    /**
     * A notification never waits behind a project sync: the two queues have
     * channels of their own in every application.
     */
    #[DataProvider('configProvider')]
    public function testNotificationsHaveAChannelOfTheirOwn(string $file): void
    {
        $notifications = $this->queue($file, 'notificationQueue')['channel'] ?? null;
        $syncs = $this->queue($file, 'queue')['channel'] ?? null;

        $this->assertIsString($notifications);
        $this->assertIsString($syncs);
        $this->assertNotSame($syncs, $notifications);
    }

    /**
     * The tests push to a channel that the notification worker of the dev
     * stack does not run: it would take their messages and look for the
     * deliveries they name in its own database.
     */
    public function testTheTestsPushToAChannelThatNoWorkerOfTheStackRuns(): void
    {
        $this->assertSame(self::TEST_CHANNEL, $this->queue('test.php', 'notificationQueue')['channel'] ?? null);
        $this->assertNotSame(self::CHANNEL, self::TEST_CHANNEL);
    }

    /**
     * Redis hands a message out again once its TTR has passed, also while
     * the worker still delivers it: the TTR outlasts the longest delivery,
     * MattermostApi::MAX_REQUESTS requests at the client's timeout (7 of 15
     * seconds), with a margin for the database and the pauses before a
     * refused edit and a create whose answer was lost are sent again, which
     * the margin holds.
     */
    public function testTheTtrOutlastsTheLongestDelivery(): void
    {
        $client = new OutboundHttpClient();
        $channel = new MattermostChannel();

        $this->assertGreaterThanOrEqual(7, MattermostApi::MAX_REQUESTS, 'the check after a change (3), an edit, its retry, the lookup of the post, a new post');
        $this->assertGreaterThan(MattermostApi::MAX_REQUESTS * $client->timeout + 10, self::TTR);
        $this->assertLessThan(10, ($channel->editRetryPauseMs + $channel->createRetryPauseMs) / 1000, 'the pauses fit in the margin');
        $this->assertSame(MattermostApi::CREATE_RETRY_PAUSE_MS, $channel->createRetryPauseMs);
    }

    /**
     * The configs name the longest delivery by its constant, which follows
     * the channel, never by a number.
     *
     * Regression: config/console.php said that a delivery makes "at most
     * three requests" while MattermostApi::MAX_REQUESTS was 6.
     */
    #[DataProvider('configProvider')]
    public function testRegressionTheConfigsNameTheLongestDeliveryByItsConstant(string $file): void
    {
        $code = (string)file_get_contents(dirname(__DIR__, 3) . '/config/' . $file);
        // The comment's lines as one text.
        $text = (string)preg_replace('~\s*\n\s*//\s*~', ' ', $code);

        preg_match_all('/at most (\S+) requests/', $text, $limits);
        // config/console.php says it where it sets the TTR; the others may.
        $expected = $file === 'console.php' ? 1 : count($limits[1]);
        $this->assertSame(
            array_fill(0, $expected, 'MattermostApi::MAX_REQUESTS'),
            $limits[1],
            "config/{$file} names the requests of a delivery by the constant"
        );
    }

    /**
     * The definition of the queue component $id in config/$file.
     *
     * @return array<string, mixed>
     */
    private function queue(string $file, string $id): array
    {
        $config = require dirname(__DIR__, 3) . '/config/' . $file;
        $this->assertIsArray($config);
        $queue = $config['components'][$id] ?? null;
        $this->assertIsArray($queue, "config/{$file} defines the component {$id}");

        /** @var array<string, mixed> $queue */
        return $queue;
    }
}
