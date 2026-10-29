<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\JobTemplate;
use app\services\JobTemplateGate;
use PHPUnit\Framework\TestCase;

/**
 * Regression: JobTemplateGate::of() reads the template's rule and inventory
 * "as stored or assigned", and a JSON body assigns a number beyond the int
 * range (1e30, 9223372036854775808, -1e30, 1e999 = infinity) as a float. Its
 * (int) cast warns on PHP 8.5, and the job template form, shown again to an
 * admin with the rule hint, answered 500. Such a number is no ID now, as the
 * integer rule reads it (SubmittedId): the template has no rule of its own
 * and no inventory. Every ID the integer rule accepts is read as before.
 *
 * The models are built in memory with their relations populated: nothing
 * here reads the database.
 */
class JobTemplateGateIntRangeTest extends TestCase
{
    private const INVENTORY_ID = 17;
    private const RULE_ID = 9;

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
    public function testARuleOrInventoryBeyondTheIntRangeIsNoId(string $number): void
    {
        $value = json_decode($number, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsFloat($value, 'the parser gives a float');
        $template = $this->template($value, $value, $this->inventory(null));

        $gate = JobTemplateGate::of($template);

        $this->assertNull($gate->templateRuleId);
        $this->assertSame(0, $gate->inventoryId);
        $this->assertFalse($gate->isGated());
        $this->assertNull($gate->toApi());
    }

    /**
     * The inventory's rule, as the gate reads it from the inventory model:
     * a number beyond the int range is no rule.
     *
     * @dataProvider numberProvider
     */
    public function testAnInventoryRuleBeyondTheIntRangeIsNoRule(string $number): void
    {
        $template = $this->template(null, self::INVENTORY_ID, $this->inventory(json_decode($number, true, 512, JSON_THROW_ON_ERROR)));

        $gate = JobTemplateGate::of($template);

        $this->assertNull($gate->inventoryRuleId);
        $this->assertFalse($gate->isGated());
    }

    /**
     * Every form of an ID that the integer rule accepts keeps its reading.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function acceptedFormProvider(): array
    {
        return [
            'an int' => [self::RULE_ID],
            'digits' => ['9'],
            'digits with a plus sign' => ['+9'],
            'digits with a leading zero' => ['09'],
            'a whole float' => [9.0],
        ];
    }

    /**
     * @dataProvider acceptedFormProvider
     */
    public function testEveryFormOfAnIdTheIntegerRuleAcceptsIsTheId(mixed $form): void
    {
        $rule = new class () extends ApprovalRule {
            public function attributes(): array
            {
                return ['id', 'name', 'required_approvals'];
            }
        };
        $rule->setAttribute('id', self::RULE_ID);
        $rule->setAttribute('name', 'Change board');
        $template = $this->template($form, $form, $this->inventory($form));
        $template->populateRelation('approvalRule', $rule);

        $gate = JobTemplateGate::of($template);

        $this->assertSame([self::RULE_ID, self::RULE_ID, self::RULE_ID], [$gate->templateRuleId, $gate->inventoryId, $gate->inventoryRuleId]);
        $this->assertSame(JobTemplateGate::SOURCE_JOB_TEMPLATE, $gate->source());
    }

    /**
     * Template #41 with the rule ID and inventory ID as assigned, and the
     * loaded inventory $inventory.
     */
    private function template(mixed $ruleId, mixed $inventoryId, ?Inventory $inventory): JobTemplate
    {
        $template = new class () extends JobTemplate {
            public function attributes(): array
            {
                return ['id', 'name', 'project_id', 'inventory_id', 'approval_rule_id', 'deleted_at'];
            }
        };
        $template->setAttribute('id', 41);
        $template->setAttribute('name', 'Deploy');
        $template->setAttribute('project_id', 5);
        $template->setAttribute('inventory_id', $inventoryId);
        $template->setAttribute('approval_rule_id', $ruleId);
        $template->populateRelation('approvalRule', null);
        $template->populateRelation('inventory', $inventory);

        return $template;
    }

    /**
     * Inventory INVENTORY_ID with the rule ID $ruleId as assigned, its rule
     * not loaded (no row).
     */
    private function inventory(mixed $ruleId): Inventory
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
        $inventory->populateRelation('approvalRule', null);

        return $inventory;
    }
}
