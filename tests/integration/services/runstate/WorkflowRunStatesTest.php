<?php

declare(strict_types=1);

namespace app\tests\integration\services\runstate;

use app\helpers\TimeHelper;
use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\runstate\Display;
use app\services\runstate\RunState;
use app\services\runstate\StepState;
use app\services\runstate\Viewer;
use app\services\StrandedJobSweep;
use app\services\WorkflowCancellationService;
use app\tests\integration\CountsQueries;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The display state of workflow runs and their step executions: derived
 * from the stored statuses, the type each step had when it ran and the
 * status of its job, never stored.
 */
class WorkflowRunStatesTest extends DbTestCase
{
    use TeamScopeFixtures;
    use RunStateFixtures;
    use CountsQueries;

    private const STARTED = 1760000100;
    private const REQUESTED = 1760000150;
    private const QUEUED = 1760000160;
    private const CREATED = 1760000000;

    private User $admin;

    private WorkflowTemplate $workflow;

    private int $templateId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('rs-admin', 'admin');
        $this->workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $this->templateId = (int)$this->openJobTemplate((int)$this->admin->id)->id;
    }

    /**
     * Every row of the state table, one execution each, in a finished run
     * (whose own state does not depend on them).
     */
    public function testEveryExecutionTakesTheStateOfItsRowInTheStateTable(): void
    {
        $rule = $this->ruleOf((int)$this->admin->id, [$this->namedUser('rs-approver', 'operator')]);
        $run = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id, WorkflowJob::STATUS_FAILED);
        $pause = $this->namedStep($this->workflow, 10, 'wait', WorkflowStep::TYPE_PAUSE);
        $approval = $this->namedStep($this->workflow, 20, 'approve', WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $job = $this->namedStep($this->workflow, 30, 'deploy', WorkflowStep::TYPE_JOB, $this->templateId);
        $launcher = (int)$this->admin->id;

        $placeholder = $this->jobOf(null, $launcher, Job::STATUS_PENDING_APPROVAL);
        $this->requestOf($placeholder, $rule);
        $gated = $this->jobOf($this->templateId, $launcher, Job::STATUS_PENDING_APPROVAL);
        $this->requestOf($gated, $rule, ApprovalRequest::STATUS_PENDING, self::REQUESTED + 5);
        // Its request is gone: the maintenance run cancels the wait.
        $requestless = $this->jobOf($this->templateId, $launcher, Job::STATUS_PENDING_APPROVAL);

        $rows = [
            'paused' => [$this->executionOf($run, $pause, 'running'), Display::STATE_PAUSED, self::STARTED],
            'approval step waits' => [
                $this->executionOf($run, $approval, 'running', $placeholder),
                Display::STATE_AWAITING_APPROVAL,
                self::REQUESTED,
            ],
            'gated job waits' => [
                $this->executionOf($run, $job, 'running', $gated),
                Display::STATE_AWAITING_APPROVAL,
                self::REQUESTED + 5,
            ],
            'gated job waits, its request gone' => [
                $this->executionOf($run, $job, 'running', $requestless),
                Display::STATE_AWAITING_APPROVAL,
                self::STARTED,
            ],
            'job pending, never queued' => [
                $this->executionOf($run, $job, 'running', $this->jobOf($this->templateId, $launcher, Job::STATUS_PENDING, null, self::CREATED)),
                Display::STATE_QUEUED,
                self::CREATED,
            ],
            'job queued' => [
                $this->executionOf($run, $job, 'running', $this->jobOf($this->templateId, $launcher, Job::STATUS_QUEUED, self::QUEUED)),
                Display::STATE_QUEUED,
                self::QUEUED,
            ],
            'job runs' => [
                $this->executionOf($run, $job, 'running', $this->jobOf($this->templateId, $launcher, Job::STATUS_RUNNING, self::QUEUED)),
                Display::STATE_RUNNING,
                null,
            ],
            'job finished, the engine moves on' => [
                $this->executionOf($run, $job, 'running', $this->jobOf($this->templateId, $launcher, Job::STATUS_SUCCEEDED, self::QUEUED)),
                Display::STATE_RUNNING,
                null,
            ],
            'job step without its job yet' => [$this->executionOf($run, $job, 'running'), Display::STATE_RUNNING, null],
            'approval step without its placeholder yet' => [$this->executionOf($run, $approval, 'running'), Display::STATE_RUNNING, null],
            'approval step approved, the engine moves on' => [
                $this->executionOf($run, $approval, 'running', $this->jobOf(null, $launcher, Job::STATUS_SUCCEEDED)),
                Display::STATE_RUNNING,
                null,
            ],
            'an approval step never waits for a runner' => [
                $this->executionOf($run, $approval, 'running', $this->jobOf(null, $launcher, Job::STATUS_QUEUED, self::QUEUED)),
                Display::STATE_RUNNING,
                null,
            ],
            'legacy JOB type waits for its approval' => [
                $this->executionOf($run, $job, 'running', $this->jobOf($this->templateId, $launcher, Job::STATUS_PENDING_APPROVAL), null, self::STARTED, 'JOB'),
                Display::STATE_AWAITING_APPROVAL,
                self::STARTED,
            ],
            'legacy JOB type waits for a runner' => [
                $this->executionOf($run, $job, 'running', $this->jobOf($this->templateId, $launcher, Job::STATUS_QUEUED, self::QUEUED), null, self::STARTED, 'JOB'),
                Display::STATE_QUEUED,
                self::QUEUED,
            ],
            'legacy PAUSE type is no pause' => [
                $this->executionOf($run, $pause, 'running', null, null, self::STARTED, 'PAUSE'),
                Display::STATE_RUNNING,
                null,
            ],
            'canceled by a user' => [
                $this->executionOf($run, $job, 'failed', null, WorkflowCancellationService::STEP_RUN_CANCELED),
                Display::STATE_CANCELED,
                null,
            ],
            'canceled with its template' => [
                $this->executionOf($run, $approval, 'failed', null, WorkflowCancellationService::STEP_TEMPLATE_DELETED),
                Display::STATE_CANCELED,
                null,
            ],
            'ended by the maintenance run' => [
                $this->executionOf($run, $job, 'failed', null, StrandedJobSweep::STEP_RUN_ENDED),
                Display::STATE_FAILED,
                null,
            ],
            'failed' => [$this->executionOf($run, $job, 'failed', null, 'The job template of this step no longer exists.'), Display::STATE_FAILED, null],
            'failed without a message' => [$this->executionOf($run, $job, 'failed'), Display::STATE_FAILED, null],
            'succeeded' => [$this->executionOf($run, $job, 'succeeded'), Display::STATE_SUCCEEDED, null],
            'skipped' => [$this->executionOf($run, $job, 'skipped'), Display::STATE_SKIPPED, null],
            'pending' => [$this->executionOf($run, $job, 'pending', null, null, null), Display::STATE_PENDING, null],
        ];

        $steps = $this->stepsById($this->runStates()->forRun($run, Viewer::of((int)$this->admin->id)));

        $this->assertCount(count($rows), $steps);
        foreach ($rows as $row => [$execution, $state, $since]) {
            $step = $steps[(int)$execution->id];
            $this->assertSame($state, $step->state, $row);
            $this->assertSame(Display::waitingFor($state), $step->waitingFor, $row);
            $this->assertSame($since, $step->waitingSince, $row);
            $this->assertSame($execution->status, $step->status, "{$row}: the stored status stays");
        }
    }

    public function testWorkflowCancellationNamesTheMessagesOfTheStepsACancelEnds(): void
    {
        $this->assertSame(
            [WorkflowCancellationService::STEP_RUN_CANCELED, WorkflowCancellationService::STEP_TEMPLATE_DELETED],
            WorkflowCancellationService::stepMessages()
        );
        $this->assertNotContains(StrandedJobSweep::STEP_RUN_ENDED, WorkflowCancellationService::stepMessages());
    }

    public function testARemovedStepStillMapsThroughItsSnapshot(): void
    {
        $rule = $this->ruleOf((int)$this->admin->id, [$this->namedUser('rs-approver', 'operator')]);
        $pause = $this->namedStep($this->workflow, 10, 'hold', WorkflowStep::TYPE_PAUSE);
        $approval = $this->namedStep($this->workflow, 20, 'sign off', WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $paused = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id);
        $pausedExecution = $this->executionOf($paused, $pause, 'running');
        $waiting = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id);
        $placeholder = $this->jobOf(null, (int)$this->admin->id, Job::STATUS_PENDING_APPROVAL);
        $this->requestOf($placeholder, $rule);
        $this->executionOf($waiting, $approval, 'running', $placeholder);

        $this->assertSame(1, $pause->delete());
        $this->assertSame(1, $approval->delete());

        $viewer = Viewer::of((int)$this->admin->id);
        $pausedState = $this->runStates()->forRun($paused, $viewer);
        $this->assertSame(Display::STATE_PAUSED, $pausedState->state);
        $this->assertNotNull($pausedState->current);
        $this->assertNull($pausedState->current->workflowStepId);
        $this->assertSame('hold', $pausedState->current->stepName);
        $this->assertSame(WorkflowStep::TYPE_PAUSE, $pausedState->current->stepType);
        $this->assertSame((int)$pausedExecution->id, $pausedState->current->executionId);
        $states = $this->runStates()->forRuns([$paused, $waiting], $viewer);
        $this->assertSame(Display::STATE_PAUSED, $states[(int)$paused->id]->state);
        $this->assertSame(Display::STATE_AWAITING_APPROVAL, $states[(int)$waiting->id]->state);
        $this->assertSame('sign off', $states[(int)$waiting->id]->current?->stepName);
        $this->assertSame([(int)$paused->id], $this->runsWaitingFor(Display::WAITING_FOR_RESUME, [$paused, $waiting]));
        $this->assertSame([(int)$waiting->id], $this->runsWaitingFor(Display::WAITING_FOR_APPROVAL, [$paused, $waiting]));
    }

    public function testTheRunTakesTheStateOfItsNewestRunningExecution(): void
    {
        $pause = $this->namedStep($this->workflow, 10, 'hold', WorkflowStep::TYPE_PAUSE);
        $job = $this->namedStep($this->workflow, 20, 'deploy', WorkflowStep::TYPE_JOB, $this->templateId);
        $queuedFirst = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id);
        $this->executionOf($queuedFirst, $job, 'running', $this->jobOf($this->templateId, (int)$this->admin->id, Job::STATUS_QUEUED, self::QUEUED));
        $newest = $this->executionOf($queuedFirst, $pause, 'running');
        $pausedFirst = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id);
        $this->executionOf($pausedFirst, $pause, 'running');
        $this->executionOf($pausedFirst, $job, 'running', $this->jobOf($this->templateId, (int)$this->admin->id, Job::STATUS_QUEUED, self::QUEUED));
        $this->executionOf($pausedFirst, $job, 'succeeded');

        $viewer = Viewer::of((int)$this->admin->id);
        $one = $this->runStates()->forRun($queuedFirst, $viewer);
        $this->assertSame(Display::STATE_PAUSED, $one->state);
        $this->assertSame(Display::WAITING_FOR_RESUME, $one->waitingFor);
        $this->assertSame((int)$newest->id, $one->current?->executionId);
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $one->status, 'the stored status stays');
        $other = $this->runStates()->forRun($pausedFirst, $viewer);
        $this->assertSame(Display::STATE_QUEUED, $other->state);
        $this->assertSame(Display::WAITING_FOR_RUNNER, $other->waitingFor);
        $states = $this->runStates()->forRuns([$queuedFirst, $pausedFirst], $viewer);
        $this->assertSame(Display::STATE_PAUSED, $states[(int)$queuedFirst->id]->state);
        $this->assertSame(Display::STATE_QUEUED, $states[(int)$pausedFirst->id]->state);
        $this->assertSame([(int)$queuedFirst->id], $this->runsWaitingFor(Display::WAITING_FOR_RESUME, [$queuedFirst, $pausedFirst]));
        $this->assertSame([(int)$pausedFirst->id], $this->runsWaitingFor(Display::WAITING_FOR_RUNNER, [$queuedFirst, $pausedFirst]));
    }

    public function testARunBetweenTwoStepsRuns(): void
    {
        $job = $this->namedStep($this->workflow, 10, 'build', WorkflowStep::TYPE_JOB, $this->templateId);
        $run = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id);
        $this->executionOf($run, $job, 'succeeded');
        $fresh = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id);

        $viewer = Viewer::of((int)$this->admin->id);
        foreach ([$this->runStates()->forRun($run, $viewer), $this->runStates()->forRuns([$fresh], $viewer)[(int)$fresh->id]] as $state) {
            $this->assertSame(Display::STATE_RUNNING, $state->state);
            $this->assertNull($state->waitingFor);
            $this->assertNull($state->current);
        }
    }

    public function testAFinishedRunKeepsItsStoredStatus(): void
    {
        $pause = $this->namedStep($this->workflow, 10, 'hold', WorkflowStep::TYPE_PAUSE);
        $run = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id, WorkflowJob::STATUS_CANCELED);
        // A step whose cancel failed: the maintenance run ends it.
        $stranded = $this->executionOf($run, $pause, 'running');
        $succeeded = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id, WorkflowJob::STATUS_SUCCEEDED);

        $viewer = Viewer::of((int)$this->admin->id);
        $state = $this->runStates()->forRun($run, $viewer);
        $this->assertSame(Display::STATE_CANCELED, $state->state);
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $state->status);
        $this->assertNull($state->waitingFor);
        $this->assertNull($state->current);
        $this->assertSame(Display::STATE_PAUSED, $this->stepsById($state)[(int)$stranded->id]->state, 'the step itself still says what it does');
        $states = $this->runStates()->forRuns([$run, $succeeded], $viewer);
        $this->assertSame(Display::STATE_CANCELED, $states[(int)$run->id]->state);
        $this->assertSame(Display::STATE_SUCCEEDED, $states[(int)$succeeded->id]->state);
        $this->assertNull($states[(int)$run->id]->current);
        $this->assertSame([], $this->runsWaitingFor(Display::WAITING_FOR_RESUME, [$run, $succeeded]));
    }

    public function testForRunListsEveryExecutionAndFillsTheRunsExecutions(): void
    {
        $job = $this->namedStep($this->workflow, 10, 'build', WorkflowStep::TYPE_JOB, $this->templateId);
        $pause = $this->namedStep($this->workflow, 20, 'hold', WorkflowStep::TYPE_PAUSE);
        $run = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id);
        $buildJob = $this->jobOf($this->templateId, (int)$this->admin->id, Job::STATUS_SUCCEEDED, self::QUEUED);
        $first = $this->executionOf($run, $job, 'succeeded', $buildJob);
        $second = $this->executionOf($run, $pause, 'running');
        $loaded = WorkflowJob::findOne($run->id);
        $this->assertNotNull($loaded);

        $state = $this->runStates()->forRun($loaded, Viewer::of((int)$this->admin->id));

        $this->assertSame((int)$run->id, $state->runId);
        $this->assertSame([(int)$first->id, (int)$second->id], array_map(static fn (StepState $s): int => $s->executionId, $state->steps));
        $this->assertSame((int)$second->id, $state->current?->executionId);
        $this->assertTrue($loaded->isRelationPopulated('stepExecutions'));
        $this->assertSame(0, $this->queriesOf(static function () use ($loaded): void {
            foreach ($loaded->stepExecutions as $execution) {
                $execution->stepName();
                $execution->stepType();
            }
        }), 'the page reads the executions forRun() loaded');
        $build = $state->steps[0];
        $this->assertSame((int)$job->id, $build->workflowStepId);
        $this->assertSame('build', $build->stepName);
        $this->assertSame(WorkflowStep::TYPE_JOB, $build->stepType);
        $this->assertSame((int)$buildJob->id, $build->jobId);
        $this->assertSame(Job::STATUS_SUCCEEDED, $build->jobStatus);
        $template = JobTemplate::findOne($this->templateId);
        $this->assertNotNull($template);
        $this->assertSame($template->name, $build->jobTemplateName);
        $this->assertSame(self::STARTED, $build->startedAt);
        $this->assertSame(1760000200, $build->finishedAt);
        $this->assertNull($build->errorMessage);
        $this->assertNull($build->approval);
        $this->assertNull($build->decisionNote);
        $this->assertNull($state->steps[1]->jobTemplateName, 'a pause step has no job');
    }

    public function testADeletedJobTemplateIsStillNamed(): void
    {
        $job = $this->namedStep($this->workflow, 10, 'build', WorkflowStep::TYPE_JOB, $this->templateId);
        $run = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id, WorkflowJob::STATUS_SUCCEEDED);
        $this->executionOf($run, $job, 'succeeded', $this->jobOf($this->templateId, (int)$this->admin->id, Job::STATUS_SUCCEEDED));
        $template = JobTemplate::findOne($this->templateId);
        $this->assertNotNull($template);
        JobTemplate::updateAll(['deleted_at' => time()], ['id' => $this->templateId]);

        $state = $this->runStates()->forRun($run, Viewer::of((int)$this->admin->id));

        $this->assertSame($template->name, $state->steps[0]->jobTemplateName);
    }

    public function testThePendingRequestOfTheCurrentStepCarriesTheEligibilityOfItsApprovers(): void
    {
        $approver = $this->namedUser('rs-approver', 'operator');
        $other = $this->namedUser('rs-other', 'operator');
        $launcher = $this->namedUser('rs-launcher', 'operator');
        $rule = $this->ruleOf((int)$this->admin->id, [$approver, $other], 1);
        $approval = $this->namedStep($this->workflow, 10, 'approve', WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $run = $this->createWorkflowJob((int)$this->workflow->id, (int)$launcher->id);
        $earlier = $this->jobOf(null, (int)$launcher->id, Job::STATUS_SUCCEEDED);
        $this->requestOf($earlier, $rule, ApprovalRequest::STATUS_APPROVED, self::REQUESTED, null, 1760000300);
        $this->executionOf($run, $approval, 'succeeded', $earlier);
        $placeholder = $this->jobOf(null, (int)$launcher->id, Job::STATUS_PENDING_APPROVAL);
        $request = $this->requestOf($placeholder, $rule);
        $this->executionOf($run, $approval, 'running', $placeholder);

        $plain = $this->runStates()->forRun($run, Viewer::of((int)$approver->id));
        $wait = $plain->current?->approval;
        $this->assertNotNull($wait);
        $this->assertSame((int)$request->id, $wait->requestId);
        $this->assertSame(2, $wait->eligibleCount);
        $this->assertSame(2, $wait->openCount);
        $this->assertTrue($wait->approvable);
        $this->assertNull($wait->relation, 'only the viewer\'s own page asks for the relation');
        $this->assertNull($plain->steps[0]->approval?->eligibleCount, 'an earlier request carries no eligibility');

        $personal = $this->runStates()->forRun($run, Viewer::of((int)$approver->id), true);
        $this->assertSame(Display::RELATION_MAY_DECIDE, $personal->current?->approval?->relation);
        $mine = $this->runStates()->forRun($run, Viewer::of((int)$launcher->id), true);
        $this->assertSame(Display::RELATION_LAUNCHER, $mine->current?->approval?->relation);
        $listed = $this->runStates()->forRuns([$run], Viewer::of((int)$approver->id))[(int)$run->id];
        $this->assertSame((int)$request->id, $listed->current?->approval?->requestId);
        $this->assertNull($listed->current?->approval?->eligibleCount, 'lists carry no eligibility');
    }

    public function testTheStepsCarryTheDecisionNotesOfTheirRequests(): void
    {
        $approver = $this->namedUser('rs-approver', 'operator');
        $rule = $this->ruleOf((int)$this->admin->id, [$approver], 1);
        $approval = $this->namedStep($this->workflow, 10, 'approve', WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $run = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id, WorkflowJob::STATUS_SUCCEEDED);
        $placeholder = $this->jobOf(null, (int)$this->admin->id, Job::STATUS_SUCCEEDED);
        $request = $this->requestOf($placeholder, $rule, ApprovalRequest::STATUS_APPROVED, self::REQUESTED, null, 1760000300);
        $this->voteOn($request, $approver, ApprovalDecision::DECISION_APPROVED, 'ok');
        $this->executionOf($run, $approval, 'succeeded', $placeholder);

        $step = $this->runStates()->forRun($run, Viewer::of((int)$this->admin->id))->steps[0];

        $expected = sprintf('Approved by %s at %s. Comment: "ok"', $approver->username, TimeHelper::stamp(1760000300));
        $this->assertSame($expected, $step->decisionNote);
        $this->assertSame($expected, $step->approval?->decisionNote);
        $this->assertNull(
            $this->runStates()->forRuns([$run], Viewer::of((int)$this->admin->id))[(int)$run->id]->current,
            'a finished run has no current step'
        );
    }

    /**
     * A run viewer may view the workflow, which needs view access to the
     * project of every job step it has now: a step that ran a job of
     * another team's template before the workflow changed keeps its state,
     * but neither its request nor its template's name is shown.
     */
    public function testARequestOrTemplateTheViewerMayNotSeeIsLeftOut(): void
    {
        $scope = $this->teamScope();
        $member = $scope['member'];
        $adminId = (int)$scope['admin']->id;
        $rule = $this->ruleOf($adminId, [$scope['outsider']]);
        $workflow = $this->createWorkflowWithJobSteps($adminId, (int)$scope['own']->id);
        $ownStep = WorkflowStep::findOne(['workflow_template_id' => $workflow->id]);
        $this->assertNotNull($ownStep);
        $foreignStep = $this->namedStep($workflow, 5, 'old deploy', WorkflowStep::TYPE_JOB, (int)$scope['foreign']->id);
        $run = $this->createWorkflowJob((int)$workflow->id, $adminId, WorkflowJob::STATUS_SUCCEEDED);
        $foreignJob = $this->jobOf((int)$scope['foreign']->id, $adminId, Job::STATUS_SUCCEEDED, self::QUEUED);
        $this->requestOf($foreignJob, $rule, ApprovalRequest::STATUS_APPROVED, self::REQUESTED, null, 1760000300);
        $this->executionOf($run, $foreignStep, 'succeeded', $foreignJob);
        $this->executionOf($run, $ownStep, 'succeeded', $this->jobOf((int)$scope['own']->id, $adminId, Job::STATUS_SUCCEEDED, self::QUEUED));
        $this->assertSame(1, $foreignStep->delete(), 'the workflow no longer uses the foreign template');

        $asMember = $this->runStates()->forRun($run, Viewer::of((int)$member->id))->steps;
        $this->assertSame(Job::STATUS_SUCCEEDED, $asMember[0]->jobStatus);
        $this->assertSame(Display::STATE_SUCCEEDED, $asMember[0]->state);
        $this->assertNull($asMember[0]->approval);
        $this->assertNull($asMember[0]->decisionNote);
        $this->assertNull($asMember[0]->jobTemplateName);
        $this->assertSame($scope['own']->name, $asMember[1]->jobTemplateName);

        $asAdmin = $this->runStates()->forRun($run, Viewer::of($adminId))->steps;
        $this->assertNotNull($asAdmin[0]->approval);
        $this->assertSame($scope['foreign']->name, $asAdmin[0]->jobTemplateName);
        $nobody = $this->runStates()->forRun($run, Viewer::of(0))->steps;
        $this->assertNull($nobody[1]->jobTemplateName, 'nobody sees no name');
        $this->assertNull($nobody[1]->approval);
    }

    /**
     * forRuns() reads the current execution of every run at once: the same
     * number of queries for 2 runs as for 12.
     */
    public function testForRunsUsesAConstantNumberOfQueries(): void
    {
        // A team-restricted project, so team scoping applies to the member.
        $member = $this->teamScope()['member'];
        $runs = $this->waitingRuns(12);
        $this->runStates()->forRuns($runs, Viewer::of((int)$this->admin->id));

        foreach ([(int)$this->admin->id, (int)$member->id] as $userId) {
            // RBAC keeps a user's role assignments once it has read them.
            $this->runStates()->forRuns($runs, Viewer::of($userId));
            $two = $this->queriesOf(fn () => $this->runStates()->forRuns(array_slice($runs, 0, 2), Viewer::of($userId)));
            $twelve = $this->queriesOf(fn () => $this->runStates()->forRuns($runs, Viewer::of($userId)));
            $this->assertSame($two, $twelve, "user #{$userId}");
        }
        $states = $this->runStates()->forRuns($runs, Viewer::of((int)$this->admin->id));
        $this->assertSame(array_map(static fn (WorkflowJob $run): int => (int)$run->id, $runs), array_keys($states));
        foreach ($states as $state) {
            $this->assertSame([], $state->steps, 'lists carry the current execution only');
            $this->assertNotNull($state->current);
        }
        $this->assertSame(
            [Display::STATE_AWAITING_APPROVAL, Display::STATE_PAUSED, Display::STATE_QUEUED, Display::STATE_RUNNING],
            array_values(array_unique(array_map(static fn (RunState $s): string => $s->state, $states)))
        );
    }

    /**
     * forRun() reads the executions, their jobs and requests at once: the
     * same number of queries for 2 executions as for 10.
     */
    public function testForRunUsesAConstantNumberOfQueries(): void
    {
        $approver = $this->namedUser('rs-approver', 'operator');
        $rule = $this->ruleOf((int)$this->admin->id, [$approver]);
        $approval = $this->namedStep($this->workflow, 10, 'approve', WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $short = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id);
        $long = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id);
        foreach ([$short->id => 2, $long->id => 10] as $runId => $count) {
            $run = WorkflowJob::findOne($runId);
            $this->assertNotNull($run);
            for ($i = 0; $i < $count; $i++) {
                $last = $i === $count - 1;
                $placeholder = $this->jobOf(null, (int)$this->admin->id, $last ? Job::STATUS_PENDING_APPROVAL : Job::STATUS_SUCCEEDED);
                $request = $last
                    ? $this->requestOf($placeholder, $rule)
                    : $this->requestOf($placeholder, $rule, ApprovalRequest::STATUS_APPROVED, self::REQUESTED, null, 1760000300);
                if (!$last) {
                    $this->voteOn($request, $approver, ApprovalDecision::DECISION_APPROVED, 'ok');
                }
                $this->executionOf($run, $approval, $last ? 'running' : 'succeeded', $placeholder);
            }
        }
        $this->runStates()->forRun($short, Viewer::of((int)$approver->id), true);

        $two = $this->queriesOf(fn () => $this->runStates()->forRun($short, Viewer::of((int)$approver->id), true));
        $ten = $this->queriesOf(fn () => $this->runStates()->forRun($long, Viewer::of((int)$approver->id), true));

        $this->assertSame($two, $ten);
        $state = $this->runStates()->forRun($long, Viewer::of((int)$approver->id), true);
        $this->assertCount(10, $state->steps);
        $this->assertSame(Display::RELATION_MAY_DECIDE, $state->current?->approval?->relation);
    }

    public function testForRunsReadsNothingForFinishedRunsOrNoRuns(): void
    {
        $finished = [
            $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id, WorkflowJob::STATUS_SUCCEEDED),
            $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id, WorkflowJob::STATUS_FAILED),
        ];
        $viewer = Viewer::of((int)$this->admin->id);

        $this->assertSame(0, $this->queriesOf(fn () => $this->runStates()->forRuns($finished, $viewer)));
        $this->assertSame(0, $this->queriesOf(fn () => $this->runStates()->forRuns([], $viewer)));
        $this->assertSame([], $this->runStates()->forRuns([], $viewer));
    }

    /**
     * waitingCondition() keeps exactly the runs that forRuns() says wait
     * for that.
     */
    public function testWaitingConditionFindsEachKindOfWaitAndNoFinishedRun(): void
    {
        $runs = $this->waitingRuns(8);
        $pause = $this->namedStep($this->workflow, 90, 'late hold', WorkflowStep::TYPE_PAUSE);
        $canceled = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id, WorkflowJob::STATUS_CANCELED);
        $this->executionOf($canceled, $pause, 'running');
        $legacy = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id);
        $this->executionOf($legacy, $pause, 'running', null, null, self::STARTED, 'Pause');
        // An empty snapshot counts as none: the live step's type decides.
        $blank = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id);
        $blankExecution = $this->executionOf($blank, $pause, 'running');
        WorkflowJobStep::updateAll(['step_type' => ''], ['id' => $blankExecution->id]);
        $all = array_merge($runs, [$canceled, $legacy, $blank]);
        $states = $this->runStates()->forRuns($all, Viewer::of((int)$this->admin->id));

        foreach (Display::waitingForValues() as $waitingFor) {
            $expected = [];
            foreach ($all as $run) {
                if ($states[(int)$run->id]->waitingFor === $waitingFor) {
                    $expected[] = (int)$run->id;
                }
            }
            $this->assertNotSame([], $expected, $waitingFor);
            $this->assertSame($expected, $this->runsWaitingFor($waitingFor, $all), $waitingFor);
        }
        $this->assertSame([], $this->runsWaitingFor('APPROVAL', $all), 'an unknown wait matches no run');
    }

    /**
     * Before migration m000084 there is no snapshot of the step's type: the
     * condition reads the live step's type, as WorkflowJobStep::stepType()
     * does then.
     */
    public function testWaitingConditionReadsTheLiveTypeWithoutTheSnapshotColumn(): void
    {
        $pause = $this->namedStep($this->workflow, 10, 'hold', WorkflowStep::TYPE_PAUSE);
        $job = $this->namedStep($this->workflow, 20, 'deploy', WorkflowStep::TYPE_JOB, $this->templateId);
        $live = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id);
        $this->executionOf($live, $pause, 'running');
        // The snapshot says job, the live step pause: only the live type counts without the column.
        $stale = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id);
        $this->executionOf($stale, $pause, 'running', null, null, self::STARTED, WorkflowStep::TYPE_JOB);
        $queued = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id);
        $this->executionOf($queued, $job, 'running', $this->jobOf($this->templateId, (int)$this->admin->id, Job::STATUS_QUEUED, self::QUEUED));
        $runs = [$live, $stale, $queued];
        $this->assertSame([(int)$live->id], $this->runsWaitingFor(Display::WAITING_FOR_RESUME, $runs));

        $schema = WorkflowJobStep::getTableSchema();
        $column = $schema->columns['step_type'];
        unset($schema->columns['step_type']);
        try {
            $this->assertSame([(int)$live->id, (int)$stale->id], $this->runsWaitingFor(Display::WAITING_FOR_RESUME, $runs));
            $this->assertSame([(int)$queued->id], $this->runsWaitingFor(Display::WAITING_FOR_RUNNER, $runs));
            $states = $this->runStates()->forRuns($runs, Viewer::of((int)$this->admin->id));
            $this->assertSame(Display::STATE_PAUSED, $states[(int)$stale->id]->state, 'stepType() reads the live type too');
        } finally {
            $schema->columns['step_type'] = $column;
        }
    }

    /**
     * $count running runs that wait in turn for an approval (approval step,
     * gated job step), a resume and a runner, or run a job.
     *
     * @return list<WorkflowJob>
     */
    private function waitingRuns(int $count): array
    {
        $launcher = $this->namedUser('rs-launcher', 'operator');
        $rule = $this->ruleOf((int)$this->admin->id, [$this->namedUser('rs-approver', 'operator')]);
        $approval = $this->namedStep($this->workflow, 10, 'approve', WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $pause = $this->namedStep($this->workflow, 20, 'hold', WorkflowStep::TYPE_PAUSE);
        $job = $this->namedStep($this->workflow, 30, 'deploy', WorkflowStep::TYPE_JOB, $this->templateId);
        $runs = [];
        for ($i = 0; $i < $count; $i++) {
            $run = $this->createWorkflowJob((int)$this->workflow->id, (int)$launcher->id);
            $this->executionOf($run, $job, 'succeeded', $this->jobOf($this->templateId, (int)$launcher->id, Job::STATUS_SUCCEEDED, self::QUEUED));
            switch ($i % 5) {
                case 0:
                    $placeholder = $this->jobOf(null, (int)$launcher->id, Job::STATUS_PENDING_APPROVAL);
                    $this->requestOf($placeholder, $rule);
                    $this->executionOf($run, $approval, 'running', $placeholder);
                    break;
                case 1:
                    $this->executionOf($run, $pause, 'running');
                    break;
                case 2:
                    $this->executionOf($run, $job, 'running', $this->jobOf($this->templateId, (int)$launcher->id, Job::STATUS_PENDING));
                    break;
                case 3:
                    $gated = $this->jobOf($this->templateId, (int)$launcher->id, Job::STATUS_PENDING_APPROVAL);
                    $this->requestOf($gated, $rule);
                    $this->executionOf($run, $job, 'running', $gated);
                    break;
                default:
                    $this->executionOf($run, $job, 'running', $this->jobOf($this->templateId, (int)$launcher->id, Job::STATUS_RUNNING, self::QUEUED));
            }
            $runs[] = $run;
        }

        return $runs;
    }

    /**
     * The IDs of $runs that waitingCondition($waitingFor) keeps, ascending.
     *
     * @param list<WorkflowJob> $runs
     * @return list<int>
     */
    private function runsWaitingFor(string $waitingFor, array $runs): array
    {
        return array_map('intval', WorkflowJob::find()
            ->select('workflow_job.id')
            ->where(['workflow_job.id' => array_map(static fn (WorkflowJob $run): int => (int)$run->id, $runs)])
            ->andWhere($this->runStates()->waitingCondition($waitingFor))
            ->orderBy(['workflow_job.id' => SORT_ASC])
            ->column());
    }

    /**
     * @return array<int, StepState>
     */
    private function stepsById(RunState $state): array
    {
        $steps = [];
        foreach ($state->steps as $step) {
            $steps[$step->executionId] = $step;
        }

        return $steps;
    }
}
