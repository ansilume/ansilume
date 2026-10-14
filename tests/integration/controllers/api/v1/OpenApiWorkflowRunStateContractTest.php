<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\ApprovalsController;
use app\controllers\api\v1\JobsController;
use app\controllers\api\v1\WorkflowJobsController;
use app\controllers\api\v1\WorkflowTemplatesController;
use app\models\ApprovalRequest;
use app\models\ApprovalRequestSearch;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobSearch;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\ApprovalDecisionRefusedException;
use app\services\WorkflowAccessChecker;
use app\services\WorkflowAccessDeniedException;
use app\services\WorkflowExecutionService;
use app\services\runstate\ApiFields;
use app\services\runstate\ApprovalWait;
use app\services\runstate\Display;
use app\services\runstate\RunState;
use app\services\runstate\StepState;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\CancellationFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\web\Request;

/**
 * What web/openapi.yaml says about what workflow runs wait for is what the
 * API answers: the run list and its filters, the run detail with the
 * approval request a step waits for, the cancel and resume answers, the
 * workflow fields of approval requests and jobs, and the launch answer, each
 * with exactly the documented fields, and the enums and filter errors are
 * the code's.
 *
 * Regressions (2.11.0), each fails on the spec of 2.10.1:
 * - a run that waits for an approval, a resume or a runner said only
 *   `running`: waiting_for and current_step of runs, and waiting_for,
 *   job_status, approval_request_id and approval of their steps, were not
 *   documented;
 * - the run list documented no filters, the approval list no status filter,
 *   and neither their 422 for a value they do not take;
 * - an approval request did not say which workflow run it is for, a job
 *   neither its approval request nor its workflow run;
 * - the launch answered only workflow_job_id, not what the run waits for.
 */
class OpenApiWorkflowRunStateContractTest extends WebControllerTestCase
{
    use OpenApiSchemaAssertions;
    use OpenApiWorkflowAssertions;
    use TeamScopeFixtures;
    use CancellationFixtures;

    private const RUNS = '/api/v1/workflow-jobs';
    private const APPROVALS = '/api/v1/approvals';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->userWithRole('run-state-contract-admin', 'admin');
        $this->loginAs($this->admin);
        // The launches, resumes and cancels below notify: record, do not send.
        $this->recordNotifications();
    }

    protected function tearDown(): void
    {
        $this->restoreNotifications();
        parent::tearDown();
    }

    /**
     * Regression: a run waiting at an approval step read "running" and named
     * no approval request. The list and the detail say what it waits for,
     * at which step, and which request with how many approvers can decide,
     * exactly as WorkflowJob, WorkflowJobCurrentStep, WorkflowJobStep and
     * WorkflowJobStepApproval document it.
     */
    public function testRegressionARunWaitingForAnApprovalAnswersAsTheSpecSays(): void
    {
        ['run' => $run, 'approval' => $step, 'request' => $request, 'placeholder' => $placeholder] = $this->waitingRun();
        $execution = $this->executionOf($run, $step);
        $this->assertNotNull($execution);
        $ctrl = new WorkflowJobsController('api/v1/workflow-jobs', $this->app());

        $item = $this->listItem($ctrl->actionIndex(), (int)$run->id);
        $this->assertRun($item);
        $current = ['id' => (int)$execution->id, 'workflow_step_id' => (int)$step->id, 'step_name' => $step->name, 'step_type' => WorkflowStep::TYPE_APPROVAL];
        $this->assertSame([WorkflowJob::STATUS_RUNNING, Display::WAITING_FOR_APPROVAL, $current], [$item['status'], $item['waiting_for'], $item['current_step']]);

        $detail = $this->data($ctrl->actionView((int)$run->id));
        $this->assertRun($detail, 'WorkflowJobDetail');
        $this->assertSame([Display::WAITING_FOR_APPROVAL, $current], [$detail['waiting_for'], $detail['current_step']]);
        $this->assertIsArray($detail['steps']);
        $this->assertCount(1, $detail['steps']);
        $executed = $detail['steps'][0];
        $this->assertIsArray($executed);
        $this->assertMatchesSchema('WorkflowJobStep', $executed);
        $this->assertMatchesNested('WorkflowJobStep', 'approval', $executed['approval']);
        $this->assertSame(
            [Display::WAITING_FOR_APPROVAL, Job::STATUS_PENDING_APPROVAL, (int)$placeholder->id, (int)$request->id],
            [$executed['waiting_for'], $executed['job_status'], $executed['job_id'], $executed['approval_request_id']]
        );
        $approval = $executed['approval'];
        $this->assertIsArray($approval);
        $this->assertSame(
            [(int)$request->id, ApprovalRequest::STATUS_PENDING, 1, 1, 1, true],
            [$approval['id'], $approval['status'], $approval['required_approvals'], $approval['eligible_count'], $approval['open_count'], $approval['can_still_be_approved']],
            'The pending request of the current step counts the approvers who can still decide.'
        );
        $this->assertDocumentedData('/api/v1/workflow-jobs/{id}', 'get', '200', 'WorkflowJobDetail');
    }

    /**
     * Regression: the run list had no filters. waiting_for,
     * workflow_template_id and status filter as documented, and a value a
     * filter does not take answers 422 with the documented message.
     */
    public function testRegressionTheRunFiltersAnswerAsTheSpecSays(): void
    {
        ['run' => $run] = $this->waitingRun();
        $ctrl = new WorkflowJobsController('api/v1/workflow-jobs', $this->app());
        $this->setQueryParams(['waiting_for' => Display::WAITING_FOR_APPROVAL, 'workflow_template_id' => (string)$run->workflow_template_id, 'status' => WorkflowJob::STATUS_RUNNING]);
        $this->assertRun($this->listItem($ctrl->actionIndex(), (int)$run->id));
        $this->setQueryParams(['waiting_for' => Display::WAITING_FOR_RESUME, 'workflow_template_id' => (string)$run->workflow_template_id]);
        $this->assertSame([], $this->data($ctrl->actionIndex()), 'The run waits for an approval, not for a resume.');

        $parameters = [
            'waiting_for' => ['WaitingForFilter', 'resume ', Display::waitingForValues()],
            'workflow_template_id' => ['WorkflowTemplateIdFilter', '+5', null],
            'status' => ['WorkflowJobStatusFilter', 'Running', WorkflowJob::statuses()],
        ];
        $refusals = [];
        foreach ($parameters as $name => [$parameter, $invalid, $values]) {
            $this->setQueryParams([$name => $invalid]);
            $answer = $ctrl->actionIndex();
            $refusals[] = WorkflowJobSearch::errorOf($name);
            $this->assertSame([422, ['error' => ['message' => WorkflowJobSearch::errorOf($name)]]], [\Yii::$app->response->statusCode, $answer], $name);
            $this->assertParameter($parameter, $name, $values, WorkflowJobSearch::errorOf($name));
        }
        $this->assertEqualsCanonicalizing($refusals, $this->exampleMessages(self::RUNS, 'get', '422'));
        $this->assertSame(
            ['#/components/parameters/Page', '#/components/parameters/WaitingForFilter', '#/components/parameters/WorkflowTemplateIdFilter', '#/components/parameters/WorkflowJobStatusFilter'],
            array_column((array)$this->specAt(['paths', self::RUNS, 'get', 'parameters']), '$ref')
        );
    }

    /**
     * Regression: an approval request did not say which workflow run it is
     * for, and the approval list took no status filter. The request of an
     * approval step names its run, step execution, workflow and step, and
     * the list filters by status, answering 422 for one it does not know.
     */
    public function testRegressionAnApprovalRequestNamesItsWorkflowRunAsTheSpecSays(): void
    {
        ['run' => $run, 'approval' => $step, 'request' => $request] = $this->waitingRun();
        $execution = $this->executionOf($run, $step);
        $this->assertNotNull($execution);
        $ctrl = new ApprovalsController('api/v1/approvals', $this->app());

        $data = $this->data($ctrl->actionView((int)$request->id));
        $this->assertMatchesSchema('ApprovalRequest', $data);
        $this->assertSame(
            [(int)$run->id, (int)$execution->id, (int)$run->workflow_template_id, $step->name],
            [$data['workflow_job_id'], $data['workflow_job_step_id'], $data['workflow_template_id'], $data['workflow_step_name']]
        );
        foreach (['workflow_job_id', 'workflow_job_step_id', 'workflow_template_id', 'workflow_step_name'] as $field) {
            $this->assertContains($field, $this->required('ApprovalRequest'), "{$field} is always returned.");
            $this->assertTrue($this->properties('ApprovalRequest')[$field]['nullable'] ?? false, "{$field} is null for a request without a workflow run.");
        }

        $this->setQueryParams(['status' => ApprovalRequest::STATUS_PENDING]);
        $this->assertMatchesSchema('ApprovalRequest', $this->listItem($ctrl->actionIndex(), (int)$request->id));
        $this->setQueryParams(['status' => 'Pending']);
        $answer = $ctrl->actionIndex();
        $this->assertSame([422, ['error' => ['message' => ApprovalRequestSearch::statusError()]]], [\Yii::$app->response->statusCode, $answer]);
        $this->assertSame([ApprovalRequestSearch::statusError()], $this->exampleMessages(self::APPROVALS, 'get', '422'));
        $this->assertParameter('ApprovalStatusFilter', 'status', ApprovalRequest::statuses(), ApprovalRequestSearch::statusError());
        $this->assertContains('#/components/parameters/ApprovalStatusFilter', array_column((array)$this->specAt(['paths', self::APPROVALS, 'get', 'parameters']), '$ref'));
    }

    /**
     * Regression: a job named neither its approval request nor the workflow
     * run it was created for. The placeholder job of an approval step names
     * both, as JobDetail documents them.
     */
    public function testRegressionAJobNamesItsApprovalRequestAndWorkflowRunAsTheSpecSays(): void
    {
        ['run' => $run, 'approval' => $step, 'request' => $request, 'placeholder' => $placeholder] = $this->waitingRun();
        $execution = $this->executionOf($run, $step);
        $this->assertNotNull($execution);

        $data = $this->data((new JobsController('api/v1/jobs', $this->app()))->actionView((int)$placeholder->id));
        $this->assertMatchesSchema('JobDetail', $data);
        $this->assertSame(
            [(int)$request->id, (int)$run->id, (int)$execution->id],
            [$data['approval_request_id'], $data['workflow_job_id'], $data['workflow_job_step_id']]
        );
        $this->assertStringContainsString(
            '`approval_request_id`, `workflow_job_id` and `workflow_job_step_id` link the job to its approval request and to the workflow run and step execution it was created for',
            $this->specText(['paths', '/api/v1/jobs/{id}', 'get', 'description'])
        );
    }

    /**
     * Regression: the launch answered only the run's ID. A run whose first
     * step waits for an approval says so, with its request, as
     * WorkflowLaunchResult documents it.
     */
    public function testRegressionTheLaunchSaysWhatTheRunWaitsFor(): void
    {
        $approver = $this->createUserWithRole('run-state-contract-approver', 'operator');
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $rule = $this->usersRule((int)$this->admin->id, (int)$approver->id);
        $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->setBodyParams([]);

        $answer = (new WorkflowTemplatesController('api/v1/workflow-templates', $this->app()))->actionLaunch((int)$workflow->id);
        $this->assertSame(201, \Yii::$app->response->statusCode);
        $data = $this->data($answer);
        $this->assertMatchesSchema('WorkflowLaunchResult', $data);
        $execution = WorkflowJobStep::findOne(['workflow_job_id' => $data['workflow_job_id']]);
        $this->assertNotNull($execution);
        $waiting = ApprovalRequest::findOne(['job_id' => $execution->job_id]);
        $this->assertNotNull($waiting, 'The approval step waits for its request.');
        $this->assertSame([Display::WAITING_FOR_APPROVAL, (int)$waiting->id], [$data['waiting_for'], $data['approval_request_id']]);
        $this->assertDocumentedData('/api/v1/workflow-templates/{id}/launch', 'post', '201', 'WorkflowLaunchResult');
        foreach ($this->exampleBodies($this->responseOf('/api/v1/workflow-templates/{id}/launch', 'post', '201')) as $example) {
            $this->assertIsArray($example['data'] ?? null);
            $this->assertMatchesSchema('WorkflowLaunchResult', $example['data']);
        }
    }

    /**
     * A paused run waits for a resume; the resume and the cancel answer the
     * run as WorkflowJob documents it, after the resume without a wait or a
     * current step.
     */
    public function testTheResumeAndTheCancelAnswerARunAsTheSpecSays(): void
    {
        $ctrl = new WorkflowJobsController('api/v1/workflow-jobs', $this->app());
        $paused = $this->pausedRun();
        $item = $this->listItem($ctrl->actionIndex(), (int)$paused->id);
        $this->assertRun($item);
        $this->assertSame(Display::WAITING_FOR_RESUME, $item['waiting_for']);

        $resumed = $this->data($ctrl->actionResume((int)$paused->id));
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertRun($resumed);
        $this->assertSame([WorkflowJob::STATUS_SUCCEEDED, null, null], [$resumed['status'], $resumed['waiting_for'], $resumed['current_step']]);

        $canceled = $this->data($ctrl->actionCancel((int)$this->pausedRun()->id));
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertRun($canceled);
        $this->assertSame([WorkflowJob::STATUS_CANCELED, null, null], [$canceled['status'], $canceled['waiting_for'], $canceled['current_step']]);
        foreach (['cancel', 'resume'] as $operation) {
            $this->assertDocumentedData("/api/v1/workflow-jobs/{id}/{$operation}", 'post', '200', 'WorkflowJob');
        }
    }

    /**
     * The refusals of a cancel and a resume are documented with the code's
     * texts: the gate's, the access check's (which names the job templates
     * only to a caller who may see the workflow), and the run's state.
     */
    public function testTheRunOperationRefusalsAreQuotedFromTheCode(): void
    {
        $scope = $this->teamScope();
        $viewed = $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['viewed']->id);
        $foreign = $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['foreign']->id);
        $visible = $this->operateRefusal((int)$scope['member']->id, (int)$viewed->id);
        $templateId = (int)$scope['viewed']->id;
        $this->assertSame([$templateId], $visible->jobTemplateIds);
        $documented = [
            ['message' => 'Forbidden.'],
            ['message' => str_replace('#' . $templateId . ' ', '#15 ', $visible->getMessage()), 'job_template_ids' => [15]],
            ['message' => $this->operateRefusal((int)$scope['member']->id, (int)$foreign->id)->getMessage()],
        ];
        $this->assertSame([], $this->operateRefusal((int)$scope['member']->id, (int)$foreign->id)->jobTemplateIds, 'A hidden workflow names no job template.');
        foreach (['cancel', 'resume'] as $operation) {
            $this->assertSame($documented, $this->exampleErrors("/api/v1/workflow-jobs/{id}/{$operation}", 'post', '403'), $operation);
        }
        $this->assertSame([WorkflowExecutionService::RUN_FINISHED], $this->exampleMessages('/api/v1/workflow-jobs/{id}/cancel', 'post', '422'));
        $this->assertSame(
            [WorkflowExecutionService::RUN_FINISHED, WorkflowExecutionService::NO_PAUSED_STEP],
            $this->exampleMessages('/api/v1/workflow-jobs/{id}/resume', 'post', '422')
        );
    }

    /**
     * The enums of what runs wait for are the code's, the fields of the
     * nested objects are exactly what ApiFields writes, and the examples
     * have the documented shape.
     */
    public function testTheWaitFieldsAreTheCodesValues(): void
    {
        foreach (['WorkflowJob', 'WorkflowJobStep', 'WorkflowLaunchResult'] as $schema) {
            $this->assertSame(Display::waitingForValues(), $this->enumOf($schema, 'waiting_for'), $schema);
        }
        $this->assertSame(Job::statuses(), $this->enumOf('WorkflowJobStep', 'job_status'));
        $this->assertSame(ApprovalRequest::statuses(), $this->enumOf('WorkflowJobStepApproval', 'status'));
        $this->assertSame([ApprovalRule::TIMEOUT_ACTION_APPROVE, ApprovalRule::TIMEOUT_ACTION_REJECT], $this->enumOf('WorkflowJobStepApproval', 'timeout_action'));

        $wait = new ApprovalWait(12, 34, ApprovalRequest::STATUS_PENDING, 3, 'deploy-prod', 1, 0, 0, 1792396800, null, null, null, true);
        $approval = ApiFields::approval($wait);
        $this->assertIsArray($approval);
        $this->assertSame(array_keys($approval), array_keys($this->properties('WorkflowJobStepApproval')));
        $this->assertSame(array_keys($approval), $this->required('WorkflowJobStepApproval'));
        $this->assertMatchesSchema('WorkflowJobStepApproval', $approval);
        $example = $this->specAt(['components', 'schemas', 'WorkflowJobStepApproval', 'example']);
        $this->assertIsArray($example);
        $this->assertMatchesSchema('WorkflowJobStepApproval', $example);

        $step = new StepState(31, 42, 'Release approval', WorkflowStep::TYPE_APPROVAL, 'running', Display::STATE_AWAITING_APPROVAL, Display::WAITING_FOR_APPROVAL, 34, Job::STATUS_PENDING_APPROVAL, null, 1792396800, null, 1792396800, null, $wait, null);
        $run = new WorkflowJob();
        $run->setAttributes(['id' => 17, 'workflow_template_id' => 5, 'status' => WorkflowJob::STATUS_RUNNING, 'launched_by' => 7, 'started_at' => 1792396800, 'created_at' => 1792396800], false);
        $fields = ApiFields::run($run, new RunState(17, WorkflowJob::STATUS_RUNNING, Display::STATE_AWAITING_APPROVAL, Display::WAITING_FOR_APPROVAL, $step, []));
        $this->assertMatchesSchema('WorkflowJob', $fields);
        $this->assertIsArray($fields['current_step']);
        $this->assertSame(array_keys($fields['current_step']), $this->required('WorkflowJobCurrentStep'));
        $this->assertMatchesSchema('WorkflowJobCurrentStep', $fields['current_step']);
        $this->assertSame($fields['current_step'], $this->specAt(['components', 'schemas', 'WorkflowJobCurrentStep', 'example']), 'The example is a current step ApiFields writes.');
        $this->assertMatchesSchema('WorkflowJobStep', ApiFields::step($step));

        $forbidden = $this->specText(['components', 'responses', 'ApprovalDecisionForbidden', 'description']);
        foreach ([ApprovalDecisionRefusedException::LAUNCHER_REFUSED, ApprovalDecisionRefusedException::LAUNCHER_REFUSED_RUN] as $refusal) {
            $this->assertStringContainsString('`' . $refusal . '`', $forbidden);
        }
    }

    /**
     * A run answer exactly as $schema (WorkflowJob, or WorkflowJobDetail)
     * documents it, its current step as WorkflowJobCurrentStep.
     *
     * @param array<mixed> $run
     */
    private function assertRun(array $run, string $schema = 'WorkflowJob'): void
    {
        $this->assertMatchesSchema($schema, $run);
        $this->assertMatchesNested($schema, 'current_step', $run['current_step']);
    }

    /**
     * The query parameter $parameter: it is $name, its values are $values
     * (null: a whole number from 1), and it quotes $refusal.
     *
     * @param list<string>|null $values
     */
    private function assertParameter(string $parameter, string $name, ?array $values, string $refusal): void
    {
        $definition = $this->specAt(['components', 'parameters', $parameter]);
        $this->assertIsArray($definition);
        $this->assertSame([$name, 'query', false], [$definition['name'] ?? null, $definition['in'] ?? null, $definition['required'] ?? null], $parameter);
        $expected = $values === null ? ['type' => 'integer', 'minimum' => 1] : ['type' => 'string', 'enum' => $values];
        $this->assertSame($expected, $definition['schema'] ?? null, $parameter);
        $this->assertStringContainsString('`' . $refusal . '`', $this->specText(['components', 'parameters', $parameter, 'description']), $parameter);
    }

    /**
     * A run waiting at its approval step (CancellationFixtures), launched by
     * an operator.
     *
     * @return array{run: WorkflowJob, approval: WorkflowStep, request: ApprovalRequest, placeholder: Job}
     */
    private function waitingRun(): array
    {
        $fixture = $this->runAtApprovalStep();
        $this->loginAs($this->admin);

        return [
            'run' => $fixture['run'],
            'approval' => $fixture['approval'],
            'request' => $fixture['request'],
            'placeholder' => $fixture['placeholder'],
        ];
    }

    /**
     * Why $userId may not cancel or resume the runs of workflow
     * $workflowTemplateId.
     */
    private function operateRefusal(int $userId, int $workflowTemplateId): WorkflowAccessDeniedException
    {
        /** @var WorkflowAccessChecker $checker */
        $checker = \Yii::$app->get('workflowAccessChecker');
        try {
            $checker->assertMayOperate($userId, $workflowTemplateId);
        } catch (WorkflowAccessDeniedException $e) {
            return $e;
        }
        $this->fail("User #{$userId} may operate workflow template #{$workflowTemplateId}.");
    }

    /**
     * A run of a workflow with one pause step, launched by the admin: it
     * waits for a resume.
     */
    private function pausedRun(): WorkflowJob
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_PAUSE);

        return $this->workflowEngine()->launch($workflow, (int)$this->admin->id);
    }
}
