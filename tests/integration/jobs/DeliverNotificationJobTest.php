<?php

declare(strict_types=1);

namespace app\tests\integration\jobs;

use app\components\AllowlistQueueSerializer;
use app\components\OutboundHttpException;
use app\components\OutboundHttpResponse;
use app\components\QueueLogBehavior;
use app\jobs\DeliverNotificationJob;
use app\models\AuditLog;
use app\models\NotificationDelivery;
use app\services\notification\NotificationDeliveryService;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;
use app\tests\integration\services\notification\DeliveryFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\log\Logger;
use yii\queue\ExecEvent;
use yii\queue\Queue;

/**
 * The job of the notification queue (DeliverNotificationJob): it carries
 * the ID of an outbox row only, and delivers it
 * (NotificationDeliveryService::deliver()), in the notification worker,
 * under the queue's log behaviour (QueueLogBehavior), as config/console.php
 * wires it.
 */
class DeliverNotificationJobTest extends DbTestCase
{
    use RestoresComponents;
    use TeamScopeFixtures;
    use DeliveryFixtures;

    /** @var list<\Throwable> the errors of the jobs that failed */
    private array $errors = [];

    private int $finished = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTestDoubles();
        // As config/console.php, but without the flush after each job, which
        // would hand the test's logger's messages away before they are read.
        $this->queue->attachBehavior('log', ['class' => QueueLogBehavior::class, 'autoFlush' => false]);
        $this->queue->on(Queue::EVENT_AFTER_ERROR, function (ExecEvent $event): void {
            $this->errors[] = $event->error ?? new \LogicException('An error event without its error.');
        });
        $this->queue->on(Queue::EVENT_AFTER_EXEC, function (): void {
            $this->finished++;
        });
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    /**
     * The job never lets the error of a channel reach the queue: the
     * queue's log behaviour would log it, with its message and its trace.
     * The delivery keeps it as ErrorText gives it (a database error by its
     * summary only); neither the statement of a database error nor the
     * answer of a server reaches a log, the row or the audit.
     */
    public function testAChannelErrorNeverEscapesTheJob(): void
    {
        $template = $this->template();
        $hook = $this->delivery([], $template);
        $database = $this->delivery([], $template);
        $refused = $this->delivery([], $template);
        $this->channel->script = [
            new \RuntimeException('CustomChannel: the hook did not answer.'),
            new \yii\db\Exception(
                "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away\nThe SQL being executed was: SELECT 'marker-sql-5521'",
                ['HY000', 2006, 'MySQL server has gone away']
            ),
            (new OutboundHttpException(OutboundHttpException::KIND_STATUS, 'HTTP 404.', 404, new OutboundHttpResponse(404, [], 'marker-body-5522')))->from('WebhookChannel'),
        ];
        foreach ([$hook, $database, $refused] as $delivery) {
            $this->queue->push(new DeliverNotificationJob(['deliveryId' => (int)$delivery->id]));
        }
        $this->queue->pushes = [];

        $logs = $this->logsDuring(fn () => $this->queue->run());

        $this->assertSame([], $this->errors, 'no job ended with an error');
        $this->assertSame(3, $this->finished);
        $finishedLines = 0;
        foreach ($logs as [$level, $category, $text]) {
            if ($category === Queue::class) {
                $this->assertNotSame(Logger::LEVEL_ERROR, $level, "the queue logged no error: {$text}");
                $finishedLines += str_ends_with($text, 'is finished.') ? 1 : 0;
            }
            $this->assertStringNotContainsString('marker-sql-5521', $text);
            $this->assertStringNotContainsString('marker-body-5522', $text);
        }
        $this->assertSame(3, $finishedLines, 'the queue logged each job as finished');
        $this->assertSame('CustomChannel: the hook did not answer.', $this->reload($hook)->last_error);
        $this->assertSame('yii\db\Exception (SQLSTATE HY000, driver error 2006)', $this->reload($database)->last_error);
        $this->assertSame('WebhookChannel: HTTP 404.', $this->reload($refused)->last_error);
        foreach (AuditLog::find()->where(['object_type' => 'notification_template', 'object_id' => $template->id])->all() as $entry) {
            $this->assertInstanceOf(AuditLog::class, $entry);
            $this->assertStringNotContainsString('marker-sql-5521', (string)$entry->metadata);
            $this->assertStringNotContainsString('marker-body-5522', (string)$entry->metadata);
        }
        $this->assertSame([(int)$hook->id, (int)$database->id], $this->queue->pushedIds(), 'the two errors that may pass are retried');
    }

    /**
     * An error of the delivery's own bookkeeping, such as a database that
     * went away, is no channel error: it reaches the queue, whose log
     * behaviour writes it as ExceptionLogText gives it, without the
     * statement. The row stays where it was, for the maintenance run to
     * deliver it later.
     */
    public function testADatabaseErrorOfTheBookkeepingIsLoggedWithoutItsStatement(): void
    {
        $this->swapComponent('notificationDeliveryService', new class () extends NotificationDeliveryService {
            public function deliver(int $deliveryId): void
            {
                throw new \yii\db\Exception(
                    "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away\nThe SQL being executed was: UPDATE `notification_delivery` SET `last_error`='marker-sql-5523'",
                    ['HY000', 2006, 'MySQL server has gone away']
                );
            }
        });
        $this->queue->push(new DeliverNotificationJob(['deliveryId' => 42]));

        $logs = $this->logsDuring(fn () => $this->queue->run());

        $this->assertCount(1, $this->errors);
        $errorLines = [];
        foreach ($logs as [$level, $category, $text]) {
            $this->assertStringNotContainsString('marker-sql-5523', $text);
            if ($level === Logger::LEVEL_ERROR && $category === Queue::class) {
                $errorLines[] = $text;
            }
        }
        $this->assertCount(1, $errorLines);
        $this->assertStringContainsString('yii\db\Exception (SQLSTATE HY000, driver error 2006)', $errorLines[0]);
    }

    public function testTheJobDeliversItsDelivery(): void
    {
        $delivery = $this->delivery();

        (new DeliverNotificationJob(['deliveryId' => (int)$delivery->id]))->execute($this->queue);

        $this->assertSame(NotificationDelivery::STATUS_DELIVERED, $this->reload($delivery)->status);
        $this->assertCount(1, $this->channel->calls);
    }

    /**
     * A queue message names the delivery only: the outbox row holds what is
     * sent, and Redis nothing of it. Custom channels never enter the
     * allowlist of the queue's serializer.
     */
    public function testTheJobCarriesOnlyTheDeliveryId(): void
    {
        $job = new DeliverNotificationJob(['deliveryId' => 42]);

        $this->assertSame(['deliveryId' => 42], get_object_vars($job));
        $this->assertSame(0, (new DeliverNotificationJob())->deliveryId);
        $this->assertContains(DeliverNotificationJob::class, AllowlistQueueSerializer::ALLOWED_CLASSES);
    }
}
