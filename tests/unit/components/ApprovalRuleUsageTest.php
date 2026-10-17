<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\ApprovalRuleUsage;
use PHPUnit\Framework\TestCase;

class ApprovalRuleUsageTest extends TestCase
{
    private const INVENTORY = ['id' => 5, 'name' => 'prod-hosts', 'project_id' => null, 'project_name' => null];
    private const TEMPLATE = ['id' => 7, 'name' => 'deploy', 'project_id' => 3, 'project_name' => 'web'];
    private const STEP = ['workflow_template_id' => 4, 'workflow_template_name' => 'release', 'step_id' => 9, 'step_name' => 'sign-off'];

    public function testSummaryCountsEverythingButNamesNothingHidden(): void
    {
        $usage = new ApprovalRuleUsage('prod-gate', [self::TEMPLATE], 3, [self::STEP], 2, 4, [self::INVENTORY], 2);

        $this->assertTrue($usage->isInUse());
        $this->assertSame(1, $usage->hiddenInventoryCount());
        $this->assertSame(2, $usage->hiddenJobTemplateCount());
        $this->assertSame(1, $usage->hiddenWorkflowStepCount());
        $this->assertSame(
            'Approval rule "prod-gate" is in use by 2 inventory(ies), 3 job template(s), 2 workflow step(s) and 4 pending approval request(s).',
            $usage->summary(),
            'the inventories first'
        );
        $this->assertSame([
            'in_use' => true,
            'inventories' => [self::INVENTORY],
            'hidden_inventory_count' => 1,
            'job_templates' => [self::TEMPLATE],
            'hidden_job_template_count' => 2,
            'workflow_steps' => [self::STEP],
            'hidden_workflow_step_count' => 1,
            'pending_request_count' => 4,
        ], $usage->toArray());
    }

    /**
     * The order of the keys is part of the API: the OpenAPI spec lists them
     * in this order as the required fields of ApprovalRuleUsage.
     */
    public function testTheKeysOfTheArrayComeInTheirDocumentedOrder(): void
    {
        $usage = new ApprovalRuleUsage('spare', [], 0, [], 0, 0);

        $this->assertSame(
            ['in_use', 'inventories', 'hidden_inventory_count', 'job_templates', 'hidden_job_template_count', 'workflow_steps', 'hidden_workflow_step_count', 'pending_request_count'],
            array_keys($usage->toArray())
        );
    }

    /**
     * Callers written before rules gated inventories construct the usage
     * with six arguments: it then has no inventories.
     */
    public function testAUsageWithoutInventoriesHasNone(): void
    {
        $usage = new ApprovalRuleUsage('gate', [self::TEMPLATE], 1, [], 0, 0);

        $this->assertSame([], $usage->inventories);
        $this->assertSame(0, $usage->inventoryTotal);
        $this->assertSame(0, $usage->hiddenInventoryCount());
        $this->assertSame('Approval rule "gate" is in use by 1 job template(s).', $usage->summary());
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int, 3: int, 4: string}>
     */
    public static function singleUseProvider(): array
    {
        return [
            'inventories only' => [1, 0, 0, 0, '1 inventory(ies)'],
            'job templates only' => [0, 2, 0, 0, '2 job template(s)'],
            'workflow steps only' => [0, 0, 1, 0, '1 workflow step(s)'],
            'pending requests only' => [0, 0, 0, 3, '3 pending approval request(s)'],
            'two kinds' => [0, 1, 0, 1, '1 job template(s) and 1 pending approval request(s)'],
            'inventories and requests' => [3, 0, 0, 1, '3 inventory(ies) and 1 pending approval request(s)'],
        ];
    }

    /**
     * @dataProvider singleUseProvider
     */
    public function testEachKindOfUseAloneMakesTheRuleInUse(int $inventories, int $templates, int $steps, int $pending, string $list): void
    {
        $usage = new ApprovalRuleUsage('gate', [], $templates, [], $steps, $pending, [], $inventories);

        $this->assertTrue($usage->isInUse());
        $this->assertTrue($usage->toArray()['in_use']);
        $this->assertSame('Approval rule "gate" is in use by ' . $list . '.', $usage->summary());
        $this->assertSame($inventories, $usage->hiddenInventoryCount(), 'nothing listed: all of them hidden');
        $this->assertSame($templates, $usage->hiddenJobTemplateCount());
        $this->assertSame($steps, $usage->hiddenWorkflowStepCount());
    }

    public function testAnUnusedRule(): void
    {
        $usage = new ApprovalRuleUsage('spare', [], 0, [], 0, 0, [], 0);

        $this->assertFalse($usage->isInUse());
        $this->assertSame('Approval rule "spare" is not in use.', $usage->summary());
        $this->assertSame(0, $usage->hiddenInventoryCount());
        $this->assertSame(0, $usage->hiddenJobTemplateCount());
        $this->assertSame(0, $usage->hiddenWorkflowStepCount());
        $this->assertFalse($usage->toArray()['in_use']);
    }

    /**
     * A list longer than its total (a use that went away between the two
     * reads) never gives a negative hidden count.
     */
    public function testHiddenCountsAreNeverNegative(): void
    {
        $usage = new ApprovalRuleUsage('gate', [self::TEMPLATE], 0, [self::STEP], 0, 0, [self::INVENTORY], 0);

        $this->assertSame(0, $usage->hiddenInventoryCount());
        $this->assertSame(0, $usage->hiddenJobTemplateCount());
        $this->assertSame(0, $usage->hiddenWorkflowStepCount());
    }

    /**
     * The name is quoted as JSON: a quote or a line break in it cannot end
     * the quoted name or start a new line in a flash or a log.
     */
    public function testTheRuleNameIsQuotedSafely(): void
    {
        $usage = new ApprovalRuleUsage("ops \"night\"\nshift", [], 1, [], 0, 0);

        $this->assertSame('Approval rule "ops \"night\"\nshift" is in use by 1 job template(s).', $usage->summary());
    }

    /**
     * The texts of the rule page and its usage card, as the docs and the
     * E2E tests quote them.
     */
    public function testThePageTextsNameTheInventories(): void
    {
        $this->assertSame(
            'In use: remove it from the inventories, job templates and workflow steps below, '
            . 'and let its pending requests be decided or their jobs canceled, before deleting it.',
            ApprovalRuleUsage::DELETE_BLOCKED
        );
        $this->assertSame(
            'Not used by any inventory, job template, workflow step or pending approval request.',
            ApprovalRuleUsage::NOT_IN_USE
        );
        $this->assertSame(
            '+ 2 inventory(ies) in projects you cannot see',
            sprintf(ApprovalRuleUsage::HIDDEN_INVENTORIES, 2)
        );
    }
}
