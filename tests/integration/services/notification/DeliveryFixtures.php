<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\models\AuditLog;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\services\NotificationDispatcher;
use app\services\notification\NotificationDeliveryService;
use yii\log\Logger;

/**
 * Fixtures of the tests of notification deliveries; use in DbTestCase
 * subclasses with RestoresComponents and TeamScopeFixtures.
 *
 * useTestDoubles() makes the notification queue a RecordingNotificationQueue
 * ($queue), the email channel a ScriptedChannel ($channel), and the
 * delivery service one of its own; call it right after parent::setUp(),
 * and restoreComponents() first in tearDown().
 */
trait DeliveryFixtures
{
    private RecordingNotificationQueue $queue;

    private ScriptedChannel $channel;

    private ?User $identity = null;

    private function useTestDoubles(): void
    {
        $this->queue = new RecordingNotificationQueue();
        $this->swapComponent('notificationQueue', $this->queue);
        $this->channel = new ScriptedChannel();
        $dispatcher = new NotificationDispatcher();
        $dispatcher->setChannel(NotificationTemplate::CHANNEL_EMAIL, $this->channel);
        $this->swapComponent('notificationDispatcher', $dispatcher);
        $this->swapComponent('notificationDeliveryService', ['class' => NotificationDeliveryService::class]);
    }

    private function service(): NotificationDeliveryService
    {
        /** @var NotificationDeliveryService $service */
        $service = \Yii::$app->get('notificationDeliveryService');

        return $service;
    }

    /**
     * An email template that delivers as an admin, whose subject renders
     * "Job #7 failed" and whose body "Template: deploy" from the variables
     * of delivery().
     */
    private function template(string $channel = NotificationTemplate::CHANNEL_EMAIL, string $config = '{"emails": ["ops@example.com"]}'): NotificationTemplate
    {
        $this->identity ??= $this->createUserWithRole('delivery-identity', 'admin');

        return $this->createNotificationTemplate((int)$this->identity->id, $channel, 'webhook.invalid_token', $config);
    }

    /**
     * A pending message of webhook.invalid_token (any active identity may
     * see it), due now, of $template (a new template()), with
     * $attributes on top.
     *
     * @param array<string, mixed> $attributes
     */
    private function delivery(array $attributes = [], ?NotificationTemplate $template = null): NotificationDelivery
    {
        $template ??= $this->template();
        $delivery = new NotificationDelivery();
        $delivery->notification_template_id = (int)$template->id;
        $delivery->event = NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN;
        $delivery->kind = NotificationDelivery::KIND_MESSAGE;
        $delivery->status = NotificationDelivery::STATUS_PENDING;
        $delivery->setVariableMap(['job.id' => '7', 'job.status' => 'failed', 'template.name' => 'deploy']);
        $delivery->next_attempt_at = time();
        foreach ($attributes as $name => $value) {
            $delivery->setAttribute($name, $value);
        }
        $this->assertTrue($delivery->save(false));

        return $delivery;
    }

    /** A running run of a workflow without job steps, launched by the identity. */
    private function runId(): int
    {
        $this->identity ??= $this->createUserWithRole('delivery-identity', 'admin');
        $workflow = $this->createWorkflowTemplate((int)$this->identity->id);

        return (int)$this->createWorkflowJob((int)$workflow->id, (int)$this->identity->id, WorkflowJob::STATUS_RUNNING)->id;
    }

    private function reload(NotificationDelivery $delivery): NotificationDelivery
    {
        $row = NotificationDelivery::findOne($delivery->id);
        $this->assertInstanceOf(NotificationDelivery::class, $row);

        return $row;
    }

    /**
     * The audit entries of the outcomes of $delivery: action => their
     * contexts, in order.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function auditOf(NotificationDelivery $delivery): array
    {
        $entries = [];
        $rows = AuditLog::find()
            ->where(['object_type' => 'notification_template', 'object_id' => $delivery->notification_template_id])
            ->andWhere(['action' => [AuditLog::ACTION_NOTIFICATION_DISPATCHED, AuditLog::ACTION_NOTIFICATION_FAILED, AuditLog::ACTION_NOTIFICATION_SKIPPED]])
            ->orderBy(['id' => SORT_ASC])
            ->all();
        foreach ($rows as $row) {
            $this->assertInstanceOf(AuditLog::class, $row);
            $context = json_decode((string)$row->metadata, true);
            $this->assertIsArray($context);
            if (($context['delivery_id'] ?? null) === (int)$delivery->id) {
                $this->assertNull($row->user_id, 'an entry of the worker carries no user');
                $entries[(string)$row->action][] = $context;
            }
        }

        return $entries;
    }

    /**
     * What is logged while $action runs: [level, category, text] in order.
     *
     * @return list<array{0: int, 1: string, 2: string}>
     */
    private function logsDuring(callable $action): array
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
        $lines = [];
        foreach ($logger->messages as $message) {
            $lines[] = [(int)$message[1], (string)$message[2], is_string($message[0]) ? $message[0] : (string)json_encode($message[0])];
        }

        return $lines;
    }

    /**
     * The texts logged at $level in the category of NotificationDeliveryService.
     *
     * @param list<array{0: int, 1: string, 2: string}> $logs
     * @return list<string>
     */
    private static function serviceLines(array $logs, int $level): array
    {
        $lines = [];
        foreach ($logs as [$logLevel, $category, $text]) {
            if ($logLevel === $level && $category === NotificationDeliveryService::class) {
                $lines[] = $text;
            }
        }

        return $lines;
    }
}
