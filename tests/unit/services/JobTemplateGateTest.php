<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\JobTemplate;
use app\services\ApproverEligibility;
use app\services\JobLaunchService;
use app\services\JobTemplateGate;
use PHPUnit\Framework\TestCase;
use yii\base\UnknownPropertyException;
use yii\db\Query;
use yii\log\Logger;

/**
 * JobTemplateGate::of() and what it answers, for every combination of the
 * template's own rule and its inventory's: none, the template's, the
 * inventory's, the same rule on both, different rules, a rule whose row is
 * gone on either side, an inventory whose row is gone, and an inventory
 * model without the column (code running before m000088).
 *
 * The models are built in memory with their relations populated, as the
 * callers eager-load them (JobTemplateGate::RELATIONS), and list their
 * columns themselves: nothing here reads the database, its schema included.
 */
class JobTemplateGateTest extends TestCase
{
    private const TEMPLATE_ID = 41;
    private const INVENTORY_ID = 17;
    private const OWN_RULE_ID = 7;
    private const INVENTORY_RULE_ID = 9;
    private const LAUNCHER_ID = 3;

    /** A name that a page must encode: of() and the notes give it back as stored. */
    private const HOSTILE_NAME = '<img src=x onerror=alert(1)> "q" \'a\'';

    public function testATemplateWithoutAnyRuleIsNotGated(): void
    {
        $gate = JobTemplateGate::of($this->template(null, null, $this->inventory(null, null)));
        $eligibility = $this->eligibility('never asked');

        $this->assertFalse($gate->isGated());
        $this->assertNull($gate->ruleId());
        $this->assertNull($gate->source());
        $this->assertNull($gate->rule);
        $this->assertNull($gate->inventoryRule);
        $this->assertFalse($gate->rulesDiffer());
        $this->assertFalse($gate->ruleMissing());
        $this->assertNull($gate->refusal(self::LAUNCHER_ID, $eligibility));
        $this->assertSame([], $eligibility->calls, 'no approvers are counted for an ungated launch');
        $this->assertNull($gate->launchNote());
        $this->assertNull($gate->scheduleNote());
        $this->assertNull($gate->toApi());
        $this->assertSame(
            [self::TEMPLATE_ID, null, self::INVENTORY_ID, 'web-hosts', null],
            [$gate->templateId, $gate->templateRuleId, $gate->inventoryId, $gate->inventoryName, $gate->inventoryRuleId]
        );
    }

    public function testTheTemplatesOwnRuleGatesIt(): void
    {
        $rule = $this->rule(self::OWN_RULE_ID, 'Deploy sign-off');
        $template = $this->template(self::OWN_RULE_ID, $rule, $this->inventory(null, null));
        $gate = JobTemplateGate::of($template);

        $this->assertTrue($gate->isGated());
        $this->assertSame(self::OWN_RULE_ID, $gate->ruleId());
        $this->assertSame(JobTemplateGate::SOURCE_JOB_TEMPLATE, $gate->source());
        $this->assertSame($rule, $gate->rule);
        $this->assertNull($gate->inventoryRule);
        $this->assertFalse($gate->ruleMissing());
        $this->assertSame('The job will wait for approval before it runs: rule "Deploy sign-off" (job template).', $gate->launchNote());
        $this->assertSame('Each run waits for approval: rule "Deploy sign-off" (job template).', $gate->scheduleNote());
        $this->assertSame(
            ['approval_rule_id' => self::OWN_RULE_ID, 'approval_rule_name' => 'Deploy sign-off', 'required_by' => 'job_template'],
            $gate->toApi()
        );
    }

    public function testTheInventorysRuleGatesATemplateWithoutARuleOfItsOwn(): void
    {
        $rule = $this->rule(self::INVENTORY_RULE_ID, 'Production hosts');
        $gate = JobTemplateGate::of($this->template(null, null, $this->inventory(self::INVENTORY_RULE_ID, $rule)));

        $this->assertTrue($gate->isGated());
        $this->assertSame(self::INVENTORY_RULE_ID, $gate->ruleId());
        $this->assertSame(JobTemplateGate::SOURCE_INVENTORY, $gate->source());
        $this->assertSame(ApprovalRequest::REQUIRED_BY_INVENTORY, $gate->source());
        $this->assertNull($gate->templateRuleId);
        $this->assertSame(self::INVENTORY_RULE_ID, $gate->inventoryRuleId);
        $this->assertSame($rule, $gate->rule);
        $this->assertSame($rule, $gate->inventoryRule);
        $this->assertFalse($gate->rulesDiffer());
        $this->assertFalse($gate->ruleMissing());
        $this->assertSame(
            'The job will wait for approval before it runs: rule "Production hosts" (inventory "web-hosts").',
            $gate->launchNote()
        );
        $this->assertSame('Each run waits for approval: rule "Production hosts" (inventory "web-hosts").', $gate->scheduleNote());
        $this->assertSame(
            ['approval_rule_id' => self::INVENTORY_RULE_ID, 'approval_rule_name' => 'Production hosts', 'required_by' => 'inventory'],
            $gate->toApi()
        );
    }

    public function testTheSameRuleOnBothIsTheTemplatesOwn(): void
    {
        $own = $this->rule(self::OWN_RULE_ID, 'Shared');
        $ofInventory = $this->rule(self::OWN_RULE_ID, 'Shared');
        $gate = JobTemplateGate::of($this->template(self::OWN_RULE_ID, $own, $this->inventory(self::OWN_RULE_ID, $ofInventory)));

        $this->assertTrue($gate->isGated());
        $this->assertFalse($gate->rulesDiffer());
        $this->assertSame(JobTemplateGate::SOURCE_JOB_TEMPLATE, $gate->source(), 'the template has its own rule');
        $this->assertSame($own, $gate->rule);
        $this->assertSame($ofInventory, $gate->inventoryRule, 'the inventory rule is read also when the template has one');
        $this->assertSame('The job will wait for approval before it runs: rule "Shared" (job template).', $gate->launchNote());
        $this->assertSame(['approval_rule_id' => self::OWN_RULE_ID, 'approval_rule_name' => 'Shared', 'required_by' => 'job_template'], $gate->toApi());
    }

    public function testDifferentRulesAreRefusedAndTheTemplatesRuleIsTheOneNamed(): void
    {
        $own = $this->rule(self::OWN_RULE_ID, 'Own');
        $ofInventory = $this->rule(self::INVENTORY_RULE_ID, 'Of the inventory');
        $gate = JobTemplateGate::of($this->template(self::OWN_RULE_ID, $own, $this->inventory(self::INVENTORY_RULE_ID, $ofInventory)));
        $eligibility = $this->eligibility('never asked');

        $this->assertTrue($gate->rulesDiffer());
        $this->assertTrue($gate->isGated());
        $this->assertFalse($gate->ruleMissing());
        $this->assertSame(self::OWN_RULE_ID, $gate->ruleId());
        $this->assertSame($own, $gate->rule);
        $this->assertSame($ofInventory, $gate->inventoryRule);
        $this->assertSame(JobTemplateGate::SOURCE_JOB_TEMPLATE, $gate->source());
        $this->assertSame(
            sprintf(JobLaunchService::RULES_DIFFER, self::TEMPLATE_ID, self::INVENTORY_ID),
            $gate->refusal(self::LAUNCHER_ID, $eligibility)
        );
        $this->assertSame(
            'Job template #41 and its inventory #17 have different approval rules, and a launch waits for one rule only. '
            . 'An admin can remove the template\'s own rule or give it the inventory\'s rule.',
            $gate->refusal(self::LAUNCHER_ID, $eligibility)
        );
        $this->assertSame([], $eligibility->calls);
        $this->assertNull($gate->launchNote(), 'no launch waits for two rules: it is refused');
        $this->assertNull($gate->scheduleNote());
        $this->assertSame(['approval_rule_id' => self::OWN_RULE_ID, 'approval_rule_name' => 'Own', 'required_by' => 'job_template'], $gate->toApi());
    }

    /**
     * Two rules meet even when the template's own rule is gone: the refusal
     * names the conflict first.
     */
    public function testDifferentRulesComeBeforeAMissingRule(): void
    {
        $gate = JobTemplateGate::of($this->template(
            self::OWN_RULE_ID,
            null,
            $this->inventory(self::INVENTORY_RULE_ID, $this->rule(self::INVENTORY_RULE_ID, 'Of the inventory'))
        ));

        $this->assertTrue($gate->ruleMissing());
        $this->assertSame(
            sprintf(JobLaunchService::RULES_DIFFER, self::TEMPLATE_ID, self::INVENTORY_ID),
            $gate->refusal(self::LAUNCHER_ID, $this->eligibility('never asked'))
        );
    }

    public function testTheTemplatesOwnRuleWhoseRowIsGoneIsRefused(): void
    {
        $gate = JobTemplateGate::of($this->template(self::OWN_RULE_ID, null, $this->inventory(null, null)));
        $eligibility = $this->eligibility('never asked');

        $this->assertTrue($gate->isGated());
        $this->assertTrue($gate->ruleMissing());
        $this->assertNull($gate->rule);
        $this->assertSame(JobTemplateGate::SOURCE_JOB_TEMPLATE, $gate->source());
        $this->assertSame(sprintf(JobLaunchService::RULE_MISSING, self::TEMPLATE_ID), $gate->refusal(self::LAUNCHER_ID, $eligibility));
        $this->assertSame([], $eligibility->calls);
        $this->assertNull($gate->launchNote());
        $this->assertNull($gate->scheduleNote());
        $this->assertSame(['approval_rule_id' => self::OWN_RULE_ID, 'approval_rule_name' => null, 'required_by' => 'job_template'], $gate->toApi());
    }

    public function testTheInventorysRuleWhoseRowIsGoneIsRefused(): void
    {
        $gate = JobTemplateGate::of($this->template(null, null, $this->inventory(self::INVENTORY_RULE_ID, null)));
        $eligibility = $this->eligibility('never asked');

        $this->assertTrue($gate->ruleMissing());
        $this->assertNull($gate->inventoryRule);
        $this->assertSame(JobTemplateGate::SOURCE_INVENTORY, $gate->source());
        $this->assertSame(
            sprintf(JobLaunchService::INVENTORY_RULE_MISSING, self::TEMPLATE_ID, self::INVENTORY_ID),
            $gate->refusal(self::LAUNCHER_ID, $eligibility)
        );
        $this->assertSame(
            'The approval rule of inventory #17, which job template #41 uses, no longer exists. An admin can choose another rule.',
            $gate->refusal(self::LAUNCHER_ID, $eligibility)
        );
        $this->assertSame([], $eligibility->calls);
        $this->assertNull($gate->launchNote());
        $this->assertSame(['approval_rule_id' => self::INVENTORY_RULE_ID, 'approval_rule_name' => null, 'required_by' => 'inventory'], $gate->toApi());
    }

    /**
     * The template's inventory row is gone: it has no name and no rule, and
     * only the template's own rule can gate it.
     */
    public function testAnInventoryWhoseRowIsGoneHasNoNameAndNoRule(): void
    {
        $ungated = JobTemplateGate::of($this->template(null, null, null));

        $this->assertFalse($ungated->isGated());
        $this->assertSame(self::INVENTORY_ID, $ungated->inventoryId, 'the ID as loaded');
        $this->assertNull($ungated->inventoryName);
        $this->assertNull($ungated->inventoryRuleId);

        $rule = $this->rule(self::OWN_RULE_ID, 'Own');
        $gated = JobTemplateGate::of($this->template(self::OWN_RULE_ID, $rule, null));
        $this->assertSame(JobTemplateGate::SOURCE_JOB_TEMPLATE, $gated->source());
        $this->assertSame($rule, $gated->rule);
        $this->assertNull($gated->inventoryRule);
    }

    /**
     * Code running before m000088, or with a schema cached from before it:
     * the inventory model has no approval_rule_id. It has no rule, and
     * nothing throws (the magic property would).
     */
    public function testAnInventoryModelWithoutTheColumnHasNoRule(): void
    {
        $inventory = $this->inventoryWithoutTheColumn();
        $inventory->populateRelation('approvalRule', $this->rule(self::INVENTORY_RULE_ID, 'never read'));

        $gate = JobTemplateGate::of($this->template(null, null, $inventory));

        $this->assertFalse($gate->isGated());
        $this->assertNull($gate->inventoryRuleId);
        $this->assertNull($gate->inventoryRule);
        $this->assertSame('web-hosts', $gate->inventoryName);
        $this->assertNull($gate->refusal(self::LAUNCHER_ID, $this->eligibility('never asked')));

        $rule = $this->rule(self::OWN_RULE_ID, 'Own');
        $this->assertSame($rule, JobTemplateGate::of($this->template(self::OWN_RULE_ID, $rule, $this->inventoryWithoutTheColumn()))->rule);

        // Why the gate reads getAttribute(): the magic getter throws for a column the schema lacks.
        $this->expectException(UnknownPropertyException::class);
        $inventory->__get('approval_rule_id');
    }

    /**
     * A relation is read only for a rule ID: a populated relation without
     * an ID counts for nothing.
     */
    public function testARelationIsReadOnlyForARuleId(): void
    {
        $template = $this->template(null, $this->rule(self::OWN_RULE_ID, 'stale relation'), $this->inventory(null, $this->rule(self::INVENTORY_RULE_ID, 'stale')));

        $gate = JobTemplateGate::of($template);

        $this->assertFalse($gate->isGated());
        $this->assertNull($gate->rule);
        $this->assertNull($gate->inventoryRule);
    }

    /**
     * IDs as stored (strings from the database) or assigned: any numeric
     * value is an ID, as JobLaunchService read them.
     */
    public function testRuleIdsAreReadAsStoredOrAssigned(): void
    {
        $template = $this->template('7', $this->rule(self::OWN_RULE_ID, 'Own'), $this->inventory('9', $this->rule(self::INVENTORY_RULE_ID, 'Inv')));
        $template->setAttribute('id', '41');
        $template->setAttribute('inventory_id', '17');

        $gate = JobTemplateGate::of($template);

        $this->assertSame(
            [41, 7, 17, 9],
            [$gate->templateId, $gate->templateRuleId, $gate->inventoryId, $gate->inventoryRuleId]
        );

        $empty = $this->template('', null, $this->inventory('not a rule', null));
        $this->assertFalse(JobTemplateGate::of($empty)->isGated(), 'an empty or non-numeric value is no rule');
    }

    public function testANewTemplateHasTheIdZero(): void
    {
        $template = $this->template(null, null, $this->inventory(self::INVENTORY_RULE_ID, $this->rule(self::INVENTORY_RULE_ID, 'Inv')));
        $template->setAttribute('id', null);

        $gate = JobTemplateGate::of($template);

        $this->assertSame(0, $gate->templateId);
        $this->assertTrue($gate->isGated());
    }

    public function testATemplateWithoutAnInventoryHasTheInventoryIdZero(): void
    {
        $template = $this->template(null, null, null);
        $template->setAttribute('inventory_id', null);

        $this->assertSame(0, JobTemplateGate::of($template)->inventoryId);
    }

    /**
     * The approvers who may see the template's project decide, also for
     * the rule of its inventory: refusal() hands the eligibility check the
     * template of() was given, with the gating rule, and returns its answer.
     */
    public function testTheRefusalCountsTheApproversOfTheTemplatesProject(): void
    {
        $ofInventory = $this->rule(self::INVENTORY_RULE_ID, 'Production hosts');
        $template = $this->template(null, null, $this->inventory(self::INVENTORY_RULE_ID, $ofInventory));
        $refusing = $this->eligibility('Approval rule "Production hosts" needs 1 approval(s) ...');

        $this->assertSame('Approval rule "Production hosts" needs 1 approval(s) ...', JobTemplateGate::of($template)->refusal(self::LAUNCHER_ID, $refusing));
        $this->assertCount(1, $refusing->calls);
        [$rule, $audience, $launchedBy] = $refusing->calls[0];
        $this->assertSame($ofInventory, $rule);
        $this->assertSame($template, $audience, 'the job template, whose project is the audience');
        $this->assertSame(self::LAUNCHER_ID, $launchedBy);

        $own = $this->rule(self::OWN_RULE_ID, 'Own');
        $ownTemplate = $this->template(self::OWN_RULE_ID, $own, $this->inventory(null, null));
        $allowing = $this->eligibility(null);
        $this->assertNull(JobTemplateGate::of($ownTemplate)->refusal(5, $allowing));
        $this->assertSame([[$own, $ownTemplate, 5]], $allowing->calls);
    }

    /**
     * The notes and the API give the names as stored: the pages encode them.
     */
    public function testNamesWithQuotesOrHtmlAreGivenBackAsStored(): void
    {
        $rule = $this->rule(self::INVENTORY_RULE_ID, self::HOSTILE_NAME);
        $inventory = $this->inventory(self::INVENTORY_RULE_ID, $rule);
        $inventory->setAttribute('name', self::HOSTILE_NAME . ' hosts');

        $gate = JobTemplateGate::of($this->template(null, null, $inventory));

        $this->assertSame(sprintf(JobTemplateGate::LAUNCH_NOTE_INVENTORY, self::HOSTILE_NAME, self::HOSTILE_NAME . ' hosts'), $gate->launchNote());
        $this->assertSame(sprintf(JobTemplateGate::SCHEDULE_NOTE_INVENTORY, self::HOSTILE_NAME, self::HOSTILE_NAME . ' hosts'), $gate->scheduleNote());
        $this->assertSame(self::HOSTILE_NAME, $gate->toApi()['approval_rule_name'] ?? null);
        $this->assertSame(self::HOSTILE_NAME . ' hosts', $gate->inventoryName);

        $own = JobTemplateGate::of($this->template(self::OWN_RULE_ID, $this->rule(self::OWN_RULE_ID, self::HOSTILE_NAME), $this->inventory(null, null)));
        $this->assertSame(sprintf(JobTemplateGate::LAUNCH_NOTE, self::HOSTILE_NAME), $own->launchNote());
        $this->assertSame(sprintf(JobTemplateGate::SCHEDULE_NOTE, self::HOSTILE_NAME), $own->scheduleNote());
    }

    /**
     * Loaded relations cost no query: of() reads what the caller loaded.
     */
    public function testOfReadsLoadedRelationsWithoutAQuery(): void
    {
        $template = $this->template(self::OWN_RULE_ID, $this->rule(self::OWN_RULE_ID, 'Own'), $this->inventory(self::OWN_RULE_ID, $this->rule(self::OWN_RULE_ID, 'Own')));

        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $gate = JobTemplateGate::of($template);
            $gate->refusal(self::LAUNCHER_ID, $this->eligibility(null));
            $gate->launchNote();
            $gate->toApi();
        } finally {
            \Yii::setLogger($previous);
        }

        $queries = array_filter($logger->messages, static fn (array $m): bool => str_starts_with((string)$m[2], 'yii\db\\'));
        $this->assertSame([], array_values($queries));
    }

    public function testTheRelationsToEagerLoadAreTheOnesOfReads(): void
    {
        $this->assertSame(['approvalRule', 'inventory.approvalRule'], JobTemplateGate::RELATIONS);
        $this->assertSame('gate_inventory', JobTemplateGate::INVENTORY_ALIAS);
        $this->assertSame(ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE, JobTemplateGate::SOURCE_JOB_TEMPLATE);
        $this->assertSame(ApprovalRequest::REQUIRED_BY_INVENTORY, JobTemplateGate::SOURCE_INVENTORY);
    }

    public function testJoinInventoryJoinsTheTemplatesInventoryUnderItsAlias(): void
    {
        $query = (new Query())->from(['jt' => JobTemplate::tableName()]);

        JobTemplateGate::joinInventory($query, 'jt');
        JobTemplateGate::joinInventory($query, 'job_template', 'inv');

        $this->assertSame([
            ['LEFT JOIN', ['gate_inventory' => '{{%inventory}}'], '[[gate_inventory.id]] = [[jt.inventory_id]]'],
            ['LEFT JOIN', ['inv' => '{{%inventory}}'], '[[inv.id]] = [[job_template.inventory_id]]'],
        ], $query->join);
    }

    public function testTheGatedConditionReadsBothRules(): void
    {
        $this->assertSame(
            ['or', ['not', ['jt.approval_rule_id' => null]], ['not', ['gate_inventory.approval_rule_id' => null]]],
            JobTemplateGate::gatedCondition('jt')
        );
        $this->assertSame(
            ['or', ['not', ['job_template.approval_rule_id' => null]], ['not', ['inv.approval_rule_id' => null]]],
            JobTemplateGate::gatedCondition('job_template', 'inv')
        );
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * Job template TEMPLATE_ID on inventory INVENTORY_ID ($inventory: its
     * loaded row, null when the row is gone), with the own rule ID
     * $ruleId and the loaded rule $rule.
     */
    private function template(mixed $ruleId, ?ApprovalRule $rule, ?Inventory $inventory): JobTemplate
    {
        $template = new class () extends JobTemplate {
            public function attributes(): array
            {
                return ['id', 'name', 'project_id', 'inventory_id', 'approval_rule_id', 'deleted_at'];
            }
        };
        $template->setAttribute('id', self::TEMPLATE_ID);
        $template->setAttribute('name', 'Deploy');
        $template->setAttribute('project_id', 5);
        $template->setAttribute('inventory_id', self::INVENTORY_ID);
        $template->setAttribute('approval_rule_id', $ruleId);
        $template->populateRelation('approvalRule', $rule);
        $template->populateRelation('inventory', $inventory);

        return $template;
    }

    /**
     * Inventory INVENTORY_ID "web-hosts" with the rule ID $ruleId and the
     * loaded rule $rule.
     */
    private function inventory(mixed $ruleId, ?ApprovalRule $rule): Inventory
    {
        $inventory = new class () extends Inventory {
            public function attributes(): array
            {
                return ['id', 'name', 'inventory_type', 'project_id', 'approval_rule_id'];
            }
        };
        $inventory->setAttribute('id', self::INVENTORY_ID);
        $inventory->setAttribute('name', 'web-hosts');
        $inventory->setAttribute('inventory_type', Inventory::TYPE_STATIC);
        $inventory->setAttribute('approval_rule_id', $ruleId);
        $inventory->populateRelation('approvalRule', $rule);

        return $inventory;
    }

    /** Inventory INVENTORY_ID "web-hosts" as a schema from before m000088 has it. */
    private function inventoryWithoutTheColumn(): Inventory
    {
        $inventory = new class () extends Inventory {
            public function attributes(): array
            {
                return ['id', 'name', 'inventory_type', 'project_id'];
            }
        };
        $inventory->setAttribute('id', self::INVENTORY_ID);
        $inventory->setAttribute('name', 'web-hosts');
        $inventory->setAttribute('inventory_type', Inventory::TYPE_STATIC);

        return $inventory;
    }

    private function rule(int $id, string $name): ApprovalRule
    {
        $rule = new class () extends ApprovalRule {
            public function attributes(): array
            {
                return ['id', 'name', 'required_approvals'];
            }
        };
        $rule->setAttribute('id', $id);
        $rule->setAttribute('name', $name);
        $rule->setAttribute('required_approvals', 1);

        return $rule;
    }

    /**
     * An eligibility check that answers $answer and records each call:
     * the rule, the template and the launcher it was given.
     *
     * @return ApproverEligibility&object{calls: list<array{0: ApprovalRule, 1: JobTemplate, 2: int}>}
     */
    private function eligibility(?string $answer): ApproverEligibility
    {
        return new class ($answer) extends ApproverEligibility {
            /** @var list<array{0: ApprovalRule, 1: JobTemplate, 2: int}> */
            public array $calls = [];

            public function __construct(private readonly ?string $answer)
            {
                parent::__construct();
            }

            public function jobLaunchRefusal(ApprovalRule $rule, JobTemplate $template, int $launchedBy): ?string
            {
                $this->calls[] = [$rule, $template, $launchedBy];

                return $this->answer;
            }
        };
    }
}
