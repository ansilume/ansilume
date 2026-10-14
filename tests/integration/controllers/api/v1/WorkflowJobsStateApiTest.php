<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\ApprovalsController;
use app\controllers\api\v1\WorkflowJobsController;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobSearch;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowCancellationService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\CountsQueries;
use app\tests\integration\services\runstate\RunStateFixtures;
use app\tests\integration\services\WorkflowEngineFixtures;
use app\tests\integration\TeamScopeFixtures;

/**
 * What workflow runs wait for, through the API (contract C13): every run
 * (list, detail, cancel, resume) says what it waits for (waiting_for) and
 * names its current step execution (current_step); each step execution of
 * the detail adds waiting_for, job_status and the approval request it waits
 * or waited for. GET /api/v1/workflow-jobs filters by ?waiting_for=,
 * ?workflow_template_id= and ?status=, and answers 422 for a value it does
 * not know.
 *
 * Runs are launched through the engine where the engine can bring them
 * there, and built by hand (RunStateFixtures) where a list needs every
 * state at once.
 */
class WorkflowJobsStateApiTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use RunStateFixtures;
    use WorkflowEngineFixtures;
    use CountsQueries;
    use RunContextApiFixtures;

    private User $launcher;

    private User $approver;

    private int $templateId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->launcher = $this->namedUser('wjs-launcher', 'operator');
        $this->approver = $this->namedUser('wjs-approver', 'operator');
        $this->templateId = (int)$this->openTemplate((int)$this->launcher->id)->id;
    }

    // -- The detail ---------------------------------------------------------------------

    /**
     * regression: the run detail did not say what a step waits for. A run
     * that waited at its approval step was stored, and answered, as running,
     * like a run whose job runs: GET /workflow-jobs/{id} had no waiting_for
     * and named no approval request. The run and its step now say
     * "approval", the step names its request, and the request counts who may
     * still decide.
     */
    public function testRegressionTheRunDetailDidNotSayWhatAStepWaitsFor(): void
    {
        $launched = $this->launchedAtApprovalStep($this->launcher, [$this->approver], $this->templateId);
        $request = $launched['request'];
        $rule = ApprovalRule::findOne($request->approval_rule_id);
        $this->assertNotNull($rule);
        $this->authenticate($this->approver);

        $data = $this->data($this->controller()->actionView((int)$launched['run']->id));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $data['status'], 'stored as running, as before');
        $this->assertArrayHasKey('waiting_for', $data, 'the run says what it waits for');
        $this->assertSame('approval', $data['waiting_for']);
        $this->assertSame([
            'id' => (int)$launched['execution']->id,
            'workflow_step_id' => (int)$launched['gate']->id,
            'step_name' => 'sign off',
            'step_type' => WorkflowStep::TYPE_APPROVAL,
        ], $data['current_step']);
        /** @var list<array<string, mixed>> $steps */
        $steps = $data['steps'];
        $this->assertCount(1, $steps);
        $this->assertSame([
            'id' => (int)$launched['execution']->id,
            'workflow_step_id' => (int)$launched['gate']->id,
            'step_name' => 'sign off',
            'step_type' => WorkflowStep::TYPE_APPROVAL,
            'job_id' => (int)$launched['job']->id,
            'status' => WorkflowJobStep::STATUS_RUNNING,
            'started_at' => $launched['execution']->started_at,
            'finished_at' => null,
            'error' => null,
            'waiting_for' => 'approval',
            'job_status' => Job::STATUS_PENDING_APPROVAL,
            'approval_request_id' => (int)$request->id,
            'approval' => [
                'id' => (int)$request->id,
                'status' => ApprovalRequest::STATUS_PENDING,
                'approval_rule_id' => (int)$rule->id,
                'approval_rule_name' => $rule->name,
                'approval_count' => 0,
                'rejection_count' => 0,
                'required_approvals' => 1,
                'requested_at' => $request->requested_at,
                'expires_at' => null,
                'resolved_at' => null,
                'timeout_action' => null,
                'eligible_count' => 1,
                'open_count' => 1,
                'can_still_be_approved' => true,
            ],
        ], $steps[0]);
    }

    /**
     * Approved through the API, the approval step succeeds and the run goes
     * on to its job step, whose job waits for a runner. Who may still decide
     * is counted only for the request the run waits for now.
     */
    public function testAnApprovedRunGoesOnAndThenWaitsForARunner(): void
    {
        $launched = $this->launchedAtApprovalStep($this->launcher, [$this->approver], $this->templateId);
        $this->authenticate($this->approver);
        $approved = (new ApprovalsController('api/v1/approvals', \Yii::$app))->actionApprove((int)$launched['request']->id);
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $this->data($approved)['status']);

        $data = $this->data($this->controller()->actionView((int)$launched['run']->id));

        $deploy = $this->executionOfStep($launched['run'], $launched['deploy']);
        $this->assertSame('runner', $data['waiting_for']);
        $this->assertSame([
            'id' => (int)$deploy->id,
            'workflow_step_id' => (int)$launched['deploy']->id,
            'step_name' => 'deploy',
            'step_type' => WorkflowStep::TYPE_JOB,
        ], $data['current_step']);
        /** @var list<array<string, mixed>> $steps */
        $steps = $data['steps'];
        $this->assertSame([WorkflowJobStep::STATUS_SUCCEEDED, WorkflowJobStep::STATUS_RUNNING], array_column($steps, 'status'));
        $this->assertSame([null, 'runner'], array_column($steps, 'waiting_for'));
        $this->assertSame([Job::STATUS_SUCCEEDED, Job::STATUS_QUEUED], array_column($steps, 'job_status'));
        $this->assertSame([(int)$launched['request']->id, null], array_column($steps, 'approval_request_id'));
        $approval = $steps[0]['approval'];
        $this->assertIsArray($approval);
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $approval['status']);
        $this->assertSame(1, $approval['approval_count']);
        $this->assertNotNull($approval['resolved_at']);
        $this->assertNull($approval['eligible_count'], 'counted only for the request the run waits for');
        $this->assertNull($approval['open_count']);
        $this->assertNull($approval['can_still_be_approved']);
        $this->assertNull($steps[1]['approval']);
    }

    /**
     * A gated job step waits for its job's approval: the run says
     * "approval" with the job step as its current step. Approved, its job
     * is queued, and the same step waits for a runner.
     */
    public function testAGatedJobStepWaitsForApprovalThenForARunner(): void
    {
        $rule = $this->ruleOf((int)$this->launcher->id, [$this->approver]);
        $launched = $this->launchedAtGatedJobStep($this->launcher, $this->gatedTemplate((int)$this->launcher->id, $rule));
        $currentStep = [
            'id' => (int)$launched['execution']->id,
            'workflow_step_id' => (int)$launched['deploy']->id,
            'step_name' => 'deploy',
            'step_type' => WorkflowStep::TYPE_JOB,
        ];
        $this->authenticate($this->approver);

        $waiting = $this->data($this->controller()->actionView((int)$launched['run']->id));

        $this->assertSame('approval', $waiting['waiting_for']);
        $this->assertSame($currentStep, $waiting['current_step']);
        $step = $this->onlyStep($waiting);
        $this->assertSame((int)$launched['job']->id, $step['job_id'], 'the job itself waits, no placeholder');
        $this->assertSame('approval', $step['waiting_for']);
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $step['job_status']);
        $this->assertSame((int)$launched['request']->id, $step['approval_request_id']);
        $this->assertSame(1, $this->approvalOf($step)['eligible_count']);

        (new ApprovalsController('api/v1/approvals', \Yii::$app))->actionApprove((int)$launched['request']->id);
        $queued = $this->data($this->controller()->actionView((int)$launched['run']->id));

        $this->assertSame('runner', $queued['waiting_for']);
        $this->assertSame($currentStep, $queued['current_step'], 'the same step: its job now waits for a runner');
        $step = $this->onlyStep($queued);
        $this->assertSame('runner', $step['waiting_for']);
        $this->assertSame(Job::STATUS_QUEUED, $step['job_status']);
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $this->approvalOf($step)['status']);
        $this->assertNull($this->approvalOf($step)['eligible_count'], 'no longer pending: nobody is counted');
    }

    /**
     * A run whose step the caller may see but whose approval request they
     * may not (the step's job template belongs to another team's project
     * and was removed from the workflow since): the request is left out for
     * them, as the caller of the API, and shown to an admin.
     */
    public function testTheDetailShowsTheApprovalRequestOnlyToACallerWhoMaySeeIt(): void
    {
        $s = $this->teamScope();
        $adminId = (int)$s['admin']->id;
        $rule = $this->ruleOf($adminId, [$s['outsider']]);
        $workflow = $this->createWorkflowWithJobSteps($adminId, (int)$s['own']->id);
        $foreignStep = $this->namedStep($workflow, 5, 'old deploy', WorkflowStep::TYPE_JOB, (int)$s['foreign']->id);
        $run = $this->createWorkflowJob((int)$workflow->id, $adminId, WorkflowJob::STATUS_FAILED);
        $job = $this->jobOf((int)$s['foreign']->id, $adminId, Job::STATUS_REJECTED);
        $request = $this->requestOf($job, $rule, ApprovalRequest::STATUS_REJECTED, 1760000150, null, 1760000300);
        $this->executionOf($run, $foreignStep, WorkflowJobStep::STATUS_FAILED, $job, 'The approval was rejected.');
        $this->assertSame(1, $foreignStep->delete());

        $this->authenticate($s['member']);
        $asMember = $this->onlyStep($this->data($this->controller()->actionView((int)$run->id)));
        $this->authenticate($s['admin']);
        $asAdmin = $this->onlyStep($this->data($this->controller()->actionView((int)$run->id)));

        $this->assertNull($asMember['approval_request_id']);
        $this->assertNull($asMember['approval']);
        $this->assertSame(Job::STATUS_REJECTED, $asMember['job_status']);
        $this->assertSame((int)$request->id, $asAdmin['approval_request_id']);
        $this->assertSame(ApprovalRequest::STATUS_REJECTED, $this->approvalOf($asAdmin)['status']);
    }

    // -- Cancel and resume -------------------------------------------------------------

    /**
     * The resume answers the run as it stands then: no longer paused, at
     * the step the resume led to.
     */
    public function testAPausedRunWaitsForAResumeAndTheResumeAnswerSaysWhereItWent(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);
        $hold = $this->namedStep($workflow, 10, 'hold', WorkflowStep::TYPE_PAUSE);
        $deploy = $this->namedStep($workflow, 20, 'deploy', WorkflowStep::TYPE_JOB, $this->templateId);
        $run = $this->engine()->launch($workflow, (int)$this->launcher->id);
        $this->authenticate($this->launcher);

        $paused = $this->itemsById($this->controller()->actionIndex())[(int)$run->id];
        $this->assertSame('resume', $paused['waiting_for']);
        $this->assertSame([
            'id' => (int)$this->executionOfStep($run, $hold)->id,
            'workflow_step_id' => (int)$hold->id,
            'step_name' => 'hold',
            'step_type' => WorkflowStep::TYPE_PAUSE,
        ], $paused['current_step']);

        $resumed = $this->data($this->controller()->actionResume((int)$run->id));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $resumed['status']);
        $this->assertSame('runner', $resumed['waiting_for']);
        $this->assertSame([
            'id' => (int)$this->executionOfStep($run, $deploy)->id,
            'workflow_step_id' => (int)$deploy->id,
            'step_name' => 'deploy',
            'step_type' => WorkflowStep::TYPE_JOB,
        ], $resumed['current_step']);
        $this->assertSame(array_keys($paused), array_keys($resumed), 'the fields of a run in the list');
    }

    /**
     * A canceled run waits for nothing; the step that waited keeps its
     * stored status (failed, with the cancel's message) and its canceled
     * request.
     */
    public function testACanceledRunWaitsForNothing(): void
    {
        $launched = $this->launchedAtApprovalStep($this->launcher, [$this->approver], $this->templateId);
        $this->authenticate($this->launcher);

        $canceled = $this->data($this->controller()->actionCancel((int)$launched['run']->id));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $canceled['status']);
        $this->assertNull($canceled['waiting_for']);
        $this->assertNull($canceled['current_step']);
        $step = $this->onlyStep($this->data($this->controller()->actionView((int)$launched['run']->id)));
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $step['status']);
        $this->assertSame(WorkflowCancellationService::STEP_RUN_CANCELED, $step['error']);
        $this->assertNull($step['waiting_for']);
        $this->assertSame(Job::STATUS_CANCELED, $step['job_status']);
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $this->approvalOf($step)['status']);
    }

    // -- The list -----------------------------------------------------------------------

    /**
     * Each run of the list says what it waits for and names its current
     * step; a run between two steps and a finished run have neither.
     */
    public function testTheListSaysWhatEachRunWaitsFor(): void
    {
        ['workflow' => $workflow, 'runs' => $runs] = $this->runsInEveryState();
        $this->authenticate($this->approver);
        $this->setQueryParams(['workflow_template_id' => (string)$workflow->id]);

        $items = $this->itemsById($this->controller()->actionIndex());

        $expected = [
            'approval' => ['approval', 'sign off'],
            'gated' => ['approval', 'deploy'],
            'pause' => ['resume', 'hold'],
            'pending' => ['runner', 'deploy'],
            'queued' => ['runner', 'deploy'],
            'running' => [null, 'deploy'],
            'between' => [null, null],
            'succeeded' => [null, null],
            'canceled' => [null, null],
        ];
        $this->assertSame(array_keys($expected), array_keys($runs));
        foreach ($expected as $key => [$waitingFor, $stepName]) {
            $item = $items[(int)$runs[$key]->id];
            $this->assertSame($waitingFor, $item['waiting_for'], $key);
            $this->assertSame($stepName, $item['current_step']['step_name'] ?? null, $key);
        }
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $items[(int)$runs['canceled']->id]['status'], 'a finished run keeps its stored status');
    }

    /**
     * Each filter keeps exactly the runs it names, newest first, and the
     * total counts them; filters combine.
     *
     * @return array<string, array{0: array<string, string>, 1: list<string>}>
     */
    public static function filterProvider(): array
    {
        return [
            'waiting for approval' => [['waiting_for' => 'approval'], ['gated', 'approval']],
            'waiting for a resume' => [['waiting_for' => 'resume'], ['pause']],
            'waiting for a runner' => [['waiting_for' => 'runner'], ['queued', 'pending']],
            'running, waiting runs included' => [['status' => 'running'], ['between', 'running', 'queued', 'pending', 'pause', 'gated', 'approval']],
            'succeeded' => [['status' => 'succeeded'], ['succeeded']],
            'canceled, also with a step left running' => [['status' => 'canceled'], ['canceled']],
            'failed' => [['status' => 'failed'], []],
            'combined' => [['waiting_for' => 'approval', 'status' => 'running'], ['gated', 'approval']],
            'combined, nothing matches' => [['waiting_for' => 'resume', 'status' => 'succeeded'], []],
            'empty values do not filter' => [['waiting_for' => '', 'status' => ''], ['canceled', 'succeeded', 'between', 'running', 'queued', 'pending', 'pause', 'gated', 'approval']],
        ];
    }

    /**
     * @dataProvider filterProvider
     * @param array<string, string> $filters
     * @param list<string> $expected the keys of runsInEveryState(), newest first
     */
    public function testEachFilterKeepsTheRunsItNames(array $filters, array $expected): void
    {
        ['workflow' => $workflow, 'runs' => $runs] = $this->runsInEveryState();
        $this->authenticate($this->approver);
        $this->setQueryParams($filters + ['workflow_template_id' => (string)$workflow->id]);

        $result = $this->controller()->actionIndex();

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $ids = array_map(static fn (string $key): int => (int)$runs[$key]->id, $expected);
        $this->assertSame($ids, array_column($this->data($result), 'id'));
        $this->assertSame(count($ids), $result['meta']['total']);
    }

    /**
     * ?workflow_template_id= keeps the runs of one workflow; leading zeros
     * name the same one.
     */
    public function testTheWorkflowFilterKeepsTheRunsOfOneWorkflow(): void
    {
        $first = $this->createWorkflowWithJobSteps((int)$this->launcher->id, $this->templateId);
        $second = $this->createWorkflowWithJobSteps((int)$this->launcher->id, $this->templateId);
        $ofFirst = $this->createWorkflowJob((int)$first->id, (int)$this->launcher->id, WorkflowJob::STATUS_SUCCEEDED);
        $ofSecond = $this->createWorkflowJob((int)$second->id, (int)$this->launcher->id, WorkflowJob::STATUS_SUCCEEDED);
        $this->authenticate($this->launcher);

        foreach ([(string)$first->id, '00' . $first->id] as $value) {
            $this->setQueryParams(['workflow_template_id' => $value]);
            $ids = array_column($this->data($this->controller()->actionIndex()), 'id');
            $this->assertSame([(int)$ofFirst->id], $ids, $value);
        }
        $this->setQueryParams(['workflow_template_id' => (string)$second->id]);
        $this->assertSame([(int)$ofSecond->id], array_column($this->data($this->controller()->actionIndex()), 'id'));
    }

    /**
     * A filter never widens team scoping: a member who filters by another
     * team's workflow, or by what runs wait for, gets only runs they may
     * see; an empty list, not 403 or 422, so nothing is learned.
     */
    public function testFiltersKeepTeamScoping(): void
    {
        $s = $this->teamScope();
        $adminId = (int)$s['admin']->id;
        $foreignWorkflow = $this->createWorkflowTemplate($adminId);
        $foreignHold = $this->namedStep($foreignWorkflow, 10, 'hold', WorkflowStep::TYPE_PAUSE);
        $this->namedStep($foreignWorkflow, 20, 'deploy', WorkflowStep::TYPE_JOB, (int)$s['foreign']->id);
        $foreignRun = $this->createWorkflowJob((int)$foreignWorkflow->id, $adminId);
        $this->executionOf($foreignRun, $foreignHold, WorkflowJobStep::STATUS_RUNNING);
        $ownWorkflow = $this->createWorkflowTemplate($adminId);
        $ownHold = $this->namedStep($ownWorkflow, 10, 'hold', WorkflowStep::TYPE_PAUSE);
        $this->namedStep($ownWorkflow, 20, 'deploy', WorkflowStep::TYPE_JOB, (int)$s['own']->id);
        $ownRun = $this->createWorkflowJob((int)$ownWorkflow->id, $adminId);
        $this->executionOf($ownRun, $ownHold, WorkflowJobStep::STATUS_RUNNING);
        $this->authenticate($s['member']);

        $this->setQueryParams(['workflow_template_id' => (string)$foreignWorkflow->id]);
        $result = $this->controller()->actionIndex();
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame([], $this->data($result));
        $this->assertSame(0, $result['meta']['total']);

        $this->setQueryParams(['waiting_for' => 'resume']);
        $this->assertSame([(int)$ownRun->id], array_column($this->data($this->controller()->actionIndex()), 'id'));

        $this->authenticate($s['admin']);
        $this->assertSame(
            [(int)$ownRun->id, (int)$foreignRun->id],
            array_column($this->data($this->controller()->actionIndex()), 'id'),
            'an admin sees both'
        );
    }

    /**
     * A value the filter does not know answers 422 with the filter's error,
     * so a typo never passes for "no filter"; with several, the first in
     * the order waiting_for, workflow_template_id, status.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidFilterProvider(): array
    {
        $waitingFor = 'waiting_for must be one of: approval, resume, runner.';
        $workflow = 'workflow_template_id must be a positive integer.';
        $status = 'status must be one of: running, succeeded, failed, canceled.';

        return [
            'a state, not a wait' => [['waiting_for' => 'paused'], $waitingFor],
            'a wait in upper case' => [['waiting_for' => 'APPROVAL'], $waitingFor],
            'a list of waits' => [['waiting_for' => ['approval']], $waitingFor],
            'zero' => [['workflow_template_id' => '0'], $workflow],
            'a negative ID' => [['workflow_template_id' => '-3'], $workflow],
            'a name' => [['workflow_template_id' => 'deploy'], $workflow],
            'a fraction' => [['workflow_template_id' => '1.5'], $workflow],
            'a signed ID' => [['workflow_template_id' => '+5'], $workflow],
            'a padded ID' => [['workflow_template_id' => ' 5'], $workflow],
            'beyond the largest integer' => [['workflow_template_id' => '99999999999999999999'], $workflow],
            'a job status' => [['status' => 'pending'], $status],
            'a status in another case' => [['status' => 'Running'], $status],
            'a list of statuses' => [['status' => ['running']], $status],
            'every filter invalid' => [['status' => 'x', 'workflow_template_id' => 'x', 'waiting_for' => 'x'], $waitingFor],
            'two filters invalid' => [['status' => 'x', 'workflow_template_id' => '0'], $workflow],
            'a valid wait, an invalid status' => [['waiting_for' => 'approval', 'status' => 'waiting'], $status],
        ];
    }

    /**
     * @dataProvider invalidFilterProvider
     * @param array<string, mixed> $filters
     */
    public function testAnInvalidFilterAnswers422WithItsError(array $filters, string $message): void
    {
        $this->authenticate($this->approver);
        $this->setQueryParams($filters);

        $result = $this->controller()->actionIndex();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $message]], $result);
        $this->assertContains($message, array_map(
            static fn (string $filter): string => WorkflowJobSearch::errorOf($filter),
            ['waiting_for', 'workflow_template_id', 'status']
        ), 'the text comes from the search model');
    }

    /**
     * The list reads the states of its runs at once: the same number of
     * queries for a page of 2 runs as for a page of 12, for an admin and
     * for a member restricted by team scoping.
     */
    public function testTheListUsesAConstantNumberOfQueries(): void
    {
        $s = $this->teamScope();
        $adminId = (int)$s['admin']->id;
        $templateId = (int)$s['own']->id;
        $short = $this->createWorkflowTemplate($adminId);
        $long = $this->createWorkflowTemplate($adminId);
        $runs = [
            (int)$short->id => $this->runsOf($short, 2, $templateId, $s['outsider']),
            (int)$long->id => $this->runsOf($long, 12, $templateId, $s['outsider']),
        ];

        foreach ([$s['admin'], $s['member']] as $caller) {
            $this->authenticate($caller);
            $queries = [];
            foreach ($runs as $workflowId => $ofWorkflow) {
                $this->setQueryParams(['workflow_template_id' => (string)$workflowId]);
                // Once before measuring: schemas and RBAC assignments are read on first use.
                $this->controller()->actionIndex();
                $result = [];
                $queries[] = $this->queriesOf(function () use (&$result): void {
                    $result = $this->controller()->actionIndex();
                });
                $ids = array_reverse(array_map(static fn (WorkflowJob $run): int => (int)$run->id, $ofWorkflow));
                $this->assertSame($ids, array_column($this->data($result), 'id'), "the page holds exactly the runs of workflow #{$workflowId}");
                $this->assertNotContains(null, array_column($this->data($result), 'waiting_for'), 'every run waits');
            }
            $this->assertSame($queries[0], $queries[1], "user #{$caller->id}: 2 runs and 12 runs");
        }
    }

    // -- Helpers ------------------------------------------------------------------------

    private function controller(): WorkflowJobsController
    {
        return new WorkflowJobsController('api/v1/workflow-jobs', \Yii::$app);
    }

    /**
     * The only step execution of a run detail.
     *
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private function onlyStep(array $detail): array
    {
        /** @var list<array<string, mixed>> $steps */
        $steps = $detail['steps'];
        $this->assertCount(1, $steps);

        return $steps[0];
    }

    /**
     * @param array<string, mixed> $step
     * @return array<string, mixed>
     */
    private function approvalOf(array $step): array
    {
        $approval = $step['approval'];
        $this->assertIsArray($approval);
        /** @var array<string, mixed> $approval */
        return $approval;
    }

    /**
     * A workflow (sign off: approval; hold: pause; deploy: job) with one
     * run per state, oldest first, built as the engine leaves them.
     *
     * @return array{workflow: WorkflowTemplate, runs: array<string, WorkflowJob>}
     */
    private function runsInEveryState(): array
    {
        $launcherId = (int)$this->launcher->id;
        $rule = $this->ruleOf($launcherId, [$this->approver]);
        $workflow = $this->createWorkflowTemplate($launcherId);
        $approval = $this->namedStep($workflow, 10, 'sign off', WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $pause = $this->namedStep($workflow, 20, 'hold', WorkflowStep::TYPE_PAUSE);
        $job = $this->namedStep($workflow, 30, 'deploy', WorkflowStep::TYPE_JOB, $this->templateId);
        $runs = [];
        foreach (['approval', 'gated', 'pause', 'pending', 'queued', 'running', 'between'] as $key) {
            $runs[$key] = $this->createWorkflowJob((int)$workflow->id, $launcherId);
        }
        $placeholder = $this->jobOf(null, $launcherId, Job::STATUS_PENDING_APPROVAL);
        $this->requestOf($placeholder, $rule);
        $this->executionOf($runs['approval'], $approval, WorkflowJobStep::STATUS_RUNNING, $placeholder);
        $gated = $this->jobOf($this->templateId, $launcherId, Job::STATUS_PENDING_APPROVAL);
        $this->requestOf($gated, $rule);
        $this->executionOf($runs['gated'], $job, WorkflowJobStep::STATUS_RUNNING, $gated);
        $this->executionOf($runs['pause'], $pause, WorkflowJobStep::STATUS_RUNNING);
        foreach (['pending' => Job::STATUS_PENDING, 'queued' => Job::STATUS_QUEUED, 'running' => Job::STATUS_RUNNING] as $key => $status) {
            $this->executionOf($runs[$key], $job, WorkflowJobStep::STATUS_RUNNING, $this->jobOf($this->templateId, $launcherId, $status, 1760000160));
        }
        $this->executionOf($runs['between'], $pause, WorkflowJobStep::STATUS_SUCCEEDED);
        $runs['succeeded'] = $this->createWorkflowJob((int)$workflow->id, $launcherId, WorkflowJob::STATUS_SUCCEEDED);
        $this->executionOf($runs['succeeded'], $pause, WorkflowJobStep::STATUS_SUCCEEDED);
        // A run canceled while a step still ran, as a cancel that failed part-way leaves it.
        $runs['canceled'] = $this->createWorkflowJob((int)$workflow->id, $launcherId, WorkflowJob::STATUS_CANCELED);
        $this->executionOf($runs['canceled'], $pause, WorkflowJobStep::STATUS_RUNNING);

        return ['workflow' => $workflow, 'runs' => $runs];
    }

    /**
     * $count runs of the new, empty $workflow, oldest first, that each
     * wait: cycling through an approval step, a gated job step, a pause and
     * a runner, so 2 runs already read everything 12 runs read. The
     * workflow gets its steps here.
     *
     * @return list<WorkflowJob>
     */
    private function runsOf(WorkflowTemplate $workflow, int $count, int $templateId, User $approver): array
    {
        $adminId = (int)$workflow->created_by;
        $rule = $this->ruleOf($adminId, [$approver]);
        $approval = $this->namedStep($workflow, 10, 'sign off', WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $pause = $this->namedStep($workflow, 20, 'hold', WorkflowStep::TYPE_PAUSE);
        $job = $this->namedStep($workflow, 30, 'deploy', WorkflowStep::TYPE_JOB, $templateId);
        $runs = [];
        for ($i = 0; $i < $count; $i++) {
            $run = $this->createWorkflowJob((int)$workflow->id, $adminId);
            $this->executionOf($run, $job, WorkflowJobStep::STATUS_SUCCEEDED, $this->jobOf($templateId, $adminId, Job::STATUS_SUCCEEDED, 1760000160));
            switch ($i % 4) {
                case 0:
                    $placeholder = $this->jobOf(null, $adminId, Job::STATUS_PENDING_APPROVAL);
                    $this->requestOf($placeholder, $rule);
                    $this->executionOf($run, $approval, WorkflowJobStep::STATUS_RUNNING, $placeholder);
                    break;
                case 1:
                    $gated = $this->jobOf($templateId, $adminId, Job::STATUS_PENDING_APPROVAL);
                    $this->requestOf($gated, $rule);
                    $this->executionOf($run, $job, WorkflowJobStep::STATUS_RUNNING, $gated);
                    break;
                case 2:
                    $this->executionOf($run, $pause, WorkflowJobStep::STATUS_RUNNING);
                    break;
                default:
                    $this->executionOf($run, $job, WorkflowJobStep::STATUS_RUNNING, $this->jobOf($templateId, $adminId, Job::STATUS_QUEUED, 1760000160));
            }
            $runs[] = $run;
        }

        return $runs;
    }
}
