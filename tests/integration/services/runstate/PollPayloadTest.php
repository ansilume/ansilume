<?php

declare(strict_types=1);

namespace app\tests\integration\services\runstate;

use app\models\ApprovalRequest;
use app\models\Job;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\runstate\ApprovalWait;
use app\services\runstate\Banner;
use app\services\runstate\Display;
use app\services\runstate\PollPayload;
use app\services\runstate\RunState;
use app\services\runstate\StepState;
use app\services\runstate\Texts;
use app\services\runstate\Viewer;
use app\services\WorkflowCancellationService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\CountsQueries;
use app\tests\integration\TeamScopeFixtures;

/**
 * The run page's data, as the page renders it and the poll answers it: the
 * stored statuses stay, the display states are added, and the actions say
 * what the viewer can do now.
 */
class PollPayloadTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use RunStateFixtures;
    use CountsQueries;

    private int $now = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = (int)mktime(10, 0, 0, 10, 9, 2025);
    }

    /**
     * status, status_label and status_css keep the stored values; state,
     * state_label and state_css are the display state, which the badge
     * shows.
     */
    public function testTheRunKeepsItsStoredStatusAndAddsItsDisplayState(): void
    {
        $step = $this->step(1, WorkflowStep::TYPE_APPROVAL, Display::STATE_AWAITING_APPROVAL, approval: $this->wait(9));
        $payload = $this->payload($this->runOf(), $this->state(Display::STATE_AWAITING_APPROVAL, $step, [$step]));

        $this->assertSame([
            'id' => 41,
            'status' => 'running',
            'status_label' => 'Running',
            'status_css' => 'primary',
            'state' => 'awaiting_approval',
            'state_label' => 'Awaiting Approval',
            'state_css' => 'info',
            'waiting_for' => 'approval',
            'is_finished' => false,
            'started_at' => $this->now - 3600,
            'started_label' => date('H:i:s', $this->now - 3600),
            'finished_at' => null,
            'finished_label' => null,
            'current_step_id' => null,
            'current_execution_id' => null,
        ], array_diff_key($payload, ['banner' => true, 'actions' => true, 'steps' => true]));
        $this->assertSame(Banner::of($this->state(Display::STATE_AWAITING_APPROVAL, $step, [$step]), $this->now), $payload['banner']);
    }

    public function testEachStepKeepsItsStoredStatusAndAddsItsStateJobAndRequest(): void
    {
        $step = $this->step(1, WorkflowStep::TYPE_JOB, Display::STATE_AWAITING_APPROVAL, approval: $this->wait(9), jobId: 77);

        $row = $this->payload($this->runOf(), $this->state(Display::STATE_AWAITING_APPROVAL, $step, [$step]))['steps'][0];

        $this->assertSame([
            'id' => 1,
            'workflow_step_id' => 3,
            'step_name' => 'step <1>',
            'step_type' => 'job',
            'step_type_label' => 'Job',
            'step_index' => 1,
            'job_id' => 77,
            'show_job_link' => true,
            'job_status' => Job::STATUS_PENDING_APPROVAL,
            'status' => 'running',
            'status_label' => 'Running',
            'status_css' => 'primary',
            'state' => 'awaiting_approval',
            'state_label' => 'Awaiting Approval',
            'state_css' => 'info',
            'waiting_for' => 'approval',
            'approval' => ['id' => 9, 'label' => 'Request #9', 'status' => 'pending', 'status_label' => 'Pending', 'url' => '/approval/view?id=9'],
            'decision_note' => null,
            'started_at' => $this->now - 600,
            'started_label' => date('H:i:s', $this->now - 600),
            'finished_at' => null,
            'finished_label' => null,
            'duration_seconds' => 600,
            'duration_label' => 'waiting 10m 00s',
            'is_current' => false,
            'error_message' => null,
        ], $row);
    }

    /**
     * The job of an approval step is the placeholder that holds its request:
     * the page links the request, not the job. A request is linked only for
     * a viewer who may open its page.
     */
    public function testAnApprovalStepLinksItsRequestOnlyForAViewerWhoMayOpenIt(): void
    {
        $linked = $this->step(1, WorkflowStep::TYPE_APPROVAL, Display::STATE_SUCCEEDED, approval: $this->wait(9, ApprovalRequest::STATUS_APPROVED), finishedAt: $this->now);
        $unlinked = $this->step(2, WorkflowStep::TYPE_APPROVAL, Display::STATE_SUCCEEDED, approval: $this->wait(10, ApprovalRequest::STATUS_APPROVED, false), finishedAt: $this->now);

        $steps = $this->payload($this->runOf(WorkflowJob::STATUS_SUCCEEDED), $this->state(Display::STATE_SUCCEEDED, null, [$linked, $unlinked], WorkflowJob::STATUS_SUCCEEDED))['steps'];

        $this->assertSame([false, false], array_column($steps, 'show_job_link'));
        $this->assertSame(
            [
                ['id' => 9, 'label' => 'Request #9', 'status' => 'approved', 'status_label' => 'Approved', 'url' => '/approval/view?id=9'],
                ['id' => 10, 'label' => 'Request #10', 'status' => 'approved', 'status_label' => 'Approved', 'url' => null],
            ],
            array_column($steps, 'approval')
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool, 3: bool, 4: array{resume: bool, cancel: bool}}>
     */
    public static function actionData(): array
    {
        return [
            'paused, may both' => [WorkflowJob::STATUS_RUNNING, Display::STATE_PAUSED, true, true, ['resume' => true, 'cancel' => true]],
            'paused, may cancel only' => [WorkflowJob::STATUS_RUNNING, Display::STATE_PAUSED, false, true, ['resume' => false, 'cancel' => true]],
            'paused, may resume only' => [WorkflowJob::STATUS_RUNNING, Display::STATE_PAUSED, true, false, ['resume' => true, 'cancel' => false]],
            'awaiting approval' => [WorkflowJob::STATUS_RUNNING, Display::STATE_AWAITING_APPROVAL, true, true, ['resume' => false, 'cancel' => true]],
            'queued' => [WorkflowJob::STATUS_RUNNING, Display::STATE_QUEUED, true, true, ['resume' => false, 'cancel' => true]],
            'running' => [WorkflowJob::STATUS_RUNNING, Display::STATE_RUNNING, true, true, ['resume' => false, 'cancel' => true]],
            'running, may nothing' => [WorkflowJob::STATUS_RUNNING, Display::STATE_RUNNING, false, false, ['resume' => false, 'cancel' => false]],
            'succeeded' => [WorkflowJob::STATUS_SUCCEEDED, Display::STATE_SUCCEEDED, true, true, ['resume' => false, 'cancel' => false]],
            'canceled' => [WorkflowJob::STATUS_CANCELED, Display::STATE_CANCELED, true, true, ['resume' => false, 'cancel' => false]],
        ];
    }

    /**
     * Resume only while the run is paused, Cancel until it has finished, and
     * each only for a viewer who may use it.
     *
     * @dataProvider actionData
     * @param array{resume: bool, cancel: bool} $expected
     */
    public function testTheActionsSayWhatTheViewerCanDoNow(string $status, string $state, bool $mayResume, bool $mayCancel, array $expected): void
    {
        $finished = $status !== WorkflowJob::STATUS_RUNNING;
        $step = $this->step(1, WorkflowStep::TYPE_PAUSE, $finished ? $status : $state);
        $runState = $this->state($state, $finished ? null : $step, [$step], $status);

        $payload = (new PollPayload($mayResume, $mayCancel, $this->now))->of($this->runOf($status), $runState);

        $this->assertSame($expected, $payload['actions']);
        $this->assertSame($finished, $payload['is_finished']);
    }

    /**
     * The page keeps highlighting the newest execution of the run's current
     * step; a step that ran twice is two rows, keyed by execution.
     */
    public function testTheNewestExecutionOfTheCurrentStepIsCurrent(): void
    {
        $first = $this->step(1, WorkflowStep::TYPE_JOB, Display::STATE_FAILED, finishedAt: $this->now - 300);
        $second = $this->step(2, WorkflowStep::TYPE_JOB, Display::STATE_RUNNING);
        $other = new StepState(3, 99, 'other', WorkflowStep::TYPE_PAUSE, 'pending', 'pending', null, null, null, null, null, null, null, null, null, null);
        $run = $this->runOf();
        $run->current_step_id = 3;

        $payload = $this->payload($run, $this->state(Display::STATE_RUNNING, $second, [$first, $second, $other]));

        $this->assertSame(2, $payload['current_execution_id']);
        $this->assertSame(3, $payload['current_step_id']);
        $this->assertSame([false, true, false], array_column($payload['steps'], 'is_current'));
        $this->assertSame([1, 2, 3], array_column($payload['steps'], 'step_index'));
        $this->assertSame([1, 2, 3], array_column($payload['steps'], 'id'));
    }

    /**
     * Durations read in their two largest units, "waiting" while a step
     * waits and "running" while it runs; a step that never started has none.
     * (The run page's regression test for "133m 00s" is
     * WorkflowJobPageStateTest::testRegressionStepDurationsWereMinutesOnly.)
     */
    public function testDurationsReadInTheirTwoLargestUnits(): void
    {
        $finished = $this->step(1, WorkflowStep::TYPE_JOB, Display::STATE_SUCCEEDED, startedAt: $this->now - 9000, finishedAt: $this->now - 1020);
        $running = $this->step(2, WorkflowStep::TYPE_JOB, Display::STATE_RUNNING, startedAt: $this->now - 47);
        $paused = $this->step(3, WorkflowStep::TYPE_PAUSE, Display::STATE_PAUSED, startedAt: $this->now - 93600);
        $never = $this->step(4, WorkflowStep::TYPE_JOB, Display::STATE_PENDING, startedAt: null);
        $canceled = $this->step(5, WorkflowStep::TYPE_JOB, Display::STATE_CANCELED, startedAt: $this->now - 10, finishedAt: $this->now - 20);

        $steps = $this->payload($this->runOf(), $this->state(Display::STATE_PAUSED, $paused, [$finished, $running, $paused, $never, $canceled]))['steps'];

        $this->assertSame([7980, 47, 93600, null, 0], array_column($steps, 'duration_seconds'));
        $this->assertSame(['2h 13m', 'running 47s', 'waiting 1d 2h', null, '0s'], array_column($steps, 'duration_label'));
        $this->assertSame(['Succeeded', 'Running', 'Paused', 'Pending', 'Canceled'], array_column($steps, 'state_label'));
        $this->assertSame(['success', 'primary', 'warning', 'secondary', 'warning'], array_column($steps, 'state_css'));
    }

    /**
     * Labels are absolute times: the time of day today, with the date on
     * another day.
     */
    public function testTimesReadAsTheTimeOfDayAndOnAnotherDayWithTheDate(): void
    {
        $step = $this->step(1, WorkflowStep::TYPE_JOB, Display::STATE_SUCCEEDED, startedAt: $this->now - 86400 * 3, finishedAt: $this->now - 60);
        $run = $this->runOf(WorkflowJob::STATUS_SUCCEEDED);
        $run->started_at = $this->now - 86400 * 3;
        $run->finished_at = $this->now - 60;

        $payload = $this->payload($run, $this->state(Display::STATE_SUCCEEDED, null, [$step], WorkflowJob::STATUS_SUCCEEDED));

        $this->assertSame(date('Y-m-d H:i:s', $this->now - 86400 * 3), $payload['started_label']);
        $this->assertSame(date('H:i:s', $this->now - 60), $payload['finished_label']);
        $this->assertSame(date('Y-m-d H:i:s', $this->now - 86400 * 3), $payload['steps'][0]['started_label']);
        $this->assertSame(date('H:i:s', $this->now - 60), $payload['steps'][0]['finished_label']);
    }

    /**
     * A step is typed as it was when it ran; an unknown type reads as a
     * dash, a legacy type as it is stored.
     */
    public function testStepTypesReadAsTheyRan(): void
    {
        $steps = [];
        foreach ([WorkflowStep::TYPE_APPROVAL, WorkflowStep::TYPE_PAUSE, null, 'JOB'] as $index => $type) {
            $steps[] = new StepState($index + 1, null, 's', $type, 'failed', 'failed', null, null, null, null, null, null, null, 'why', null, 'note');
        }

        $rows = $this->payload($this->runOf(WorkflowJob::STATUS_FAILED), $this->state(Display::STATE_FAILED, null, $steps, WorkflowJob::STATUS_FAILED))['steps'];

        $this->assertSame(['Approval', 'Pause', PollPayload::NO_VALUE, 'JOB'], array_column($rows, 'step_type_label'));
        $this->assertSame([false, true, true, true], array_column($rows, 'show_job_link'));
        $this->assertSame(['why', 'why', 'why', 'why'], array_column($rows, 'error_message'));
        $this->assertSame(['note', 'note', 'note', 'note'], array_column($rows, 'decision_note'));
    }

    /**
     * Through the run states of real executions: a step that a run cancel
     * ended reads Canceled, its stored status stays failed, and the decision
     * of an approval step is told.
     */
    public function testARealRunOfAnApprovedStepAndAStepTheCancelEnded(): void
    {
        $admin = $this->createUserWithRole('poll-admin', 'admin');
        $rule = $this->ruleOf((int)$admin->id, [$admin]);
        $workflow = $this->createWorkflowTemplate((int)$admin->id);
        $gate = $this->namedStep($workflow, 10, 'approve', WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $build = $this->namedStep($workflow, 20, 'build', WorkflowStep::TYPE_JOB, (int)$this->openJobTemplate((int)$admin->id)->id);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$admin->id, WorkflowJob::STATUS_CANCELED);
        $placeholder = $this->jobOf(null, (int)$admin->id, Job::STATUS_SUCCEEDED);
        $request = $this->requestOf($placeholder, $rule, ApprovalRequest::STATUS_TIMED_OUT, 1760000150, 1760000160, 1760000170);
        $this->executionOf($run, $gate, WorkflowJobStep::STATUS_SUCCEEDED, $placeholder);
        $this->executionOf($run, $build, WorkflowJobStep::STATUS_FAILED, null, WorkflowCancellationService::STEP_RUN_CANCELED);

        $payload = (new PollPayload(true, true, $this->now))->of($run, $this->runStates()->forRun($run, Viewer::of((int)$admin->id), true));

        $this->assertSame(['canceled', 'Canceled', 'warning'], [$payload['status'], $payload['status_label'], $payload['status_css']]);
        $this->assertSame(['canceled', 'Canceled', 'warning'], [$payload['state'], $payload['state_label'], $payload['state_css']]);
        $this->assertNull($payload['banner']);
        $this->assertSame(['resume' => false, 'cancel' => false], $payload['actions']);
        $steps = $payload['steps'];
        $this->assertSame(['succeeded', 'failed'], array_column($steps, 'status'));
        $this->assertSame(['Succeeded', 'Failed'], array_column($steps, 'status_label'));
        $this->assertSame(['Succeeded', 'Canceled'], array_column($steps, 'state_label'));
        $this->assertSame(sprintf(Texts::DECIDED_TIMEOUT_APPROVED, date('Y-m-d H:i:s', 1760000170)), $steps[0]['decision_note']);
        $this->assertSame((int)$request->id, $steps[0]['approval']['id']);
        $this->assertSame([false, true], array_column($steps, 'show_job_link'));
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function payload(WorkflowJob $run, RunState $state): array
    {
        return (new PollPayload(true, true, $this->now))->of($run, $state);
    }

    private function runOf(string $status = WorkflowJob::STATUS_RUNNING): WorkflowJob
    {
        $run = new WorkflowJob();
        $run->id = 41;
        $run->workflow_template_id = 5;
        $run->status = $status;
        $run->launched_by = 1;
        $run->started_at = $this->now - 3600;

        return $run;
    }

    /**
     * @param list<StepState> $steps
     */
    private function state(string $state, ?StepState $current, array $steps, string $status = WorkflowJob::STATUS_RUNNING): RunState
    {
        return new RunState(41, $status, $state, Display::waitingFor($state), $current, $steps);
    }

    private function step(
        int $executionId,
        string $type,
        string $state,
        ?ApprovalWait $approval = null,
        ?int $jobId = null,
        ?int $startedAt = -1,
        ?int $finishedAt = null
    ): StepState {
        $finished = !in_array($state, [Display::STATE_RUNNING, Display::STATE_AWAITING_APPROVAL, Display::STATE_PAUSED, Display::STATE_QUEUED, Display::STATE_PENDING], true);

        return new StepState(
            executionId: $executionId,
            workflowStepId: 3,
            stepName: 'step <' . $executionId . '>',
            stepType: $type,
            status: $finished ? ($state === Display::STATE_CANCELED ? 'failed' : $state) : ($state === Display::STATE_PENDING ? 'pending' : 'running'),
            state: $state,
            waitingFor: Display::waitingFor($state),
            jobId: $jobId,
            jobStatus: $jobId === null ? null : Job::STATUS_PENDING_APPROVAL,
            jobTemplateName: null,
            startedAt: $startedAt === -1 ? $this->now - 600 : $startedAt,
            finishedAt: $finishedAt,
            waitingSince: Display::waitingFor($state) === null ? null : $this->now - 600,
            errorMessage: null,
            approval: $approval,
            decisionNote: null,
        );
    }

    private function wait(int $requestId, string $status = ApprovalRequest::STATUS_PENDING, bool $linkable = true): ApprovalWait
    {
        return new ApprovalWait($requestId, 77, $status, 5, 'gate', 1, 0, 0, $this->now - 600, null, null, null, $linkable);
    }
}
