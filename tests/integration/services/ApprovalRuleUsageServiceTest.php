<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\WorkflowStep;
use app\services\ApprovalRuleUsageService;
use app\services\ProjectAccessChecker;
use app\tests\integration\CountsQueries;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Where an approval rule is used: inventories, live job templates, steps of
 * live workflows (of any type) and pending requests whose job still waits.
 * Lists hold only what the viewer may see, totals count everything.
 */
class ApprovalRuleUsageServiceTest extends DbTestCase
{
    use CountsQueries;
    use TeamScopeFixtures;

    private ApprovalRuleUsageService $service;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ApprovalRuleUsageService();
        $admin = $this->createUser('rule-usage-admin');
        $admin->is_superadmin = true;
        $admin->save(false);
        $this->adminId = (int)$admin->id;
    }

    public function testEveryKindOfUseIsFound(): void
    {
        $rule = $this->createApprovalRule($this->adminId);
        $gated = $this->gatedTemplate($rule, 'b-gated');
        $alsoGated = $this->gatedTemplate($rule, 'a-gated');
        $workflow = $this->createWorkflowTemplate($this->adminId);
        $step = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        // A step of a type older versions saved keeps its rule: it is a reference too.
        $legacy = $this->createWorkflowStep((int)$workflow->id, 1, 'APPROVAL', null, (int)$rule->id);
        $this->pendingRequest($rule, $gated, Job::STATUS_PENDING_APPROVAL);

        $usage = $this->service->forRule($rule, $this->adminId);

        $this->assertTrue($usage->isInUse());
        $this->assertSame((string)$rule->name, $usage->ruleName);
        $this->assertSame(2, $usage->jobTemplateTotal);
        $this->assertSame([(int)$alsoGated->id, (int)$gated->id], array_column($usage->jobTemplates, 'id'), 'by name');
        $this->assertSame([
            'id' => (int)$alsoGated->id,
            'name' => 'a-gated',
            'project_id' => (int)$alsoGated->project_id,
            'project_name' => $alsoGated->project->name,
        ], $usage->jobTemplates[0]);
        $this->assertSame(2, $usage->workflowStepTotal);
        $this->assertSame([
            ['workflow_template_id' => (int)$workflow->id, 'workflow_template_name' => (string)$workflow->name, 'step_id' => (int)$step->id, 'step_name' => (string)$step->name],
            ['workflow_template_id' => (int)$workflow->id, 'workflow_template_name' => (string)$workflow->name, 'step_id' => (int)$legacy->id, 'step_name' => (string)$legacy->name],
        ], $usage->workflowSteps);
        $this->assertSame(1, $usage->pendingRequestCount);
    }

    /**
     * Soft-deleted templates and workflows cannot run (the delete detaches
     * them), a pending request whose job no longer waits decides nothing,
     * and resolved requests are history: none of them is a use.
     */
    public function testWhatCannotRunAnyMoreIsNoUse(): void
    {
        $rule = $this->createApprovalRule($this->adminId);
        $deletedTemplate = $this->gatedTemplate($rule, 'deleted');
        $deletedTemplate->softDelete();
        $deletedWorkflow = $this->createWorkflowTemplate($this->adminId);
        $this->createWorkflowStep((int)$deletedWorkflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $deletedWorkflow->softDelete();
        $other = $this->templateOf();
        $this->pendingRequest($rule, $other, Job::STATUS_CANCELED);
        $this->pendingRequest($rule, $other, Job::STATUS_QUEUED);
        $resolved = $this->pendingRequest($rule, $other, Job::STATUS_SUCCEEDED);
        $resolved->status = ApprovalRequest::STATUS_APPROVED;
        $resolved->save(false);
        $otherRule = $this->createApprovalRule($this->adminId);
        $this->gatedTemplate($otherRule, 'other-rule');
        $this->pendingRequest($otherRule, $other, Job::STATUS_PENDING_APPROVAL);

        $usage = $this->service->forRule($rule, $this->adminId);

        $this->assertFalse($usage->isInUse(), $usage->summary());
        $this->assertSame(0, $usage->jobTemplateTotal);
        $this->assertSame(0, $usage->workflowStepTotal);
        $this->assertSame(0, $usage->pendingRequestCount);
    }

    public function testTheListsHoldOnlyWhatTheViewerMaySee(): void
    {
        $s = $this->teamScope();
        $rule = $this->createApprovalRule((int)$s['admin']->id);
        foreach (['own', 'foreign', 'open'] as $key) {
            $s[$key]->approval_rule_id = (int)$rule->id;
            $s[$key]->save(false);
        }
        $visible = $this->createWorkflowWithJobSteps((int)$s['admin']->id, (int)$s['own']->id);
        $visibleStep = $this->createWorkflowStep((int)$visible->id, 5, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $hidden = $this->createWorkflowWithJobSteps((int)$s['admin']->id, (int)$s['foreign']->id);
        $this->createWorkflowStep((int)$hidden->id, 5, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);

        $usage = $this->service->forRule($rule, (int)$s['member']->id);

        $this->assertSame(3, $usage->jobTemplateTotal);
        $this->assertEqualsCanonicalizing([(int)$s['own']->id, (int)$s['open']->id], array_column($usage->jobTemplates, 'id'));
        $this->assertSame(1, $usage->hiddenJobTemplateCount());
        $this->assertSame(2, $usage->workflowStepTotal);
        $this->assertSame([(int)$visibleStep->id], array_column($usage->workflowSteps, 'step_id'));
        $this->assertSame(1, $usage->hiddenWorkflowStepCount());

        $everything = $this->service->forRule($rule, (int)$s['admin']->id);
        $this->assertCount(3, $everything->jobTemplates, 'admins see every team');
        $this->assertCount(2, $everything->workflowSteps);
    }

    public function testWithoutAViewerNothingIsListedButEverythingCounted(): void
    {
        $rule = $this->createApprovalRule($this->adminId);
        $this->gatedTemplate($rule, 'counted');
        $workflow = $this->createWorkflowTemplate($this->adminId);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $this->gatedInventory($rule, 'counted-hosts', null);

        $usage = $this->service->forRule($rule, null);

        $this->assertSame([], $usage->jobTemplates);
        $this->assertSame([], $usage->workflowSteps);
        $this->assertSame([], $usage->inventories, 'not even an inventory without a project');
        $this->assertSame(1, $usage->jobTemplateTotal);
        $this->assertSame(1, $usage->workflowStepTotal);
        $this->assertSame(1, $usage->inventoryTotal);
        $this->assertSame(1, $usage->hiddenInventoryCount());
        $this->assertTrue($usage->isInUse());
    }

    // -- Inventories --------------------------------------------------------------

    /**
     * regression: inventories were no use. An inventory with the rule gates
     * every launch of its job templates, but the usage counted none, so the
     * rule's page said it was not in use and offered its delete, which the
     * foreign key then refused.
     */
    public function testRegressionInventoriesWereNoUse(): void
    {
        $rule = $this->createApprovalRule($this->adminId);
        $inventory = $this->gatedInventory($rule, 'gated-hosts', null);

        $usage = $this->service->forRule($rule, $this->adminId);

        $this->assertTrue($usage->isInUse(), 'an inventory with the rule is a use');
        $this->assertSame(1, $usage->inventoryTotal);
        $this->assertSame(
            [['id' => (int)$inventory->id, 'name' => 'gated-hosts', 'project_id' => null, 'project_name' => null]],
            $usage->inventories
        );
        $this->assertSame('Approval rule ' . json_encode($rule->name) . ' is in use by 1 inventory(ies).', $usage->summary());
    }

    /**
     * The inventory list holds what the viewer may see: inventories without
     * a project, and those of projects the viewer may see (operated or only
     * viewed). Admins see every one; the total counts them all.
     */
    public function testTheInventoryListHoldsOnlyWhatTheViewerMaySee(): void
    {
        $s = $this->teamScope();
        $rule = $this->createApprovalRule((int)$s['admin']->id);
        $own = $this->gatedInventory($rule, 'b-own', (int)$s['own']->project_id);
        $viewed = $this->gatedInventory($rule, 'c-viewed', (int)$s['viewed']->project_id);
        $this->gatedInventory($rule, 'd-foreign', (int)$s['foreign']->project_id);
        $open = $this->gatedInventory($rule, 'e-open', (int)$s['open']->project_id);
        $global = $this->gatedInventory($rule, 'a-global', null);

        $usage = $this->service->forRule($rule, (int)$s['member']->id);

        $this->assertSame(5, $usage->inventoryTotal);
        $this->assertSame(
            [(int)$global->id, (int)$own->id, (int)$viewed->id, (int)$open->id],
            array_column($usage->inventories, 'id'),
            'by name; the foreign project\'s inventory is hidden'
        );
        $this->assertSame(1, $usage->hiddenInventoryCount());
        $this->assertSame([
            'id' => (int)$own->id,
            'name' => 'b-own',
            'project_id' => (int)$s['own']->project_id,
            'project_name' => $s['own']->project->name,
        ], $usage->inventories[1]);
        $this->assertStringContainsString('5 inventory(ies)', $usage->summary(), 'the summary counts the hidden one too');

        $everything = $this->service->forRule($rule, (int)$s['admin']->id);
        $this->assertCount(5, $everything->inventories, 'admins see every team');
        $this->assertSame(0, $everything->hiddenInventoryCount());
    }

    /**
     * The list comes by name, then ID; another rule's inventories and
     * ungated ones are no use.
     */
    public function testOnlyTheRulesInventoriesAreListedByNameThenId(): void
    {
        $rule = $this->createApprovalRule($this->adminId);
        $older = $this->gatedInventory($rule, 'b-same-name', null);
        $newer = $this->gatedInventory($rule, 'b-same-name', null);
        $first = $this->gatedInventory($rule, 'a-first', null);
        $this->gatedInventory($this->createApprovalRule($this->adminId), 'a-other-rule', null);
        $this->createInventory($this->adminId);

        $usage = $this->service->forRule($rule, $this->adminId);

        $this->assertSame(3, $usage->inventoryTotal);
        $this->assertSame([(int)$first->id, (int)$older->id, (int)$newer->id], array_column($usage->inventories, 'id'));
    }

    /**
     * On a default install no project is team-restricted, so the visibility
     * filter is null: an operator gets every inventory and job template
     * listed, and the lists are read without an SQL error.
     */
    public function testWithoutTeamRestrictedProjectsEveryUseIsListed(): void
    {
        $this->assertFalse($this->projects()->hasRestrictedProjects(), 'precondition: no team-restricted project');
        $operator = $this->createUserWithRole('rule-usage-operator', 'operator');
        $rule = $this->createApprovalRule($this->adminId);
        $inventory = $this->gatedInventory($rule, 'in-a-project', (int)$this->createProject($this->adminId)->id);
        $template = $this->gatedTemplate($rule, 'gated-template');

        $usage = $this->service->forRule($rule, (int)$operator->id);

        $this->assertSame([(int)$inventory->id], array_column($usage->inventories, 'id'));
        $this->assertSame(0, $usage->hiddenInventoryCount());
        $this->assertSame([(int)$template->id], array_column($usage->jobTemplates, 'id'));
        $this->assertSame(0, $usage->hiddenJobTemplateCount());
    }

    /**
     * The usage costs the same number of statements for 2 and for 12
     * inventories and job templates: projects come with one query, not
     * one per row.
     */
    public function testTheCostDoesNotGrowWithTheUses(): void
    {
        $few = $this->ruleUsedBy(2);
        $many = $this->ruleUsedBy(12);
        // The first use of the models loads their table schemas.
        $this->service->forRule($few, $this->adminId);

        $fewQueries = $this->queriesOf(fn () => $this->service->forRule($few, $this->adminId));
        $manyQueries = $this->queriesOf(fn () => $this->service->forRule($many, $this->adminId));

        $this->assertSame($fewQueries, $manyQueries);
        $this->assertCount(12, $this->service->forRule($many, $this->adminId)->inventories);
    }

    // -- Helpers ----------------------------------------------------------------

    private function projects(): ProjectAccessChecker
    {
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');

        return $checker;
    }

    /**
     * A rule that $count inventories and $count job templates use, each
     * in a project of its own.
     */
    private function ruleUsedBy(int $count): ApprovalRule
    {
        $rule = $this->createApprovalRule($this->adminId);
        for ($i = 0; $i < $count; $i++) {
            $this->gatedInventory($rule, 'cost-hosts-' . $i, (int)$this->createProject($this->adminId)->id);
            $this->gatedTemplate($rule, 'cost-template-' . $i);
        }

        return $rule;
    }

    private function gatedInventory(ApprovalRule $rule, string $name, ?int $projectId): Inventory
    {
        $inventory = $this->createInventory($this->adminId);
        $inventory->name = $name;
        $inventory->project_id = $projectId;
        $inventory->setAttribute('approval_rule_id', (int)$rule->id);
        $inventory->save(false);

        return $inventory;
    }

    private function templateOf(): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($this->adminId)->id,
            (int)$this->createInventory($this->adminId)->id,
            (int)$this->createRunnerGroup($this->adminId)->id,
            $this->adminId
        );
    }

    private function gatedTemplate(ApprovalRule $rule, string $name): JobTemplate
    {
        $template = $this->templateOf();
        $template->name = $name;
        $template->approval_rule_id = (int)$rule->id;
        $template->save(false);

        return $template;
    }

    private function pendingRequest(ApprovalRule $rule, JobTemplate $template, string $jobStatus): ApprovalRequest
    {
        $job = $this->createJob((int)$template->id, $this->adminId, $jobStatus);
        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->approval_rule_id = (int)$rule->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $request->save(false);

        return $request;
    }
}
