<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\models\NotificationTemplate;
use app\services\NotificationDispatcher;
use app\services\notification\ChannelInterface;
use app\tests\integration\DbTestCase;
use yii\log\Logger;

/**
 * Integration tests for NotificationDispatcher — covers the core dispatch
 * loop, per-delivery audit writes, channel routing, and failure isolation.
 */
class NotificationDispatcherTest extends DbTestCase
{
    public function testDispatchOnlyFiresMatchingTemplates(): void
    {
        $user = $this->createUser('disp');
        $matching = $this->createNotificationTemplate($user->id);
        $matching->events = NotificationTemplate::EVENT_JOB_FAILED;
        $matching->save(false);

        $nonMatching = $this->createNotificationTemplate($user->id);
        $nonMatching->events = NotificationTemplate::EVENT_JOB_SUCCEEDED;
        $nonMatching->save(false);
        $this->assertNotSame($matching->id, $nonMatching->id);

        $channel = new RecordingChannel();
        $dispatcher = new NotificationDispatcher();
        $dispatcher->setChannel(NotificationTemplate::CHANNEL_EMAIL, $channel);

        $dispatcher->dispatch(NotificationTemplate::EVENT_JOB_FAILED, [
            'job' => ['id' => '42', 'status' => 'failed'],
        ]);

        $this->assertCount(1, $channel->calls);
        $this->assertSame($matching->id, $channel->calls[0]['template']->id);
    }

    public function testDispatchWritesAuditOnSuccess(): void
    {
        $user = $this->createUser('disp');
        $nt = $this->createNotificationTemplate($user->id);
        $nt->events = NotificationTemplate::EVENT_JOB_FAILED;
        $nt->save(false);

        $dispatcher = new NotificationDispatcher();
        $dispatcher->setChannel(NotificationTemplate::CHANNEL_EMAIL, new RecordingChannel());

        $dispatcher->dispatch(NotificationTemplate::EVENT_JOB_FAILED, []);

        $row = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_NOTIFICATION_DISPATCHED])
            ->andWhere(['object_id' => $nt->id])
            ->one();
        $this->assertNotNull($row);
    }

    public function testDispatchWritesAuditOnFailureAndContinues(): void
    {
        $user = $this->createUser('disp');
        $first = $this->createNotificationTemplate($user->id);
        $first->events = NotificationTemplate::EVENT_JOB_FAILED;
        $first->save(false);

        $second = $this->createNotificationTemplate($user->id);
        $second->events = NotificationTemplate::EVENT_JOB_FAILED;
        $second->save(false);
        $this->assertNotSame($first->id, $second->id);

        $recording = new RecordingChannel();
        $dispatcher = new NotificationDispatcher();
        // Both templates use CHANNEL_EMAIL; swap in a channel that fails the first call
        // and succeeds the second via a conditional recorder.
        $dispatcher->setChannel(NotificationTemplate::CHANNEL_EMAIL, new class ($recording) implements ChannelInterface {
            private int $n = 0;
            public function __construct(private RecordingChannel $inner)
            {
            }
            public function send(NotificationTemplate $template, string $subject, string $body, array $variables): void
            {
                $this->n++;
                if ($this->n === 1) {
                    throw new \RuntimeException('boom');
                }
                $this->inner->send($template, $subject, $body, $variables);
            }
        });

        $dispatcher->dispatch(NotificationTemplate::EVENT_JOB_FAILED, []);

        $failedRows = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_NOTIFICATION_FAILED])
            ->count();
        $okRows = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_NOTIFICATION_DISPATCHED])
            ->count();
        $this->assertSame(1, (int)$failedRows);
        $this->assertSame(1, (int)$okRows);
        $this->assertCount(1, $recording->calls); // second call succeeded
    }

    public function testDispatchIsNoOpWhenNoTemplates(): void
    {
        // No templates at all.
        $dispatcher = new NotificationDispatcher();
        $dispatcher->dispatch(NotificationTemplate::EVENT_JOB_FAILED, []);
        $this->assertSame(0, (int)AuditLog::find()
            ->where(['action' => AuditLog::ACTION_NOTIFICATION_DISPATCHED])
            ->count());
    }

    /**
     * Regression: a channel's database error reached the notification.failed
     * audit entry and the application log with its message, which carries the
     * SQL statement with its values. Both now say its class, SQLSTATE and
     * driver error code only.
     */
    public function testRegressionADatabaseErrorOfAChannelWasStoredAndLoggedWithTheStatementAndItsValues(): void
    {
        $nt = $this->listeningTemplate();
        $dispatcher = new NotificationDispatcher();
        $dispatcher->setChannel(NotificationTemplate::CHANNEL_EMAIL, $this->failingChannel(new \yii\db\Exception(
            "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away\n"
            . "The SQL being executed was: SELECT * FROM `user` WHERE `email`='marker-4711'",
            ['HY000', 2006, 'MySQL server has gone away']
        )));

        $errors = $this->errorsLoggedBy(fn () => $dispatcher->dispatch(NotificationTemplate::EVENT_JOB_FAILED, []));

        $row = $this->failureAuditOf($nt);
        $this->assertStringNotContainsString('marker-4711', (string)$row->metadata, 'no value reaches the audit entry');
        $this->assertSame('yii\db\Exception (SQLSTATE HY000, driver error 2006)', $this->errorOf($row));
        $this->assertCount(1, $errors);
        $this->assertStringNotContainsString('marker-4711', $errors[0], 'no value reaches the log');
        $this->assertSame(
            "NotificationDispatcher: failed to send notification #{$nt->id} (email) for job.failed: "
            . 'yii\db\Exception (SQLSTATE HY000, driver error 2006)',
            $errors[0]
        );
        $this->assertSame(
            [sprintf(NotificationDispatcher::SEND_FAILED, $nt->id, 'email', 'job.failed', 'yii\db\Exception (SQLSTATE HY000, driver error 2006)')],
            $errors
        );
    }

    /**
     * Any other error of a channel keeps its message in the audit entry and
     * the log: an operator needs it to fix the channel.
     */
    public function testAChannelErrorOfAnotherKindKeepsItsMessage(): void
    {
        $nt = $this->listeningTemplate();
        $dispatcher = new NotificationDispatcher();
        $dispatcher->setChannel(
            NotificationTemplate::CHANNEL_EMAIL,
            $this->failingChannel(new \RuntimeException('SlackChannel: HTTP 404 — no_service'))
        );

        $errors = $this->errorsLoggedBy(fn () => $dispatcher->dispatch(NotificationTemplate::EVENT_JOB_FAILED, []));

        $this->assertSame('SlackChannel: HTTP 404 — no_service', $this->errorOf($this->failureAuditOf($nt)));
        $this->assertSame(
            [sprintf(NotificationDispatcher::SEND_FAILED, $nt->id, 'email', 'job.failed', 'SlackChannel: HTTP 404 — no_service')],
            $errors
        );
    }

    public function testUnknownChannelWritesFailureAudit(): void
    {
        $user = $this->createUser('disp');
        $nt = $this->createNotificationTemplate($user->id);
        $nt->events = NotificationTemplate::EVENT_JOB_FAILED;
        $nt->channel = 'nonexistent';
        $nt->save(false);

        $dispatcher = new NotificationDispatcher();
        $dispatcher->dispatch(NotificationTemplate::EVENT_JOB_FAILED, []);

        $row = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_NOTIFICATION_FAILED])
            ->andWhere(['object_id' => $nt->id])
            ->one();
        $this->assertNotNull($row);
        $this->assertSame("Unknown channel 'nonexistent'", $this->errorOf($row));
    }

    // -- sendSingle(): what it answers --------------------------------------------------

    /**
     * A message the channel accepted: null, and a notification.dispatched
     * entry.
     */
    public function testSendSingleAnswersNullWhenTheChannelAcceptedTheMessage(): void
    {
        $nt = $this->listeningTemplate();
        $channel = new RecordingChannel();
        $dispatcher = new NotificationDispatcher();
        $dispatcher->setChannel(NotificationTemplate::CHANNEL_EMAIL, $channel);

        $this->assertNull($dispatcher->sendSingle($nt, ['event' => 'test']));

        $this->assertCount(1, $channel->calls);
        $this->assertSame(1, (int)AuditLog::find()
            ->where(['action' => AuditLog::ACTION_NOTIFICATION_DISPATCHED, 'object_id' => $nt->id])
            ->count());
        $this->assertSame(0, (int)AuditLog::find()
            ->where(['action' => AuditLog::ACTION_NOTIFICATION_FAILED, 'object_id' => $nt->id])
            ->count());
    }

    /**
     * Regression (the test actions reported success for a failed channel):
     * a channel's error is sendSingle()'s answer, the same text its
     * notification.failed entry carries; a database error only as its
     * class, SQLSTATE and driver error code.
     *
     * @dataProvider channelErrorProvider
     */
    public function testSendSingleAnswersTheErrorItsAuditEntryCarries(\Throwable $error, string $expected): void
    {
        $nt = $this->listeningTemplate();
        $dispatcher = new NotificationDispatcher();
        $dispatcher->setChannel(NotificationTemplate::CHANNEL_EMAIL, $this->failingChannel($error));

        $answer = $dispatcher->sendSingle($nt, ['event' => 'test'], NotificationTemplate::EVENT_JOB_FAILED);

        $this->assertSame($expected, $answer);
        $this->assertSame($expected, $this->errorOf($this->failureAuditOf($nt)));
        $this->assertSame(0, (int)AuditLog::find()
            ->where(['action' => AuditLog::ACTION_NOTIFICATION_DISPATCHED, 'object_id' => $nt->id])
            ->count());
    }

    /**
     * @return array<string, array{0: \Throwable, 1: string}>
     */
    public static function channelErrorProvider(): array
    {
        return [
            'a channel error keeps its message' => [
                new \RuntimeException('SlackChannel: HTTP 404 — no_service'),
                'SlackChannel: HTTP 404 — no_service',
            ],
            'a database error only its class, SQLSTATE and code' => [
                new \yii\db\Exception(
                    "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away\n"
                        . "The SQL being executed was: SELECT * FROM `user` WHERE `email`='marker-4711'",
                    ['HY000', 2006, 'MySQL server has gone away']
                ),
                'yii\db\Exception (SQLSTATE HY000, driver error 2006)',
            ],
        ];
    }

    /**
     * A template whose channel no handler sends: UNKNOWN_CHANNEL, also in
     * its notification.failed entry, and the UNKNOWN_CHANNEL_LOGGED warning.
     */
    public function testSendSingleAnswersTheUnknownChannel(): void
    {
        $nt = $this->listeningTemplate();
        $nt->channel = 'carrier-pigeon';
        $nt->save(false);
        $dispatcher = new NotificationDispatcher();

        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $answer = $dispatcher->sendSingle($nt, ['event' => 'test']);
        } finally {
            \Yii::setLogger($previous);
        }

        $this->assertSame("Unknown channel 'carrier-pigeon'", $answer);
        $this->assertSame(sprintf(NotificationDispatcher::UNKNOWN_CHANNEL, 'carrier-pigeon'), $answer);
        $this->assertSame($answer, $this->errorOf($this->failureAuditOf($nt)));
        $warnings = [];
        foreach ($logger->messages as $message) {
            if ($message[1] === Logger::LEVEL_WARNING && $message[2] === NotificationDispatcher::class) {
                $warnings[] = $message[0];
            }
        }
        $this->assertSame(["NotificationDispatcher: unknown channel 'carrier-pigeon'"], $warnings);
        $this->assertSame([sprintf(NotificationDispatcher::UNKNOWN_CHANNEL_LOGGED, 'carrier-pigeon')], $warnings);
    }

    // -- Helpers ----------------------------------------------------------------------

    /** A notification template (channel email) that listens to job.failed. */
    private function listeningTemplate(): NotificationTemplate
    {
        $nt = $this->createNotificationTemplate($this->createUser('disp')->id);
        $nt->events = NotificationTemplate::EVENT_JOB_FAILED;
        $nt->save(false);
        $this->assertSame('email', $nt->channel);

        return $nt;
    }

    private function failingChannel(\Throwable $error): ChannelInterface
    {
        return new class ($error) implements ChannelInterface {
            public function __construct(private readonly \Throwable $error)
            {
            }

            public function send(NotificationTemplate $template, string $subject, string $body, array $variables): void
            {
                throw $this->error;
            }
        };
    }

    /** The one notification.failed audit entry of $nt. */
    private function failureAuditOf(NotificationTemplate $nt): AuditLog
    {
        $rows = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_NOTIFICATION_FAILED, 'object_id' => $nt->id])
            ->all();
        $this->assertCount(1, $rows);
        $this->assertInstanceOf(AuditLog::class, $rows[0]);

        return $rows[0];
    }

    /** The error that $row (a notification.failed entry) carries. */
    private function errorOf(AuditLog $row): mixed
    {
        $context = json_decode((string)$row->metadata, true);
        $this->assertIsArray($context);

        return $context['error'] ?? null;
    }

    /**
     * The errors NotificationDispatcher logs while $action runs.
     *
     * @return list<string>
     */
    private function errorsLoggedBy(callable $action): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $action();
        } finally {
            \Yii::setLogger($previous);
        }
        $errors = [];
        foreach ($logger->messages as $message) {
            if ($message[1] === Logger::LEVEL_ERROR && $message[2] === NotificationDispatcher::class && is_string($message[0])) {
                $errors[] = $message[0];
            }
        }

        return $errors;
    }
}
