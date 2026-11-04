<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\models\Job;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\NotificationThread;
use app\models\User;
use app\models\WorkflowJob;
use app\services\NotificationDispatcher;
use app\services\notification\NotificationDeliveryService;
use app\services\notification\RunThread;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;
use app\tests\integration\TeamScopeFixtures;

/**
 * What NotificationDispatcher::dispatch() writes for an event of a workflow
 * run on a channel that keeps a status post per run (spec answers 1 and 2,
 * RunThread): a template that subscribes to the event gets a status
 * delivery and, for a reply event or a job or approval event of the run, a
 * reply after it; a template whose post of the run exists or is on its way
 * gets a status delivery whatever its events and level; a template whose
 * identity may see the job but not the run's workflow gets a message of its
 * own without the run's context, and no status. The worker skips a status
 * or a reply whose identity may no longer see the run's workflow.
 */
class NotificationThreadRoutingTest extends DbTestCase
{
    use RestoresComponents;
    use TeamScopeFixtures;
    use DeliveryFixtures;

    private const THREADED = 'threaded-test';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTestDoubles();
        $this->dispatcher()->setChannel(self::THREADED, new ThreadedTestChannel());
        $this->admin = $this->createUserWithRole('routing-admin', 'admin');
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    /**
     * A template that subscribes to a step event of the run gets its status
     * first, then its reply, both pushed; workflow.launched only makes the
     * post. A template of another channel gets a message, as before.
     */
    public function testASubscribedTemplateGetsTheStatusThenTheReply(): void
    {
        $run = $this->newRun();
        $threaded = $this->threadedTemplate($this->admin, 'workflow.launched,workflow.step_failed');
        $email = $this->createNotificationTemplate((int)$this->admin->id, NotificationTemplate::CHANNEL_EMAIL, 'workflow.step_failed');

        $this->dispatchOf(NotificationTemplate::EVENT_WORKFLOW_LAUNCHED, $run);
        $this->dispatchOf(NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED, $run);

        $this->assertSame(
            [['workflow.launched', 'status'], ['workflow.step_failed', 'status'], ['workflow.step_failed', 'reply']],
            $this->rowsOf($threaded)
        );
        $this->assertSame([['workflow.step_failed', 'message']], $this->rowsOf($email));
        $this->assertSame($this->idsOf([$threaded, $email]), $this->queue->pushedIds());
    }

    /**
     * A template whose post of the run exists, or is on its way, gets a
     * status of every event of the run, its workflow events and the job
     * and approval events of its steps, whatever its events and level; one
     * without a post, and one of another channel, get nothing.
     */
    public function testAPostHolderGetsAStatusOfEveryEventOfTheRun(): void
    {
        $scope = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['own']->id);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$scope['admin']->id);
        $job = $this->createJob((int)$scope['own']->id, (int)$scope['admin']->id, Job::STATUS_FAILED);
        $holder = $this->threadedTemplate($this->admin, 'project.sync_failed');
        $holder->level = NotificationTemplate::LEVEL_ESSENTIAL;
        $holder->save(false);
        $this->postOf($holder, $run);
        $onItsWay = $this->threadedTemplate($this->admin, 'project.sync_failed');
        $this->delivery(['event' => 'workflow.launched', 'kind' => NotificationDelivery::KIND_STATUS, 'workflow_job_id' => (int)$run->id, 'next_attempt_at' => time() + 600], $onItsWay);
        $without = $this->threadedTemplate($this->admin, 'project.sync_failed');
        $email = $this->createNotificationTemplate((int)$this->admin->id, NotificationTemplate::CHANNEL_EMAIL, 'project.sync_failed');
        $this->postOf($email, $run);

        $this->dispatchOf(NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED, $run);
        $this->dispatchOf(NotificationTemplate::EVENT_JOB_FAILED, $run, ['job_id' => (int)$job->id]);

        foreach ([$holder, $onItsWay] as $template) {
            $this->assertSame([['workflow.step_started', 'status'], ['job.failed', 'status']], array_slice($this->rowsOf($template), -2), (string)$template->id);
        }
        $this->assertSame([], $this->rowsOf($without));
        $this->assertSame([], $this->rowsOf($email));
    }

    /**
     * Spec answer 2: a job event of a run reaches a template whose identity
     * may see the job, through its project, but not the run's workflow as a
     * message of its own, without the run's context; it subscribes to the
     * job event, so it gets that, and no status, even with a post of the
     * run.
     */
    public function testAJobEventOfAHiddenRunIsAMessageWithoutTheRunsContext(): void
    {
        $scope = $this->teamScope();
        $hidden = $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['own']->id, (int)$scope['foreign']->id);
        $run = $this->createWorkflowJob((int)$hidden->id, (int)$scope['admin']->id);
        $job = $this->createJob((int)$scope['own']->id, (int)$scope['admin']->id, Job::STATUS_FAILED);
        $subscribed = $this->threadedTemplate($scope['member'], 'job.failed');
        $holder = $this->threadedTemplate($scope['member'], 'project.sync_failed');
        $this->postOf($holder, $run);

        $this->dispatchOf(NotificationTemplate::EVENT_JOB_FAILED, $run, ['job_id' => (int)$job->id], ['workflow' => ['template_name' => 'hidden-name'], 'step' => ['name' => 'hidden-step']]);

        $this->assertSame([['job.failed', 'message']], $this->rowsOf($subscribed));
        $delivery = NotificationDelivery::findOne(['notification_template_id' => $subscribed->id]);
        $this->assertInstanceOf(NotificationDelivery::class, $delivery);
        $this->assertStringNotContainsString('hidden-', (string)$delivery->variables);
        $this->assertSame([], $this->rowsOf($holder));
    }

    /**
     * RunThread: which deliveries an event of a run gets on a template of a
     * channel that keeps a post, and which replies need attention.
     */
    public function testTheKindsOfAnEventOfARun(): void
    {
        $all = $this->threadedTemplate($this->admin, 'workflow.launched,workflow.step_started,job.failed,approval.requested');
        $essential = $this->threadedTemplate($this->admin, 'workflow.step_started,workflow.failed');
        $essential->level = NotificationTemplate::LEVEL_ESSENTIAL;

        $this->assertSame(['status'], RunThread::kindsOf($all, 'workflow.launched'));
        $this->assertSame(['status', 'reply'], RunThread::kindsOf($all, 'workflow.step_started'));
        $this->assertSame(['status', 'reply'], RunThread::kindsOf($all, 'job.failed'));
        $this->assertSame(['status', 'reply'], RunThread::kindsOf($all, 'approval.requested'));
        $this->assertSame(['status'], RunThread::kindsOf($all, 'workflow.failed'), 'a holder that does not subscribe');
        $this->assertSame(['status'], RunThread::kindsOf($essential, 'workflow.step_started'), 'below the level');
        $this->assertSame(['status', 'reply'], RunThread::kindsOf($essential, 'workflow.failed'));
        $this->assertTrue(RunThread::needsAttention('workflow.waiting'));
        $this->assertFalse(RunThread::needsAttention('workflow.step_started'));
        $this->assertFalse(RunThread::needsAttention('job.failed'));
    }

    /**
     * A status or a reply shows the run: the worker skips it once the
     * template's identity may no longer see the run's workflow, also for a
     * job event of the run whose job the identity still sees (here a step
     * that runs in a project of another team was added to the workflow).
     * A message of the same job event is still sent.
     */
    public function testAThreadDeliveryNeedsTheRunsWorkflowWhenItIsSent(): void
    {
        $scope = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['own']->id);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$scope['admin']->id);
        $job = $this->createJob((int)$scope['own']->id, (int)$scope['admin']->id, Job::STATUS_FAILED);
        $threaded = $this->threadedTemplate($scope['member'], 'job.failed');
        $refs = ['workflow_job_id' => (int)$run->id, 'job_id' => (int)$job->id];
        $status = $this->delivery(['event' => 'job.failed', 'kind' => NotificationDelivery::KIND_STATUS] + $refs, $threaded);
        $email = $this->createNotificationTemplate((int)$scope['member']->id, NotificationTemplate::CHANNEL_EMAIL, 'job.failed');
        $message = $this->delivery(['event' => 'job.failed', 'kind' => NotificationDelivery::KIND_MESSAGE] + $refs, $email);
        $this->createWorkflowStep((int)$workflow->id, 5, \app\models\WorkflowStep::TYPE_JOB, (int)$scope['foreign']->id);

        $this->service()->deliver((int)$status->id);
        $this->service()->deliver((int)$message->id);

        $this->assertSame(NotificationDelivery::STATUS_SKIPPED, $this->reload($status)->status);
        $this->assertSame(sprintf(NotificationDeliveryService::NOT_VISIBLE, (int)$scope['member']->id), $this->reload($status)->last_error);
        $this->assertSame(NotificationDelivery::STATUS_DELIVERED, $this->reload($message)->status);
    }

    // -- Helpers ------------------------------------------------------------------------

    private function dispatcher(): NotificationDispatcher
    {
        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');

        return $dispatcher;
    }

    /**
     * Dispatches $event of $run, with the refs of $extra and $payload.
     *
     * @param array<string, int> $extra
     * @param array<string, mixed> $payload
     */
    private function dispatchOf(string $event, WorkflowJob $run, array $extra = [], array $payload = []): void
    {
        $this->dispatcher()->dispatch($event, $payload + [NotificationDispatcher::REFS => ['workflow_job_id' => (int)$run->id] + $extra]);
    }

    private function newRun(): WorkflowJob
    {
        return $this->createWorkflowJob((int)$this->createWorkflowTemplate((int)$this->admin->id)->id, (int)$this->admin->id);
    }

    private function threadedTemplate(User $identity, string $events): NotificationTemplate
    {
        return $this->createNotificationTemplate((int)$identity->id, self::THREADED, $events, '{}');
    }

    private function postOf(NotificationTemplate $template, WorkflowJob $run): void
    {
        $thread = new NotificationThread();
        $thread->notification_template_id = (int)$template->id;
        $thread->workflow_job_id = (int)$run->id;
        $thread->root_post_id = 'post-' . $template->id;
        $thread->channel_id = 'channel-1';
        $thread->server = 'https://chat.example.com';
        $this->assertTrue($thread->save(false));
    }

    /**
     * The deliveries of $template: [event, kind], by ID.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function rowsOf(NotificationTemplate $template): array
    {
        return array_map(
            static fn (NotificationDelivery $d): array => [(string)$d->event, (string)$d->kind],
            NotificationDelivery::find()->where(['notification_template_id' => $template->id])->orderBy(['id' => SORT_ASC])->all()
        );
    }

    /**
     * The IDs of the deliveries of $templates, by ID.
     *
     * @param list<NotificationTemplate> $templates
     * @return list<int>
     */
    private function idsOf(array $templates): array
    {
        return array_map('intval', NotificationDelivery::find()
            ->select('id')
            ->where(['notification_template_id' => array_map(static fn (NotificationTemplate $t): int => (int)$t->id, $templates)])
            ->orderBy(['id' => SORT_ASC])
            ->column());
    }
}
