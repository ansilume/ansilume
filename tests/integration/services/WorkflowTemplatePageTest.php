<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRule;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\WorkflowTemplatePage;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * WorkflowTemplatePage: what the workflow template page shows a user, its
 * texts, whether its add-step form may add a step, and the step that form
 * posts. WorkflowTemplateController renders and handles the requests; its
 * tests (WorkflowTemplateController*Test, WorkflowTemplateGatedStepViewTest)
 * show the page's behaviour end to end.
 */
class WorkflowTemplatePageTest extends DbTestCase
{
    use TeamScopeFixtures;

    /**
     * The texts keep their wording: docs/updating.md and the E2E specs
     * (tests/e2e/lib/workflows.ts) quote them as the page shows them, so a
     * change here must change them too.
     */
    public function testTheTextsKeepTheirWording(): void
    {
        $this->assertSame(
            [
                'ROUTE_NEXT_STEP' => '→ next step',
                'ROUTE_END' => 'end workflow',
                'ROUTE_END_FAILED' => 'end workflow (failed)',
                'GATED_JOB_FAILURE_ROUTE' => '→ next step; end workflow (failed) after a refused approval',
                'GATED_JOB_FAILURE_TITLE' => 'After a rejected, timed-out or canceled approval, when the job never ran. '
                    . 'A job that ran and failed goes on with the next step.',
                'APPROVAL_ALWAYS_NOTE' => '(only on approval)',
                'GATED_JOB_ALWAYS_NOTE' => '(not after a rejected, timed-out or canceled approval)',
                'GATED_TEMPLATES_HINT' => 'Job templates with an approval rule are not listed: only admins may add a job step for them.',
                'STEPS_LOCKED_NOTE' => 'You may still rename the workflow, launch it and manage its trigger token.',
            ],
            (new \ReflectionClass(WorkflowTemplatePage::class))->getConstants(\ReflectionClassConstant::IS_PUBLIC)
        );
    }

    /**
     * An admin is offered every job template they may operate, gated ones
     * included, and every approval rule by name, and may change the steps
     * of a workflow with a gated step; the page lists the active runs and
     * names whom the trigger runs as.
     */
    public function testAnAdminGetsEveryChoiceAndNoLock(): void
    {
        $s = $this->teamScope();
        $rule = $this->gate($s['own'], $s);
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $run = $this->createWorkflowJob((int)$workflow->id, $s['admin']->id);
        $this->createWorkflowJob((int)$workflow->id, $s['admin']->id, WorkflowJob::STATUS_SUCCEEDED);
        $workflow->generateTriggerToken($s['member']->id);

        $params = (new WorkflowTemplatePage($s['admin']->id))->viewParams($workflow);

        $this->assertSame(
            ['model', 'canOperate', 'jobTemplateOptions', 'approvalRuleOptions', 'mayAssignRules', 'stepsLock', 'activeRunIds', 'triggerUser'],
            array_keys($params),
            'the parameters views/workflow-template/view.php reads'
        );
        $this->assertSame($workflow, $params['model']);
        $this->assertTrue($params['canOperate']);
        foreach (['own', 'viewed', 'foreign', 'open'] as $key) {
            $this->assertSame($s[$key]->name, $params['jobTemplateOptions'][$s[$key]->id] ?? null, $key);
        }
        $this->assertSame($rule->name, $params['approvalRuleOptions'][$rule->id] ?? null);
        $this->assertTrue($params['mayAssignRules']);
        $this->assertNull($params['stepsLock']);
        $this->assertSame([(int)$run->id], $params['activeRunIds'], 'running runs only');
        $this->assertInstanceOf(User::class, $params['triggerUser']);
        $this->assertSame($s['member']->id, $params['triggerUser']->id, 'the user who generated the token');
    }

    /**
     * A user who may not assign approval rules is offered neither approval
     * rules nor job templates with an approval rule, and the steps of a
     * workflow with a gated step are locked for them, with the reason.
     */
    public function testAnOperatorGetsNoGatedChoicesAndTheLock(): void
    {
        $s = $this->teamScope();
        $this->gate($s['own'], $s);
        $gated = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $open = $this->createWorkflowWithJobSteps($s['admin']->id, $s['open']->id);
        $page = new WorkflowTemplatePage($s['member']->id);

        $params = $page->viewParams($gated);

        $this->assertTrue($params['canOperate']);
        $this->assertSame([$s['open']->id => $s['open']->name], $params['jobTemplateOptions'], 'operable and without a rule');
        $this->assertSame([], $params['approvalRuleOptions']);
        $this->assertFalse($params['mayAssignRules']);
        $this->assertSame(ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED, $params['stepsLock']);
        $this->assertSame([], $params['activeRunIds']);
        $this->assertNull($params['triggerUser'], 'no token');

        $this->assertNull($page->viewParams($open)['stepsLock'], 'nothing gated, nothing locked');
    }

    /**
     * A user who may only view the workflow is offered nothing to add.
     */
    public function testAViewerOfTheWorkflowIsOfferedNoSteps(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['viewed']->id);

        $params = (new WorkflowTemplatePage($s['member']->id))->viewParams($workflow);

        $this->assertFalse($params['canOperate']);
        $this->assertSame([], $params['jobTemplateOptions']);
        $this->assertSame([], $params['approvalRuleOptions']);
    }

    /**
     * Only admins add approval steps and gated job steps, or any step to a
     * workflow that has one.
     */
    public function testOnlyAdminsAddGatedSteps(): void
    {
        $s = $this->teamScope();
        $rule = $this->gate($s['own'], $s);
        $plain = $this->createWorkflowWithJobSteps($s['admin']->id, $s['open']->id);
        $locked = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $approval = $this->step(WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $gatedJob = $this->step(WorkflowStep::TYPE_JOB, (int)$s['own']->id);
        $pause = $this->step(WorkflowStep::TYPE_PAUSE);
        $member = new WorkflowTemplatePage($s['member']->id);
        $admin = new WorkflowTemplatePage($s['admin']->id);

        $this->assertSame(ApprovalRuleAssignmentPolicy::APPROVAL_STEP_ADD_REFUSED, $member->addRefusal($plain, $approval));
        $this->assertSame(ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED, $member->addRefusal($plain, $gatedJob));
        $this->assertNull($member->addRefusal($plain, $pause));
        $this->assertSame(ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED, $member->addRefusal($locked, $pause));
        foreach ([$approval, $gatedJob, $pause] as $step) {
            $this->assertNull($admin->addRefusal($locked, $step));
        }
    }

    /**
     * A route the form leaves out or empty is the next step (null); a
     * posted "0" ends the workflow and stays 0; any other value is the ID
     * as an int.
     */
    public function testTheFormsRoutesBecomeTheStepsRoutes(): void
    {
        $step = $this->step(WorkflowStep::TYPE_JOB, 7);
        $step->on_success_step_id = 5;
        $step->on_failure_step_id = 5;
        $step->on_always_step_id = 5;

        WorkflowTemplatePage::completeFromForm($step, ['on_success_step_id' => '0', 'on_failure_step_id' => '']);

        $this->assertSame(WorkflowStep::END_WORKFLOW, $step->on_success_step_id, '"0" ends the workflow');
        $this->assertNull($step->on_failure_step_id, 'empty: the next step');
        $this->assertNull($step->on_always_step_id, 'left out: the next step');

        WorkflowTemplatePage::completeFromForm($step, [
            'on_success_step_id' => '12',
            'on_failure_step_id' => '0',
            'on_always_step_id' => '05',
        ]);

        $this->assertSame(12, $step->on_success_step_id);
        $this->assertSame(WorkflowStep::END_WORKFLOW, $step->on_failure_step_id);
        $this->assertSame(5, $step->on_always_step_id);
    }

    /**
     * A step keeps only the target of its type: the form posts the job and
     * the approval dropdown for every type.
     */
    public function testAStepKeepsOnlyTheTargetOfItsType(): void
    {
        $cases = [
            WorkflowStep::TYPE_JOB => [7, null],
            WorkflowStep::TYPE_APPROVAL => [null, 3],
            WorkflowStep::TYPE_PAUSE => [null, null],
            'unknown' => [null, null],
        ];
        foreach ($cases as $type => [$jobTemplateId, $approvalRuleId]) {
            $step = $this->step((string)$type, 7, 3);

            WorkflowTemplatePage::completeFromForm($step, []);

            $this->assertSame($jobTemplateId, $step->job_template_id, "{$type}: job template");
            $this->assertSame($approvalRuleId, $step->approval_rule_id, "{$type}: approval rule");
        }
    }

    // -- Helpers --------------------------------------------------------------------

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
     * An unsaved step as the add-step form posts it.
     */
    private function step(string $type, ?int $jobTemplateId = null, ?int $approvalRuleId = null): WorkflowStep
    {
        $step = new WorkflowStep();
        $step->name = 'added-' . $type;
        $step->step_type = $type;
        $step->job_template_id = $jobTemplateId;
        $step->approval_rule_id = $approvalRuleId;

        return $step;
    }
}
