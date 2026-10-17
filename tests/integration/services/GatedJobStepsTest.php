<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Inventory;
use app\models\JobTemplate;
use app\models\TeamProject;
use app\models\WorkflowStep;
use app\services\GatedJobSteps;
use app\services\JobTemplateGate;
use app\tests\integration\CountsQueries;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * GatedJobSteps (contract C15): which job templates gate the job steps that
 * use them, by an approval rule of their own or by the rule of their
 * inventory, in SQL that agrees with JobTemplateGate::of() (the launch's
 * gate). The source names the job template's own rule first, also when the
 * inventory has the same one; deleted job templates, and steps of any type
 * but exactly "job", gate nothing; a job template the user cannot see counts
 * as none.
 */
class GatedJobStepsTest extends DbTestCase
{
    use CountsQueries;
    use InventoryGatedStepFixtures;
    use TeamScopeFixtures;

    private int $userId;
    private int $ruleId;
    private int $otherRuleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('gated-steps')->id;
        $this->ruleId = (int)$this->createApprovalRule($this->userId)->id;
        $this->otherRuleId = (int)$this->createApprovalRule($this->userId)->id;
    }

    // -- sourcesOf() -------------------------------------------------------------------

    public function testSourcesOfNamesWhatGatesEachLiveJobTemplateInAscendingOrder(): void
    {
        $none = $this->templateOnGatedInventory($this->userId, null);
        $own = $this->templateOnGatedInventory($this->userId, null, $this->ruleId);
        $inventory = $this->templateOnGatedInventory($this->userId, $this->ruleId);
        $same = $this->templateOnGatedInventory($this->userId, $this->ruleId, $this->ruleId);
        $differ = $this->templateOnGatedInventory($this->userId, $this->otherRuleId, $this->ruleId);
        $deleted = $this->templateOnGatedInventory($this->userId, $this->ruleId, $this->ruleId);
        $deleted->softDelete();
        $ids = [(int)$differ->id, (int)$none->id, (int)$inventory->id, (int)$own->id, (int)$same->id, (int)$deleted->id, (int)$inventory->id, 987654321];

        $sources = GatedJobSteps::sourcesOf($ids);

        $expected = [
            (int)$own->id => JobTemplateGate::SOURCE_JOB_TEMPLATE,
            (int)$inventory->id => JobTemplateGate::SOURCE_INVENTORY,
            (int)$same->id => JobTemplateGate::SOURCE_JOB_TEMPLATE,
            (int)$differ->id => JobTemplateGate::SOURCE_JOB_TEMPLATE,
        ];
        ksort($expected);
        $this->assertSame($expected, $sources, 'gated ones only, each once, ascending; deleted and unknown ones left out');
        $this->assertSame(['job_template', 'inventory'], [JobTemplateGate::SOURCE_JOB_TEMPLATE, JobTemplateGate::SOURCE_INVENTORY]);
    }

    public function testSourcesOfWithoutIdsReadsNothing(): void
    {
        GatedJobSteps::sourcesOf([(int)$this->templateOnGatedInventory($this->userId, $this->ruleId)->id]);

        $this->assertSame(0, $this->queriesOf(static fn () => GatedJobSteps::sourcesOf([])));
        $this->assertSame([], GatedJobSteps::sourcesOf([]));
        $this->assertSame(1, $this->queriesOf(static fn () => GatedJobSteps::sourcesOf(range(1, 40))), 'one query for any number of IDs');
    }

    /**
     * The SQL agrees with the launch's gate: a job template is gated in SQL
     * exactly when JobTemplateGate::of() says so, by the same source, for
     * every combination of the two rules, and for a job template whose
     * inventory row is gone (as only older versions or a failed delete
     * leave it).
     */
    public function testSourcesOfAgreesWithJobTemplateGate(): void
    {
        $templates = [
            $this->templateOnGatedInventory($this->userId, null),
            $this->templateOnGatedInventory($this->userId, null, $this->ruleId),
            $this->templateOnGatedInventory($this->userId, $this->ruleId),
            $this->templateOnGatedInventory($this->userId, $this->ruleId, $this->ruleId),
            $this->templateOnGatedInventory($this->userId, $this->otherRuleId, $this->ruleId),
            $this->templateOnGatedInventory($this->userId, null, $this->ruleId),
            $this->templateOnGatedInventory($this->userId, $this->ruleId),
        ];
        $this->withoutForeignKeyChecks(function () use ($templates): void {
            $this->assertSame(1, Inventory::deleteAll(['id' => $templates[5]->inventory_id]));
            $this->assertSame(1, Inventory::deleteAll(['id' => $templates[6]->inventory_id]));
        });

        $sources = GatedJobSteps::sourcesOf(array_map(static fn (JobTemplate $t): int => (int)$t->id, $templates));

        foreach ($templates as $index => $template) {
            $fresh = JobTemplate::findOne($template->id);
            $this->assertNotNull($fresh);
            $gate = JobTemplateGate::of($fresh);
            $this->assertSame($gate->isGated(), isset($sources[(int)$template->id]), "template {$index}: gated");
            $this->assertSame($gate->source(), $sources[(int)$template->id] ?? null, "template {$index}: its source");
        }
        $this->assertArrayNotHasKey((int)$templates[6]->id, $sources, 'an inventory row that is gone gates nothing');
    }

    // -- sourceIn() --------------------------------------------------------------------

    public function testSourceInNamesTheJobTemplateBeforeTheInventory(): void
    {
        $own = $this->templateOnGatedInventory($this->userId, null, $this->ruleId);
        $inventory = $this->templateOnGatedInventory($this->userId, $this->ruleId);
        $open = $this->templateOnGatedInventory($this->userId, null);

        $this->assertSame(JobTemplateGate::SOURCE_JOB_TEMPLATE, GatedJobSteps::sourceIn($this->workflowOf([$open, $own])));
        $this->assertSame(JobTemplateGate::SOURCE_INVENTORY, GatedJobSteps::sourceIn($this->workflowOf([$open, $inventory])));
        $this->assertSame(JobTemplateGate::SOURCE_JOB_TEMPLATE, GatedJobSteps::sourceIn($this->workflowOf([$inventory, $own])), 'both kinds');
        $this->assertNull(GatedJobSteps::sourceIn($this->workflowOf([$open])));
        $this->assertNull(GatedJobSteps::sourceIn($this->workflowOf([])));
        $this->assertNull(GatedJobSteps::sourceIn(0), 'a workflow not saved yet');
    }

    /**
     * Only live job templates and steps of exactly the type job count: a
     * step of a deleted template runs nothing, and dispatch fails a step of
     * type "JOB"; a pause step's job template, which older versions saved,
     * gates nothing.
     */
    public function testSourceInCountsLiveJobStepsOfTheExactTypeOnly(): void
    {
        $inventory = $this->templateOnGatedInventory($this->userId, $this->ruleId);
        $workflow = $this->createWorkflowTemplate($this->userId);
        foreach (['JOB', 'job ', WorkflowStep::TYPE_PAUSE, WorkflowStep::TYPE_APPROVAL] as $order => $type) {
            $this->createWorkflowStep((int)$workflow->id, $order, $type, (int)$inventory->id);
        }
        $this->assertNull(GatedJobSteps::sourceIn((int)$workflow->id));

        $live = $this->workflowOf([$inventory]);
        $this->assertSame(JobTemplateGate::SOURCE_INVENTORY, GatedJobSteps::sourceIn($live));
        JobTemplate::updateAll(['deleted_at' => time()], ['id' => $inventory->id]);
        $this->assertNull(GatedJobSteps::sourceIn($live), 'a step of a deleted job template runs nothing');
    }

    /**
     * Whether a workflow has a gated job step is read when asked: an
     * inventory that gets a rule, or a job template moved to an inventory
     * with one, gates the steps from then on; removing the rule ungates
     * them.
     */
    public function testSourceInReadsTheRulesWhenAsked(): void
    {
        $template = $this->templateOnGatedInventory($this->userId, null);
        $workflow = $this->workflowOf([$template]);
        $this->assertNull(GatedJobSteps::sourceIn($workflow));

        $this->giveInventoryRule((int)$template->inventory_id, $this->ruleId);
        $this->assertSame(JobTemplateGate::SOURCE_INVENTORY, GatedJobSteps::sourceIn($workflow), 'its inventory got a rule');
        $this->giveInventoryRule((int)$template->inventory_id, null);
        $this->assertNull(GatedJobSteps::sourceIn($workflow), 'and lost it');

        $this->moveToInventory((int)$template->id, (int)$this->gatedInventory($this->userId, $this->ruleId)->id);
        $this->assertSame(JobTemplateGate::SOURCE_INVENTORY, GatedJobSteps::sourceIn($workflow), 'moved to a gated inventory');
    }

    /**
     * At most two queries: one when a job template's own rule gates a step,
     * two otherwise, whatever the number of steps.
     */
    public function testSourceInCostsAtMostTwoQueries(): void
    {
        $own = $this->workflowOf([$this->templateOnGatedInventory($this->userId, null, $this->ruleId)]);
        $inventory = $this->workflowOf(array_map(fn (): JobTemplate => $this->templateOnGatedInventory($this->userId, $this->ruleId), range(1, 6)));
        $none = $this->workflowOf([$this->templateOnGatedInventory($this->userId, null)]);
        GatedJobSteps::sourceIn($own);

        $this->assertSame(1, $this->queriesOf(static fn () => GatedJobSteps::sourceIn($own)));
        $this->assertSame(2, $this->queriesOf(static fn () => GatedJobSteps::sourceIn($inventory)));
        $this->assertSame(2, $this->queriesOf(static fn () => GatedJobSteps::sourceIn($none)));
    }

    // -- visibleSourceOf() -------------------------------------------------------------

    /**
     * The ID is read as the step's integer rule reads it, so a forged form
     * value that stands for the gated template is that template; a value
     * that is no ID is no template. Only the exact type job counts.
     */
    public function testVisibleSourceOfReadsTheIdAsTheStepsRuleDoes(): void
    {
        $id = (int)$this->templateOnGatedInventory($this->userId, $this->ruleId)->id;

        foreach (['integer' => $id, 'text' => (string)$id, 'leading zero' => '0' . $id, 'plus sign' => '+' . $id, 'final line break' => $id . "\n"] as $case => $value) {
            $this->assertSame(JobTemplateGate::SOURCE_INVENTORY, GatedJobSteps::visibleSourceOf($this->userId, [$this->jobStep($value)]), $case);
        }
        foreach (['padded' => ' ' . $id, 'decimal' => $id . '.0', 'list' => [$id], 'none' => null, 'empty' => ''] as $case => $value) {
            $this->assertNull(GatedJobSteps::visibleSourceOf($this->userId, [$this->jobStep($value)]), $case);
        }
        foreach (['JOB', 'job ', WorkflowStep::TYPE_PAUSE, WorkflowStep::TYPE_APPROVAL] as $type) {
            $step = $this->jobStep($id);
            $step->step_type = $type;
            $this->assertNull(GatedJobSteps::visibleSourceOf($this->userId, [$step]), $type);
        }
        $this->assertNull(GatedJobSteps::visibleSourceOf($this->userId, []));
    }

    /**
     * A gated job template the user cannot see counts as none, so the
     * answer does not tell whether it, or its inventory, has a rule; one the
     * user may see but not operate counts. Among the visible ones, a job
     * template's own rule is named first.
     */
    public function testVisibleSourceOfCountsOnlyJobTemplatesTheUserMaySee(): void
    {
        $s = $this->teamScope();
        $memberId = (int)$s['member']->id;
        $this->moveToInventory((int)$s['foreign']->id, (int)$this->gatedInventory($this->userId, $this->ruleId)->id);
        $this->moveToInventory((int)$s['viewed']->id, (int)$this->gatedInventory($this->userId, $this->ruleId)->id);
        JobTemplate::updateAll(['approval_rule_id' => $this->ruleId], ['id' => $s['own']->id]);
        $foreign = $this->jobStep((int)$s['foreign']->id);
        $viewed = $this->jobStep((int)$s['viewed']->id);
        $own = $this->jobStep((int)$s['own']->id);

        $this->assertNull(GatedJobSteps::visibleSourceOf($memberId, [$foreign]), 'another team\'s job template counts as none');
        $this->assertSame(JobTemplateGate::SOURCE_INVENTORY, GatedJobSteps::visibleSourceOf($memberId, [$foreign, $viewed]));
        $this->assertSame(JobTemplateGate::SOURCE_JOB_TEMPLATE, GatedJobSteps::visibleSourceOf($memberId, [$viewed, $own]), 'its own rule first');
        $this->assertSame(JobTemplateGate::SOURCE_INVENTORY, GatedJobSteps::visibleSourceOf((int)$s['outsider']->id, [$foreign]), 'its own team sees it');
    }

    /**
     * On an installation without team-restricted projects (the visibility
     * filter is null), every job template is visible, and the inventory's
     * rule counts.
     */
    public function testVisibleSourceOfWithoutAnyTeamRestrictedProject(): void
    {
        $s = $this->teamScope();
        $this->moveToInventory((int)$s['foreign']->id, (int)$this->gatedInventory($this->userId, $this->ruleId)->id);
        TeamProject::deleteAll();

        $this->assertSame(
            JobTemplateGate::SOURCE_INVENTORY,
            GatedJobSteps::visibleSourceOf((int)$s['member']->id, [$this->jobStep((int)$s['foreign']->id)])
        );
    }

    // -- Helpers -----------------------------------------------------------------------

    /**
     * A workflow with a job step on each of $templates, in this order; its ID.
     *
     * @param list<JobTemplate> $templates
     */
    private function workflowOf(array $templates): int
    {
        $workflow = $this->createWorkflowTemplate($this->userId);
        foreach ($templates as $order => $template) {
            $this->createWorkflowStep((int)$workflow->id, $order, WorkflowStep::TYPE_JOB, (int)$template->id);
        }

        return (int)$workflow->id;
    }

    /**
     * An unsaved job step of the job template $templateId, as a request
     * submits it.
     */
    private function jobStep(mixed $templateId): WorkflowStep
    {
        $step = new WorkflowStep();
        $step->step_type = WorkflowStep::TYPE_JOB;
        // As load() sets it from a form: whatever was posted.
        $step->setAttribute('job_template_id', $templateId);

        return $step;
    }

    private function withoutForeignKeyChecks(callable $change): void
    {
        $db = \Yii::$app->db;
        $db->createCommand('SET FOREIGN_KEY_CHECKS = 0')->execute();
        try {
            $change();
        } finally {
            $db->createCommand('SET FOREIGN_KEY_CHECKS = 1')->execute();
        }
    }
}
