<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\NotificationThread;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\JobCancellationService;
use app\services\JobLaunchService;
use app\services\NotificationDispatcher;
use app\services\notification\NotificationDeliveryService;
use app\services\runstate\Viewer;
use app\services\runstate\WorkflowRunStates;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;
use app\tests\integration\services\notification\RecordingNotificationQueue;
use app\tests\integration\services\notification\ThreadedTestChannel;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\db\BaseActiveRecord;

/**
 * The events of a workflow run's steps (WorkflowRunEvents), each fired once
 * per transition, and only after the conditional update that made the
 * transition returned true:
 * - workflow.step_started at the end of WorkflowExecutionService::executeStep(),
 *   after the step's work started, while the step still runs; then
 *   workflow.waiting when it waits for a person (an approval, a resume);
 * - workflow.step_succeeded when a step ended successfully: its job
 *   succeeded, its approval was granted, its pause was resumed;
 * - workflow.step_failed also for a step that could not start;
 * - workflow.canceled once the cancel ended the running steps, which send
 *   none of their own.
 * Every event carries the run's context (RunContext): the run's link, its
 * counts, what the run page says of the step, and the step itself. A
 * settled approval of a step refreshes the run's status posts
 * (WorkflowRunEvents::statusChanged()), also when no event says so.
 */
class WorkflowStepEventsTest extends DbTestCase
{
    use TeamScopeFixtures;
    use WorkflowEngineFixtures;
    use CancellationFixtures;
    use RestoresComponents;

    /** The base of every link (params.appBaseUrl). */
    private const BASE = 'https://ansilume.example.com';

    /** The channel of ThreadedTestChannel, which keeps a status post per run. */
    private const THREADED = 'threaded-test';

    /** The events of a step. */
    private const STEP_EVENTS = [
        NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED,
        NotificationTemplate::EVENT_WORKFLOW_STEP_SUCCEEDED,
        NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED,
        NotificationTemplate::EVENT_WORKFLOW_WAITING,
    ];

    private RecordingNotificationDispatcher $notifications;

    private User $launcher;

    private User $approver;

    /** @var list<array{0: string, 1: \Closure}> */
    private array $hooks = [];

    private bool $hadBase = false;

    private mixed $base = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = $this->recordNotifications();
        $this->hadBase = array_key_exists('appBaseUrl', \Yii::$app->params);
        $this->base = \Yii::$app->params['appBaseUrl'] ?? null;
        \Yii::$app->params['appBaseUrl'] = self::BASE;
        $this->launcher = $this->operator('wf-events-launcher');
        $this->approver = $this->operator('wf-events-approver');
    }

    protected function tearDown(): void
    {
        foreach ($this->hooks as [$event, $hook]) {
            Event::off(WorkflowJobStep::class, $event, $hook);
        }
        $this->hooks = [];
        $this->restoreComponents();
        $this->restoreNotifications();
        if ($this->hadBase) {
            \Yii::$app->params['appBaseUrl'] = $this->base;
        } else {
            unset(\Yii::$app->params['appBaseUrl']);
        }
        parent::tearDown();
    }

    /**
     * A job step starts once its job was launched, while it runs: its job
     * waits for a runner, as the run page says. It succeeds with its job,
     * the next step starts, and the run's end names its last step.
     */
    public function testAJobStepFiresStartedThenSucceeded(): void
    {
        $template = $this->openTemplate((int)$this->launcher->id);
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);
        $deploy = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$template->id);
        $verify = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$template->id);

        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);

        $first = $this->executionOfStep($run, $deploy);
        $firstJob = $this->jobOfStep($run, $deploy);
        $this->assertSame(
            [NotificationTemplate::EVENT_WORKFLOW_LAUNCHED, NotificationTemplate::EVENT_JOB_LAUNCHED, NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED],
            $this->eventNames()
        );
        $started = $this->lastPayloadOf(NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED);
        $this->assertSame([
            'id' => (string)$run->id,
            'status' => WorkflowJob::STATUS_RUNNING,
            'template_id' => (string)$workflow->id,
            'template_name' => (string)$workflow->name,
            'url' => self::BASE . '/workflow-job/view?id=' . $run->id,
            'steps_done' => '0',
            'steps_total' => '2',
            'reason' => 'Job #' . $firstJob->id . ' of step "step-0" is queued: it waits for a runner.',
        ], $started['workflow'] ?? null);
        $this->assertSame([
            'id' => (string)$first->id,
            'job_id' => (string)$firstJob->id,
            'execution_id' => (string)$first->id,
            'workflow_step_id' => (string)$deploy->id,
            'position' => '1',
            'name' => 'step-0',
            'type' => 'job',
            'waiting_for' => 'runner',
        ], $started['step'] ?? null);
        $this->assertArrayNotHasKey('approval', $started, 'no approval request, no link to one');
        $this->assertSame(
            [['workflow_job_id' => (int)$run->id, 'job_id' => (int)$firstJob->id]],
            $this->notifications->refsOf(NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED)
        );

        $mark = count($this->notifications->events);
        $this->finishJob($firstJob);

        $second = $this->executionOfStep($run, $verify);
        $this->assertSame(
            [NotificationTemplate::EVENT_WORKFLOW_STEP_SUCCEEDED, NotificationTemplate::EVENT_JOB_LAUNCHED, NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED],
            $this->eventNames($mark)
        );
        $succeeded = $this->lastPayloadOf(NotificationTemplate::EVENT_WORKFLOW_STEP_SUCCEEDED);
        $this->assertSame(
            ['execution_id' => (string)$first->id, 'position' => '1', 'waiting_for' => ''],
            $this->only($succeeded['step'] ?? [], ['execution_id', 'position', 'waiting_for'])
        );
        $this->assertSame(['steps_done' => '1', 'reason' => ''], $this->only($succeeded['workflow'] ?? [], ['steps_done', 'reason']));
        $this->assertSame(
            [['workflow_job_id' => (int)$run->id, 'job_id' => (int)$firstJob->id]],
            $this->notifications->refsOf(NotificationTemplate::EVENT_WORKFLOW_STEP_SUCCEEDED)
        );
        $this->assertSame(
            ['execution_id' => (string)$second->id, 'position' => '2', 'name' => 'step-1'],
            $this->only($this->lastPayloadOf(NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED)['step'] ?? [], ['execution_id', 'position', 'name'])
        );

        $mark = count($this->notifications->events);
        $this->finishJob($this->jobOfStep($run, $verify));

        $this->assertSame(
            [NotificationTemplate::EVENT_WORKFLOW_STEP_SUCCEEDED, NotificationTemplate::EVENT_WORKFLOW_SUCCEEDED],
            $this->eventNames($mark)
        );
        $end = $this->lastPayloadOf(NotificationTemplate::EVENT_WORKFLOW_SUCCEEDED);
        $this->assertSame(
            ['status' => WorkflowJob::STATUS_SUCCEEDED, 'steps_done' => '2', 'steps_total' => '2', 'reason' => ''],
            $this->only($end['workflow'] ?? [], ['status', 'steps_done', 'steps_total', 'reason'])
        );
        $this->assertSame((string)$second->id, $this->partOf($end, 'step')['execution_id'] ?? null, 'the run\'s end names its last step');
        $this->assertSame([['workflow_job_id' => (int)$run->id]], $this->notifications->refsOf(NotificationTemplate::EVENT_WORKFLOW_SUCCEEDED));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function gateProvider(): array
    {
        return [
            'an approval step' => [WorkflowStep::TYPE_APPROVAL],
            'a gated job step' => [WorkflowStep::TYPE_JOB],
        ];
    }

    /**
     * A step that waits for an approval starts once its request was opened
     * and announced, and then waits; both say what the run page's banner
     * says and link the request. Approved, the approval step succeeds and
     * the next one starts; the gated job step's job is queued, and the step
     * runs on with it.
     *
     * @dataProvider gateProvider
     */
    public function testAnApprovalStepFiresStartedThenWaitingForApproval(string $stepType): void
    {
        $rule = $this->usersRuleOf((int)$this->launcher->id, (int)$this->approver->id);
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);
        $gate = $stepType === WorkflowStep::TYPE_APPROVAL
            ? $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id)
            : $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$this->gatedTemplate((int)$this->launcher->id, $rule)->id);
        $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_PAUSE);

        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);

        $job = $this->jobOfStep($run, $gate);
        $request = $this->requestOfJob($job);
        $this->assertSame([
            NotificationTemplate::EVENT_WORKFLOW_LAUNCHED,
            NotificationTemplate::EVENT_APPROVAL_REQUESTED,
            NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED,
            NotificationTemplate::EVENT_WORKFLOW_WAITING,
        ], $this->eventNames());
        $reason = $stepType === WorkflowStep::TYPE_APPROVAL
            ? 'Waiting for approval at step "step-0".'
            : 'Job #' . $job->id . ' of step "step-0" waits for approval.';
        foreach ([NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED, NotificationTemplate::EVENT_WORKFLOW_WAITING] as $event) {
            $payload = $this->lastPayloadOf($event);
            $this->assertSame(
                ['job_id' => (string)$job->id, 'type' => $stepType, 'waiting_for' => 'approval'],
                $this->only($payload['step'] ?? [], ['job_id', 'type', 'waiting_for']),
                $event
            );
            $this->assertSame($reason, $this->partOf($payload, 'workflow')['reason'] ?? null, $event);
            $this->assertSame(['url' => self::BASE . '/approval/view?id=' . $request->id], $payload['approval'] ?? null, $event);
            $this->assertSame(
                [['workflow_job_id' => (int)$run->id, 'job_id' => (int)$job->id, 'approval_request_id' => (int)$request->id]],
                $this->notifications->refsOf($event),
                $event
            );
        }

        $mark = count($this->notifications->events);
        $this->approvalService()->recordDecision($request, (int)$this->approver->id, ApprovalDecision::DECISION_APPROVED);

        $this->assertSame(
            $stepType === WorkflowStep::TYPE_APPROVAL
                ? [
                    NotificationTemplate::EVENT_APPROVAL_APPROVED,
                    NotificationTemplate::EVENT_WORKFLOW_STEP_SUCCEEDED,
                    NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED,
                    NotificationTemplate::EVENT_WORKFLOW_WAITING,
                ]
                : [NotificationTemplate::EVENT_APPROVAL_APPROVED],
            $this->eventNames($mark)
        );
    }

    /**
     * A pause step starts and waits to be resumed; resumed, it succeeds,
     * and the next step starts.
     */
    public function testAPauseStepFiresWaitingForResumeAndSucceededOnResume(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);
        $hold = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$this->openTemplate((int)$this->launcher->id)->id);

        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);

        $execution = $this->executionOfStep($run, $hold);
        $this->assertSame(
            [NotificationTemplate::EVENT_WORKFLOW_LAUNCHED, NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED, NotificationTemplate::EVENT_WORKFLOW_WAITING],
            $this->eventNames()
        );
        $waiting = $this->lastPayloadOf(NotificationTemplate::EVENT_WORKFLOW_WAITING);
        $this->assertSame([
            'id' => (string)$execution->id,
            'job_id' => '',
            'execution_id' => (string)$execution->id,
            'workflow_step_id' => (string)$hold->id,
            'position' => '1',
            'name' => 'step-0',
            'type' => 'pause',
            'waiting_for' => 'resume',
        ], $waiting['step'] ?? null);
        $this->assertSame('Paused at step "step-0" until someone resumes the run.', $this->partOf($waiting, 'workflow')['reason'] ?? null);
        $this->assertArrayNotHasKey('approval', $waiting);
        $this->assertSame([['workflow_job_id' => (int)$run->id]], $this->notifications->refsOf(NotificationTemplate::EVENT_WORKFLOW_WAITING));

        $mark = count($this->notifications->events);
        $this->engine()->resume($run, (int)$this->launcher->id);

        $this->assertSame(
            [NotificationTemplate::EVENT_WORKFLOW_STEP_SUCCEEDED, NotificationTemplate::EVENT_JOB_LAUNCHED, NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED],
            $this->eventNames($mark)
        );
        $succeeded = $this->lastPayloadOf(NotificationTemplate::EVENT_WORKFLOW_STEP_SUCCEEDED);
        $this->assertSame(
            ['execution_id' => (string)$execution->id, 'waiting_for' => ''],
            $this->only($succeeded['step'] ?? [], ['execution_id', 'waiting_for'])
        );
        $this->assertSame(['steps_done' => '1', 'reason' => ''], $this->only($succeeded['workflow'] ?? [], ['steps_done', 'reason']));
        $this->assertSame([['workflow_job_id' => (int)$run->id]], $this->notifications->refsOf(NotificationTemplate::EVENT_WORKFLOW_STEP_SUCCEEDED));
    }

    /**
     * A step that could not start (here a step type the model refuses,
     * which older versions saved) fails, and with it the run: it says why,
     * as its row on the run page does.
     */
    public function testAStepThatCouldNotStartFiresStepFailed(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);
        $odd = $this->createWorkflowStep((int)$workflow->id, 0, 'bogus');
        $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_PAUSE);

        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);

        $execution = $this->executionOfStep($run, $odd);
        $this->assertSame(
            [NotificationTemplate::EVENT_WORKFLOW_LAUNCHED, NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED, NotificationTemplate::EVENT_WORKFLOW_FAILED],
            $this->eventNames()
        );
        $failed = $this->lastPayloadOf(NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED);
        $this->assertSame([
            'id' => (string)$execution->id,
            'job_id' => '',
            'execution_id' => (string)$execution->id,
            'workflow_step_id' => (string)$odd->id,
            'position' => '1',
            'name' => 'step-0',
            'type' => 'bogus',
            'waiting_for' => '',
        ], $failed['step'] ?? null);
        $this->assertSame(
            ['steps_done' => '1', 'steps_total' => '2', 'reason' => 'Not run: unknown step type "bogus".'],
            $this->only($failed['workflow'] ?? [], ['steps_done', 'steps_total', 'reason'])
        );
        $this->assertSame([['workflow_job_id' => (int)$run->id]], $this->notifications->refsOf(NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED));
        $end = $this->lastPayloadOf(NotificationTemplate::EVENT_WORKFLOW_FAILED);
        $this->assertSame(
            ['status' => WorkflowJob::STATUS_FAILED, 'reason' => 'Not run: unknown step type "bogus".'],
            $this->only($end['workflow'] ?? [], ['status', 'reason'])
        );
    }

    /**
     * workflow.canceled is sent once the cancel ended the running steps, so
     * what it says of the run is final: the step it ended, with the cancel's
     * message. That step sends no workflow.step_failed of its own, and its
     * placeholder no job.canceled.
     */
    public function testCanceledComesAfterTheStepsEnded(): void
    {
        $flow = $this->runAtApprovalStep();
        $mark = count($this->notifications->events);

        $this->runCancellation()->cancelRun($flow['run'], (int)$flow['launcher']->id, JobCancellationService::REASON_OPERATOR);

        $execution = $this->executionOf($flow['run'], $flow['approval']);
        $this->assertNotNull($execution);
        $this->assertSame([NotificationTemplate::EVENT_WORKFLOW_CANCELED], $this->eventNames($mark));
        $canceled = $this->lastPayloadOf(NotificationTemplate::EVENT_WORKFLOW_CANCELED);
        $this->assertSame(
            ['status' => WorkflowJob::STATUS_CANCELED, 'steps_done' => '1', 'steps_total' => '3', 'reason' => 'Not finished: the run was canceled.'],
            $this->only($canceled['workflow'] ?? [], ['status', 'steps_done', 'steps_total', 'reason'])
        );
        $this->assertSame(
            ['execution_id' => (string)$execution->id, 'waiting_for' => ''],
            $this->only($canceled['step'] ?? [], ['execution_id', 'waiting_for'])
        );
    }

    /**
     * Review focus 1: a run that loops runs one step again and again; each
     * execution starts once and ends once, whatever reads the run's state in
     * between (the run page's poll) and however often a runner reports a
     * job of an earlier pass.
     */
    public function testALoopPassFiresStepEventsOncePerExecution(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);
        $loop = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$this->openTemplate((int)$this->launcher->id)->id);
        $this->branchTo($loop, 'on_success_step_id', (int)$loop->id);

        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);
        $firstJob = $this->jobOfStep($run, $loop);
        $this->pollTheRun($run);
        $this->finishJob($firstJob);
        $secondJob = $this->jobOfStep($run, $loop);
        $this->pollTheRun($run);
        $this->finishJob($firstJob);
        $this->finishJob($secondJob, Job::STATUS_FAILED);
        $this->pollTheRun($run);

        $executions = WorkflowJobStep::find()->where(['workflow_job_id' => $run->id])->orderBy(['id' => SORT_ASC])->all();
        $this->assertCount(2, $executions);
        $this->assertNotSame((int)$firstJob->id, (int)$secondJob->id, 'the second pass ran a job of its own');
        $this->assertSame([
            (int)$executions[0]->id => [NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED, NotificationTemplate::EVENT_WORKFLOW_STEP_SUCCEEDED],
            (int)$executions[1]->id => [NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED, NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED],
        ], $this->stepEventsByExecution());
        $this->assertSame([
            NotificationTemplate::EVENT_WORKFLOW_LAUNCHED,
            NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED,
            NotificationTemplate::EVENT_WORKFLOW_STEP_SUCCEEDED,
            NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED,
            NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED,
            NotificationTemplate::EVENT_WORKFLOW_FAILED,
        ], $this->workflowEventNames());
        $this->assertSame(
            ['position' => '1', 'steps_done' => '1'],
            [
                'position' => $this->partOf($this->lastPayloadOf(NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED), 'step')['position'] ?? null,
                'steps_done' => $this->partOf($this->lastPayloadOf(NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED), 'workflow')['steps_done'] ?? null,
            ],
            'a step counts once, however often the loop ran it'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function raceProvider(): array
    {
        return [
            'two reports of the same job' => ['report'],
            'two resumes of the same pause' => ['resume'],
        ];
    }

    /**
     * Two callers move the same step at once: the one whose conditional
     * update moved it announces it and moves the run on; the other, whose
     * update found the step moved already, fires nothing, and a run end
     * that comes after the run ended fires nothing either.
     *
     * @dataProvider raceProvider
     */
    public function testARefusedTransitionFiresNothing(string $race): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);
        $first = $race === 'report'
            ? $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$this->openTemplate((int)$this->launcher->id)->id)
            : $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $next = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_PAUSE);
        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);
        $execution = $this->executionOfStep($run, $first);
        $job = $race === 'report' ? $this->jobOfStep($run, $first) : null;
        $other = $job !== null
            ? function () use ($job): void {
                $this->engine()->onChildJobCompleted($job);
            }
            : function () use ($run): void {
                $this->engine()->resume($run, (int)$this->launcher->id);
            };
        $this->whenTheStepIsRead($execution, $other);
        $mark = count($this->notifications->events);

        $refused = null;
        try {
            if ($job !== null) {
                $this->finishJob($job);
            } else {
                $this->engine()->resume($run, (int)$this->launcher->id);
            }
        } catch (\RuntimeException $e) {
            $refused = $e->getMessage();
        }

        $this->assertSame($race === 'resume' ? 'No paused step to resume.' : null, $refused, 'the resume that came second is refused');
        $this->assertSame([
            NotificationTemplate::EVENT_WORKFLOW_STEP_SUCCEEDED,
            NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED,
            NotificationTemplate::EVENT_WORKFLOW_WAITING,
        ], $this->eventNames($mark), 'one success, and the next step starts once');
        $this->assertCount(1, WorkflowJobStep::find()->where(['workflow_job_id' => $run->id, 'workflow_step_id' => $next->id])->all());

        $this->runCancellation()->cancelRun($run, (int)$this->launcher->id, JobCancellationService::REASON_OPERATOR);
        $mark = count($this->notifications->events);
        $this->assertFalse($this->engine()->completeWorkflow($run, WorkflowJob::STATUS_FAILED));
        $this->assertSame([], $this->eventNames($mark), 'the run had ended');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function canceledStartProvider(): array
    {
        return [
            'a job step, the run canceled before its execution was written' => [WorkflowStep::TYPE_JOB, BaseActiveRecord::EVENT_BEFORE_INSERT],
            'a step that cannot start, the run canceled once it was written' => ['bogus', BaseActiveRecord::EVENT_AFTER_INSERT],
        ];
    }

    /**
     * A step that starts while its run is canceled is ended by the cancel,
     * or by the engine once it started (WorkflowStepDispatcher::settleStart());
     * either way it sends neither workflow.step_started nor
     * workflow.step_failed (the run's workflow.canceled covers it), and the
     * run, canceled, sends no workflow.failed.
     *
     * @dataProvider canceledStartProvider
     */
    public function testAStepThatStartsInACanceledRunFiresNoStepEvent(string $stepType, string $cancelAt): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);
        $step = $stepType === WorkflowStep::TYPE_JOB
            ? $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$this->openTemplate((int)$this->launcher->id)->id)
            : $this->createWorkflowStep((int)$workflow->id, 0, $stepType);
        $this->cancelWhenTheStepIsWritten($step, $cancelAt);

        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);

        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $this->executionOfStep($run, $step)->status);
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $run->status);
        $this->assertSame(
            [NotificationTemplate::EVENT_WORKFLOW_LAUNCHED, NotificationTemplate::EVENT_WORKFLOW_CANCELED],
            $this->workflowEventNames()
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function settlementProvider(): array
    {
        return [
            'approved by its timeout, which sends no event' => ['timeout'],
            'rejected by a vote' => ['vote'],
        ];
    }

    /**
     * Spec answer 2: a settled approval of a workflow step changes what the
     * run's status post shows (it no longer waits for approval), also when
     * no event says so: a timeout that approves the job of a gated job step
     * queues it, and no event goes out. Every template whose status post of
     * the run exists, or is on its way, gets a status delivery of the run,
     * whatever events and level it has, and one for each event of the run
     * that the settle causes; a template without a post, one whose channel
     * keeps none, and one whose identity may not see the run get none.
     *
     * @dataProvider settlementProvider
     */
    public function testSettlingAWorkflowApprovalRefreshesTheStatus(string $settlement): void
    {
        $queue = $this->useRealDispatcher();
        $admin = $this->createUserWithRole('wf-status-admin', 'admin');
        $rule = $this->usersRuleOf((int)$this->launcher->id, (int)$this->approver->id);
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);
        $gate = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$this->gatedTemplate((int)$this->launcher->id, $rule)->id);
        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);
        $request = $this->requestOfJob($this->jobOfStep($run, $gate));
        $posted = $this->threadedTemplate($admin);
        $this->postOf($posted, $run);
        $onItsWay = $this->threadedTemplate($admin);
        $creation = $this->statusDeliveryOf($onItsWay, $run);
        $this->threadedTemplate($admin);
        $email = $this->createNotificationTemplate((int)$admin->id, NotificationTemplate::CHANNEL_EMAIL, 'project.sync_failed');
        $this->postOf($email, $run);
        $inactive = $this->createUserWithRole('wf-status-inactive', 'admin');
        User::updateAll(['status' => User::STATUS_INACTIVE], ['id' => $inactive->id]);
        $this->postOf($this->threadedTemplate($inactive), $run);
        $queue->pushes = [];

        if ($settlement === 'timeout') {
            ApprovalRule::updateAll(['timeout_minutes' => 5, 'timeout_action' => ApprovalRule::TIMEOUT_ACTION_APPROVE], ['id' => $rule->id]);
            ApprovalRequest::updateAll(['expires_at' => time() - 60], ['id' => $request->id]);
            $this->assertSame(1, $this->approvalService()->processTimeouts());
        } else {
            $this->approvalService()->recordDecision($request, (int)$this->approver->id, ApprovalDecision::DECISION_REJECTED);
        }

        /** @var list<NotificationDelivery> $statuses */
        $statuses = NotificationDelivery::find()
            ->where(['workflow_job_id' => $run->id, 'kind' => NotificationDelivery::KIND_STATUS])
            ->andWhere(['<>', 'id', $creation->id])
            ->orderBy(['id' => SORT_ASC])
            ->all();
        $refreshes = array_values(array_filter($statuses, static fn (NotificationDelivery $d): bool => $d->event === NotificationDelivery::EVENT_STATUS_REFRESH));
        $this->assertSame(
            [(int)$posted->id, (int)$onItsWay->id],
            array_map(static fn (NotificationDelivery $d): int => (int)$d->notification_template_id, $refreshes)
        );
        foreach ($refreshes as $refresh) {
            $this->assertSame(NotificationDelivery::STATUS_PENDING, $refresh->status);
            $this->assertSame(['workflow_job_id' => (int)$run->id], $refresh->getRefs());
        }
        // Spec answer 1: the events of the run that a vote causes (the
        // request's rejection, the step's failure, the run's end) refresh the
        // same posts too, whatever the templates' events; a timeout causes none.
        $byEvent = [];
        foreach ($statuses as $status) {
            if ($status->event !== NotificationDelivery::EVENT_STATUS_REFRESH) {
                $byEvent[(string)$status->event][] = (int)$status->notification_template_id;
            }
        }
        $events = $settlement === 'timeout' ? [] : [NotificationTemplate::EVENT_APPROVAL_REJECTED, NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED, NotificationTemplate::EVENT_WORKFLOW_FAILED];
        $this->assertEqualsCanonicalizing($events, array_keys($byEvent));
        foreach ($byEvent as $event => $templates) {
            $this->assertSame([(int)$posted->id, (int)$onItsWay->id], $templates, $event);
        }
        $this->assertSame(
            array_map(static fn (NotificationDelivery $d): int => (int)$d->id, $statuses),
            $queue->pushedIds(),
            'each is pushed to the notification queue, and nothing else is'
        );
    }

    /**
     * The job of a step and the approval of a step name the run and the
     * step they belong to, also before the step recorded its job (a launch
     * announces its job first); a job and an approval outside a run name
     * none. Every approval event links its request.
     */
    public function testTheEventsOfAStepsJobAndApprovalCarryTheRunContext(): void
    {
        $rule = $this->usersRuleOf((int)$this->launcher->id, (int)$this->approver->id);
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);
        $deploy = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$this->openTemplate((int)$this->launcher->id)->id);
        $gate = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$this->gatedTemplate((int)$this->launcher->id, $rule)->id);
        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);
        $deployJob = $this->jobOfStep($run, $deploy);
        $this->finishJob($deployJob);
        $gatedJob = $this->jobOfStep($run, $gate);
        $request = $this->requestOfJob($gatedJob);

        $launched = $this->notifications->payloadsOf(NotificationTemplate::EVENT_JOB_LAUNCHED)[0];
        $this->assertSame(
            ['id' => (string)$run->id, 'template_name' => (string)$workflow->name, 'url' => self::BASE . '/workflow-job/view?id=' . $run->id],
            $this->only($launched['workflow'] ?? [], ['id', 'template_name', 'url'])
        );
        $this->assertSame([
            'id' => (string)$this->executionOfStep($run, $deploy)->id,
            'job_id' => (string)$deployJob->id,
            'execution_id' => (string)$this->executionOfStep($run, $deploy)->id,
            'workflow_step_id' => (string)$deploy->id,
            'position' => '1',
            'name' => 'step-0',
            'type' => 'job',
            'waiting_for' => 'runner',
        ], $launched['step'] ?? null);
        $requested = $this->lastPayloadOf(NotificationTemplate::EVENT_APPROVAL_REQUESTED);
        $this->assertSame(
            ['execution_id' => (string)$this->executionOfStep($run, $gate)->id, 'job_id' => (string)$gatedJob->id, 'position' => '2', 'waiting_for' => 'approval'],
            $this->only($requested['step'] ?? [], ['execution_id', 'job_id', 'position', 'waiting_for'])
        );
        $this->assertSame(
            ['id' => (string)$run->id, 'steps_done' => '1', 'steps_total' => '2'],
            $this->only($requested['workflow'] ?? [], ['id', 'steps_done', 'steps_total'])
        );
        $this->assertSame(self::BASE . '/approval/view?id=' . $request->id, $this->partOf($requested, 'approval')['url'] ?? null);

        $loneJob = $this->jobLaunchService()->launch($this->gatedTemplate((int)$this->launcher->id, $rule), (int)$this->launcher->id);
        $loneRequest = $this->requestOfJob($loneJob);
        $lonePayload = $this->lastPayloadOf(NotificationTemplate::EVENT_APPROVAL_REQUESTED);
        $this->assertArrayNotHasKey('workflow', $lonePayload);
        $this->assertArrayNotHasKey('step', $lonePayload);
        $this->assertSame(self::BASE . '/approval/view?id=' . $loneRequest->id, $this->partOf($lonePayload, 'approval')['url'] ?? null);
    }

    // -- Helpers ------------------------------------------------------------------------

    /**
     * The events dispatched from index $from on.
     *
     * @return list<string>
     */
    private function eventNames(int $from = 0): array
    {
        return array_column(array_slice($this->notifications->events, $from), 'event');
    }

    /**
     * The workflow events dispatched, in order.
     *
     * @return list<string>
     */
    private function workflowEventNames(): array
    {
        return array_values(array_filter($this->eventNames(), static fn (string $event): bool => str_starts_with($event, 'workflow.')));
    }

    /**
     * The step events of each execution, in order: execution ID => events.
     *
     * @return array<int, list<string>>
     */
    private function stepEventsByExecution(): array
    {
        $byExecution = [];
        foreach ($this->notifications->events as $entry) {
            if (!in_array($entry['event'], self::STEP_EVENTS, true)) {
                continue;
            }
            $step = $entry['payload']['step'] ?? [];
            $byExecution[(int)(is_array($step) ? ($step['execution_id'] ?? 0) : 0)][] = $entry['event'];
        }

        return $byExecution;
    }

    /**
     * The payload of the last $event dispatched.
     *
     * @return array<string, mixed>
     */
    private function lastPayloadOf(string $event): array
    {
        $payloads = $this->notifications->payloadsOf($event);
        $this->assertNotSame([], $payloads, "{$event} was dispatched");

        return $payloads[count($payloads) - 1];
    }

    /**
     * The part $name of $payload (workflow, step, approval), which must be
     * there.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function partOf(array $payload, string $name): array
    {
        $part = $payload[$name] ?? null;
        $this->assertIsArray($part, "the payload has {$name}");

        return $part;
    }

    /**
     * The entries of $values under $keys, in the order of $keys.
     *
     * @param mixed $values
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private function only(mixed $values, array $keys): array
    {
        $this->assertIsArray($values);
        $picked = [];
        foreach ($keys as $key) {
            $picked[$key] = $values[$key] ?? null;
        }

        return $picked;
    }

    /**
     * What the run page's poll reads of $run, as its launcher sees it.
     */
    private function pollTheRun(WorkflowJob $run): void
    {
        /** @var WorkflowRunStates $states */
        $states = \Yii::$app->get('workflowRunStates');
        $states->forRun(WorkflowJob::findOne($run->id) ?? $run, Viewer::of((int)$this->launcher->id), true);
    }

    /**
     * $other runs once, when the engine reads $execution while it still
     * runs: another caller that moves the step first.
     */
    private function whenTheStepIsRead(WorkflowJobStep $execution, \Closure $other): void
    {
        $id = (int)$execution->id;
        $fired = false;
        $hook = static function (Event $e) use ($id, $other, &$fired): void {
            $sender = $e->sender;
            if ($fired || !$sender instanceof WorkflowJobStep || (int)$sender->id !== $id || $sender->status !== WorkflowJobStep::STATUS_RUNNING) {
                return;
            }
            $fired = true;
            $other();
        };
        Event::on(WorkflowJobStep::class, BaseActiveRecord::EVENT_AFTER_FIND, $hook);
        $this->hooks[] = [BaseActiveRecord::EVENT_AFTER_FIND, $hook];
    }

    /**
     * The launcher cancels the run when the engine writes the execution of
     * $step ($event: before or after the insert).
     */
    private function cancelWhenTheStepIsWritten(WorkflowStep $step, string $event): void
    {
        $stepId = (int)$step->id;
        $userId = (int)$this->launcher->id;
        $fired = false;
        $hook = function (Event $e) use ($stepId, $userId, &$fired): void {
            $sender = $e->sender;
            if ($fired || !$sender instanceof WorkflowJobStep || (int)$sender->workflow_step_id !== $stepId) {
                return;
            }
            $fired = true;
            $run = WorkflowJob::findOne((int)$sender->workflow_job_id);
            $this->assertNotNull($run);
            $this->runCancellation()->cancelRun($run, $userId, JobCancellationService::REASON_OPERATOR);
        };
        Event::on(WorkflowJobStep::class, $event, $hook);
        $this->hooks[] = [$event, $hook];
    }

    /**
     * The real dispatcher, with ThreadedTestChannel for THREADED, and a
     * notification queue that records what is pushed.
     */
    private function useRealDispatcher(): RecordingNotificationQueue
    {
        $queue = new RecordingNotificationQueue();
        $this->swapComponent('notificationQueue', $queue);
        $dispatcher = new NotificationDispatcher();
        $dispatcher->setChannel(self::THREADED, new ThreadedTestChannel());
        $this->swapComponent('notificationDispatcher', $dispatcher);
        $this->swapComponent('notificationDeliveryService', ['class' => NotificationDeliveryService::class]);

        return $queue;
    }

    /**
     * A template of THREADED that delivers as $identity and listens to an
     * event no run sends.
     */
    private function threadedTemplate(User $identity): NotificationTemplate
    {
        return $this->createNotificationTemplate((int)$identity->id, self::THREADED, 'project.sync_failed', '{}');
    }

    /** $template's status post of $run. */
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

    /** A status delivery of $run to $template that is not sent yet: its post is on its way. */
    private function statusDeliveryOf(NotificationTemplate $template, WorkflowJob $run): NotificationDelivery
    {
        $delivery = new NotificationDelivery();
        $delivery->notification_template_id = (int)$template->id;
        $delivery->event = NotificationTemplate::EVENT_WORKFLOW_LAUNCHED;
        $delivery->kind = NotificationDelivery::KIND_STATUS;
        $delivery->status = NotificationDelivery::STATUS_PENDING;
        $delivery->setVariableMap([]);
        $delivery->setRefs(['workflow_job_id' => (int)$run->id]);
        $delivery->next_attempt_at = time() + 600;
        $this->assertTrue($delivery->save(false));

        return $delivery;
    }

    private function jobLaunchService(): JobLaunchService
    {
        /** @var JobLaunchService $service */
        $service = \Yii::$app->get('jobLaunchService');

        return $service;
    }
}
