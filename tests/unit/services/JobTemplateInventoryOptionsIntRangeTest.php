<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\JobTemplate;
use app\services\JobTemplateInventoryOptions;
use PHPUnit\Framework\TestCase;

/**
 * Regression: the inventory options of the job template form read IDs with
 * is_numeric($x) ? (int)$x : 0, and the form is shown again with the values
 * a JSON body assigned, a number beyond the int range (1e30,
 * 9223372036854775808, -1e30, 1e999 = infinity) as a float among them: its
 * (int) cast warns on PHP 8.5, and the page answered 500. Such a number is
 * no ID now, as the integer rule reads it (SubmittedId): no rule, no stored
 * inventory to keep, no rule hint. Every positive ID is read as before.
 *
 * The models are built in memory with their relations populated: nothing
 * here reads the database.
 */
class JobTemplateInventoryOptionsIntRangeTest extends TestCase
{
    private const STATIC_ID = 11;
    private const GATED_ID = 12;
    private const RULE_ID = 7;

    /** JSON numbers beyond the int range, as a client writes them. */
    private const NUMBERS = ['1e30', '9223372036854775808', '-1e30', '1e999'];

    /**
     * @return array<string, array{0: string}>
     */
    public static function numberProvider(): array
    {
        $cases = [];
        foreach (self::NUMBERS as $number) {
            $cases[$number] = [$number];
        }

        return $cases;
    }

    /**
     * @dataProvider numberProvider
     */
    public function testARuleIdBeyondTheIntRangeIsNoRule(string $number): void
    {
        $inventory = $this->inventory(self::STATIC_ID, $this->decoded($number), null);

        $this->assertSame([self::STATIC_ID => 'web-hosts'], JobTemplateInventoryOptions::of([$inventory], true, null)['options']);
        $listed = JobTemplateInventoryOptions::of([$inventory], false, null);
        $this->assertSame([self::STATIC_ID => 'web-hosts'], $listed['options']);
        $this->assertFalse($listed['gated_omitted']);
    }

    /**
     * The form shown again with an inventory beyond the int range: admins
     * get no rule hint (the template names no inventory), others keep the
     * stored inventory, read as before.
     *
     * @dataProvider numberProvider
     */
    public function testASubmittedInventoryBeyondTheIntRangeNamesNoInventory(string $number): void
    {
        $template = $this->savedTemplate(self::GATED_ID, $this->decoded($number));

        $forAdmins = JobTemplateInventoryOptions::formParams($template, $this->inventories(), true, false);
        $forOthers = JobTemplateInventoryOptions::formParams($template, $this->inventories(), false, true);

        $this->assertNull($forAdmins['inventoryRuleHint']);
        $this->assertCount(2, $forAdmins['inventoryOptions'], 'every inventory');
        $this->assertSame(
            [self::STATIC_ID => 'web-hosts', self::GATED_ID => 'repo-hosts · needs approval: Change board'],
            $forOthers['inventoryOptions'],
            'the stored inventory is kept'
        );
    }

    /**
     * A stored inventory beyond the int range is none to keep: others are
     * offered no inventory with a rule.
     *
     * @dataProvider numberProvider
     */
    public function testAStoredInventoryBeyondTheIntRangeIsNoneToKeep(string $number): void
    {
        $template = $this->savedTemplate($this->decoded($number), self::STATIC_ID);

        $params = JobTemplateInventoryOptions::formParams($template, $this->inventories(), false, false);

        $this->assertSame([self::STATIC_ID => 'web-hosts'], $params['inventoryOptions']);
        $this->assertSame(JobTemplateInventoryOptions::GATED_INVENTORIES_HINT, $params['inventoryHint']);
    }

    /**
     * $json as the request's JSON parser gives it.
     */
    private function decoded(string $json): mixed
    {
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsFloat($value, 'the parser gives a float');

        return $value;
    }

    /**
     * Inventory STATIC_ID "web-hosts" without a rule and inventory GATED_ID
     * "repo-hosts" with the rule "Change board".
     *
     * @return list<Inventory>
     */
    private function inventories(): array
    {
        $rule = new class () extends ApprovalRule {
            public function attributes(): array
            {
                return ['id', 'name', 'required_approvals'];
            }
        };
        $rule->setAttribute('id', self::RULE_ID);
        $rule->setAttribute('name', 'Change board');

        return [
            $this->inventory(self::STATIC_ID, null, null),
            $this->inventory(self::GATED_ID, self::RULE_ID, $rule, 'repo-hosts'),
        ];
    }

    private function inventory(int $id, mixed $ruleId, ?ApprovalRule $rule, string $name = 'web-hosts'): Inventory
    {
        $inventory = new class () extends Inventory {
            public function attributes(): array
            {
                return ['id', 'name', 'inventory_type', 'project_id', 'approval_rule_id'];
            }
        };
        $inventory->setAttribute('id', $id);
        $inventory->setAttribute('name', $name);
        $inventory->setAttribute('inventory_type', Inventory::TYPE_STATIC);
        $inventory->setAttribute('approval_rule_id', $ruleId);
        $inventory->populateRelation('approvalRule', $rule);
        $inventory->populateRelation('project', null);

        return $inventory;
    }

    /**
     * Template #41, stored on inventory $storedInventoryId and now on
     * $inventoryId, without a rule of its own; its inventory relation is
     * empty, so nothing reads the database.
     */
    private function savedTemplate(mixed $storedInventoryId, mixed $inventoryId): JobTemplate
    {
        $template = new class () extends JobTemplate {
            public function attributes(): array
            {
                return ['id', 'name', 'project_id', 'inventory_id', 'approval_rule_id', 'deleted_at'];
            }
        };
        $template->setAttribute('id', 41);
        $template->setAttribute('inventory_id', $inventoryId);
        $template->setOldAttributes(['id' => 41, 'inventory_id' => $storedInventoryId, 'approval_rule_id' => null]);
        $template->populateRelation('inventory', null);
        $template->populateRelation('approvalRule', null);

        return $template;
    }
}
