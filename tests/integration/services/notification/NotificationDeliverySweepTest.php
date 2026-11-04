<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\models\AuditLog;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\services\notification\NotificationDeliveryService;
use app\services\notification\NotificationQueueDepth;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\db\BaseActiveRecord;
use yii\log\Logger;

/**
 * The sweep of notification deliveries (NotificationDeliveryService::sweep()),
 * which the maintenance run starts every minute and which makes delivery
 * durable without Redis:
 * - a pending delivery that is overdue by more than REPUSH_AFTER_SECONDS
 *   lost its message (Redis started empty, or an old worker of an update
 *   dropped it) while the notification queue holds no message for a worker
 *   (NotificationQueueDepth): it is pushed again, once per
 *   REPUSH_AFTER_SECONDS; while the queue holds one, the worker is behind
 *   or does not run, and nothing is pushed again;
 * - a delivery a worker claimed more than STALE_SENDING_SECONDS ago and did
 *   not finish lost its worker: it is pending again, the lost attempt
 *   counted, and pushed; out of attempts it fails;
 * - a delivery that finished more than RETENTION_SECONDS (30 days) ago is
 *   deleted.
 * Each step works in batches; a batch is started while the deadline it is
 * given has not passed, the first one of each step always.
 */
class NotificationDeliverySweepTest extends DbTestCase
{
    use RestoresComponents;
    use TeamScopeFixtures;
    use DeliveryFixtures;

    /** The notification queue on Redis of the test that uses one; its keys are removed after it. */
    private ?RedisNotificationQueue $redisQueue = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTestDoubles();
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        $this->redisQueue?->clear();
        parent::tearDown();
    }

    /**
     * Regression (review: the queue grew without bound): while no
     * notification-worker consumed the notification queue (stopped,
     * restarting, or missing from an older compose file), the sweep pushed
     * every overdue delivery again every REPUSH_AFTER_SECONDS, whether its
     * message still waited or not: 1,000 deliveries pending for a day came
     * to 720,000 messages in the Redis of the sessions and the cache, which
     * the worker took one by one before any new delivery (FIFO). The sweep
     * now pushes again only while the queue holds no message for a worker:
     * three hours of maintenance ticks, with nothing that consumes, give
     * each delivery whose message Redis lost one message, and no more.
     */
    public function testRegressionALongOutagePushesAtMostOneMoreMessagePerDelivery(): void
    {
        $start = time();
        $queue = $this->useRedisQueue();
        $template = $this->template();
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = (int)$this->delivery(['next_attempt_at' => $start - 300], $template)->id;
        }

        $repushed = 0;
        for ($minute = 0; $minute < 180; $minute++) {
            $repushed += $this->service()->sweep($start + 60 * $minute)['repushed'];
        }

        $this->assertSame(array_fill_keys($ids, 1), $queue->pushesPerDelivery(), 'one message each, for three hours without a worker');
        $this->assertSame(3, $repushed);
        $this->assertSame(3, NotificationQueueDepth::at($start + 60 * 180), 'three messages wait for the worker');
        foreach ($ids as $id) {
            $this->assertSame(NotificationDelivery::STATUS_PENDING, NotificationDelivery::findOne($id)?->status, 'the deliveries wait in the database');
        }
    }

    /**
     * Regression (review: the queue grew without bound): deliveries whose
     * messages wait for a worker that does not run get no second message,
     * however long they wait; once the queue is empty again (Redis started
     * empty, or an old worker dropped the messages), the overdue ones are
     * pushed again, once each, and no more while those messages wait.
     */
    public function testRegressionAQueueThatIsEmptyAgainRepushesTheOverdueOnesOnce(): void
    {
        $start = time();
        $queue = $this->useRedisQueue();
        $template = $this->template();
        $ids = [];
        for ($i = 0; $i < 2; $i++) {
            $ids[] = $id = (int)$this->delivery(['next_attempt_at' => $start], $template)->id;
            $this->assertTrue($this->service()->enqueuePush($id), 'the push of the event, which no worker takes');
        }

        for ($minute = 0; $minute < 60; $minute++) {
            $this->assertSame(0, $this->service()->sweep($start + 60 * $minute)['repushed']);
        }
        $this->assertSame(array_fill_keys($ids, 1), $queue->pushesPerDelivery(), 'an hour without a worker adds nothing');

        $queue->clear();
        $later = $start + 3600;
        $this->assertSame(2, $this->service()->sweep($later)['repushed'], 'an empty queue lost the messages');
        for ($minute = 1; $minute <= 30; $minute++) {
            $this->assertSame(0, $this->service()->sweep($later + 60 * $minute)['repushed']);
        }

        $this->assertSame(array_fill_keys($ids, 2), $queue->pushesPerDelivery());
        foreach ($ids as $id) {
            $this->assertSame($later, (int)NotificationDelivery::findOne($id)?->next_attempt_at, 'marked as pushed again when it was');
        }
    }

    /**
     * What holds the push of a lost delivery back is a message that a
     * worker is to take now or has taken: one delayed to a later time (a
     * retry that waits for its backoff) says nothing of the worker, so a
     * lost delivery is pushed again beside it; once that message's time has
     * come and no worker took it, it holds the push back. A message that a
     * worker reserved holds it back too.
     */
    public function testOnlyAMessageForAWorkerHoldsTheRepushBack(): void
    {
        $now = time();
        $queue = $this->useRedisQueue();
        $template = $this->template();
        $retrying = $this->delivery(['next_attempt_at' => $now + 3600, 'attempts' => 5], $template);
        $this->assertTrue($this->service()->enqueuePush((int)$retrying->id, 3600));
        $lost = $this->delivery(['next_attempt_at' => $now - 300], $template);

        $this->assertSame(1, $this->service()->sweep($now)['repushed'], 'a retry in an hour does not hold back a lost delivery');
        $this->assertSame([(int)$retrying->id, (int)$lost->id], $queue->pushedIds());

        $this->assertNotNull($queue->reserveNext(), 'a worker takes the lost one');
        NotificationDelivery::updateAll(['next_attempt_at' => $now - 300], ['id' => $lost->id]);
        $this->assertSame(0, $this->service()->sweep($now)['repushed'], 'a message a worker holds');

        $queue->clear();
        $this->assertTrue($this->service()->enqueuePush((int)$retrying->id, 60));
        $this->assertSame(0, $this->service()->sweep($now + 120)['repushed'], 'a message whose time has come and that no worker took');
        $this->assertSame(1, $this->service()->sweep($now)['repushed'], 'the same message before its time');
    }

    /**
     * Review focus 2 of the workflow notifications plan: Redis starts
     * empty, or a worker crashes mid-delivery. The sweep pushes the pending
     * deliveries whose message was lost and gives back the ones a worker
     * left in sending, and nothing is lost or sent twice: not the delivery
     * a live worker sends right now, not the one whose message is merely a
     * little late, not one that waits for its backoff, not a finished one,
     * and not when the pushes come twice.
     */
    public function testTheSweeperRepushesLostAndStaleDeliveries(): void
    {
        $now = time();
        $template = $this->template();
        $lost = $this->delivery(['next_attempt_at' => $now - 300], $template);
        $late = $this->delivery(['next_attempt_at' => $now - 30], $template);
        $backingOff = $this->delivery(['next_attempt_at' => $now + 600, 'attempts' => 2], $template);
        $stale = $this->delivery(['status' => NotificationDelivery::STATUS_SENDING, 'claimed_at' => $now - 400, 'next_attempt_at' => $now - 400], $template);
        $live = $this->delivery(['status' => NotificationDelivery::STATUS_SENDING, 'claimed_at' => $now - 30, 'next_attempt_at' => $now - 30], $template);
        $finished = $this->delivery(['status' => NotificationDelivery::STATUS_DELIVERED, 'attempts' => 1, 'finished_at' => $now - 600, 'next_attempt_at' => $now - 900], $template);

        $logs = $this->logsDuring(fn () => $this->assertSame(['repushed' => 1, 'reset' => 1, 'purged' => 0], $this->service()->sweep($now)));

        $this->assertSame([(int)$stale->id, (int)$lost->id], $this->queue->pushedIds(), 'the stale one is given back, the lost one pushed again');
        $this->assertSame([0, 0], array_column($this->queue->pushes, 'delay'));
        $this->assertSame([NotificationDelivery::STATUS_PENDING, $now], [$this->reload($lost)->status, (int)$this->reload($lost)->next_attempt_at], 'marked as pushed now');
        $reset = $this->reload($stale);
        $this->assertSame(NotificationDelivery::STATUS_PENDING, $reset->status);
        $this->assertNull($reset->claimed_at);
        $this->assertSame(1, (int)$reset->attempts, 'the lost attempt counts');
        $this->assertSame(NotificationDeliveryService::WORKER_STOPPED, $reset->last_error);
        $this->assertSame($now, (int)$reset->next_attempt_at);
        $this->assertSame(NotificationDelivery::STATUS_SENDING, $this->reload($live)->status, 'a live worker keeps its delivery');
        $this->assertSame($now - 30, (int)$this->reload($late)->next_attempt_at, 'a message a little late is not pushed twice');
        $this->assertSame($now + 600, (int)$this->reload($backingOff)->next_attempt_at);
        $this->assertSame(NotificationDelivery::STATUS_DELIVERED, $this->reload($finished)->status);
        $this->assertSame(
            [sprintf(NotificationDeliveryService::WORKER_STOPPED_LOGGED, (int)$stale->id, (int)$template->id, 400, 1, 8)],
            self::serviceLines($logs, Logger::LEVEL_WARNING)
        );

        $this->assertSame(['repushed' => 0, 'reset' => 0, 'purged' => 0], $this->service()->sweep($now + 60), 'a minute later: nothing again');

        foreach ([...$this->queue->pushedIds(), ...$this->queue->pushedIds()] as $id) {
            $this->service()->deliver($id);
        }
        $this->assertCount(2, $this->channel->calls, 'each delivery that was lost is sent once, also when its push comes twice');
        foreach ([$lost, $stale] as $delivery) {
            $this->assertSame(NotificationDelivery::STATUS_DELIVERED, $this->reload($delivery)->status);
            $this->assertCount(1, $this->auditOf($delivery)[AuditLog::ACTION_NOTIFICATION_DISPATCHED] ?? []);
        }
        $this->assertSame([], $this->auditOf($live));
    }

    /**
     * A delivery pushed again by one sweep is pushed again by a later one
     * only once REPUSH_AFTER_SECONDS have passed. With a queue whose depth
     * the sweep cannot read (another driver, a Redis it cannot read), every
     * overdue delivery is pushed again so, as before the depth was read: one
     * message per delivery and period, not one per minute.
     */
    public function testALostDeliveryIsPushedAgainOncePerPeriod(): void
    {
        $now = time();
        $lost = $this->delivery(['next_attempt_at' => $now - 300]);

        $this->service()->sweep($now);
        $this->service()->sweep($now + NotificationDeliveryService::REPUSH_AFTER_SECONDS - 1);
        $this->service()->sweep($now + NotificationDeliveryService::REPUSH_AFTER_SECONDS);

        $this->assertSame([(int)$lost->id, (int)$lost->id], $this->queue->pushedIds());
        $this->assertSame([120, 180], [NotificationDeliveryService::REPUSH_AFTER_SECONDS, NotificationDeliveryService::STALE_SENDING_SECONDS]);
    }

    /**
     * A delivery whose worker stopped on its last attempt fails: the worker
     * stopped every time, and trying it on would stop the next one too.
     * One notification.failed entry, nothing pushed.
     */
    public function testAStaleDeliveryOutOfAttemptsFails(): void
    {
        $now = time();
        $delivery = $this->delivery(['status' => NotificationDelivery::STATUS_SENDING, 'claimed_at' => $now - 200, 'attempts' => 7]);

        $logs = $this->logsDuring(fn () => $this->assertSame(['repushed' => 0, 'reset' => 1, 'purged' => 0], $this->service()->sweep($now)));

        $row = $this->reload($delivery);
        $this->assertSame(NotificationDelivery::STATUS_FAILED, $row->status);
        $this->assertSame(8, (int)$row->attempts);
        $this->assertSame(NotificationDeliveryService::WORKER_STOPPED, $row->last_error);
        $this->assertSame($now, (int)$row->finished_at);
        $this->assertSame([], $this->queue->pushes);
        $audit = $this->auditOf($delivery);
        $this->assertSame([AuditLog::ACTION_NOTIFICATION_FAILED], array_keys($audit));
        $this->assertSame([NotificationDeliveryService::WORKER_STOPPED, 8], [$audit[AuditLog::ACTION_NOTIFICATION_FAILED][0]['error'], $audit[AuditLog::ACTION_NOTIFICATION_FAILED][0]['attempts']]);
        $template = $this->templateOf($delivery);
        $this->assertSame(
            [sprintf(NotificationDeliveryService::FAILED, (int)$delivery->id, $template, 'email', 'webhook.invalid_token', 8, NotificationDeliveryService::WORKER_STOPPED)],
            self::serviceLines($logs, Logger::LEVEL_ERROR)
        );
    }

    /**
     * Finished deliveries are kept for 30 days after they finished, then
     * deleted, whatever their outcome; unfinished ones are never deleted.
     */
    public function testFinishedDeliveriesArePurgedAfter30Days(): void
    {
        $now = time();
        $template = $this->template();
        $old = [];
        foreach (NotificationDelivery::FINISHED_STATUSES as $status) {
            $old[] = (int)$this->delivery(['status' => $status, 'finished_at' => $now - 31 * 86400, 'next_attempt_at' => $now - 31 * 86400], $template)->id;
        }
        $recent = $this->delivery(['status' => NotificationDelivery::STATUS_FAILED, 'finished_at' => $now - 29 * 86400], $template);
        $waiting = $this->delivery(['next_attempt_at' => $now + 3600, 'created_at' => $now - 40 * 86400], $template);

        $this->assertSame(['repushed' => 0, 'reset' => 0, 'purged' => 4], $this->service()->sweep($now));

        $this->assertSame(0, (int)NotificationDelivery::find()->where(['id' => $old])->count());
        $this->assertSame(NotificationDelivery::STATUS_FAILED, $this->reload($recent)->status);
        $this->assertSame(NotificationDelivery::STATUS_PENDING, $this->reload($waiting)->status);
        $this->assertSame(30 * 86400, NotificationDeliveryService::RETENTION_SECONDS);
    }

    /**
     * Each step works through what it finds in batches of SWEEP_BATCH_SIZE,
     * and starts a further batch only while the deadline has not passed;
     * the first batch of each step always runs, so that a tick whose budget
     * the other tasks used up still makes progress.
     */
    public function testEachStepGoesOnInBatchesUntilTheDeadline(): void
    {
        $now = time();
        $template = $this->template();
        $rows = [];
        for ($i = 0; $i < NotificationDeliveryService::SWEEP_BATCH_SIZE + 1; $i++) {
            $rows[] = [(int)$template->id, 'webhook.invalid_token', 'message', '{}', 'pending', 0, $now - 1000, $now - 1000, $now - 1000];
            $rows[] = [(int)$template->id, 'webhook.invalid_token', 'message', '{}', 'delivered', 1, $now - 40 * 86400, $now - 40 * 86400, $now - 40 * 86400];
        }
        \Yii::$app->db->createCommand()->batchInsert(
            NotificationDelivery::tableName(),
            ['notification_template_id', 'event', 'kind', 'variables', 'status', 'attempts', 'next_attempt_at', 'created_at', 'updated_at'],
            $rows
        )->execute();
        NotificationDelivery::updateAll(['finished_at' => $now - 40 * 86400], ['notification_template_id' => $template->id, 'status' => 'delivered']);

        $this->assertSame(
            ['repushed' => NotificationDeliveryService::SWEEP_BATCH_SIZE, 'reset' => 0, 'purged' => NotificationDeliveryService::SWEEP_BATCH_SIZE],
            $this->service()->sweep($now, microtime(true) - 1),
            'past the deadline: the first batch of each step'
        );
        $this->assertSame(['repushed' => 1, 'reset' => 0, 'purged' => 1], $this->service()->sweep($now, microtime(true) + 60), 'the rest');
        $this->assertSame(100, NotificationDeliveryService::SWEEP_BATCH_SIZE);
    }

    /**
     * Stale deliveries go on in batches too: more than one batch is given
     * back without a deadline.
     */
    public function testStaleDeliveriesAreGivenBackInBatches(): void
    {
        $now = time();
        $template = $this->template();
        $rows = [];
        for ($i = 0; $i < NotificationDeliveryService::SWEEP_BATCH_SIZE + 2; $i++) {
            $rows[] = [(int)$template->id, 'webhook.invalid_token', 'message', '{}', 'sending', 0, $now - 1000, $now - 1000, $now - 1000, $now - 1000];
        }
        \Yii::$app->db->createCommand()->batchInsert(
            NotificationDelivery::tableName(),
            ['notification_template_id', 'event', 'kind', 'variables', 'status', 'attempts', 'claimed_at', 'next_attempt_at', 'created_at', 'updated_at'],
            $rows
        )->execute();

        $result = [];
        $this->logsDuring(function () use ($now, &$result): void {
            $result = $this->service()->sweep($now);
        });

        $this->assertSame(['repushed' => 0, 'reset' => NotificationDeliveryService::SWEEP_BATCH_SIZE + 2, 'purged' => 0], $result);
        $this->assertSame(0, (int)NotificationDelivery::find()->where(['notification_template_id' => $template->id, 'status' => 'sending'])->count());
    }

    /**
     * A stale delivery that its late worker finishes while the sweep looks
     * at it keeps what the worker recorded, also one on its last attempt:
     * the sweep changes, pushes, audits and counts nothing.
     */
    public function testAStaleDeliveryItsWorkerFinishesMeanwhileIsLeftAlone(): void
    {
        $now = time();
        $template = $this->template();
        $stale = $this->delivery(['status' => NotificationDelivery::STATUS_SENDING, 'claimed_at' => $now - 400], $template);
        $lastAttempt = $this->delivery(['status' => NotificationDelivery::STATUS_SENDING, 'claimed_at' => $now - 400, 'attempts' => 7], $template);
        $ids = [(int)$stale->id, (int)$lastAttempt->id];
        $workerFinishes = static function () use ($ids): void {
            NotificationDelivery::updateAll(['status' => NotificationDelivery::STATUS_DELIVERED, 'finished_at' => time()], ['id' => $ids]);
        };

        Event::on(NotificationTemplate::class, BaseActiveRecord::EVENT_AFTER_FIND, $workerFinishes);
        try {
            $result = $this->service()->sweep($now);
        } finally {
            Event::off(NotificationTemplate::class, BaseActiveRecord::EVENT_AFTER_FIND, $workerFinishes);
        }

        $this->assertSame(['repushed' => 0, 'reset' => 0, 'purged' => 0], $result);
        foreach ([$stale, $lastAttempt] as $delivery) {
            $this->assertSame(NotificationDelivery::STATUS_DELIVERED, $this->reload($delivery)->status);
            $this->assertSame([], $this->auditOf($delivery));
        }
        $this->assertSame([], $this->queue->pushes);
    }

    /**
     * A stale delivery whose template is deleted while the sweep looks at
     * it goes with it: nothing is counted or pushed.
     */
    public function testAStaleDeliveryWhoseTemplateIsDeletedMeanwhileIsLeftAlone(): void
    {
        $now = time();
        $template = $this->template();
        $delivery = $this->delivery(['status' => NotificationDelivery::STATUS_SENDING, 'claimed_at' => $now - 400], $template);
        $deleteTemplate = static function () use ($template): void {
            $template->delete();
        };

        Event::on(NotificationDelivery::class, BaseActiveRecord::EVENT_AFTER_FIND, $deleteTemplate);
        try {
            $result = $this->service()->sweep($now);
        } finally {
            Event::off(NotificationDelivery::class, BaseActiveRecord::EVENT_AFTER_FIND, $deleteTemplate);
        }

        $this->assertSame(['repushed' => 0, 'reset' => 0, 'purged' => 0], $result);
        $this->assertNull(NotificationDelivery::findOne($delivery->id));
        $this->assertSame([], $this->queue->pushes);
    }

    private function templateOf(NotificationDelivery $delivery): int
    {
        return (int)$delivery->notification_template_id;
    }

    /**
     * The notification queue on Redis, on a channel of this test that no
     * worker runs, as notificationQueue; its keys are removed after the test.
     */
    private function useRedisQueue(): RedisNotificationQueue
    {
        $this->redisQueue = new RedisNotificationQueue();
        $this->swapComponent('notificationQueue', $this->redisQueue);

        return $this->redisQueue;
    }
}
