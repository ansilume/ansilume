<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\models\NotificationTemplate;
use app\services\NotificationDispatcher;
use app\services\notification\ChannelInterface;
use app\tests\integration\DbTestCase;

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
    }
}
