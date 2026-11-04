<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\components\NotificationTestVariables;
use app\models\AuditLog;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\NotificationThread;
use app\models\User;
use app\services\NotificationDispatcher;
use app\services\notification\NotificationDeliveryService;
use app\services\notification\RunThread;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;
use app\tests\integration\TeamScopeFixtures;

/**
 * A channel that sends nothing for some events (SelectiveChannelInterface:
 * PagerDuty for the info events of a workflow step, which never trigger an
 * incident, PagerDutyChannel::NEVER_TRIGGERED, or a channel of an
 * installation's own) is treated for such an event as a template that does
 * not listen to it: dispatch() writes no delivery and no audit entry, a run's
 * thread gets no reply (its status post stays current), a delivery written
 * before is skipped instead of claimed as delivered, and Send Test sends the
 * template's first event that its channel sends. Every other event goes out
 * as before.
 */
class SelectiveChannelTest extends DbTestCase
{
    use RestoresComponents;
    use TeamScopeFixtures;
    use DeliveryFixtures;

    private const SELECTIVE = 'selective-test';

    private const SELECTIVE_THREADED = 'selective-threaded-test';

    private User $admin;

    private SelectiveTestChannel $selective;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTestDoubles();
        $this->admin = $this->createUserWithRole('selective-admin', 'admin');
        $this->selective = new SelectiveTestChannel([NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN, NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED]);
        $this->dispatcher()->setChannel(self::SELECTIVE, $this->selective);
        $this->dispatcher()->setChannel(self::SELECTIVE_THREADED, new SelectiveThreadedTestChannel([NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED]));
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    /**
     * Regression (spec answer 3: info events never trigger an incident):
     * PagerDuty sent nothing for workflow.step_started and
     * workflow.step_succeeded, but a template that listened to them still
     * got a delivery of each, which finished as delivered with a
     * notification.dispatched audit entry: the deliveries said a message
     * went out that never did. Now it gets none, as for an event it does not
     * listen to; workflow.failed and workflow.waiting still reach it.
     */
    public function testRegressionPagerDutyGotDeliveriesOfStepEventsItSendsNothingFor(): void
    {
        $template = $this->templateOf(NotificationTemplate::CHANNEL_PAGERDUTY, 'workflow.step_started,workflow.step_succeeded,workflow.waiting,workflow.failed', '{"routing_key":"R0UT1NGKEY-selective"}');
        $run = $this->runId();

        foreach ([NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED, NotificationTemplate::EVENT_WORKFLOW_STEP_SUCCEEDED] as $event) {
            $this->dispatcher()->dispatch($event, $this->ofRun($run));
        }

        $this->assertSame([], $this->deliveriesOf($template));
        $this->assertSame([], $this->queue->pushes);
        $this->assertSame([], $this->auditActionsOf($template));

        $this->dispatcher()->dispatch(NotificationTemplate::EVENT_WORKFLOW_WAITING, $this->ofRun($run));
        $this->dispatcher()->dispatch(NotificationTemplate::EVENT_WORKFLOW_FAILED, $this->ofRun($run));

        $this->assertSame(
            [[NotificationTemplate::EVENT_WORKFLOW_WAITING, NotificationDelivery::KIND_MESSAGE], [NotificationTemplate::EVENT_WORKFLOW_FAILED, NotificationDelivery::KIND_MESSAGE]],
            array_map(static fn (NotificationDelivery $d): array => [$d->event, $d->kind], $this->deliveriesOf($template))
        );
    }

    /**
     * A channel of an installation's own says which events it sends nothing
     * for the same way: no delivery and, for a throttled event, no
     * notification.throttled entry either; the other events reach it.
     */
    public function testAChannelOfItsOwnGetsNoDeliveryOfAnEventItSendsNothingFor(): void
    {
        $template = $this->templateOf(self::SELECTIVE, 'webhook.invalid_token,runner.offline');

        $this->dispatcher()->dispatch(NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN, ['trigger' => ['token_prefix' => 'abc123']]);
        $this->dispatcher()->dispatch(NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN, ['trigger' => ['token_prefix' => 'abc123']]);
        $this->dispatcher()->dispatch(NotificationTemplate::EVENT_RUNNER_OFFLINE, ['runner' => ['id' => '1']]);

        $this->assertSame([NotificationTemplate::EVENT_RUNNER_OFFLINE], array_map(static fn (NotificationDelivery $d): string => $d->event, $this->deliveriesOf($template)));
        $this->assertSame([], $this->auditActionsOf($template));
    }

    /**
     * On a channel that keeps a status post per run, an event the channel
     * sends nothing for gets no reply, but the post of the run stays
     * current: a template that holds it gets its status delivery.
     */
    public function testAThreadedChannelGetsTheStatusButNoReplyOfAnEventItSendsNothingFor(): void
    {
        $template = $this->templateOf(self::SELECTIVE_THREADED, 'workflow.launched,workflow.step_started,workflow.failed');
        $run = $this->runId();
        $this->assertSame([NotificationDelivery::KIND_STATUS, NotificationDelivery::KIND_REPLY], RunThread::kindsOf($template, NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED), 'subscribed');
        $this->assertSame([NotificationDelivery::KIND_STATUS], RunThread::kindsOf($template, NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED, $this->dispatcher()->channel(self::SELECTIVE_THREADED)));

        $this->dispatcher()->dispatch(NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED, $this->ofRun($run));
        $this->assertSame([], $this->deliveriesOf($template), 'no post to keep current yet');

        $this->threadOf($template, $run);
        $this->dispatcher()->dispatch(NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED, $this->ofRun($run));
        $this->dispatcher()->dispatch(NotificationTemplate::EVENT_WORKFLOW_FAILED, $this->ofRun($run));

        $this->assertSame(
            [
                [NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED, NotificationDelivery::KIND_STATUS],
                [NotificationTemplate::EVENT_WORKFLOW_FAILED, NotificationDelivery::KIND_STATUS],
                [NotificationTemplate::EVENT_WORKFLOW_FAILED, NotificationDelivery::KIND_REPLY],
            ],
            array_map(static fn (NotificationDelivery $d): array => [$d->event, $d->kind], $this->deliveriesOf($template))
        );
    }

    /**
     * A delivery of an event that the template's channel sends nothing for,
     * written before (its template had another channel then), is skipped
     * with why, and audited as skipped: never claimed as delivered. Its
     * status delivery still goes out on a threaded channel.
     */
    public function testADeliveryOfAnEventTheChannelSendsNothingForIsSkipped(): void
    {
        $template = $this->templateOf(self::SELECTIVE, 'webhook.invalid_token');
        $delivery = $this->delivery([], $template);

        $this->service()->deliver((int)$delivery->id);

        $row = $this->reload($delivery);
        $reason = sprintf(NotificationDeliveryService::CHANNEL_SENDS_NOTHING, self::SELECTIVE, NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN);
        $this->assertSame(NotificationDelivery::STATUS_SKIPPED, $row->status);
        $this->assertSame($reason, $row->last_error);
        $this->assertSame(0, (int)$row->attempts);
        $this->assertSame([], $this->selective->sent);
        $this->assertSame([AuditLog::ACTION_NOTIFICATION_SKIPPED], array_keys($this->auditOf($delivery)));
        $this->assertSame($reason, $this->auditOf($delivery)[AuditLog::ACTION_NOTIFICATION_SKIPPED][0]['reason'] ?? null);

        $threaded = $this->templateOf(self::SELECTIVE_THREADED, 'workflow.step_started');
        $run = $this->runId();
        $status = $this->delivery(['event' => NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED, 'kind' => NotificationDelivery::KIND_STATUS, 'workflow_job_id' => $run], $threaded);
        $reply = $this->delivery(['event' => NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED, 'kind' => NotificationDelivery::KIND_REPLY, 'workflow_job_id' => $run], $threaded);

        $this->service()->deliver((int)$status->id);
        $this->service()->deliver((int)$reply->id);

        $this->assertSame(NotificationDelivery::STATUS_DELIVERED, $this->reload($status)->status, 'the status post stays current');
        $this->assertSame(NotificationDelivery::STATUS_SKIPPED, $this->reload($reply)->status);
        $this->assertSame(sprintf(NotificationDeliveryService::CHANNEL_SENDS_NOTHING, self::SELECTIVE_THREADED, NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED), $this->reload($reply)->last_error);
    }

    /**
     * Send Test sends the template's first event that its channel sends,
     * so a PagerDuty template whose first event is a step's start tests
     * with its next one.
     */
    public function testSendTestUsesTheFirstEventTheChannelSends(): void
    {
        $pagerDuty = $this->templateOf(NotificationTemplate::CHANNEL_PAGERDUTY, 'workflow.step_started,workflow.step_succeeded,job.failed', '{"routing_key":"R0UT1NGKEY-selective"}');
        $own = $this->templateOf(self::SELECTIVE, 'webhook.invalid_token,runner.offline');
        $email = $this->templateOf(NotificationTemplate::CHANNEL_EMAIL, 'workflow.step_started,job.failed');

        $this->assertSame(NotificationTemplate::EVENT_JOB_FAILED, NotificationTestVariables::eventOf($pagerDuty));
        $this->assertSame(NotificationTemplate::EVENT_RUNNER_OFFLINE, NotificationTestVariables::eventOf($own));
        $this->assertSame(NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED, NotificationTestVariables::eventOf($email), 'a channel that sends every event: its first');

        $this->assertNull($this->dispatcher()->sendSingle($own, NotificationTestVariables::of('runner.offline', 'alice'), 'runner.offline'));
        $this->assertCount(1, $this->selective->sent);
    }

    /**
     * Regression: a Send Test of a template whose channel sends none of its
     * events answered that the test was sent, and audited it as dispatched,
     * although nothing went out. It fails now with why, audited as failed.
     */
    public function testRegressionSendTestOfEventsTheChannelSendsNothingForClaimedASend(): void
    {
        $template = $this->templateOf(self::SELECTIVE, 'webhook.invalid_token');
        $event = NotificationTestVariables::eventOf($template);
        $this->assertSame(NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN, $event, 'none it sends: the first');

        $error = $this->dispatcher()->sendSingle($template, NotificationTestVariables::of($event, 'alice'), $event);

        $expected = sprintf(NotificationDispatcher::SENDS_NOTHING, self::SELECTIVE, $event);
        $this->assertSame($expected, $error);
        $this->assertSame([], $this->selective->sent);
        $entries = AuditLog::find()->where(['object_type' => 'notification_template', 'object_id' => $template->id])->all();
        $this->assertCount(1, $entries);
        $this->assertSame(AuditLog::ACTION_NOTIFICATION_FAILED, $entries[0]->action);
        $context = json_decode((string)$entries[0]->metadata, true);
        $this->assertIsArray($context);
        $this->assertSame($expected, $context['error'] ?? null);
    }

    // -- Fixtures -----------------------------------------------------------------

    private function dispatcher(): NotificationDispatcher
    {
        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');

        return $dispatcher;
    }

    private function templateOf(string $channel, string $events, string $config = '{}'): NotificationTemplate
    {
        return $this->createNotificationTemplate((int)$this->admin->id, $channel, $events, $config);
    }

    /**
     * The payload of a workflow event of run $runId, with its refs.
     *
     * @return array<string, mixed>
     */
    private function ofRun(int $runId): array
    {
        return ['workflow' => ['id' => (string)$runId], NotificationDispatcher::REFS => ['workflow_job_id' => $runId]];
    }

    /**
     * The deliveries of $template, in the order they were written.
     *
     * @return list<NotificationDelivery>
     */
    private function deliveriesOf(NotificationTemplate $template): array
    {
        /** @var list<NotificationDelivery> $rows */
        $rows = NotificationDelivery::find()->where(['notification_template_id' => $template->id])->orderBy(['id' => SORT_ASC])->all();

        return $rows;
    }

    /**
     * The actions of the audit entries about $template.
     *
     * @return list<string>
     */
    private function auditActionsOf(NotificationTemplate $template): array
    {
        return array_values(array_map('strval', AuditLog::find()->select('action')->where(['object_type' => 'notification_template', 'object_id' => $template->id])->column()));
    }

    /** The status post of run $runId for $template. */
    private function threadOf(NotificationTemplate $template, int $runId): NotificationThread
    {
        $thread = new NotificationThread();
        $thread->notification_template_id = (int)$template->id;
        $thread->workflow_job_id = $runId;
        $thread->root_post_id = 'selective-root-post';
        $thread->channel_id = 'selective-channel';
        $thread->server = 'https://chat.example.com';
        $this->assertTrue($thread->save(false));

        return $thread;
    }
}
