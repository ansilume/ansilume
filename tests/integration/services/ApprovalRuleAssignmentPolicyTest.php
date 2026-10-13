<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRule;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Only admins (approval-rule.update, superadmins) assign approval rules to
 * job templates and change the steps of workflows with gated steps:
 * approval steps, and job steps whose job template has an approval rule.
 */
class ApprovalRuleAssignmentPolicyTest extends DbTestCase
{
    use TeamScopeFixtures;

    private ApprovalRuleAssignmentPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ApprovalRuleAssignmentPolicy();
    }

    public function testAdminsAndSuperadminsMayAssignOperatorsAndViewersMayNot(): void
    {
        $superadmin = $this->createUser('assign-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);

        $this->assertTrue($this->policy->mayAssign((int)$this->createUserWithRole('assign-admin', 'admin')->id));
        $this->assertTrue($this->policy->mayAssign((int)$superadmin->id), 'superadmins hold every permission');
        $this->assertFalse($this->policy->mayAssign((int)$this->createUserWithRole('assign-operator', 'operator')->id));
        $this->assertFalse($this->policy->mayAssign((int)$this->createUserWithRole('assign-viewer', 'viewer')->id));
        $this->assertFalse($this->policy->mayAssign((int)$this->createUser('assign-nobody')->id));
    }

    public function testACustomRoleWithTheUpdatePermissionMayAssign(): void
    {
        $user = $this->createUser('assign-custom');
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('rule-admins-' . uniqid());
        $auth->add($role);
        $permission = $auth->getPermission(ApprovalRuleAssignmentPolicy::PERMISSION);
        $this->assertNotNull($permission);
        $auth->addChild($role, $permission);
        $auth->assign($role, (string)$user->id);

        $this->assertTrue($this->policy->mayAssign((int)$user->id));
    }

    public function testADeactivatedAdminMayNotAssign(): void
    {
        $admin = $this->createUserWithRole('assign-inactive', 'admin');
        $admin->status = User::STATUS_INACTIVE;
        $admin->save(false);

        $this->assertFalse($this->policy->mayAssign((int)$admin->id));
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function submittedValueProvider(): array
    {
        return [
            'empty form value' => ['', null],
            'JSON null' => [null, null],
            'integer' => [5, 5],
            'string of digits' => ['5', 5],
            'leading zero' => ['05', 5],
            'zero' => ['0', 0],
            'negative' => ['-1', '-1'],
            'not a number' => ['abc', 'abc'],
            'padded' => [' 5', ' 5'],
            'decimal' => [5.0, 5.0],
            'list' => [[5], [5]],
            'boolean' => [true, true],
        ];
    }

    /**
     * @dataProvider submittedValueProvider
     */
    public function testNormalizeRuleId(mixed $submitted, mixed $expected): void
    {
        $this->assertSame($expected, ApprovalRuleAssignmentPolicy::normalizeRuleId($submitted));
    }

    public function testTheStoredRuleIsTheBaseline(): void
    {
        $user = $this->createUser('assign-baseline');
        $rule = $this->createApprovalRule((int)$user->id);
        $template = $this->templateOf((int)$user->id);

        $this->assertNull(ApprovalRuleAssignmentPolicy::storedRuleId(new JobTemplate()), 'a new template has none');
        $this->assertNull(ApprovalRuleAssignmentPolicy::storedRuleId($template));

        $template->approval_rule_id = (int)$rule->id;
        $template->save(false);
        $template->approval_rule_id = null;
        $this->assertSame((int)$rule->id, ApprovalRuleAssignmentPolicy::storedRuleId($template), 'the stored value, not the one in memory');
    }

    public function testAnOperatorMaySendTheStoredRuleAgainButNotChangeIt(): void
    {
        $operatorId = (int)$this->createUserWithRole('assign-tpl-operator', 'operator')->id;

        $this->assertNull($this->policy->templateRuleRefusal($operatorId, '7', 7), 'the same rule as text');
        $this->assertNull($this->policy->templateRuleRefusal($operatorId, 7, 7));
        $this->assertNull($this->policy->templateRuleRefusal($operatorId, '', null), 'still none');
        $this->assertNull($this->policy->templateRuleRefusal($operatorId, null, null));

        foreach (['another rule' => [8, 7], 'removing it' => ['', 7], 'removing it as JSON' => [null, 7], 'a new template with one' => ['7', null], 'no rule ID' => ['abc', 7]] as $case => [$submitted, $baseline]) {
            $this->assertSame(
                ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED,
                $this->policy->templateRuleRefusal($operatorId, $submitted, $baseline),
                $case
            );
        }
    }

    public function testAnAdminMayAssignAndRemoveRules(): void
    {
        $adminId = (int)$this->createUserWithRole('assign-tpl-admin', 'admin')->id;

        $this->assertNull($this->policy->templateRuleRefusal($adminId, 8, 7));
        $this->assertNull($this->policy->templateRuleRefusal($adminId, '', 7));
        $this->assertNull($this->policy->templateRuleRefusal($adminId, '7', null));
        $this->assertNull($this->policy->templateRuleRefusal($adminId, 'abc', 7), 'validation refuses it, not the policy');
    }

    public function testWorkflowsWithoutApprovalStepsStayOpenToOperators(): void
    {
        $operatorId = (int)$this->createUserWithRole('assign-wf-operator', 'operator')->id;
        $workflow = $this->workflowWith(WorkflowStep::TYPE_JOB, WorkflowStep::TYPE_PAUSE);

        $this->assertFalse($this->policy->hasApprovalStep($workflow));
        $this->assertNull($this->policy->workflowStepsChangeRefusal($operatorId, $workflow, false));
        $this->assertNull($this->policy->workflowStepsChangeRefusal($operatorId, new WorkflowTemplate(), false), 'a new workflow');
    }

    public function testOnlyAdminsAddApprovalSteps(): void
    {
        $operatorId = (int)$this->createUserWithRole('assign-add-operator', 'operator')->id;
        $adminId = (int)$this->createUserWithRole('assign-add-admin', 'admin')->id;
        $workflow = $this->workflowWith(WorkflowStep::TYPE_JOB);

        $this->assertSame(
            ApprovalRuleAssignmentPolicy::APPROVAL_STEP_ADD_REFUSED,
            $this->policy->workflowStepsChangeRefusal($operatorId, $workflow, true)
        );
        $this->assertSame(
            ApprovalRuleAssignmentPolicy::APPROVAL_STEP_ADD_REFUSED,
            $this->policy->workflowStepsChangeRefusal($operatorId, new WorkflowTemplate(), true),
            'creating a workflow with one'
        );
        $this->assertSame(
            ApprovalRuleAssignmentPolicy::APPROVAL_STEP_ADD_REFUSED,
            $this->policy->workflowStepsChangeRefusal($operatorId, $this->workflowWith(WorkflowStep::TYPE_APPROVAL), true),
            'adding one to a workflow that has one already'
        );
        $this->assertNull($this->policy->workflowStepsChangeRefusal($adminId, $workflow, true));
    }

    public function testOnlyAdminsChangeTheStepsOfAWorkflowWithAnApprovalStep(): void
    {
        $operatorId = (int)$this->createUserWithRole('assign-gated-operator', 'operator')->id;
        $adminId = (int)$this->createUserWithRole('assign-gated-admin', 'admin')->id;
        $workflow = $this->workflowWith(WorkflowStep::TYPE_JOB, WorkflowStep::TYPE_APPROVAL);

        $this->assertTrue($this->policy->hasApprovalStep($workflow));
        $this->assertSame(
            ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED,
            $this->policy->workflowStepsChangeRefusal($operatorId, $workflow, false)
        );
        $this->assertNull($this->policy->workflowStepsChangeRefusal($adminId, $workflow, false));
    }

    /**
     * The type is compared as dispatch compares it, although the column's
     * collation ignores case and trailing spaces: a step of type "APPROVAL"
     * or "approval " never asks for a decision.
     */
    public function testOnlyTheExactApprovalTypeCounts(): void
    {
        $operatorId = (int)$this->createUserWithRole('assign-legacy-operator', 'operator')->id;
        $workflow = $this->workflowWith('APPROVAL', 'approval ', 'Approval');

        $this->assertFalse($this->policy->hasApprovalStep($workflow));
        $this->assertNull($this->policy->workflowStepsChangeRefusal($operatorId, $workflow, false));
    }

    public function testIncludesApprovalStepComparesTheTypeExactly(): void
    {
        $this->assertTrue(ApprovalRuleAssignmentPolicy::includesApprovalStep([$this->step('job'), $this->step('approval')]));
        $this->assertFalse(ApprovalRuleAssignmentPolicy::includesApprovalStep([$this->step('job'), $this->step('APPROVAL'), $this->step('approval ')]));
        $this->assertFalse(ApprovalRuleAssignmentPolicy::includesApprovalStep([]));
    }

    // -- Gated job steps --------------------------------------------------------

    /**
     * regression: an operator could add a gated job step to another user's
     * workflow and approve its job. A job step whose job template has an
     * approval rule gates its job as an approval step does: only admins add
     * one, and creating a workflow with one is adding it too.
     */
    public function testOnlyAdminsAddGatedJobSteps(): void
    {
        $s = $this->teamScope();
        $this->gate($s['own']);
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['open']->id);
        $memberId = (int)$s['member']->id;

        $this->assertTrue($this->policy->includesGatedJobStep($memberId, [$this->jobStep($s['own']->id)]));
        $this->assertSame(
            ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED,
            $this->policy->stepsAddRefusal($memberId, $workflow, [$this->jobStep($s['own']->id)])
        );
        $this->assertSame(
            ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED,
            $this->policy->stepsAddRefusal($memberId, new WorkflowTemplate(), [$this->jobStep($s['open']->id), $this->jobStep($s['own']->id)]),
            'creating a workflow with one'
        );
        $this->assertNull($this->policy->stepsAddRefusal((int)$s['admin']->id, $workflow, [$this->jobStep($s['own']->id)]));
        $this->assertNull($this->policy->stepsAddRefusal($memberId, $workflow, [$this->jobStep($s['open']->id)]), 'an ungated job step');
        $this->assertNull($this->policy->stepsAddRefusal($memberId, new WorkflowTemplate(), []), 'no steps');
    }

    /**
     * The ID is read as the step's integer rule reads it, so a forged form
     * value that stands for the gated template is that template; a value
     * that is no ID is no template (validation refuses it).
     */
    public function testAGatedJobTemplateIsFoundInEveryFormOfItsId(): void
    {
        $s = $this->teamScope();
        $this->gate($s['own']);
        $id = (int)$s['own']->id;

        $forms = ['integer' => $id, 'text' => (string)$id, 'leading zero' => '0' . $id, 'plus sign' => '+' . $id, 'final line break' => $id . "\n"];
        foreach ($forms as $case => $value) {
            $this->assertTrue($this->policy->includesGatedJobStep((int)$s['member']->id, [$this->jobStep($value)]), $case);
        }
        foreach (['padded' => ' ' . $id, 'decimal' => $id . '.0', 'list' => [$id], 'none' => null, 'empty' => ''] as $case => $value) {
            $this->assertFalse($this->policy->includesGatedJobStep((int)$s['member']->id, [$this->jobStep($value)]), $case);
        }
    }

    /**
     * Only the exact type job is a job step, as dispatch compares it; a
     * pause step's job template, which older versions saved, gates nothing.
     */
    public function testOnlyJobStepsOfTheExactTypeAreGatedJobSteps(): void
    {
        $s = $this->teamScope();
        $this->gate($s['own']);
        $memberId = (int)$s['member']->id;

        foreach (['JOB', 'job ', WorkflowStep::TYPE_PAUSE, WorkflowStep::TYPE_APPROVAL] as $type) {
            $step = $this->jobStep($s['own']->id);
            $step->step_type = $type;
            $this->assertFalse($this->policy->includesGatedJobStep($memberId, [$step]), $type);
        }

        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $this->createWorkflowStep((int)$workflow->id, 10, 'JOB', (int)$s['own']->id);
        $this->createWorkflowStep((int)$workflow->id, 20, WorkflowStep::TYPE_PAUSE, (int)$s['own']->id);
        $this->assertFalse($this->policy->hasGatedJobStep($workflow));
        $this->assertNull($this->policy->workflowStepsChangeRefusal($memberId, $workflow, false));
    }

    /**
     * A job template the user may not see counts as none: the step is then
     * refused as not found, and the answer must not tell whether that
     * template has a rule. One the user may see but not operate does count,
     * as its rule is on its page.
     */
    public function testAGatedJobTemplateTheUserCannotSeeCountsAsNone(): void
    {
        $s = $this->teamScope();
        $this->gate($s['foreign']);
        $this->gate($s['viewed']);
        $memberId = (int)$s['member']->id;

        $this->assertFalse($this->policy->includesGatedJobStep($memberId, [$this->jobStep($s['foreign']->id)]));
        $this->assertNull($this->policy->stepsAddRefusal($memberId, new WorkflowTemplate(), [$this->jobStep($s['foreign']->id)]));
        $this->assertTrue($this->policy->includesGatedJobStep($memberId, [$this->jobStep($s['viewed']->id)]));
        $this->assertTrue(
            $this->policy->includesGatedJobStep($memberId, [$this->jobStep($s['foreign']->id), $this->jobStep($s['viewed']->id)]),
            'one visible gated template is enough'
        );
        $this->assertTrue($this->policy->includesGatedJobStep((int)$s['outsider']->id, [$this->jobStep($s['foreign']->id)]), 'its own team sees it');
    }

    public function testADeletedJobTemplateGatesNothing(): void
    {
        $s = $this->teamScope();
        $this->gate($s['own']);
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->assertTrue($this->policy->hasGatedJobStep($workflow));

        JobTemplate::updateAll(['deleted_at' => time()], ['id' => $s['own']->id]);

        $this->assertFalse($this->policy->hasGatedJobStep($workflow), 'a step of a deleted template runs nothing');
        $this->assertFalse($this->policy->includesGatedJobStep((int)$s['member']->id, [$this->jobStep($s['own']->id)]));
        $this->assertSame([], ApprovalRuleAssignmentPolicy::gatedJobTemplateIds([(int)$s['own']->id]));
    }

    /**
     * regression: a workflow with a gated job step stayed open to
     * operators, who could replace, move or remove its steps.
     */
    public function testOnlyAdminsChangeTheStepsOfAWorkflowWithAGatedJobStep(): void
    {
        $s = $this->teamScope();
        $this->gate($s['own']);
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['open']->id, $s['own']->id);
        $memberId = (int)$s['member']->id;

        $this->assertSame(
            ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED,
            $this->policy->workflowStepsChangeRefusal($memberId, $workflow, false)
        );
        $this->assertTrue($this->policy->hasGatedJobStep($workflow));
        $this->assertFalse($this->policy->hasApprovalStep($workflow));
        $this->assertSame(
            ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED,
            $this->policy->stepsAddRefusal($memberId, $workflow, [$this->jobStep($s['open']->id)]),
            'adding an ungated step to it'
        );
        $this->assertSame(
            ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED,
            $this->policy->stepsAddRefusal($memberId, $workflow, []),
            'replacing its steps with none'
        );
        $this->assertNull($this->policy->workflowStepsChangeRefusal((int)$s['admin']->id, $workflow, false));
        $this->assertNull($this->policy->stepsAddRefusal((int)$s['admin']->id, $workflow, []));
    }

    /**
     * regression: a job template that got an approval rule left the
     * workflows that use it open to operators. Whether a workflow has a
     * gated job step is read when asked.
     */
    public function testAJobTemplateThatGetsARuleLaterLocksItsWorkflows(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $memberId = (int)$s['member']->id;
        $this->assertNull($this->policy->workflowStepsChangeRefusal($memberId, $workflow, false));

        $this->gate($s['own']);
        $this->assertSame(
            ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED,
            $this->policy->workflowStepsChangeRefusal($memberId, $workflow, false)
        );

        JobTemplate::updateAll(['approval_rule_id' => null], ['id' => $s['own']->id]);
        $this->assertNull($this->policy->workflowStepsChangeRefusal($memberId, $workflow, false), 'and the rule removed unlocks them');
    }

    /**
     * What a change adds is named before what the workflow has, and an
     * approval step before a gated job step.
     */
    public function testTheRefusalNamesWhatTheChangeAddsFirst(): void
    {
        $s = $this->teamScope();
        $rule = $this->gate($s['own']);
        $memberId = (int)$s['member']->id;
        $approval = $this->step(WorkflowStep::TYPE_APPROVAL);
        $approval->approval_rule_id = (int)$rule->id;
        $both = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->createWorkflowStep((int)$both->id, 10, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $gatedOnly = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);

        $this->assertSame(
            ApprovalRuleAssignmentPolicy::APPROVAL_STEP_ADD_REFUSED,
            $this->policy->stepsAddRefusal($memberId, $gatedOnly, [$this->jobStep($s['own']->id), $approval])
        );
        $this->assertSame(
            ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED,
            $this->policy->stepsAddRefusal($memberId, $both, [$this->jobStep($s['own']->id)])
        );
        $this->assertSame(
            ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED,
            $this->policy->stepsAddRefusal($memberId, $both, [$this->step(WorkflowStep::TYPE_PAUSE)]),
            'an approval step locks before a gated job step'
        );
        $this->assertSame(
            ApprovalRuleAssignmentPolicy::APPROVAL_STEP_ADD_REFUSED,
            $this->policy->workflowStepsChangeRefusal($memberId, $gatedOnly, true)
        );
        $this->assertNull($this->policy->stepsAddRefusal((int)$s['admin']->id, $both, [$this->jobStep($s['own']->id), $approval]));
    }

    public function testGatedJobTemplateIdsAreTheLiveOnesWithARule(): void
    {
        $s = $this->teamScope();
        $this->gate($s['viewed']);
        $this->gate($s['own']);
        $ids = [(int)$s['viewed']->id, (int)$s['open']->id, (int)$s['own']->id, (int)$s['own']->id, 987654321];

        $expected = [(int)$s['own']->id, (int)$s['viewed']->id];
        sort($expected);
        $this->assertSame($expected, ApprovalRuleAssignmentPolicy::gatedJobTemplateIds($ids), 'ascending, once each');
        $this->assertSame([], ApprovalRuleAssignmentPolicy::gatedJobTemplateIds([]));
        $this->assertSame([], ApprovalRuleAssignmentPolicy::gatedJobTemplateIds([(int)$s['open']->id]));
    }

    // -- Helpers ----------------------------------------------------------------

    private function templateOf(int $userId): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    private function workflowWith(string ...$types): WorkflowTemplate
    {
        $user = $this->createUser('assign-wf-owner');
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        $rule = $this->createApprovalRule((int)$user->id);
        foreach (array_values($types) as $order => $type) {
            $isJob = $type === WorkflowStep::TYPE_JOB;
            $this->createWorkflowStep(
                (int)$workflow->id,
                $order,
                $type,
                $isJob ? (int)$this->templateOf((int)$user->id)->id : null,
                $isJob ? null : (int)$rule->id
            );
        }

        return $workflow;
    }

    private function step(string $type): WorkflowStep
    {
        $step = new WorkflowStep();
        $step->step_type = $type;

        return $step;
    }

    /**
     * An unsaved job step of the job template $templateId, as a request
     * submits it.
     */
    private function jobStep(mixed $templateId): WorkflowStep
    {
        $step = $this->step(WorkflowStep::TYPE_JOB);
        // As load() sets it from a form: whatever was posted.
        $step->setAttribute('job_template_id', $templateId);

        return $step;
    }

    /**
     * Give $template an approval rule, which makes its job steps gated.
     */
    private function gate(JobTemplate $template): ApprovalRule
    {
        $rule = $this->createApprovalRule((int)$template->created_by);
        JobTemplate::updateAll(['approval_rule_id' => $rule->id], ['id' => $template->id]);

        return $rule;
    }
}
