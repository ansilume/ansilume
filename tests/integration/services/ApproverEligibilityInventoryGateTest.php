<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApproverEligibility;
use app\services\WorkflowExecutionService;
use app\services\WorkflowLaunchRejectedException;
use app\tests\integration\CountsQueries;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The workflow pre-flight (ApproverEligibility::workflowLaunchRefusal())
 * with approval rules on inventories: a job step waits for the rule that
 * gates its job template (JobTemplateGate), the template's own, else its
 * inventory's. A launch is refused before anything is written when a job
 * step's template and inventory have different rules
 * (REFUSAL_WORKFLOW_RULES_DIFFER), when the gating rule of either source is
 * gone (REFUSAL_WORKFLOW_RULE_MISSING), or when too few of the rule's
 * approvers besides the launcher may see the job template's project
 * (REFUSAL_WORKFLOW_LAUNCH): the first such step in run order is named.
 *
 * Inventories get their rule with setAttribute(): the column is read with
 * getAttribute() only, as code that runs before m000088 must.
 */
class ApproverEligibilityInventoryGateTest extends DbTestCase
{
    use CountsQueries;
    use TeamScopeFixtures;

    /** An ID that names no row: of a rule, or of a job template. */
    private const GONE_ID = 987654321;

    private int $owner = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = (int)$this->createUser('inventory-gate-owner')->id;
    }

    /**
     * regression: the pre-flight skipped a job step whose job template has
     * no rule of its own, so a workflow whose job step waits for the rule of
     * its inventory, which nobody besides the launcher could approve, was
     * launched: the run was written and started, and the step failed only
     * when the run reached it. Fails on the 3007a3b ApproverEligibility,
     * whose launch writes the run instead of refusing it.
     */
    public function testRegressionAWorkflowWhoseInventoryGatedStepNobodyCouldApproveWasLaunched(): void
    {
        $launcher = $this->operator('launcher');
        $rule = $this->usersRule([$launcher], 1, 'Production hosts');
        $template = $this->templateOn($this->gatedInventory((int)$rule->id));
        $workflow = $this->createWorkflowTemplate($this->owner);
        $this->step($workflow, 0, WorkflowStep::TYPE_PAUSE, 'Wait');
        $this->step($workflow, 1, WorkflowStep::TYPE_JOB, 'Deploy "web"', (int)$template->id);

        $refusal = null;
        try {
            $this->workflows()->launch($workflow, $launcher);
        } catch (WorkflowLaunchRejectedException $e) {
            $refusal = $e->getMessage();
        }

        $this->assertNotNull($refusal, 'the launch is refused');
        $this->assertSame(0, (int)WorkflowJob::find()->where(['workflow_template_id' => $workflow->id])->count(), 'no run is written');
        $expected = static fn (): string => sprintf(ApproverEligibility::REFUSAL_WORKFLOW_LAUNCH, '"Deploy \"web\""', '"Production hosts"', 1, 0);
        $this->assertSame($expected(), $refusal);
        $this->assertSame(
            'Step "Deploy \"web\"": approval rule "Production hosts" needs 1 approval(s) from users other than the one'
            . ' who launches, but only 0 of its approvers may decide. An admin can add approvers to the rule.',
            $refusal
        );
        $this->assertSame($refusal, $this->eligibility()->workflowLaunchRefusal($workflow, $launcher));
    }

    public function testAnInventoryGatedStepPassesWhileEnoughApproversBesidesTheLauncherMayDecide(): void
    {
        $launcher = $this->operator('launcher');
        $approver = $this->operator('approver');
        $rule = $this->usersRule([$launcher, $approver], 1, 'Production hosts');
        $workflow = $this->createWorkflowTemplate($this->owner);
        $this->step($workflow, 0, WorkflowStep::TYPE_JOB, 'Deploy', (int)$this->templateOn($this->gatedInventory((int)$rule->id))->id);

        $this->assertNull($this->eligibility()->workflowLaunchRefusal($workflow, $launcher));

        $rule->required_approvals = 2;
        $rule->save(false);

        $this->assertSame(
            sprintf(ApproverEligibility::REFUSAL_WORKFLOW_LAUNCH, '"Deploy"', '"Production hosts"', 2, 1),
            $this->eligibility()->workflowLaunchRefusal($workflow, $launcher),
            'the launcher never counts'
        );
    }

    /**
     * Under team scoping the approvers of an inventory's rule are judged by
     * the job template's project, as for the template's own rule: the
     * inventory, which belongs to no project, plays no part.
     */
    public function testTheApproversOfAnInventorysRuleMustSeeTheJobTemplatesProject(): void
    {
        $s = $this->teamScope();
        $launcher = (int)$s['admin']->id;
        $rule = $this->usersRule([(int)$s['outsider']->id], 1, 'Outsiders');
        $inventory = $this->gatedInventory((int)$rule->id);
        $hidden = $this->templateOn($inventory, null, (int)$s['own']->project_id);
        $visible = $this->templateOn($inventory, null, (int)$s['foreign']->project_id);
        $workflow = $this->createWorkflowTemplate($this->owner);
        $step = $this->step($workflow, 0, WorkflowStep::TYPE_JOB, 'Deploy', (int)$hidden->id);

        $this->assertSame(
            sprintf(ApproverEligibility::REFUSAL_WORKFLOW_LAUNCH, '"Deploy"', '"Outsiders"', 1, 0),
            $this->eligibility()->workflowLaunchRefusal($workflow, $launcher),
            'the outsider may not see the project of the step\'s job template'
        );

        $step->job_template_id = (int)$visible->id;
        $step->save(false);

        $this->assertNull($this->eligibility()->workflowLaunchRefusal($workflow, $launcher), 'the outsider sees the other project');
    }

    /**
     * A launch waits for one rule only: a job step whose template has a rule
     * of its own and whose inventory has another can never be launched, so
     * the workflow is refused, before any approver is counted. The same
     * rule on both is the template's own.
     */
    public function testAJobStepWhoseTemplateAndInventoryHaveDifferentRulesRefusesTheLaunch(): void
    {
        $launcher = $this->operator('launcher');
        $approver = $this->operator('approver');
        $own = $this->usersRule([$launcher, $approver], 1, 'Own');
        $ofInventory = $this->usersRule([$launcher, $approver], 1, 'Inventory');
        $template = $this->templateOn($this->gatedInventory((int)$ofInventory->id), (int)$own->id);
        $workflow = $this->createWorkflowTemplate($this->owner);
        $this->step($workflow, 0, WorkflowStep::TYPE_JOB, 'Deploy "web"', (int)$template->id);

        $expected = static fn (): string => sprintf(ApproverEligibility::REFUSAL_WORKFLOW_RULES_DIFFER, '"Deploy \"web\""');
        $refusal = $this->eligibility()->workflowLaunchRefusal($workflow, $launcher);

        $this->assertNotNull($refusal, 'refused although both rules could be approved');
        $this->assertSame($expected(), $refusal);
        $this->assertSame(
            'Step "Deploy \"web\"": its job template and its inventory have different approval rules, and a launch'
            . ' waits for one rule only. An admin can remove the template\'s own rule or give it the inventory\'s rule.',
            $refusal
        );

        $own->approver_config = (string)json_encode(['user_ids' => [$launcher]]);
        $own->save(false);
        $this->assertSame($expected(), $this->eligibility()->workflowLaunchRefusal($workflow, $launcher), 'checked before the approvers');

        $this->inventoryRule((int)$template->inventory_id, (int)$own->id);
        $this->assertSame(
            sprintf(ApproverEligibility::REFUSAL_WORKFLOW_LAUNCH, '"Deploy \"web\""', '"Own"', 1, 0),
            $this->eligibility()->workflowLaunchRefusal($workflow, $launcher),
            'the same rule on both: the template\'s own, whose approvers are counted'
        );
    }

    /**
     * A job step whose gating rule is gone can never be approved, whether
     * it was the template's own rule or its inventory's. Only data written
     * without the foreign keys names a rule that does not exist.
     *
     * @dataProvider sources
     */
    public function testAJobStepWhoseRuleIsGoneRefusesTheLaunch(string $source): void
    {
        $launcher = $this->operator('launcher');
        $template = $source === 'inventory'
            ? $this->templateOn($this->gatedInventory(null))
            : $this->templateOn($this->createInventory($this->owner));
        $workflow = $this->createWorkflowTemplate($this->owner);
        $this->step($workflow, 0, WorkflowStep::TYPE_JOB, 'Deploy', (int)$template->id);
        $this->assertNull($this->eligibility()->workflowLaunchRefusal($workflow, $launcher), 'precondition: not gated');

        $this->withoutForeignKeys(static function () use ($source, $template): void {
            if ($source === 'inventory') {
                Inventory::updateAll(['approval_rule_id' => self::GONE_ID], ['id' => $template->inventory_id]);
            } else {
                JobTemplate::updateAll(['approval_rule_id' => self::GONE_ID], ['id' => $template->id]);
            }
        });

        $this->assertSame(
            sprintf(ApproverEligibility::REFUSAL_WORKFLOW_RULE_MISSING, '"Deploy"'),
            $this->eligibility()->workflowLaunchRefusal($workflow, $launcher)
        );
        $this->assertSame(
            'Step "Deploy": its approval rule no longer exists. An admin can choose another rule.',
            $this->eligibility()->workflowLaunchRefusal($workflow, $launcher)
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function sources(): array
    {
        return ['the job template\'s own rule' => ['job_template'], 'the inventory\'s rule' => ['inventory']];
    }

    /**
     * The steps are checked in run order, (step_order, id), whatever order
     * they were added in: the first step whose approval could never be
     * granted is named, of any kind.
     */
    public function testTheFirstStepInRunOrderThatCouldNeverBeApprovedIsNamed(): void
    {
        $launcher = $this->operator('launcher');
        $approver = $this->operator('approver');
        $nobody = $this->usersRule([$launcher], 1, 'Nobody');
        $own = $this->usersRule([$launcher, $approver], 1, 'Own');
        $workflow = $this->createWorkflowTemplate($this->owner);
        $last = $this->step($workflow, 9, WorkflowStep::TYPE_APPROVAL, 'Sign-off', null, (int)$nobody->id);
        $differ = $this->step($workflow, 4, WorkflowStep::TYPE_JOB, 'Conflict', (int)$this->templateOn($this->gatedInventory((int)$nobody->id), (int)$own->id)->id);
        $later = $this->step($workflow, 4, WorkflowStep::TYPE_JOB, 'Unapprovable', (int)$this->templateOn($this->gatedInventory((int)$nobody->id))->id);
        $this->step($workflow, 1, WorkflowStep::TYPE_JOB, 'Open', (int)$this->templateOn($this->createInventory($this->owner))->id);
        $this->assertLessThan((int)$later->id, (int)$differ->id);

        $this->assertSame(
            sprintf(ApproverEligibility::REFUSAL_WORKFLOW_RULES_DIFFER, '"Conflict"'),
            $this->eligibility()->workflowLaunchRefusal($workflow, $launcher),
            'position 2: of two steps with the same step_order, the lower ID runs first'
        );

        $differ->step_order = 10;
        $differ->save(false);
        $this->assertSame(
            sprintf(ApproverEligibility::REFUSAL_WORKFLOW_LAUNCH, '"Unapprovable"', '"Nobody"', 1, 0),
            $this->eligibility()->workflowLaunchRefusal($workflow, $launcher)
        );

        $later->delete();
        $this->assertSame(
            sprintf(ApproverEligibility::REFUSAL_WORKFLOW_LAUNCH, '"Sign-off"', '"Nobody"', 1, 0),
            $this->eligibility()->workflowLaunchRefusal($workflow, $launcher)
        );

        $last->delete();
        $this->assertSame(
            sprintf(ApproverEligibility::REFUSAL_WORKFLOW_RULES_DIFFER, '"Conflict"'),
            $this->eligibility()->workflowLaunchRefusal($workflow, $launcher),
            'now the last step'
        );
    }

    /**
     * A job step on an inventory without a rule waits for nothing, and a job
     * step whose template was deleted or no longer exists is left to its
     * own check when the run reaches it, whatever rule its inventory has.
     */
    public function testJobStepsThatNoRuleGatesOrWhoseTemplateIsGoneAreNotChecked(): void
    {
        $launcher = $this->operator('launcher');
        $nobody = $this->usersRule([$launcher], 1, 'Nobody');
        $deleted = $this->templateOn($this->gatedInventory((int)$nobody->id));
        $this->assertTrue($deleted->softDelete());
        $workflow = $this->createWorkflowTemplate($this->owner);
        $this->step($workflow, 0, WorkflowStep::TYPE_JOB, 'Open', (int)$this->templateOn($this->createInventory($this->owner))->id);
        $this->step($workflow, 1, WorkflowStep::TYPE_JOB, 'Deleted', (int)$deleted->id);
        $this->step($workflow, 2, WorkflowStep::TYPE_JOB, 'Gone', self::GONE_ID);
        $this->step($workflow, 3, WorkflowStep::TYPE_PAUSE, 'Wait');

        $this->assertNull($this->eligibility()->workflowLaunchRefusal($workflow, $launcher));
    }

    /**
     * The steps, their job templates, the templates' inventories and the
     * rules of both are read at once: steps whose templates' inventories
     * need no approval cost nothing of their own, so a workflow of 12 steps
     * costs what one of 2 costs. The inventory-gated step that ends both is
     * named.
     */
    public function testTheGatesOfTwoOrTwelveStepsCostTheSame(): void
    {
        $launcher = $this->operator('launcher');
        $rule = $this->usersRule([$launcher], 1, 'Nobody');
        $two = $this->workflowEndingInAnInventoryGatedStep(2, $rule);
        $twelve = $this->workflowEndingInAnInventoryGatedStep(12, $rule);
        $expected = sprintf(ApproverEligibility::REFUSAL_WORKFLOW_LAUNCH, '"Last"', '"Nobody"', 1, 0);
        // The first use loads table schemas and the RBAC items.
        $this->assertSame($expected, $this->eligibility()->workflowLaunchRefusal($two, $launcher));
        $this->assertSame($expected, $this->eligibility()->workflowLaunchRefusal($twelve, $launcher));

        $this->assertSame(
            $this->queriesOf(fn () => $this->eligibility()->workflowLaunchRefusal($two, $launcher)),
            $this->queriesOf(fn () => $this->eligibility()->workflowLaunchRefusal($twelve, $launcher))
        );
    }

    /**
     * The rule of every step's inventory is read with the steps: twelve job
     * steps gated by their inventories cost what twelve gated by their
     * templates' own rules cost, each step its approver check.
     */
    public function testAnInventoryGatedStepCostsWhatAStepGatedByItsTemplatesOwnRuleCosts(): void
    {
        $launcher = $this->operator('launcher');
        $approver = $this->operator('approver');
        $byInventory = $this->createWorkflowTemplate($this->owner);
        $byTemplate = $this->createWorkflowTemplate($this->owner);
        for ($i = 0; $i < 12; $i++) {
            $inventoryRule = $this->usersRule([$launcher, $approver], 1, 'Inventory ' . $i);
            $this->step($byInventory, $i, WorkflowStep::TYPE_JOB, 'Step ' . $i, (int)$this->templateOn($this->gatedInventory((int)$inventoryRule->id))->id);
            $ownRule = $this->usersRule([$launcher, $approver], 1, 'Own ' . $i);
            $this->step($byTemplate, $i, WorkflowStep::TYPE_JOB, 'Step ' . $i, (int)$this->templateOn($this->createInventory($this->owner), (int)$ownRule->id)->id);
        }
        $this->assertNull($this->eligibility()->workflowLaunchRefusal($byInventory, $launcher));
        $this->assertNull($this->eligibility()->workflowLaunchRefusal($byTemplate, $launcher));

        $this->assertSame(
            $this->queriesOf(fn () => $this->eligibility()->workflowLaunchRefusal($byTemplate, $launcher)),
            $this->queriesOf(fn () => $this->eligibility()->workflowLaunchRefusal($byInventory, $launcher))
        );
    }

    // -- Helpers ----------------------------------------------------------------------

    private function eligibility(): ApproverEligibility
    {
        /** @var ApproverEligibility $eligibility */
        $eligibility = \Yii::$app->get('approverEligibility');

        return $eligibility;
    }

    private function workflows(): WorkflowExecutionService
    {
        /** @var WorkflowExecutionService $workflows */
        $workflows = \Yii::$app->get('workflowExecutionService');

        return $workflows;
    }

    private function operator(string $suffix): int
    {
        return (int)$this->createUserWithRole($suffix, 'operator')->id;
    }

    /**
     * @param list<int> $approverIds
     */
    private function usersRule(array $approverIds, int $required, string $name): ApprovalRule
    {
        $rule = $this->createApprovalRule(
            $this->owner,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => $approverIds]),
            $required
        );
        $rule->name = $name;
        $rule->save(false);

        return $rule;
    }

    /**
     * A static inventory of no project, with the approval rule $ruleId.
     */
    private function gatedInventory(?int $ruleId): Inventory
    {
        $inventory = $this->createInventory($this->owner);
        $this->inventoryRule((int)$inventory->id, $ruleId);
        $inventory->refresh();

        return $inventory;
    }

    private function inventoryRule(int $inventoryId, ?int $ruleId): void
    {
        $inventory = Inventory::findOne($inventoryId);
        $this->assertNotNull($inventory);
        $inventory->setAttribute('approval_rule_id', $ruleId);
        $inventory->save(false);
    }

    /**
     * A job template on $inventory with the rule $ownRuleId of its own, in
     * the project $projectId, else in a project of its own.
     */
    private function templateOn(Inventory $inventory, ?int $ownRuleId = null, ?int $projectId = null): JobTemplate
    {
        $template = $this->createJobTemplate(
            $projectId ?? (int)$this->createProject($this->owner)->id,
            (int)$inventory->id,
            (int)$this->createRunnerGroup($this->owner)->id,
            $this->owner
        );
        $template->approval_rule_id = $ownRuleId;
        $template->save(false);

        return $template;
    }

    private function step(
        WorkflowTemplate $workflow,
        int $stepOrder,
        string $type,
        string $name,
        ?int $jobTemplateId = null,
        ?int $ruleId = null
    ): WorkflowStep {
        $step = $this->createWorkflowStep((int)$workflow->id, $stepOrder, $type, $jobTemplateId, $ruleId);
        $step->name = $name;
        $step->save(false);

        return $step;
    }

    /**
     * A workflow of $steps job steps: before the last, job steps on
     * templates and inventories of their own without rules; the last, named
     * "Last", on an inventory with $rule.
     */
    private function workflowEndingInAnInventoryGatedStep(int $steps, ApprovalRule $rule): WorkflowTemplate
    {
        $workflow = $this->createWorkflowTemplate($this->owner);
        for ($i = 1; $i < $steps; $i++) {
            $this->step($workflow, $i, WorkflowStep::TYPE_JOB, 'Open ' . $i, (int)$this->templateOn($this->createInventory($this->owner))->id);
        }
        $this->step($workflow, $steps, WorkflowStep::TYPE_JOB, 'Last', (int)$this->templateOn($this->gatedInventory((int)$rule->id))->id);

        return $workflow;
    }

    /**
     * Runs $write with the foreign key checks off, so it can name a row
     * that does not exist; the test's transaction undoes the write.
     */
    private function withoutForeignKeys(callable $write): void
    {
        $db = \Yii::$app->db;
        $db->createCommand('SET FOREIGN_KEY_CHECKS = 0')->execute();
        try {
            $write();
        } finally {
            $db->createCommand('SET FOREIGN_KEY_CHECKS = 1')->execute();
        }
    }
}
