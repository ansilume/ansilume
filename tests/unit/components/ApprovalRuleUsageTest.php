<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\ApprovalRuleUsage;
use PHPUnit\Framework\TestCase;

class ApprovalRuleUsageTest extends TestCase
{
    private const TEMPLATE = ['id' => 7, 'name' => 'deploy', 'project_id' => 3, 'project_name' => 'web'];
    private const STEP = ['workflow_template_id' => 4, 'workflow_template_name' => 'release', 'step_id' => 9, 'step_name' => 'sign-off'];

    public function testSummaryCountsEverythingButNamesNothingHidden(): void
    {
        $usage = new ApprovalRuleUsage('prod-gate', [self::TEMPLATE], 3, [self::STEP], 2, 4);

        $this->assertTrue($usage->isInUse());
        $this->assertSame(2, $usage->hiddenJobTemplateCount());
        $this->assertSame(1, $usage->hiddenWorkflowStepCount());
        $this->assertSame(
            'Approval rule "prod-gate" is in use by 3 job template(s), 2 workflow step(s) and 4 pending approval request(s).',
            $usage->summary()
        );
        $this->assertSame([
            'in_use' => true,
            'job_templates' => [self::TEMPLATE],
            'hidden_job_template_count' => 2,
            'workflow_steps' => [self::STEP],
            'hidden_workflow_step_count' => 1,
            'pending_request_count' => 4,
        ], $usage->toArray());
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int, 3: string}>
     */
    public static function singleUseProvider(): array
    {
        return [
            'job templates only' => [2, 0, 0, '2 job template(s)'],
            'workflow steps only' => [0, 1, 0, '1 workflow step(s)'],
            'pending requests only' => [0, 0, 3, '3 pending approval request(s)'],
            'two kinds' => [1, 0, 1, '1 job template(s) and 1 pending approval request(s)'],
        ];
    }

    /**
     * @dataProvider singleUseProvider
     */
    public function testEachKindOfUseAloneMakesTheRuleInUse(int $templates, int $steps, int $pending, string $list): void
    {
        $usage = new ApprovalRuleUsage('gate', [], $templates, [], $steps, $pending);

        $this->assertTrue($usage->isInUse());
        $this->assertSame('Approval rule "gate" is in use by ' . $list . '.', $usage->summary());
        $this->assertSame($templates, $usage->hiddenJobTemplateCount(), 'nothing listed: all of them hidden');
        $this->assertSame($steps, $usage->hiddenWorkflowStepCount());
    }

    public function testAnUnusedRule(): void
    {
        $usage = new ApprovalRuleUsage('spare', [], 0, [], 0, 0);

        $this->assertFalse($usage->isInUse());
        $this->assertSame('Approval rule "spare" is not in use.', $usage->summary());
        $this->assertSame(0, $usage->hiddenJobTemplateCount());
        $this->assertSame(0, $usage->hiddenWorkflowStepCount());
        $this->assertFalse($usage->toArray()['in_use']);
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
}
