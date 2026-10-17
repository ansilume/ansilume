<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\InventoryUsage;
use PHPUnit\Framework\TestCase;

/**
 * Which job templates use an inventory: the summary that a refused delete
 * names, the counts and the array of the API.
 */
class InventoryUsageTest extends TestCase
{
    private const TEMPLATE = [
        'id' => 7,
        'name' => 'deploy',
        'project_id' => 3,
        'project_name' => 'web',
        'approval_rule_id' => 4,
        'approval_rule_name' => 'prod-gate',
    ];

    /**
     * @return array<string, array{0: int, 1: int, 2: string}>
     */
    public static function inUseProvider(): array
    {
        return [
            'job templates only' => [2, 0, 'Inventory "web-hosts" is in use by 2 job template(s).'],
            'deleted ones only' => [0, 1, 'Inventory "web-hosts" is in use by 1 deleted job template(s).'],
            'both' => [3, 2, 'Inventory "web-hosts" is in use by 3 job template(s) and 2 deleted job template(s).'],
        ];
    }

    /**
     * A soft-deleted job template keeps its inventory for the history of
     * its jobs: it alone keeps the inventory in use.
     *
     * @dataProvider inUseProvider
     */
    public function testLiveAndDeletedJobTemplatesMakeTheInventoryInUse(int $live, int $deleted, string $summary): void
    {
        $usage = new InventoryUsage('web-hosts', [], $live, $deleted);

        $this->assertTrue($usage->isInUse());
        $this->assertSame($summary, $usage->summary());
        $this->assertSame($live, $usage->hiddenJobTemplateCount(), 'nothing listed: all of them hidden');
        $this->assertSame([
            'in_use' => true,
            'job_templates' => [],
            'hidden_job_template_count' => $live,
            'deleted_job_template_count' => $deleted,
        ], $usage->toArray());
    }

    public function testAnInventoryThatNothingUses(): void
    {
        $usage = new InventoryUsage('spare', [], 0, 0);

        $this->assertFalse($usage->isInUse());
        $this->assertSame('Inventory "spare" is not in use.', $usage->summary());
        $this->assertSame(0, $usage->hiddenJobTemplateCount());
        $this->assertSame([
            'in_use' => false,
            'job_templates' => [],
            'hidden_job_template_count' => 0,
            'deleted_job_template_count' => 0,
        ], $usage->toArray());
    }

    /**
     * The list holds what the viewer may see, each template with its own
     * rule; the summary counts every template and names none of them.
     */
    public function testTheListAndTheHiddenCount(): void
    {
        $usage = new InventoryUsage('web-hosts', [self::TEMPLATE], 3, 0);

        $this->assertSame(2, $usage->hiddenJobTemplateCount());
        $this->assertSame('Inventory "web-hosts" is in use by 3 job template(s).', $usage->summary());
        $this->assertStringNotContainsString('deploy', $usage->summary());
        $this->assertSame(
            ['in_use', 'job_templates', 'hidden_job_template_count', 'deleted_job_template_count'],
            array_keys($usage->toArray())
        );
        $this->assertSame([self::TEMPLATE], $usage->toArray()['job_templates']);
    }

    /**
     * A list longer than its total (a template that went away between the
     * two reads) never gives a negative hidden count.
     */
    public function testTheHiddenCountIsNeverNegative(): void
    {
        $usage = new InventoryUsage('web-hosts', [self::TEMPLATE, self::TEMPLATE], 1, 0);

        $this->assertSame(0, $usage->hiddenJobTemplateCount());
    }

    /**
     * The name is quoted as JSON: a quote or a line break in it cannot end
     * the quoted name or start a new line in a flash or a log.
     */
    public function testTheInventoryNameIsQuotedSafely(): void
    {
        $usage = new InventoryUsage("prod \"east\"\nhosts", [], 1, 0);

        $this->assertSame('Inventory "prod \"east\"\nhosts" is in use by 1 job template(s).', $usage->summary());
        // Raw, not HTML: the pages encode it.
        $this->assertSame('Inventory "<b>x</b>" is not in use.', (new InventoryUsage('<b>x</b>', [], 0, 0))->summary());
    }

    /**
     * The lines of the inventory page's job templates card, as the docs and
     * the E2E tests quote them.
     */
    public function testTheCardTexts(): void
    {
        $this->assertSame('No job template uses this inventory.', InventoryUsage::CARD_EMPTY);
        $this->assertSame('+ 2 job template(s) in projects you cannot see', sprintf(InventoryUsage::CARD_HIDDEN, 2));
        $this->assertSame(
            '1 deleted job template(s) still refer to this inventory, for the history of their jobs.',
            sprintf(InventoryUsage::CARD_DELETED, 1)
        );
    }
}
