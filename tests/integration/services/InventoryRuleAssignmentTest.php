<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\JobTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\InventoryRuleAssignment;
use app\services\JobTemplateRuleAssignment;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The approval rule a request submits for an inventory: only admins assign
 * or remove one, the value is read as the rule ID it stands for, the audit
 * names the rule before and after, and an inventory gets no rule while job
 * templates on it have another rule of their own (RULES_DIFFER), named as
 * far as the viewer may see them.
 */
class InventoryRuleAssignmentTest extends DbTestCase
{
    use InventoryWriteFixtures;
    use TeamScopeFixtures;

    private InventoryRuleAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assignment = new InventoryRuleAssignment();
    }

    public function testNotSubmittingTheFieldLeavesTheRuleAsItIs(): void
    {
        $operator = $this->inventoryOperator();
        $rule = $this->createApprovalRule((int)$operator->id);
        $inventory = $this->gate($this->staticInventory((int)$operator->id), $rule);

        $refusal = $this->assignment->apply($inventory, ['name' => 'renamed', 'content' => 'x'], (int)$operator->id, (int)$rule->id);

        $this->assertNull($refusal);
        $this->assertSame((int)$rule->id, $inventory->getAttribute('approval_rule_id'));
    }

    /**
     * @return array<string, array{0: mixed, 1: string}> the submitted value, and what the attribute becomes:
     *     'rule' for the rule's ID, 'null' for none, 'as-is' for the value unchanged
     */
    public static function submittedValueProvider(): array
    {
        return [
            'an integer' => ['rule', 'rule'],
            'a string of digits' => ['rule-string', 'rule'],
            'a plus sign' => ['rule-plus', 'rule'],
            'a final line break' => ['rule-line-break', 'rule'],
            'a whole float' => ['rule-float', 'rule'],
            'an empty form value' => ['', 'null'],
            'null from JSON' => [null, 'null'],
            'no rule ID at all' => ['abc', 'as-is'],
            'a leading space' => [' 5', 'as-is'],
            'a fraction' => [5.5, 'as-is'],
            'a float beyond the int range' => [1e30, 'as-is'],
            'true' => [true, 'as-is'],
        ];
    }

    /**
     * An admin's value is applied as the rule it stands for, in every
     * spelling that the integer rule reads as the ID ("+5", "5\n" and 5.0 are
     * 5); a value that is no rule ID stays as it is, for the model's integer
     * rule to refuse.
     *
     * @dataProvider submittedValueProvider
     */
    public function testAnAdminsValueIsAppliedAsTheRuleItStandsFor(mixed $submitted, string $expected): void
    {
        $admin = $this->inventoryAdmin();
        $rule = $this->createApprovalRule((int)$admin->id);
        $inventory = $this->staticInventory((int)$admin->id);
        $value = match ($submitted) {
            'rule' => (int)$rule->id,
            'rule-string' => (string)$rule->id,
            'rule-plus' => '+' . $rule->id,
            'rule-line-break' => $rule->id . "\n",
            'rule-float' => (float)$rule->id,
            default => $submitted,
        };

        $refusal = $this->assignment->apply($inventory, [InventoryRuleAssignment::FIELD => $value], (int)$admin->id, null);

        $this->assertNull($refusal);
        $this->assertSame(
            match ($expected) {
                'rule' => (int)$rule->id,
                'null' => null,
                default => $submitted,
            },
            $inventory->getAttribute('approval_rule_id')
        );
    }

    /**
     * @return array<string, array{0: string, 1: bool}> the change, and whether the inventory had the rule
     */
    public static function changeProvider(): array
    {
        return [
            'setting a rule' => ['set', false],
            'removing the rule' => ['remove', true],
            'another rule' => ['other', true],
        ];
    }

    /**
     * Any change of the rule by a user who may not assign rules is refused,
     * and the attribute stays as it is.
     *
     * @dataProvider changeProvider
     */
    public function testANonAdminIsRefusedEveryChangeOfTheRule(string $change, bool $gated): void
    {
        $operator = $this->inventoryOperator();
        $rule = $this->createApprovalRule((int)$operator->id);
        $other = $this->createApprovalRule((int)$operator->id);
        $inventory = $this->staticInventory((int)$operator->id);
        if ($gated) {
            $inventory = $this->gate($inventory, $rule);
        }
        $submitted = match ($change) {
            'set' => (string)$rule->id,
            'remove' => '',
            default => (int)$other->id,
        };

        $refusal = $this->assignment->apply(
            $inventory,
            [InventoryRuleAssignment::FIELD => $submitted],
            (int)$operator->id,
            InventoryRuleAssignment::storedRuleId($inventory)
        );

        $this->assertSame(ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED, $refusal);
        $this->assertSame($gated ? (int)$rule->id : null, $inventory->getAttribute('approval_rule_id'));
    }

    /**
     * The stored rule sent back (a form or the body of a GET) is no change,
     * also for a user who may not assign rules.
     */
    public function testSubmittingTheStoredRuleIsNoChange(): void
    {
        $operator = $this->inventoryOperator();
        $rule = $this->createApprovalRule((int)$operator->id);
        $inventory = $this->gate($this->staticInventory((int)$operator->id), $rule);

        $refusal = $this->assignment->apply(
            $inventory,
            [InventoryRuleAssignment::FIELD => (string)$rule->id],
            (int)$operator->id,
            InventoryRuleAssignment::storedRuleId($inventory)
        );

        $this->assertNull($refusal);
        $this->assertSame((int)$rule->id, $inventory->getAttribute('approval_rule_id'));
    }

    /**
     * Regression: the stored rule sent back as "+5", "5\n" or 5.0, which the
     * integer rule reads as rule 5, was refused for a user who may not
     * assign rules (ASSIGN_REFUSED). It is no change, in every spelling, and
     * is applied as the stored ID.
     */
    public function testSubmittingTheStoredRuleInAnotherSpellingIsNoChange(): void
    {
        $operator = $this->inventoryOperator();
        $rule = $this->createApprovalRule((int)$operator->id);
        $inventory = $this->gate($this->staticInventory((int)$operator->id), $rule);

        foreach (['+' . $rule->id, $rule->id . "\n", '00' . $rule->id, (float)$rule->id] as $spelling) {
            $refusal = $this->assignment->apply(
                $inventory,
                [InventoryRuleAssignment::FIELD => $spelling],
                (int)$operator->id,
                InventoryRuleAssignment::storedRuleId($inventory)
            );

            $this->assertNull($refusal, var_export($spelling, true));
            $this->assertSame((int)$rule->id, $inventory->getAttribute('approval_rule_id'), var_export($spelling, true));
        }
    }

    public function testOnlyAdminsMayAssignRules(): void
    {
        $superadmin = $this->createUser('inv-rule-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);

        $this->assertTrue($this->assignment->mayAssign((int)$this->inventoryAdmin()->id));
        $this->assertTrue($this->assignment->mayAssign((int)$superadmin->id), 'a superadmin without roles');
        $this->assertFalse($this->assignment->mayAssign((int)$this->inventoryOperator()->id));
        $this->assertFalse($this->assignment->mayAssign((int)$this->inventoryDeleter()->id));
    }

    public function testTheOptionsAreThoseOfTheJobTemplateForm(): void
    {
        $admin = $this->inventoryAdmin();
        $rule = $this->createApprovalRule((int)$admin->id);

        $options = $this->assignment->options();

        $this->assertSame((new JobTemplateRuleAssignment())->options(), $options);
        $this->assertSame(JobTemplateRuleAssignment::label($rule), $options[(int)$rule->id] ?? null);
    }

    /**
     * The baseline of an update is the stored rule, whatever the model holds
     * now; a new inventory has none.
     */
    public function testTheStoredRuleIsTheBaseline(): void
    {
        $admin = $this->inventoryAdmin();
        $rule = $this->createApprovalRule((int)$admin->id);
        $inventory = $this->gate($this->staticInventory((int)$admin->id), $rule);
        $inventory->setAttribute('approval_rule_id', null);

        $this->assertSame((int)$rule->id, InventoryRuleAssignment::storedRuleId($inventory));
        $this->assertNull(InventoryRuleAssignment::storedRuleId($this->staticInventory((int)$admin->id)));
        $this->assertNull(InventoryRuleAssignment::storedRuleId(new Inventory()));
    }

    public function testTheAuditContextNamesTheRuleBeforeAndAfter(): void
    {
        $admin = $this->inventoryAdmin();
        $first = $this->namedRule((int)$admin->id, 'First <rule> "a"');
        $second = $this->namedRule((int)$admin->id, 'Second rule');
        $firstEntry = ['id' => (int)$first->id, 'name' => 'First <rule> "a"'];
        $secondEntry = ['id' => (int)$second->id, 'name' => 'Second rule'];

        $this->assertSame([], $this->assignment->auditContext(null, null));
        $this->assertSame([], $this->assignment->auditContext((int)$first->id, (int)$first->id));
        $this->assertSame(['approval_rule' => ['from' => null, 'to' => $firstEntry]], $this->assignment->auditContext(null, (int)$first->id));
        $this->assertSame(['approval_rule' => ['from' => $firstEntry, 'to' => null]], $this->assignment->auditContext((int)$first->id, null));
        $this->assertSame(
            ['approval_rule' => ['from' => $firstEntry, 'to' => $secondEntry]],
            $this->assignment->auditContext((int)$first->id, (int)$second->id)
        );
        $this->assertSame(
            ['approval_rule' => ['from' => null, 'to' => ['id' => 999999999, 'name' => null]]],
            $this->assignment->auditContext(null, 999999999),
            'a rule that does not exist has no name'
        );
    }

    // -- conflictRefusal() ------------------------------------------------------

    public function testAnInventoryWithoutARuleHasNoConflict(): void
    {
        $admin = $this->inventoryAdmin();
        $inventory = $this->staticInventory((int)$admin->id);
        $this->templateOn((int)$inventory->id, (int)$admin->id, $this->createApprovalRule((int)$admin->id));

        $this->assertNull($this->assignment->conflictRefusal($inventory, (int)$admin->id));
    }

    /**
     * Job templates with the same rule, with none, and deleted ones with
     * another rule share the inventory's rule: a launch waits for it alone.
     */
    public function testTemplatesWithTheSameRuleNoneOrDeletedAreNoConflict(): void
    {
        $admin = $this->inventoryAdmin();
        $rule = $this->createApprovalRule((int)$admin->id);
        $inventory = $this->staticInventory((int)$admin->id);
        $this->templateOn((int)$inventory->id, (int)$admin->id, $rule);
        $this->templateOn((int)$inventory->id, (int)$admin->id);
        $deleted = $this->templateOn((int)$inventory->id, (int)$admin->id, $this->createApprovalRule((int)$admin->id));
        $deleted->deleted_at = time();
        $deleted->save(false);
        $inventory->setAttribute('approval_rule_id', (int)$rule->id);

        $this->assertNull($this->assignment->conflictRefusal($inventory, (int)$admin->id));
    }

    /**
     * Without any team-restricted project (a default install) every
     * template is visible: they are named, quoted, by name.
     */
    public function testTheTemplatesWithAnotherRuleAreNamedQuoted(): void
    {
        $admin = $this->inventoryAdmin();
        $inventory = $this->staticInventory((int)$admin->id);
        $other = $this->createApprovalRule((int)$admin->id);
        $this->templateOn((int)$inventory->id, (int)$admin->id, $other, 'b-deploy <web>');
        $this->templateOn((int)$inventory->id, (int)$admin->id, $other, 'a-"quoted"');
        $inventory->setAttribute('approval_rule_id', (string)$this->createApprovalRule((int)$admin->id)->id);

        $refusal = $this->assignment->conflictRefusal($inventory, (int)$this->inventoryOperator()->id);

        $this->assertSame(
            'Job templates that use this inventory have another approval rule of their own: "a-\"quoted\"", '
                . '"b-deploy <web>". A launch waits for one rule only, so give them this rule or none first.',
            $refusal
        );
        $this->assertSame(sprintf(InventoryRuleAssignment::RULES_DIFFER, '"a-\"quoted\"", "b-deploy <web>"'), $refusal);
    }

    /**
     * A template of a project the viewer may not see is counted, never named.
     */
    public function testHiddenTemplatesAreCountedNotNamed(): void
    {
        $scope = $this->teamScope();
        $other = $this->createApprovalRule((int)$scope['admin']->id);
        $this->giveRule($scope['foreign'], $other);
        $inventory = $this->reloaded((int)$scope['foreign']->inventory_id);
        $inventory->setAttribute('approval_rule_id', (int)$this->createApprovalRule((int)$scope['admin']->id)->id);

        $this->assertSame(
            sprintf(InventoryRuleAssignment::RULES_DIFFER, sprintf(InventoryRuleAssignment::RULES_DIFFER_HIDDEN, 1)),
            $this->assignment->conflictRefusal($inventory, (int)$scope['member']->id)
        );
        $this->assertSame(
            sprintf(InventoryRuleAssignment::RULES_DIFFER, '"' . $scope['foreign']->name . '"'),
            $this->assignment->conflictRefusal($inventory, (int)$scope['admin']->id),
            'an admin sees it'
        );
    }

    public function testVisibleTemplatesAreNamedAndHiddenOnesCounted(): void
    {
        $scope = $this->teamScope();
        $other = $this->createApprovalRule((int)$scope['admin']->id);
        foreach (['own', 'viewed', 'foreign'] as $key) {
            $this->giveRule($scope[$key], $other);
        }
        $inventory = $this->reloaded((int)$scope['own']->inventory_id);
        $inventory->setAttribute('approval_rule_id', (int)$this->createApprovalRule((int)$scope['admin']->id)->id);
        $names = [$scope['own']->name, $scope['viewed']->name];
        sort($names, SORT_STRING);

        $this->assertSame(
            sprintf(
                InventoryRuleAssignment::RULES_DIFFER,
                '"' . $names[0] . '", "' . $names[1] . '" and ' . sprintf(InventoryRuleAssignment::RULES_DIFFER_HIDDEN, 1)
            ),
            $this->assignment->conflictRefusal($inventory, (int)$scope['member']->id)
        );
    }

    private function giveRule(JobTemplate $template, ApprovalRule $rule): void
    {
        $template->approval_rule_id = (int)$rule->id;
        $template->save(false);
    }

    private function namedRule(int $createdBy, string $name): ApprovalRule
    {
        $rule = $this->createApprovalRule($createdBy);
        $rule->name = $name;
        $rule->save(false);

        return $rule;
    }
}
