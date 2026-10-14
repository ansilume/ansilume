<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\WorkflowTemplateController;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\WorkflowExecutionService;
use app\services\WorkflowLaunchRejectedException;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepChangeRefusedException;
use app\services\WorkflowStepChangeSummary;
use app\services\WorkflowStepsTable;
use app\services\WorkflowTemplateDeletionService;
use app\services\WorkflowTemplatePage;
use app\tests\integration\services\FailingWorkflowCancellation;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\base\ModelEvent;
use yii\db\ActiveRecord;
use yii\helpers\Url;
use yii\web\AssetManager;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\View;

/**
 * The steps of a workflow in the web UI: gated steps (approval steps, and
 * job steps whose job template has an approval rule) are for admins, steps
 * do not change while a run is active, a step that other steps route to is
 * not removed, and deleting a workflow cancels its runs.
 */
class WorkflowTemplateControllerStepGuardTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /** @var array<string, mixed> */
    private array $swapped = [];

    protected function setUp(): void
    {
        parent::setUp();
        \Yii::$app->session->removeAllFlashes();
    }

    protected function tearDown(): void
    {
        \Yii::$app->session->removeAllFlashes();
        foreach ($this->swapped as $id => $original) {
            \Yii::$app->set($id, $original);
        }
        $this->swapped = [];
        parent::tearDown();
    }

    // -- Approval steps are for admins ---------------------------------------

    /**
     * regression: an operator could add an approval step, with any rule, to
     * a workflow they may change.
     */
    public function testAnOperatorCannotAddAnApprovalStep(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $rule = $this->createApprovalRule($s['admin']->id);
        $this->loginAs($s['member']);
        $this->setPost(['WorkflowStep' => ['name' => 'sneaky-gate', 'step_type' => WorkflowStep::TYPE_APPROVAL, 'approval_rule_id' => (string)$rule->id]]);

        $this->assertForbidden(
            fn () => $this->makeController()->actionAddStep((int)$workflow->id),
            ApprovalRuleAssignmentPolicy::APPROVAL_STEP_ADD_REFUSED
        );

        $this->assertFalse(WorkflowStep::find()->where(['name' => 'sneaky-gate'])->exists());
        $this->assertFalse($this->stepAuditExists((int)$workflow->id));
    }

    /**
     * regression: an operator could remove the approval step of a workflow,
     * or move a job step in front of it, and so run its jobs ungated.
     */
    public function testAnOperatorCannotRemoveTheApprovalStepOfAWorkflow(): void
    {
        $s = $this->teamScope();
        [$workflow, $approval, $job] = $this->gatedWorkflow($s['admin']->id, $s['own']->id);
        $before = $this->stepRows($workflow);
        $this->loginAs($s['member']);

        $this->setPost(['step_id' => (string)$approval->id]);
        $this->assertForbidden(
            fn () => $this->makeController()->actionRemoveStep((int)$workflow->id),
            ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED
        );
        $this->setPost(['step_id' => (string)$job->id, 'direction' => 'up']);
        $this->assertForbidden(
            fn () => $this->makeController()->actionMoveStep((int)$workflow->id),
            ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED
        );
        $this->setPost(['WorkflowStep' => ['name' => 'before-the-gate', 'step_type' => WorkflowStep::TYPE_PAUSE, 'step_order' => '0']]);
        $this->assertForbidden(
            fn () => $this->makeController()->actionAddStep((int)$workflow->id),
            ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED
        );

        $this->assertSame($before, $this->stepRows($workflow), 'nothing changed');
        $this->assertFalse($this->stepAuditExists((int)$workflow->id));
    }

    public function testAnAdminAddsAndRemovesApprovalSteps(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $rule = $this->createApprovalRule($s['admin']->id);
        $this->loginAs($s['admin']);

        $this->setPost(['WorkflowStep' => ['name' => 'gate', 'step_type' => WorkflowStep::TYPE_APPROVAL, 'approval_rule_id' => (string)$rule->id]]);
        $this->makeController()->actionAddStep((int)$workflow->id);
        $gate = WorkflowStep::findOne(['workflow_template_id' => $workflow->id, 'name' => 'gate']);
        $this->assertNotNull($gate);
        $this->assertSuccessFlash(sprintf(WorkflowStepChangeSummary::ADDED, 'gate', 2, 2, sprintf(WorkflowStepChangeSummary::AFTER, 'step-0')));
        $added = AuditLog::findOne(['action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_ADDED, 'object_id' => $workflow->id]);
        $this->assertNotNull($added);
        $this->assertSame((int)$rule->id, $this->metadata($added)['approval_rule_id'] ?? null, 'the audit names the rule');

        $this->setPost(['step_id' => (string)$gate->id]);
        $this->makeController()->actionRemoveStep((int)$workflow->id);
        $this->assertNull(WorkflowStep::findOne($gate->id));
        $this->assertSuccessFlash(sprintf(WorkflowStepChangeSummary::REMOVED, 'gate', 2, 2));
        $removed = AuditLog::findOne(['action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_REMOVED, 'object_id' => $workflow->id]);
        $this->assertNotNull($removed);
        $this->assertSame((int)$rule->id, $this->metadata($removed)['approval_rule_id'] ?? null);
    }

    // -- Gated job steps are for admins too --------------------------------------

    /**
     * regression: an operator could add a gated job step to another user's
     * workflow and approve its job. The member, an approver of the job
     * template's rule, added a job step of that template to the admin's
     * workflow; the admin's runs launched its job as the admin, and the
     * member approved alone what they had chosen.
     */
    public function testRegressionAnOperatorCannotAddAGatedJobStepToAnotherUsersWorkflow(): void
    {
        $s = $this->teamScope();
        $this->gate($s['own'], $s);
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['open']->id);
        $workflow->generateTriggerToken($s['admin']->id);
        $before = $this->stepRows($workflow);
        $this->loginAs($s['member']);
        $this->setPost(['WorkflowStep' => ['name' => 'smuggled', 'step_type' => WorkflowStep::TYPE_JOB, 'job_template_id' => (string)$s['own']->id]]);

        $this->assertForbidden(
            fn () => $this->makeController()->actionAddStep((int)$workflow->id),
            ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED
        );

        $this->assertSame($before, $this->stepRows($workflow), 'nothing written');
        $this->assertFalse($this->stepAuditExists((int)$workflow->id));
    }

    /**
     * A forged post sends the gated job template's ID in another form the
     * step's integer rule reads as that ID: it is that template all the same.
     */
    public function testAForgedPostOfAGatedJobTemplateIsRefused(): void
    {
        $s = $this->teamScope();
        $this->gate($s['own'], $s);
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['open']->id);
        $this->loginAs($s['member']);

        foreach (['0' . $s['own']->id, '+' . $s['own']->id] as $forged) {
            $this->setPost(['WorkflowStep' => ['name' => 'forged', 'step_type' => WorkflowStep::TYPE_JOB, 'job_template_id' => $forged]]);
            $this->assertForbidden(
                fn () => $this->makeController()->actionAddStep((int)$workflow->id),
                ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED
            );
        }
        $this->assertFalse(WorkflowStep::find()->where(['name' => 'forged'])->exists());
    }

    /**
     * A gated job template the operator cannot see is refused as not found,
     * like any job template they cannot see: the answer does not tell that
     * it exists, nor that it has an approval rule.
     */
    public function testAGatedJobTemplateTheOperatorCannotSeeIsNotFound(): void
    {
        $s = $this->teamScope();
        $this->gate($s['foreign'], $s);
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['open']->id);
        $this->loginAs($s['member']);
        $this->setPost(['WorkflowStep' => ['name' => 'hidden', 'step_type' => WorkflowStep::TYPE_JOB, 'job_template_id' => (string)$s['foreign']->id]]);

        $this->makeController()->actionAddStep((int)$workflow->id);

        $this->assertSame(
            ['danger' => WorkflowTemplateController::FLASH_STEP_NOT_ADDED . 'The selected job template does not exist.'],
            \Yii::$app->session->getAllFlashes(true)
        );
        $this->assertFalse(WorkflowStep::find()->where(['name' => 'hidden'])->exists());
    }

    /**
     * regression: a workflow with a gated job step stayed open to operators:
     * they could remove or move its steps, or add one before the gated job.
     */
    public function testAWorkflowWithAGatedJobStepIsLockedForOperators(): void
    {
        $s = $this->teamScope();
        $this->gate($s['own'], $s);
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['open']->id, $s['own']->id);
        $steps = WorkflowStep::find()->where(['workflow_template_id' => $workflow->id])->orderBy(['step_order' => SORT_ASC])->all();
        $before = $this->stepRows($workflow);
        $this->loginAs($s['member']);

        $this->setPost(['step_id' => (string)$steps[1]->id]);
        $this->assertForbidden(
            fn () => $this->makeController()->actionRemoveStep((int)$workflow->id),
            ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED
        );
        $this->setPost(['step_id' => (string)$steps[1]->id, 'direction' => 'up']);
        $this->assertForbidden(
            fn () => $this->makeController()->actionMoveStep((int)$workflow->id),
            ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED
        );
        foreach ([WorkflowStep::TYPE_PAUSE => null, WorkflowStep::TYPE_JOB => (string)$s['open']->id] as $type => $templateId) {
            $this->setPost(['WorkflowStep' => ['name' => 'before-the-gate', 'step_type' => $type, 'job_template_id' => $templateId, 'step_order' => '0']]);
            $this->assertForbidden(
                fn () => $this->makeController()->actionAddStep((int)$workflow->id),
                ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED
            );
        }

        $this->assertSame($before, $this->stepRows($workflow), 'nothing changed');
        $this->assertFalse($this->stepAuditExists((int)$workflow->id));

        $params = $this->viewParams((int)$workflow->id);
        $this->assertSame(ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED, $params['stepsLock']);
        $html = $this->renderPage($params);
        $this->assertSame(
            ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED . ' ' . WorkflowTemplatePage::STEPS_LOCKED_NOTE,
            $this->lockedNotice($html)
        );
        $this->assertStringNotContainsString('id="add-step-form"', $html);
        $this->assertStringNotContainsString('move-step', $html);
        $this->assertStringNotContainsString('remove-step', $html);
        $this->assertStringContainsString(Url::to(['/workflow-template/launch', 'id' => $workflow->id]), $html, 'launching stays');
        $this->assertStringContainsString(Url::to(['/workflow-template/update', 'id' => $workflow->id]), $html, 'renaming stays');
    }

    /**
     * An admin still adds, moves and removes the steps of such a workflow,
     * gated job steps included.
     */
    public function testAnAdminChangesTheStepsOfAWorkflowWithAGatedJobStep(): void
    {
        $s = $this->teamScope();
        $this->gate($s['own'], $s);
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['open']->id, $s['own']->id);
        $this->loginAs($s['admin']);

        $params = $this->viewParams((int)$workflow->id);
        $this->assertNull($params['stepsLock']);
        $html = $this->renderPage($params);
        $this->assertNull($this->lockedNotice($html));
        $this->assertStringContainsString('id="add-step-form"', $html);
        $this->assertStringContainsString('remove-step', $html);

        $this->setPost(['WorkflowStep' => ['name' => 'second-gate', 'step_type' => WorkflowStep::TYPE_JOB, 'job_template_id' => (string)$s['own']->id]]);
        $this->makeController()->actionAddStep((int)$workflow->id);
        $this->assertSuccessFlash(sprintf(WorkflowStepChangeSummary::ADDED, 'second-gate', 3, 3, sprintf(WorkflowStepChangeSummary::AFTER, 'step-1')));
        $added = WorkflowStep::findOne(['workflow_template_id' => $workflow->id, 'name' => 'second-gate']);
        $this->assertNotNull($added);
        $this->assertSame((int)$s['own']->id, (int)$added->job_template_id);
        $audit = AuditLog::findOne(['action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_ADDED, 'object_id' => $workflow->id]);
        $this->assertNotNull($audit);
        $this->assertSame((int)$s['own']->id, $this->metadata($audit)['job_template_id'] ?? null, 'the audit names the job template');

        $this->setPost(['step_id' => (string)$added->id, 'direction' => 'up']);
        $this->makeController()->actionMoveStep((int)$workflow->id);
        $this->assertSuccessFlash(sprintf(WorkflowStepChangeSummary::MOVED, 'second-gate', 2, 3, sprintf(WorkflowStepChangeSummary::AFTER, 'step-0')));

        $this->setPost(['step_id' => (string)$added->id]);
        $this->makeController()->actionRemoveStep((int)$workflow->id);
        $this->assertSuccessFlash(sprintf(WorkflowStepChangeSummary::REMOVED, 'second-gate', 2, 3));
        $this->assertNull(WorkflowStep::findOne($added->id));
    }

    /**
     * regression: a job template that got an approval rule left the
     * workflows that use it open to operators. The lock is read when the
     * page is shown and when the steps change.
     */
    public function testAJobTemplateThatGetsARuleLaterLocksTheWorkflowsThatUseIt(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->loginAs($s['member']);

        $this->setPost(['WorkflowStep' => ['name' => 'before-the-rule', 'step_type' => WorkflowStep::TYPE_PAUSE]]);
        $this->makeController()->actionAddStep((int)$workflow->id);
        $this->assertSuccessFlash(sprintf(WorkflowStepChangeSummary::ADDED, 'before-the-rule', 2, 2, sprintf(WorkflowStepChangeSummary::AFTER, 'step-0')));

        $this->gate($s['own'], $s);

        $this->setPost(['WorkflowStep' => ['name' => 'after-the-rule', 'step_type' => WorkflowStep::TYPE_PAUSE]]);
        $this->assertForbidden(
            fn () => $this->makeController()->actionAddStep((int)$workflow->id),
            ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED
        );
        $this->assertFalse(WorkflowStep::find()->where(['name' => 'after-the-rule'])->exists());
        $params = $this->viewParams((int)$workflow->id);
        $this->assertSame(ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED, $params['stepsLock']);
        $this->assertStringNotContainsString('id="add-step-form"', $this->renderPage($params));
    }

    /**
     * The add-step form offers operators only job templates without an
     * approval rule, and says why the others are missing; admins get every
     * job template they may operate.
     */
    public function testOnlyAdminsAreOfferedGatedJobTemplates(): void
    {
        $s = $this->teamScope();
        $this->gate($s['own'], $s);
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['open']->id);
        $hint = WorkflowTemplatePage::GATED_TEMPLATES_HINT;

        $this->loginAs($s['member']);
        $member = $this->viewParams((int)$workflow->id);
        /** @var array<int, string> $memberOptions */
        $memberOptions = $member['jobTemplateOptions'];
        $this->assertArrayHasKey($s['open']->id, $memberOptions);
        $this->assertArrayNotHasKey($s['own']->id, $memberOptions, 'the gated job template');
        $html = $this->renderPage($member);
        $this->assertStringNotContainsString('<option value="' . $s['own']->id . '"', $html);
        $this->assertMatchesRegularExpression('#<div id="ws-gated-templates-hint" class="hint-block">\s*' . preg_quote($hint, '#') . '\s*</div>#', $html);

        $this->loginAs($s['admin']);
        $admin = $this->viewParams((int)$workflow->id);
        /** @var array<int, string> $adminOptions */
        $adminOptions = $admin['jobTemplateOptions'];
        $this->assertSame($s['own']->name, $adminOptions[$s['own']->id] ?? null);
        $html = $this->renderPage($admin);
        $this->assertStringContainsString('<option value="' . $s['own']->id . '"', $html);
        $this->assertStringNotContainsString('ws-gated-templates-hint', $html);
    }

    // -- Active runs ----------------------------------------------------------

    /**
     * regression: changing steps during an active run. A run reads the live
     * steps at every transition, so a change re-routed it, and a removed step
     * stranded it.
     */
    public function testStepsDoNotChangeWhileARunIsActive(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['open']->id);
        $steps = WorkflowStep::find()->where(['workflow_template_id' => $workflow->id])->orderBy(['step_order' => SORT_ASC])->all();
        $run = $this->createWorkflowJob((int)$workflow->id, $s['admin']->id);
        $before = $this->stepRows($workflow);
        $refusal = sprintf(WorkflowStepChangeGuard::RUNS_ACTIVE, '#' . $run->id);
        $this->loginAs($s['admin']);

        $this->setPost(['WorkflowStep' => ['name' => 'late-step', 'step_type' => WorkflowStep::TYPE_PAUSE]]);
        $this->makeController()->actionAddStep((int)$workflow->id);
        $this->assertSame(['danger' => 'Step not added: ' . $refusal], \Yii::$app->session->getAllFlashes(true));

        $this->setPost(['step_id' => (string)$steps[1]->id]);
        $this->makeController()->actionRemoveStep((int)$workflow->id);
        $this->assertSame(['danger' => $refusal], \Yii::$app->session->getAllFlashes(true));

        $this->setPost(['step_id' => (string)$steps[1]->id, 'direction' => 'up']);
        $this->makeController()->actionMoveStep((int)$workflow->id);
        $this->assertSame(['danger' => WorkflowTemplateController::FLASH_STEP_NOT_MOVED . $refusal], \Yii::$app->session->getAllFlashes(true));

        $this->assertSame($before, $this->stepRows($workflow), 'nothing changed');
        $this->assertFalse($this->stepAuditExists((int)$workflow->id));

        $run->status = WorkflowJob::STATUS_SUCCEEDED;
        $run->save(false);
        $this->setPost(['WorkflowStep' => ['name' => 'late-step', 'step_type' => WorkflowStep::TYPE_PAUSE]]);
        $this->makeController()->actionAddStep((int)$workflow->id);
        $this->assertSuccessFlash(sprintf(WorkflowStepChangeSummary::ADDED, 'late-step', 3, 3, sprintf(WorkflowStepChangeSummary::AFTER, 'step-1')), 'a finished run does not block');
    }

    /**
     * The answers of add-step come in the order the API gives them: the
     * approval-step rule (403), an invalid step, then the active run.
     */
    public function testAddStepReportsTheApprovalRuleAndAnInvalidStepBeforeAnActiveRun(): void
    {
        $s = $this->teamScope();
        [$workflow] = $this->gatedWorkflow($s['admin']->id, $s['own']->id);
        $this->createWorkflowJob((int)$workflow->id, $s['admin']->id);
        $invalid = ['WorkflowStep' => ['name' => '', 'step_type' => WorkflowStep::TYPE_PAUSE]];

        $this->loginAs($s['member']);
        $this->setPost($invalid);
        $this->assertForbidden(
            fn () => $this->makeController()->actionAddStep((int)$workflow->id),
            ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED
        );

        $this->loginAs($s['admin']);
        $this->makeController()->actionAddStep((int)$workflow->id);
        $this->assertSame(['danger' => 'Step not added: Name cannot be blank.'], \Yii::$app->session->getAllFlashes(true));
    }

    /**
     * A step is validated again with the workflow locked; what fails then
     * is reported like any invalid step, and nothing is written.
     */
    public function testAStepRefusedWhileTheWorkflowIsLockedIsNotAdded(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $before = $this->stepRows($workflow);
        $handler = static function (ModelEvent $event): void {
            $event->isValid = false;
        };
        $this->loginAs($s['admin']);
        $this->setPost(['WorkflowStep' => ['name' => 'vetoed', 'step_type' => WorkflowStep::TYPE_PAUSE]]);
        Event::on(WorkflowStep::class, ActiveRecord::EVENT_BEFORE_INSERT, $handler);
        try {
            $this->makeController()->actionAddStep((int)$workflow->id);
        } finally {
            Event::off(WorkflowStep::class, ActiveRecord::EVENT_BEFORE_INSERT, $handler);
        }

        $this->assertSame(['danger' => 'Step not added: Validation failed.'], \Yii::$app->session->getAllFlashes(true));
        $this->assertSame($before, $this->stepRows($workflow));
        $this->assertFalse($this->stepAuditExists((int)$workflow->id));
    }

    // -- Routes ---------------------------------------------------------------

    /**
     * regression: removing a routed-to step left the route dangling, and a
     * run that reached it ended there as succeeded.
     */
    public function testAStepThatAnotherStepRoutesToIsNotRemoved(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $target = $this->createWorkflowStep((int)$workflow->id, 20, WorkflowStep::TYPE_PAUSE);
        $routing = $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_JOB, $s['own']->id);
        $routing->on_failure_step_id = (int)$target->id;
        $routing->save(false);
        $this->loginAs($s['admin']);
        $this->setPost(['step_id' => (string)$target->id]);

        $this->makeController()->actionRemoveStep((int)$workflow->id);

        $this->assertSame(
            ['danger' => sprintf(WorkflowStepChangeGuard::STEP_ROUTED_TO, '"step-20"', 'step 1 "step-10"')],
            \Yii::$app->session->getAllFlashes(true)
        );
        $this->assertNotNull(WorkflowStep::findOne($target->id));
        $this->assertFalse($this->stepAuditExists((int)$workflow->id));
    }

    // -- The step rules of the model, as flashes --------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refusedStepProvider(): array
    {
        return [
            'an approval step without a rule' => ['no-rule', WorkflowStep::APPROVAL_RULE_REQUIRED],
            'an unknown approval rule' => ['unknown-rule', WorkflowStep::APPROVAL_RULE_NOT_FOUND],
            'an approval step with an Always route' => ['always-step', WorkflowStep::APPROVAL_ALWAYS_REFUSED],
            'an approval step that always ends the workflow' => ['always-end', WorkflowStep::APPROVAL_ALWAYS_REFUSED],
            'a route to a step of another workflow' => ['outside', WorkflowStep::ROUTE_TARGET_OUTSIDE],
        ];
    }

    /**
     * @dataProvider refusedStepProvider
     */
    public function testTheStepRulesOfTheModelAreShownAsFlashes(string $case, string $message): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $existing = $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_PAUSE);
        $elsewhere = $this->createWorkflowStep((int)$this->createWorkflowTemplate($s['admin']->id)->id, 10, WorkflowStep::TYPE_PAUSE);
        $rule = $this->createApprovalRule($s['admin']->id);
        $approval = ['name' => 'refused', 'step_type' => WorkflowStep::TYPE_APPROVAL, 'approval_rule_id' => (string)$rule->id];
        $fields = match ($case) {
            'no-rule' => ['approval_rule_id' => ''] + $approval,
            'unknown-rule' => ['approval_rule_id' => '987654321'] + $approval,
            'always-step' => ['on_always_step_id' => (string)$existing->id] + $approval,
            'always-end' => ['on_always_step_id' => '0'] + $approval,
            default => ['name' => 'refused', 'step_type' => WorkflowStep::TYPE_PAUSE, 'on_success_step_id' => (string)$elsewhere->id],
        };
        $this->loginAs($s['admin']);
        $this->setPost(['WorkflowStep' => $fields]);

        $this->makeController()->actionAddStep((int)$workflow->id);

        $this->assertSame(['danger' => WorkflowTemplateController::FLASH_STEP_NOT_ADDED . $message], \Yii::$app->session->getAllFlashes(true));
        $this->assertFalse(WorkflowStep::find()->where(['name' => 'refused'])->exists());
    }

    public function testAWorkflowDeletedMeanwhileIsNotFound(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $step = WorkflowStep::findOne(['workflow_template_id' => $workflow->id]);
        $this->assertNotNull($step);
        $this->swap('workflowStepChangeGuard', new class () extends WorkflowStepChangeGuard {
            public function change(WorkflowTemplate $template, int $userId, bool $addsApprovalStep, callable $change): mixed
            {
                throw new WorkflowStepChangeRefusedException(sprintf(self::TEMPLATE_GONE, (int)$template->id), 404);
            }
        });
        $this->loginAs($s['admin']);
        $gone = sprintf(WorkflowStepChangeGuard::TEMPLATE_GONE, (int)$workflow->id);

        $this->setPost(['WorkflowStep' => ['name' => 'late', 'step_type' => WorkflowStep::TYPE_PAUSE]]);
        $this->assertNotFound(fn () => $this->makeController()->actionAddStep((int)$workflow->id), $gone);
        $this->setPost(['step_id' => (string)$step->id]);
        $this->assertNotFound(fn () => $this->makeController()->actionRemoveStep((int)$workflow->id), $gone);
        $this->setPost(['step_id' => (string)$step->id, 'direction' => 'down']);
        $this->assertNotFound(fn () => $this->makeController()->actionMoveStep((int)$workflow->id), $gone);
        $this->assertSame([], \Yii::$app->session->getAllFlashes());
    }

    // -- Delete -----------------------------------------------------------------

    /**
     * regression: deleting a workflow template left its runs running, and a
     * run waiting at an approval step kept its request pending.
     */
    public function testDeletingAWorkflowCancelsItsRuns(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $pause = $this->createWorkflowStep((int)$workflow->id, 5, WorkflowStep::TYPE_PAUSE);
        $run = $this->createWorkflowJob((int)$workflow->id, $s['admin']->id);
        $waiting = $this->execution($run, $pause);
        $this->loginAs($s['admin']);

        $this->makeController()->actionDelete((int)$workflow->id);

        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $run->status);
        $waiting->refresh();
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $waiting->status);
        $this->assertSame(
            ['success' => sprintf(WorkflowTemplateController::FLASH_DELETED_RUNS_CANCELED, $workflow->name, 1)],
            \Yii::$app->session->getAllFlashes(true)
        );
        $audit = AuditLog::findOne(['action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_DELETED, 'object_id' => $workflow->id]);
        $this->assertNotNull($audit);
        $this->assertSame($s['admin']->id, (int)$audit->user_id);
        $this->assertSame(['name' => $workflow->name, 'canceled_runs' => 1, 'failed_runs' => 0, 'source' => 'web'], $this->metadata($audit));
    }

    /**
     * regression: a failed cancel during a workflow template delete left the
     * other runs running, and the delete answered with an error page although
     * the template was deleted. Now the other runs are canceled, and a
     * warning flash says how many could not be.
     */
    public function testDeletingAWorkflowWhoseRunCannotBeCanceledWarnsWithTheCounts(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $failing = $this->createWorkflowJob((int)$workflow->id, $s['admin']->id);
        $other = $this->createWorkflowJob((int)$workflow->id, $s['admin']->id);
        $this->swap('workflowCancellationService', new FailingWorkflowCancellation((int)$failing->id));
        $this->loginAs($s['admin']);

        $this->assertInstanceOf(Response::class, $this->makeController()->actionDelete((int)$workflow->id));

        $this->assertSame(
            ['warning' => sprintf(WorkflowTemplateController::FLASH_DELETED_RUNS_FAILED, $workflow->name, 1, 1)],
            \Yii::$app->session->getAllFlashes(true)
        );
        $this->assertNull(WorkflowTemplate::findOne($workflow->id));
        $other->refresh();
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $other->status, 'the run after the failing one is canceled');
        $failing->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $failing->status, 'left to the maintenance run');
    }

    public function testDeletingAWorkflowWithoutRunsSaysSo(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->loginAs($s['admin']);

        $this->assertInstanceOf(Response::class, $this->makeController()->actionDelete((int)$workflow->id));

        $this->assertSame(['success' => sprintf(WorkflowTemplateController::FLASH_DELETED, $workflow->name)], \Yii::$app->session->getAllFlashes(true));
        $this->assertNull(WorkflowTemplate::findOne($workflow->id));
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
        $this->loginAs($s['admin']);

        $this->assertNotFound(fn () => $this->makeController()->actionDelete((int)$workflow->id), "Workflow template #{$workflow->id} not found.");
        $this->assertSame([], \Yii::$app->session->getAllFlashes());
    }

    // -- Launch -------------------------------------------------------------------

    /**
     * The engine refuses a launch it cannot run (too few approvers, a deleted
     * template, unusable extra vars) with a WorkflowLaunchRejectedException:
     * a danger flash with the reason. The web launch sends no extra vars.
     */
    public function testALaunchTheEngineRefusesShowsTheReason(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $engine = new class () extends WorkflowExecutionService {
            /** @var list<array<string, mixed>> */
            public array $overrides = [];

            public function launch(WorkflowTemplate $template, int $launchedBy, array $overrides = [], string $source = 'web'): WorkflowJob
            {
                $this->overrides[] = $overrides;
                throw new WorkflowLaunchRejectedException('Step "Gate": approval rule "r" needs 1 approval(s).');
            }
        };
        $this->swap('workflowExecutionService', $engine);
        $this->loginAs($s['member']);
        $this->setQueryParams(['id' => (string)$workflow->id]);

        $this->assertInstanceOf(Response::class, $this->makeController()->actionLaunch());

        $this->assertSame(
            ['danger' => 'Launch failed: Step "Gate": approval rule "r" needs 1 approval(s).'],
            \Yii::$app->session->getAllFlashes(true)
        );
        $this->assertSame([[]], $engine->overrides);
        $this->assertFalse(WorkflowJob::find()->where(['workflow_template_id' => $workflow->id])->exists());
    }

    // -- The page -------------------------------------------------------------------

    public function testOnlyAdminsAreOfferedApprovalSteps(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $rule = $this->createApprovalRule($s['admin']->id);

        $this->loginAs($s['member']);
        $member = $this->viewParams((int)$workflow->id);
        $this->assertFalse($member['mayAssignRules']);
        $this->assertSame([], $member['approvalRuleOptions']);
        $html = $this->renderPage($member);
        $this->assertStringContainsString('id="add-step-form"', $html);
        $this->assertStringContainsString('<option value="pause">Pause</option>', $html);
        $this->assertStringNotContainsString('<option value="approval"', $html);
        $this->assertStringNotContainsString('id="ws-target-approval"', $html);

        $this->loginAs($s['admin']);
        $admin = $this->viewParams((int)$workflow->id);
        $this->assertTrue($admin['mayAssignRules']);
        /** @var array<int, string> $ruleOptions */
        $ruleOptions = $admin['approvalRuleOptions'];
        $this->assertSame($rule->name, $ruleOptions[$rule->id] ?? null);
        $html = $this->renderPage($admin);
        $this->assertStringContainsString('<option value="approval">Approval</option>', $html);
        $this->assertStringContainsString('id="ws-target-approval"', $html);
    }

    public function testAWorkflowWithAnApprovalStepIsLockedForOperators(): void
    {
        $s = $this->teamScope();
        [$workflow] = $this->gatedWorkflow($s['admin']->id, $s['own']->id);

        $this->loginAs($s['member']);
        $params = $this->viewParams((int)$workflow->id);
        $this->assertSame(ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED, $params['stepsLock']);
        $html = $this->renderPage($params);
        $this->assertSame(
            ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED . ' ' . WorkflowTemplatePage::STEPS_LOCKED_NOTE,
            $this->lockedNotice($html)
        );
        $this->assertStringNotContainsString('id="add-step-form"', $html);
        $this->assertStringNotContainsString('move-step', $html);
        $this->assertStringNotContainsString('remove-step', $html);
        $this->assertStringContainsString(Url::to(['/workflow-template/launch', 'id' => $workflow->id]), $html, 'launching stays');
        $this->assertStringContainsString(Url::to(['/workflow-template/update', 'id' => $workflow->id]), $html, 'renaming stays');

        $this->loginAs($s['admin']);
        $params = $this->viewParams((int)$workflow->id);
        $this->assertNull($params['stepsLock']);
        $html = $this->renderPage($params);
        $this->assertStringNotContainsString('id="wf-approval-steps-locked-notice"', $html);
        $this->assertStringContainsString('id="add-step-form"', $html);
        $this->assertStringContainsString('remove-step', $html);
    }

    public function testActiveRunsLockTheStepsOnThePage(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $runs = [];
        for ($i = 0; $i < 12; $i++) {
            $runs[] = (int)$this->createWorkflowJob((int)$workflow->id, $s['admin']->id)->id;
        }
        $this->createWorkflowJob((int)$workflow->id, $s['admin']->id, WorkflowJob::STATUS_SUCCEEDED);
        $this->loginAs($s['admin']);

        $params = $this->viewParams((int)$workflow->id);
        $this->assertSame(array_slice($runs, 0, WorkflowStepChangeGuard::RUN_LIST_LIMIT), $params['activeRunIds'], 'the oldest ten');
        $this->assertSame(12, $params['activeRunCount']);
        $html = $this->renderPage($params);

        $this->assertStringContainsString('id="wf-active-runs-notice"', $html);
        $this->assertStringContainsString('Steps cannot change while run(s) <a href="' . Url::to(['/workflow-job/view', 'id' => $runs[0]]) . '">#' . $runs[0] . '</a>, ', $html);
        $this->assertStringContainsString('#' . $runs[9] . '</a> and 2 more of this workflow are active: wait for them to finish or cancel them.', $html);
        $this->assertStringNotContainsString('#' . $runs[10] . '<', $html, 'the others are counted');
        $this->assertStringNotContainsString('id="add-step-form"', $html);
        $this->assertStringNotContainsString('remove-step', $html);
        $this->assertStringContainsString(Url::to(['/workflow-template/launch', 'id' => $workflow->id]), $html);
        $this->assertStringContainsString('Delete this workflow template? Its 12 running run(s) will be canceled.', $html, 'the delete warns');

        $idle = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->assertStringContainsString(
            'onsubmit="return confirm(&quot;Delete this workflow template?&quot;)"',
            $this->renderPage($this->viewParams((int)$idle->id))
        );

        $single = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $run = $this->createWorkflowJob((int)$single->id, $s['admin']->id);
        $html = $this->renderPage($this->viewParams((int)$single->id));
        $this->assertStringContainsString(
            'Steps cannot change while run(s) <a href="' . Url::to(['/workflow-job/view', 'id' => $run->id]) . '">#' . $run->id . '</a> of this workflow are active',
            $html
        );
    }

    public function testTheStepsTableShowsEveryRoute(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $rule = $this->createApprovalRule($s['admin']->id);
        $job = $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_JOB, $s['own']->id);
        $gate = $this->createWorkflowStep((int)$workflow->id, 20, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $legacyGate = $this->createWorkflowStep((int)$workflow->id, 30, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $legacyGate->on_always_step_id = (int)$job->id;
        $legacyGate->on_failure_step_id = WorkflowStep::END_WORKFLOW;
        $legacyGate->save(false);
        $job->on_always_step_id = (int)$gate->id;
        $job->save(false);
        // Saved by an older version: a route to a step of another workflow.
        $elsewhere = $this->createWorkflowStep((int)$this->createWorkflowTemplate($s['admin']->id)->id, 10, WorkflowStep::TYPE_PAUSE);
        $stray = $this->createWorkflowStep((int)$workflow->id, 40, WorkflowStep::TYPE_PAUSE);
        $stray->on_success_step_id = (int)$elsewhere->id;
        $stray->save(false);
        $this->loginAs($s['admin']);

        $html = $this->renderPage($this->viewParams((int)$workflow->id));

        $this->assertStringContainsString('<th>Always</th>', $html);
        $unused = WorkflowStepsTable::ROUTE_UNUSED;
        $this->assertSame([sprintf(WorkflowStepsTable::ROUTE_MISSING, $elsewhere->id), $unused, $unused], $this->routes($html, $stray));
        $this->assertSame([$unused, $unused, sprintf(WorkflowStepsTable::ROUTE_TO, '2. step-20')], $this->routes($html, $job), 'Always wins');
        $this->assertSame(
            [sprintf(WorkflowStepsTable::ROUTE_TO_NEXT, '3. step-30'), WorkflowStepsTable::ROUTE_END_FAILS, $unused],
            $this->routes($html, $gate),
            'a rejection ends the run'
        );
        $this->assertSame(
            [$unused, WorkflowStepsTable::ROUTE_END_FAILS, sprintf(WorkflowStepsTable::ROUTE_LOOP, '1. step-10') . ' ' . WorkflowStepsTable::APPROVAL_ALWAYS_NOTE],
            $this->routes($html, $legacyGate)
        );
    }

    public function testTheTriggerCardSaysTheExtraVarsReachEveryJobStep(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $workflow->generateTriggerToken($s['admin']->id);
        $this->loginAs($s['admin']);

        $html = $this->renderPage($this->viewParams((int)$workflow->id));

        $this->assertStringContainsString('id="wf-trigger-extra-vars-note"', $html);
        $this->assertStringContainsString('reach every job step of the run', $html);
    }

    // -- Helpers --------------------------------------------------------------------

    /**
     * A workflow of an approval step, then a job step of $jobTemplateId.
     *
     * @return array{0: WorkflowTemplate, 1: WorkflowStep, 2: WorkflowStep}
     */
    private function gatedWorkflow(int $createdBy, int $jobTemplateId): array
    {
        $workflow = $this->createWorkflowTemplate($createdBy);
        $rule = $this->createApprovalRule($createdBy, ApprovalRule::APPROVER_TYPE_USERS, (string)json_encode(['user_ids' => [$createdBy]]));
        $approval = $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $job = $this->createWorkflowStep((int)$workflow->id, 20, WorkflowStep::TYPE_JOB, $jobTemplateId);

        return [$workflow, $approval, $job];
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
     * The text of the notice that locks the steps, whitespace collapsed;
     * null when the page has none.
     */
    private function lockedNotice(string $html): ?string
    {
        if (preg_match('#<div class="alert alert-secondary small" id="wf-approval-steps-locked-notice">(.*?)</div>#s', $html, $notice) !== 1) {
            return null;
        }

        return (string)preg_replace('/\s+/', ' ', trim(html_entity_decode(strip_tags($notice[1]))));
    }

    private function execution(WorkflowJob $run, WorkflowStep $step): WorkflowJobStep
    {
        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = (int)$run->id;
        $execution->workflow_step_id = (int)$step->id;
        $execution->status = WorkflowJobStep::STATUS_RUNNING;
        $execution->started_at = time();
        $execution->save(false);

        return $execution;
    }

    /**
     * What the On Success, On Failure and Always cells of $step's row show.
     *
     * @return list<string>
     */
    private function routes(string $html, WorkflowStep $step): array
    {
        $found = preg_match('#<tr\b[^>]*\bdata-step-id="' . $step->id . '"[^>]*>(.*?)</tr>#s', $html, $row);
        $this->assertSame(1, $found, "row of step #{$step->id}");
        preg_match_all('#<td\b[^>]*\bdata-route="(?:success|failure|always)"[^>]*>(.*?)</td>#s', $row[1], $cells);

        return array_map(
            static fn (string $cell): string => (string)preg_replace('/\s+/', ' ', trim(html_entity_decode(strip_tags($cell)))),
            $cells[1]
        );
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

    private function stepAuditExists(int $workflowId): bool
    {
        return AuditLog::find()->where([
            'action' => [
                AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_ADDED,
                AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_REMOVED,
                AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_MOVED,
            ],
            'object_id' => $workflowId,
        ])->exists();
    }

    /**
     * @return array<string, mixed> what actionView passes to the view
     */
    private function viewParams(int $workflowId): array
    {
        $ctrl = $this->makeController();
        $ctrl->actionView($workflowId);

        return $ctrl->capturedParams;
    }

    /**
     * The real page, rendered with $params, with dummy asset bundles; view,
     * asset manager and controller are restored.
     *
     * @param array<string, mixed> $params
     */
    private function renderPage(array $params): string
    {
        $components = \Yii::$app->getComponents(true);
        $originals = ['view' => $components['view'] ?? null, 'assetManager' => $components['assetManager'] ?? null];
        $previousController = \Yii::$app->controller;
        \Yii::$app->set('assetManager', new AssetManager(['bundles' => false, 'basePath' => sys_get_temp_dir(), 'baseUrl' => '/assets']));
        \Yii::$app->set('view', new View());
        $ctrl = new WorkflowTemplateController('workflow-template', \Yii::$app);
        \Yii::$app->controller = $ctrl;
        try {
            return $ctrl->renderPartial('view', $params);
        } finally {
            \Yii::$app->controller = $previousController;
            foreach ($originals as $id => $definition) {
                \Yii::$app->set($id, $definition);
            }
        }
    }

    private function swap(string $id, object $replacement): void
    {
        if (!array_key_exists($id, $this->swapped)) {
            $this->swapped[$id] = \Yii::$app->getComponents(true)[$id] ?? null;
        }
        \Yii::$app->set($id, $replacement);
    }

    /**
     * The flashes are the success flash of a change, which starts with
     * $first (WorkflowStepChangeSummary words the rest), and maybe warnings.
     */
    private function assertSuccessFlash(string $first, string $message = ''): void
    {
        $flashes = \Yii::$app->session->getAllFlashes(true);
        $this->assertSame([], array_diff(array_keys($flashes), ['success', 'warning']), $message);
        $this->assertStringStartsWith($first, (string)($flashes['success'] ?? ''), $message);
    }

    private function assertForbidden(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail('Expected ForbiddenHttpException.');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function assertNotFound(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail('Expected NotFoundHttpException.');
        } catch (NotFoundHttpException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(AuditLog $audit): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string)$audit->metadata, true);
        return $decoded;
    }

    /**
     * @return WorkflowTemplateController&object{capturedParams: array<string, mixed>}
     */
    private function makeController(): WorkflowTemplateController
    {
        return new class ('workflow-template', \Yii::$app) extends WorkflowTemplateController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): Response
            {
                $response = new Response();
                $response->content = 'redirected';
                return $response;
            }
        };
    }
}
