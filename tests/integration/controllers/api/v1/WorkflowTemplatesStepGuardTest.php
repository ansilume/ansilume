<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\WorkflowTemplatesController;
use app\models\ApiToken;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\WorkflowExecutionService;
use app\services\WorkflowLaunchRejectedException;
use app\services\WorkflowRunVars;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepChangeRefusedException;
use app\services\WorkflowTemplateDeletionService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\FailingWorkflowCancellation;
use app\tests\integration\TeamScopeFixtures;
use yii\web\NotFoundHttpException;

/**
 * POST and PUT /api/v1/workflow-templates with steps, DELETE and launch:
 * gated steps (approval steps, and job steps whose job template has an
 * approval rule) are for admins (403), steps do not change while a run of
 * the workflow is active (409 with workflow_job_ids), a delete cancels the
 * workflow's runs, and a launch takes extra_vars.
 */
class WorkflowTemplatesStepGuardTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    private WorkflowTemplatesController $ctrl;

    /** @var array<string, mixed> */
    private array $swapped = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new WorkflowTemplatesController('api/v1/workflow-templates', \Yii::$app);
    }

    protected function tearDown(): void
    {
        foreach ($this->swapped as $id => $original) {
            \Yii::$app->set($id, $original);
        }
        $this->swapped = [];
        parent::tearDown();
    }

    // -- Approval steps are for admins ---------------------------------------

    /**
     * regression: an operator could add an approval step, with any rule,
     * through the API.
     */
    public function testAnOperatorCannotCreateAWorkflowWithAnApprovalStep(): void
    {
        $s = $this->teamScope();
        $rule = $this->createApprovalRule($s['admin']->id);
        $this->authenticate($s['member']);
        $this->setBody(['name' => 'api-wf-gated-by-operator', 'steps' => [
            ['name' => 'build', 'job_template_id' => $s['own']->id],
            ['name' => 'gate', 'step_type' => 'approval', 'approval_rule_id' => $rule->id],
        ]]);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => ApprovalRuleAssignmentPolicy::APPROVAL_STEP_ADD_REFUSED]], $result);
        $this->assertFalse(WorkflowTemplate::findWithDeleted()->where(['name' => 'api-wf-gated-by-operator'])->exists());
    }

    /**
     * regression: an operator could remove the approval step of a workflow
     * by replacing its steps, also with the same steps in another order.
     */
    public function testAnOperatorCannotReplaceTheStepsOfAWorkflowWithAnApprovalStep(): void
    {
        $s = $this->teamScope();
        [$workflow, $rule] = $this->gatedWorkflow($s['admin']->id, $s['own']->id);
        $before = $this->stepRows($workflow);
        $this->authenticate($s['member']);

        $bodies = [
            'without the gate' => ['name' => 'renamed', 'steps' => [['name' => 'deploy', 'job_template_id' => $s['own']->id]]],
            'no steps at all' => ['steps' => []],
            'the gate after the job' => ['steps' => [
                ['name' => 'deploy', 'job_template_id' => $s['own']->id],
                ['name' => 'gate', 'step_type' => 'approval', 'approval_rule_id' => $rule->id],
            ]],
        ];
        foreach ($bodies as $case => $body) {
            $this->setBody($body);
            $result = $this->ctrl->actionUpdate($workflow->id);
            $this->assertSame(403, \Yii::$app->response->statusCode, $case);
            $expected = $case === 'the gate after the job'
                ? ApprovalRuleAssignmentPolicy::APPROVAL_STEP_ADD_REFUSED
                : ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED;
            $this->assertSame(['error' => ['message' => $expected]], $result, $case);
        }

        $this->assertSame($before, $this->stepRows($workflow), 'nothing changed');
        $this->assertSame($workflow->name, WorkflowTemplate::findOne($workflow->id)?->name);
    }

    /**
     * Name and description stay the operator's to change; steps: null keeps
     * the steps, so it is no step change.
     */
    public function testAnOperatorMayRenameAWorkflowWithAnApprovalStep(): void
    {
        $s = $this->teamScope();
        [$workflow] = $this->gatedWorkflow($s['admin']->id, $s['own']->id);
        $before = $this->stepRows($workflow);
        $this->authenticate($s['member']);

        $this->setBody(['name' => 'renamed-by-operator']);
        $this->assertSame('renamed-by-operator', $this->data($this->ctrl->actionUpdate($workflow->id))['name']);
        $this->setBody(['description' => 'still gated', 'steps' => null]);
        $this->assertSame('still gated', $this->data($this->ctrl->actionUpdate($workflow->id))['description']);

        $this->assertSame($before, $this->stepRows($workflow));
    }

    public function testAnAdminReplacesTheStepsOfAWorkflowWithAnApprovalStep(): void
    {
        $s = $this->teamScope();
        [$workflow, $rule] = $this->gatedWorkflow($s['admin']->id, $s['own']->id);
        $this->authenticate($s['admin']);
        $this->setBody(['steps' => [
            ['name' => 'gate', 'step_type' => 'approval', 'approval_rule_id' => $rule->id],
            ['name' => 'deploy', 'job_template_id' => $s['open']->id],
        ]]);

        /** @var list<array<string, mixed>> $steps */
        $steps = $this->data($this->ctrl->actionUpdate($workflow->id))['steps'];

        $this->assertSame(['gate', 'deploy'], array_column($steps, 'name'));
        $audit = AuditLog::findOne(['action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_UPDATED, 'object_id' => $workflow->id]);
        $this->assertNotNull($audit);
        $this->assertSame(
            ['name' => $workflow->name, 'source' => 'api', 'job_template_ids' => [$s['open']->id], 'approval_rule_ids' => [$rule->id]],
            json_decode((string)$audit->metadata, true)
        );
    }

    /**
     * The approval-step rule comes before the references: an operator learns
     * nothing about rule IDs; an admin is told the rule does not exist.
     */
    public function testAnUnknownApprovalRuleIs403ForOperatorsAnd422ForAdmins(): void
    {
        $s = $this->teamScope();
        $body = ['name' => 'api-wf-unknown-rule', 'steps' => [['name' => 'gate', 'step_type' => 'approval', 'approval_rule_id' => 987654321]]];

        $this->authenticate($s['member']);
        $this->setBody($body);
        $this->assertSame(['error' => ['message' => ApprovalRuleAssignmentPolicy::APPROVAL_STEP_ADD_REFUSED]], $this->ctrl->actionCreate());
        $this->assertSame(403, \Yii::$app->response->statusCode);

        $this->authenticate($s['admin']);
        $this->setBody($body);
        $this->assertSame(['error' => ['message' => 'Approval rule(s) not found: #987654321.']], $this->ctrl->actionCreate());
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertFalse(WorkflowTemplate::findWithDeleted()->where(['name' => 'api-wf-unknown-rule'])->exists());
    }

    // -- Gated job steps are for admins too --------------------------------------

    /**
     * regression: an operator could add a gated job step to another user's
     * workflow and approve its job. Through the API the member replaced the
     * steps of the admin's workflow with ones that include a job step of a
     * template whose rule lets the member decide; the admin's runs launched
     * that job as the admin, and the member approved it alone.
     */
    public function testRegressionAnOperatorCannotAddAGatedJobStepToAnotherUsersWorkflow(): void
    {
        $s = $this->teamScope();
        $this->gate($s['own'], $s);
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['open']->id);
        $before = $this->stepRows($workflow);
        $this->authenticate($s['member']);
        $this->setBody(['name' => 'renamed', 'steps' => [
            ['name' => 'build', 'job_template_id' => $s['open']->id],
            ['name' => 'smuggled', 'job_template_id' => (string)$s['own']->id],
        ]]);

        $result = $this->ctrl->actionUpdate($workflow->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED]], $result);
        $this->assertSame($before, $this->stepRows($workflow), 'nothing written');
        $this->assertSame($workflow->name, WorkflowTemplate::findOne($workflow->id)?->name);
        $this->assertFalse(AuditLog::find()->where(['action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_UPDATED, 'object_id' => $workflow->id])->exists());
    }

    /**
     * Creating a workflow with a gated job step is adding one: a workflow the
     * operator created can be launched by someone else.
     */
    public function testAnOperatorCannotCreateAWorkflowWithAGatedJobStep(): void
    {
        $s = $this->teamScope();
        $this->gate($s['own'], $s);
        $this->authenticate($s['member']);
        $this->setBody(['name' => 'api-wf-gated-job-by-operator', 'steps' => [['name' => 'deploy', 'job_template_id' => $s['own']->id]]]);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED]], $result);

        // Steps that cannot be read are answered first, as for approval steps.
        $this->setBody(['name' => 'api-wf-gated-job-by-operator', 'steps' => [
            ['name' => 'deploy', 'job_template_id' => $s['own']->id],
            ['name' => '', 'step_type' => 'pause'],
        ]]);
        $this->assertSame(['error' => ['message' => 'steps[1]: Name cannot be blank.']], $this->ctrl->actionCreate());
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertFalse(WorkflowTemplate::findWithDeleted()->where(['name' => 'api-wf-gated-job-by-operator'])->exists());
    }

    /**
     * regression: a workflow with a gated job step stayed open to operators,
     * who could replace its steps, also with none or with the same ones.
     */
    public function testAnOperatorCannotReplaceTheStepsOfAWorkflowWithAGatedJobStep(): void
    {
        $s = $this->teamScope();
        $this->gate($s['own'], $s);
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['open']->id, $s['own']->id);
        $before = $this->stepRows($workflow);
        $this->authenticate($s['member']);

        $bodies = [
            'without the gated job' => ['steps' => [['name' => 'build', 'job_template_id' => $s['open']->id]]],
            'no steps at all' => ['steps' => []],
            'a pause before it' => ['steps' => [['name' => 'wait', 'step_type' => 'pause'], ['name' => 'build', 'job_template_id' => $s['open']->id]]],
        ];
        foreach ($bodies as $case => $body) {
            $this->setBody($body);
            $result = $this->ctrl->actionUpdate($workflow->id);
            $this->assertSame(403, \Yii::$app->response->statusCode, $case);
            $this->assertSame(['error' => ['message' => ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED]], $result, $case);
        }
        $this->setBody(['steps' => [['name' => 'build', 'job_template_id' => $s['open']->id], ['name' => 'deploy', 'job_template_id' => $s['own']->id]]]);
        $this->assertSame(
            ['error' => ['message' => ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED]],
            $this->ctrl->actionUpdate($workflow->id),
            'the same steps again add the gated job step'
        );

        $this->assertSame($before, $this->stepRows($workflow), 'nothing changed');

        $this->setBody(['name' => 'renamed-by-operator', 'steps' => null]);
        $this->assertSame('renamed-by-operator', $this->data($this->ctrl->actionUpdate($workflow->id))['name'], 'renaming stays');
        $this->assertSame($before, $this->stepRows($workflow));
    }

    public function testAnAdminReplacesTheStepsOfAWorkflowWithAGatedJobStep(): void
    {
        $s = $this->teamScope();
        $this->gate($s['own'], $s);
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->authenticate($s['admin']);
        $this->setBody(['steps' => [
            ['name' => 'build', 'job_template_id' => $s['open']->id],
            ['name' => 'deploy', 'job_template_id' => $s['own']->id],
        ]]);

        /** @var list<array<string, mixed>> $steps */
        $steps = $this->data($this->ctrl->actionUpdate($workflow->id))['steps'];

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(['build', 'deploy'], array_column($steps, 'name'));
        $this->assertSame([(int)$s['open']->id, (int)$s['own']->id], array_column($steps, 'job_template_id'));
    }

    /**
     * regression: a job template that got an approval rule left the
     * workflows that use it open to operators.
     */
    public function testAJobTemplateThatGetsARuleLaterLocksTheWorkflowsThatUseIt(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->authenticate($s['member']);
        $this->setBody(['steps' => [['name' => 'deploy', 'job_template_id' => $s['own']->id]]]);
        $this->data($this->ctrl->actionUpdate($workflow->id));
        $before = $this->stepRows($workflow);

        $this->gate($s['own'], $s);
        $this->setBody(['steps' => [['name' => 'wait', 'step_type' => 'pause']]]);

        $this->assertSame(
            ['error' => ['message' => ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED]],
            $this->ctrl->actionUpdate($workflow->id)
        );
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame($before, $this->stepRows($workflow));
    }

    /**
     * A gated job template the caller cannot see answers as any job template
     * they cannot see (422, not found): the 403 would tell that it exists
     * and has an approval rule. One they see but may not operate is refused
     * by the rule first.
     */
    public function testAGatedJobTemplateTheCallerCannotSeeIsNotFound(): void
    {
        $s = $this->teamScope();
        $this->gate($s['foreign'], $s);
        $this->gate($s['viewed'], $s);
        $this->authenticate($s['member']);

        $this->setBody(['name' => 'api-wf-hidden-gate', 'steps' => [['name' => 'deploy', 'job_template_id' => $s['foreign']->id]]]);
        $this->assertSame(
            ['error' => ['message' => 'Job template(s) not found: #' . $s['foreign']->id . '.', 'job_template_ids' => [(int)$s['foreign']->id]]],
            $this->ctrl->actionCreate()
        );
        $this->assertSame(422, \Yii::$app->response->statusCode);

        $this->setBody(['name' => 'api-wf-viewed-gate', 'steps' => [['name' => 'deploy', 'job_template_id' => $s['viewed']->id]]]);
        $this->assertSame(['error' => ['message' => ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED]], $this->ctrl->actionCreate());
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertFalse(WorkflowTemplate::findWithDeleted()->where(['name' => ['api-wf-hidden-gate', 'api-wf-viewed-gate']])->exists());
    }

    // -- Active runs ----------------------------------------------------------

    /**
     * regression: changing steps during an active run. Replacing the steps
     * of a workflow with a running run re-routed or stranded the run.
     */
    public function testStepsAreNotReplacedWhileARunIsActive(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['open']->id);
        $run = $this->createWorkflowJob((int)$workflow->id, $s['admin']->id);
        $before = $this->stepRows($workflow);
        $this->authenticate($s['member']);
        $this->setBody(['name' => 'renamed-while-running', 'steps' => [['name' => 'only', 'job_template_id' => $s['own']->id]]]);

        $result = $this->ctrl->actionUpdate($workflow->id);

        $this->assertSame(409, \Yii::$app->response->statusCode);
        $this->assertSame(
            ['error' => [
                'message' => sprintf(WorkflowStepChangeGuard::RUNS_ACTIVE, '#' . $run->id),
                'workflow_job_ids' => [(int)$run->id],
            ]],
            $result
        );
        $this->assertSame($before, $this->stepRows($workflow), 'the steps are kept');
        $this->assertSame($workflow->name, WorkflowTemplate::findOne($workflow->id)?->name, 'nothing is written');
        $this->assertFalse(AuditLog::find()->where(['action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_UPDATED, 'object_id' => $workflow->id])->exists());
    }

    public function testAnActiveRunDoesNotBlockChangesOfNameAndDescription(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->createWorkflowJob((int)$workflow->id, $s['admin']->id);
        $this->authenticate($s['member']);
        $this->setBody(['name' => 'renamed-while-running', 'steps' => null]);

        $this->assertSame('renamed-while-running', $this->data($this->ctrl->actionUpdate($workflow->id))['name']);
        $this->assertSame(200, \Yii::$app->response->statusCode);
    }

    /**
     * The order of the answers: steps that cannot be read (422), the
     * approval-step rule (403), the references (422, 403), then the active
     * run (409), which is checked with the workflow locked.
     */
    public function testAnActiveRunIsReportedAfterEveryOtherRefusal(): void
    {
        $s = $this->teamScope();
        [$workflow] = $this->gatedWorkflow($s['admin']->id, $s['own']->id);
        $this->createWorkflowJob((int)$workflow->id, $s['admin']->id);

        $this->authenticate($s['member']);
        $this->setBody(['steps' => [['name' => '', 'step_type' => 'pause']]]);
        $this->ctrl->actionUpdate($workflow->id);
        $this->assertSame(422, \Yii::$app->response->statusCode, 'unreadable steps first');
        $this->setBody(['steps' => [['name' => 'pause', 'step_type' => 'pause']]]);
        $this->ctrl->actionUpdate($workflow->id);
        $this->assertSame(403, \Yii::$app->response->statusCode, 'then the approval-step rule');

        $this->authenticate($s['admin']);
        $this->setBody(['steps' => [['name' => 'missing', 'job_template_id' => 987654321]]]);
        $this->ctrl->actionUpdate($workflow->id);
        $this->assertSame(422, \Yii::$app->response->statusCode, 'then the references');
        $this->setBody(['steps' => [['name' => 'pause', 'step_type' => 'pause']]]);
        $this->ctrl->actionUpdate($workflow->id);
        $this->assertSame(409, \Yii::$app->response->statusCode, 'then the active run');
    }

    public function testAWorkflowDeletedMeanwhileIsNotFound(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->swap('workflowStepChangeGuard', new class () extends WorkflowStepChangeGuard {
            public function change(WorkflowTemplate $template, int $userId, bool $addsApprovalStep, callable $change): mixed
            {
                throw new WorkflowStepChangeRefusedException(sprintf(self::TEMPLATE_GONE, (int)$template->id), 404);
            }
        });
        $this->authenticate($s['member']);
        $this->setBody(['steps' => [['name' => 'pause', 'step_type' => 'pause']]]);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage(sprintf(WorkflowStepChangeGuard::TEMPLATE_GONE, (int)$workflow->id));
        $this->ctrl->actionUpdate($workflow->id);
    }

    // -- Delete -----------------------------------------------------------------

    /**
     * regression: deleting a workflow template left its runs running.
     */
    public function testDeletingAWorkflowCancelsItsRunsAndCountsThem(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $running = $this->createWorkflowJob((int)$workflow->id, $s['admin']->id);
        $finished = $this->createWorkflowJob((int)$workflow->id, $s['admin']->id, WorkflowJob::STATUS_FAILED);
        $this->authenticate($s['admin']);

        $this->assertSame(['deleted' => true, 'canceled_runs' => 1, 'failed_runs' => 0], $this->data($this->ctrl->actionDelete($workflow->id)));

        $running->refresh();
        $finished->refresh();
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $running->status);
        $this->assertSame(WorkflowJob::STATUS_FAILED, $finished->status);
        $audit = AuditLog::findOne(['action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_DELETED, 'object_id' => $workflow->id]);
        $this->assertNotNull($audit);
        $this->assertSame($s['admin']->id, (int)$audit->user_id);
        $this->assertSame(['name' => $workflow->name, 'canceled_runs' => 1, 'failed_runs' => 0, 'source' => 'api'], json_decode((string)$audit->metadata, true));
    }

    /**
     * regression: a failed cancel during a workflow template delete left the
     * other runs running, and DELETE answered 500 although the template was
     * deleted. Now the other runs are canceled, and failed_runs counts the
     * ones that could not be.
     */
    public function testDeletingAWorkflowWhoseRunCannotBeCanceledCountsTheFailure(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $failing = $this->createWorkflowJob((int)$workflow->id, $s['admin']->id);
        $other = $this->createWorkflowJob((int)$workflow->id, $s['admin']->id);
        $this->swap('workflowCancellationService', new FailingWorkflowCancellation((int)$failing->id));
        $this->authenticate($s['admin']);

        $this->assertSame(['deleted' => true, 'canceled_runs' => 1, 'failed_runs' => 1], $this->data($this->ctrl->actionDelete($workflow->id)));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertNull(WorkflowTemplate::findOne($workflow->id));
        $other->refresh();
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $other->status, 'the run after the failing one is canceled');
        $failing->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $failing->status, 'left to the maintenance run');
        $audit = AuditLog::findOne(['action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_DELETED, 'object_id' => $workflow->id]);
        $this->assertNotNull($audit);
        $this->assertSame(['name' => $workflow->name, 'canceled_runs' => 1, 'failed_runs' => 1, 'source' => 'api'], json_decode((string)$audit->metadata, true));
    }

    public function testDeletingAWorkflowThatAnotherRequestDeletedIsNotFound(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->swap('workflowTemplateDeletionService', new class () extends WorkflowTemplateDeletionService {
            public function delete(WorkflowTemplate $template, ?int $actorId, string $source): array
            {
                return ['deleted' => false, 'canceled_runs' => 0, 'failed_runs' => 0];
            }
        });
        $this->authenticate($s['admin']);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage("Workflow template #{$workflow->id} not found.");
        $this->ctrl->actionDelete($workflow->id);
    }

    // -- Launch -------------------------------------------------------------------

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function launchBodyProvider(): array
    {
        return [
            'no body' => [[], []],
            'other keys only' => [['limit' => 'web1'], []],
            'an object' => [['extra_vars' => ['env' => 'prod', 'db_password' => 'example-answer']], ['extra_vars' => ['env' => 'prod', 'db_password' => 'example-answer']]],
            'JSON text' => [['extra_vars' => '{"env": "prod"}'], ['extra_vars' => '{"env": "prod"}']],
            'null' => [['extra_vars' => null], ['extra_vars' => null]],
            'a list' => [['extra_vars' => ['a', 'b']], ['extra_vars' => ['a', 'b']]],
        ];
    }

    /**
     * The body's extra_vars go to the engine as they came, whatever their
     * type: the engine decides what it can use.
     *
     * @dataProvider launchBodyProvider
     * @param array<string, mixed> $body
     * @param array<string, mixed> $overrides
     */
    public function testALaunchPassesTheBodysExtraVarsToTheEngine(array $body, array $overrides): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $engine = $this->recordingEngine();
        $this->authenticate($s['member']);
        $this->setBody($body);

        $data = $this->data($this->ctrl->actionLaunch($workflow->id));

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertSame([['overrides' => $overrides, 'source' => 'api']], $engine->calls);
        $this->assertSame($engine->run?->id, $data['workflow_job_id']);
    }

    public function testALaunchTheEngineRefusesIs422WithTheReason(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->recordingEngine(new WorkflowLaunchRejectedException('The extra vars must be a JSON object.'));
        $this->authenticate($s['member']);
        $this->setBody(['extra_vars' => 'not an object']);

        $result = $this->ctrl->actionLaunch($workflow->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'The extra vars must be a JSON object.']], $result);
    }

    /**
     * Contract C5 with the engine itself: the run keeps the extra vars it
     * was launched with; extra vars that are not an object are refused with
     * 422 and no run is written.
     */
    public function testTheEngineStoresTheLaunchExtraVarsOnTheRunOrRefusesThem(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $this->authenticate($s['member']);

        $this->setBody(['extra_vars' => 'prod']);
        $this->assertSame(['error' => ['message' => WorkflowRunVars::NOT_AN_OBJECT]], $this->ctrl->actionLaunch($workflow->id));
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertFalse(WorkflowJob::find()->where(['workflow_template_id' => $workflow->id])->exists(), 'no run');

        $this->setBody(['extra_vars' => ['env' => 'prod']]);
        $data = $this->data($this->ctrl->actionLaunch($workflow->id));
        $this->assertSame(201, \Yii::$app->response->statusCode);
        $run = WorkflowJob::findOne($data['workflow_job_id']);
        $this->assertNotNull($run);
        $this->assertSame(['env' => 'prod'], $run->launchExtraVars());
    }

    // -- Helpers --------------------------------------------------------------------

    /**
     * A workflow of an approval step, whose rule lets $createdBy decide, then
     * a job step of $jobTemplateId.
     *
     * @return array{0: WorkflowTemplate, 1: ApprovalRule}
     */
    private function gatedWorkflow(int $createdBy, int $jobTemplateId): array
    {
        $workflow = $this->createWorkflowTemplate($createdBy);
        $rule = $this->createApprovalRule($createdBy, ApprovalRule::APPROVER_TYPE_USERS, (string)json_encode(['user_ids' => [$createdBy]]));
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, $jobTemplateId);

        return [$workflow, $rule];
    }

    /**
     * Give $template an approval rule whose approvers are the admin and the
     * member of $s, which makes its job steps gated.
     *
     * @param array{member: User, admin: User} $s
     */
    private function gate(JobTemplate $template, array $s): ApprovalRule
    {
        $rule = $this->createApprovalRule(
            $s['admin']->id,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => [$s['admin']->id, $s['member']->id]])
        );
        JobTemplate::updateAll(['approval_rule_id' => $rule->id], ['id' => $template->id]);

        return $rule;
    }

    /**
     * An engine that records each launch and answers with a run, or with
     * $refusal.
     *
     * @return WorkflowExecutionService&object{calls: list<array{overrides: array<string, mixed>, source: string}>, run: WorkflowJob|null}
     */
    private function recordingEngine(?\RuntimeException $refusal = null): WorkflowExecutionService
    {
        $engine = new class ($refusal) extends WorkflowExecutionService {
            /** @var list<array{overrides: array<string, mixed>, source: string}> */
            public array $calls = [];
            public ?WorkflowJob $run = null;

            public function __construct(private readonly ?\RuntimeException $refusal)
            {
                parent::__construct();
            }

            public function launch(WorkflowTemplate $template, int $launchedBy, array $overrides = [], string $source = 'web'): WorkflowJob
            {
                $this->calls[] = ['overrides' => $overrides, 'source' => $source];
                if ($this->refusal !== null) {
                    throw $this->refusal;
                }
                $run = new WorkflowJob();
                $run->workflow_template_id = (int)$template->id;
                $run->launched_by = $launchedBy;
                $run->status = WorkflowJob::STATUS_RUNNING;
                $run->save(false);
                $this->run = $run;

                return $run;
            }
        };
        $this->swap('workflowExecutionService', $engine);

        return $engine;
    }

    /**
     * @return list<array<string, mixed>> the workflow's steps as stored
     */
    private function stepRows(WorkflowTemplate $workflow): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = WorkflowStep::find()
            ->where(['workflow_template_id' => $workflow->id])
            ->orderBy(['id' => SORT_ASC])
            ->asArray()
            ->all();
        return $rows;
    }

    private function swap(string $id, object $replacement): void
    {
        if (!array_key_exists($id, $this->swapped)) {
            $this->swapped[$id] = \Yii::$app->getComponents(true)[$id] ?? null;
        }
        \Yii::$app->set($id, $replacement);
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'workflow-step-guard-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function data(array $result): array
    {
        $this->assertArrayHasKey('data', $result, (string)json_encode($result));
        /** @var array<string, mixed> $data */
        $data = $result['data'];
        return $data;
    }
}
