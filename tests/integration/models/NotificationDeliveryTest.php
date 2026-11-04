<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\NotificationThread;
use app\models\WorkflowJob;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The delivery outbox (NotificationDelivery) and the threads of status
 * posts (NotificationThread) of migration m000091: their constants, the
 * defaults a new row gets, their validation and their relations.
 */
class NotificationDeliveryTest extends DbTestCase
{
    use TeamScopeFixtures;

    public function testTheStatusesAndKindsAreLowerCaseWords(): void
    {
        $this->assertSame(
            ['pending', 'sending', 'delivered', 'failed', 'skipped', 'superseded'],
            [
                NotificationDelivery::STATUS_PENDING,
                NotificationDelivery::STATUS_SENDING,
                NotificationDelivery::STATUS_DELIVERED,
                NotificationDelivery::STATUS_FAILED,
                NotificationDelivery::STATUS_SKIPPED,
                NotificationDelivery::STATUS_SUPERSEDED,
            ]
        );
        $this->assertSame(['message', 'status', 'reply'], [NotificationDelivery::KIND_MESSAGE, NotificationDelivery::KIND_STATUS, NotificationDelivery::KIND_REPLY]);
        $this->assertSame(['pending', 'sending', 'delivered', 'failed', 'skipped', 'superseded'], NotificationDelivery::STATUSES);
        $this->assertSame(['message', 'status', 'reply'], NotificationDelivery::KINDS);
        $this->assertSame(['delivered', 'failed', 'skipped', 'superseded'], NotificationDelivery::FINISHED_STATUSES);
        $this->assertSame('{{%notification_delivery}}', NotificationDelivery::tableName());
    }

    /**
     * A new delivery is a pending message with no attempt yet, and the
     * timestamps are set on insert.
     */
    public function testANewDeliveryIsAPendingMessageWithoutAttempts(): void
    {
        $template = $this->template();
        $delivery = new NotificationDelivery();
        $delivery->notification_template_id = (int)$template->id;
        $delivery->event = NotificationTemplate::EVENT_JOB_FAILED;
        $delivery->setVariableMap(['job.id' => '7']);
        $delivery->next_attempt_at = 1700000000;
        $before = time();

        $this->assertTrue($delivery->save(), (string)json_encode($delivery->errors));

        $stored = NotificationDelivery::findOne($delivery->id);
        $this->assertInstanceOf(NotificationDelivery::class, $stored);
        $this->assertSame(NotificationDelivery::STATUS_PENDING, $stored->status);
        $this->assertSame(NotificationDelivery::KIND_MESSAGE, $stored->kind);
        $this->assertSame(0, (int)$stored->attempts);
        $this->assertNull($stored->claimed_at);
        $this->assertNull($stored->finished_at);
        $this->assertNull($stored->last_error);
        $this->assertNull($stored->remote_id);
        $this->assertNull($stored->workflow_job_id);
        $this->assertGreaterThanOrEqual($before, (int)$stored->created_at);
        $this->assertSame((int)$stored->created_at, (int)$stored->updated_at);
    }

    public function testTheDatabaseDefaultsMakeARowAPendingMessage(): void
    {
        $template = $this->template();
        \Yii::$app->db->createCommand()->insert(NotificationDelivery::tableName(), [
            'notification_template_id' => $template->id,
            'event' => NotificationTemplate::EVENT_JOB_FAILED,
            'variables' => '{}',
            'next_attempt_at' => 1700000000,
            'created_at' => 1700000000,
            'updated_at' => 1700000000,
        ])->execute();

        $stored = NotificationDelivery::findOne((int)\Yii::$app->db->getLastInsertID());
        $this->assertInstanceOf(NotificationDelivery::class, $stored);
        $this->assertSame(NotificationDelivery::STATUS_PENDING, $stored->status);
        $this->assertSame(NotificationDelivery::KIND_MESSAGE, $stored->kind);
        $this->assertSame(0, (int)$stored->attempts);
    }

    /**
     * @dataProvider invalidDeliveries
     *
     * @param array<string, mixed> $attributes
     */
    public function testValidationRefusesWhatNoDeliveryHolds(array $attributes, string $attribute): void
    {
        $delivery = new NotificationDelivery();
        $delivery->notification_template_id = 1;
        $delivery->event = NotificationTemplate::EVENT_JOB_FAILED;
        $delivery->variables = '{}';
        $delivery->next_attempt_at = 1700000000;
        $delivery->setAttributes($attributes, false);

        $this->assertFalse($delivery->validate());
        $this->assertTrue($delivery->hasErrors($attribute), (string)json_encode($delivery->errors));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidDeliveries(): array
    {
        return [
            'an unknown status' => [['status' => 'lost'], 'status'],
            'a status in capitals' => [['status' => 'PENDING'], 'status'],
            'an unknown kind' => [['kind' => 'thread'], 'kind'],
            'no template' => [['notification_template_id' => null], 'notification_template_id'],
            'no event' => [['event' => ''], 'event'],
            'a long event' => [['event' => str_repeat('e', 65)], 'event'],
            'no variables' => [['variables' => null], 'variables'],
            'no next attempt' => [['next_attempt_at' => null], 'next_attempt_at'],
            'a long error' => [['last_error' => str_repeat('x', 501)], 'last_error'],
            'a long post ID' => [['remote_id' => str_repeat('p', 65)], 'remote_id'],
            'a fraction of attempts' => [['attempts' => 1.5], 'attempts'],
        ];
    }

    public function testEveryStatusAndKindIsValid(): void
    {
        foreach (NotificationDelivery::STATUSES as $status) {
            foreach (NotificationDelivery::KINDS as $kind) {
                $delivery = new NotificationDelivery();
                $delivery->notification_template_id = 1;
                $delivery->event = NotificationTemplate::EVENT_WORKFLOW_FAILED;
                $delivery->variables = '{}';
                $delivery->next_attempt_at = 1700000000;
                $delivery->status = $status;
                $delivery->kind = $kind;
                $this->assertTrue($delivery->validate(), "{$status}/{$kind}: " . json_encode($delivery->errors));
            }
        }
    }

    /**
     * The variables are a JSON object of strings: the snapshot of an event,
     * flat, as the template renders it.
     */
    public function testTheVariablesAreAMapOfStrings(): void
    {
        $delivery = new NotificationDelivery();
        $delivery->setVariableMap(['workflow.name' => 'Déploiement / prod', 'job.id' => '42', 'empty' => '']);

        $this->assertSame('{"workflow.name":"Déploiement / prod","job.id":"42","empty":""}', $delivery->variables);
        $this->assertSame(['workflow.name' => 'Déploiement / prod', 'job.id' => '42', 'empty' => ''], $delivery->getVariableMap());
    }

    /**
     * Stored JSON that is no object of strings gives what of it is one:
     * nothing for a text that is no JSON object, and only its string
     * values otherwise.
     */
    public function testStoredVariablesKeepOnlyTheirStrings(): void
    {
        $delivery = new NotificationDelivery();
        foreach (['', 'not json', '"text"', '[1,2]', 'null'] as $variables) {
            $delivery->variables = $variables;
            $this->assertSame([], $delivery->getVariableMap(), $variables);
        }
        $delivery->variables = '{"a":"x","b":1,"c":null,"d":{"e":"f"},"g":true}';
        $this->assertSame(['a' => 'x'], $delivery->getVariableMap());
    }

    public function testADeliveryBelongsToItsTemplateAndRun(): void
    {
        $template = $this->template();
        $run = $this->newRun((int)$template->created_by);
        $delivery = $this->delivery((int)$template->id, (int)$run->id);
        $message = $this->delivery((int)$template->id, null);

        $this->assertSame((int)$template->id, (int)$delivery->template->id);
        $this->assertInstanceOf(WorkflowJob::class, $delivery->workflowJob);
        $this->assertSame((int)$run->id, (int)$delivery->workflowJob->id);
        $this->assertNull($message->workflowJob);
        $ids = array_map(static fn (NotificationDelivery $d): int => (int)$d->id, $template->deliveries);
        sort($ids);
        $this->assertSame([(int)$delivery->id, (int)$message->id], $ids);
    }

    public function testAThreadBelongsToItsTemplateAndRun(): void
    {
        $template = $this->template();
        $run = $this->newRun((int)$template->created_by);
        $thread = $this->thread((int)$template->id, (int)$run->id);

        $this->assertSame('{{%notification_thread}}', NotificationThread::tableName());
        $stored = NotificationThread::findOne($thread->id);
        $this->assertInstanceOf(NotificationThread::class, $stored);
        $this->assertSame((int)$template->id, (int)$stored->template->id);
        $this->assertSame((int)$run->id, (int)$stored->workflowJob->id);
        $this->assertSame('root-post-1', $stored->root_post_id);
        $this->assertSame('channel-1', $stored->channel_id);
        $this->assertSame('https://mattermost.example.com', $stored->server);
        $this->assertGreaterThan(0, (int)$stored->created_at);
    }

    /**
     * One status post per template and run: a second thread of the same
     * pair is refused by the validation, before the unique index would.
     */
    public function testATemplateKeepsOneThreadPerRun(): void
    {
        $template = $this->template();
        $run = $this->newRun((int)$template->created_by);
        $this->thread((int)$template->id, (int)$run->id);

        $second = $this->newThread((int)$template->id, (int)$run->id);
        $this->assertFalse($second->validate());
        $this->assertTrue($second->hasErrors('notification_template_id'));

        $other = $this->newThread((int)$template->id, (int)$this->newRun((int)$template->created_by)->id);
        $this->assertTrue($other->validate(), (string)json_encode($other->errors));
    }

    /**
     * @dataProvider invalidThreads
     *
     * @param array<string, mixed> $attributes
     */
    public function testValidationRefusesWhatNoThreadHolds(array $attributes, string $attribute): void
    {
        $thread = $this->newThread(1, 1);
        $thread->setAttributes($attributes, false);

        $this->assertFalse($thread->validate([$attribute]));
        $this->assertTrue($thread->hasErrors($attribute));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidThreads(): array
    {
        return [
            'no root post' => [['root_post_id' => ''], 'root_post_id'],
            'a long root post' => [['root_post_id' => str_repeat('r', 65)], 'root_post_id'],
            'no channel' => [['channel_id' => null], 'channel_id'],
            'a long channel' => [['channel_id' => str_repeat('c', 65)], 'channel_id'],
            'no server' => [['server' => ''], 'server'],
            'a long server' => [['server' => str_repeat('s', 256)], 'server'],
            'no run' => [['workflow_job_id' => null], 'workflow_job_id'],
        ];
    }

    private function template(): NotificationTemplate
    {
        return $this->createNotificationTemplate((int)$this->createUser('nd')->id);
    }

    private function newRun(int $userId): WorkflowJob
    {
        return $this->createWorkflowJob((int)$this->createWorkflowTemplate($userId)->id, $userId);
    }

    private function delivery(int $templateId, ?int $runId): NotificationDelivery
    {
        $delivery = new NotificationDelivery();
        $delivery->notification_template_id = $templateId;
        $delivery->workflow_job_id = $runId;
        $delivery->event = NotificationTemplate::EVENT_WORKFLOW_FAILED;
        $delivery->setVariableMap([]);
        $delivery->next_attempt_at = time();
        $this->assertTrue($delivery->save(), (string)json_encode($delivery->errors));

        return $delivery;
    }

    private function thread(int $templateId, int $runId): NotificationThread
    {
        $thread = $this->newThread($templateId, $runId);
        $this->assertTrue($thread->save(), (string)json_encode($thread->errors));

        return $thread;
    }

    private function newThread(int $templateId, int $runId): NotificationThread
    {
        $thread = new NotificationThread();
        $thread->notification_template_id = $templateId;
        $thread->workflow_job_id = $runId;
        $thread->root_post_id = 'root-post-1';
        $thread->channel_id = 'channel-1';
        $thread->server = 'https://mattermost.example.com';

        return $thread;
    }
}
