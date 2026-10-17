<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\Project;
use app\services\JobTemplateInventoryOptions;
use app\services\JobTemplateRuleAssignment;
use PHPUnit\Framework\TestCase;

/**
 * The inventories the job template form offers, and its two hints:
 * - admins (who may assign rules) get every inventory, an inventory with an
 *   approval rule marked with GATED_OPTION_SUFFIX, and, when the template's
 *   inventory has a rule, INVENTORY_RULE_HINT at the rule select;
 * - everyone else gets the inventories without a rule plus the template's
 *   stored inventory (the one a locked form keeps showing), and an unlocked
 *   form says when gated ones were left out (GATED_INVENTORIES_HINT).
 *
 * The models are built in memory with their relations populated, as the
 * form's query eager-loads them, and list their columns themselves: nothing
 * here reads the database.
 */
class JobTemplateInventoryOptionsTest extends TestCase
{
    private const STATIC_ID = 11;
    private const FILE_ID = 12;
    private const DYNAMIC_ID = 13;
    private const CHANGE_BOARD_ID = 7;
    private const NIGHT_OPS_ID = 9;

    /** A name that a page must encode: the options and hints give it back as stored. */
    private const HOSTILE_NAME = '<img src=x onerror=alert(1)> "q" \'a\'';

    public function testAdminsGetEveryInventoryAndTheOnesWithARuleAreMarked(): void
    {
        $listed = JobTemplateInventoryOptions::of($this->inventories(), true, null);

        $this->assertSame([
            self::STATIC_ID => 'web-hosts',
            self::FILE_ID => 'repo-hosts (file, Site) · needs approval: Change board',
            self::DYNAMIC_ID => 'cloud (dynamic, no project) · needs approval: Night ops',
        ], $listed['options'], 'in the order given, with the labels of the form');
        $this->assertFalse($listed['gated_omitted']);
    }

    public function testOthersGetTheInventoriesWithoutARule(): void
    {
        $listed = JobTemplateInventoryOptions::of($this->inventories(), false, null);

        $this->assertSame([self::STATIC_ID => 'web-hosts'], $listed['options']);
        $this->assertTrue($listed['gated_omitted']);
    }

    public function testOthersKeepTheStoredInventoryWithItsMark(): void
    {
        $listed = JobTemplateInventoryOptions::of($this->inventories(), false, self::FILE_ID);

        $this->assertSame([
            self::STATIC_ID => 'web-hosts',
            self::FILE_ID => 'repo-hosts (file, Site) · needs approval: Change board',
        ], $listed['options']);
        $this->assertTrue($listed['gated_omitted'], 'the other gated inventory was left out');
    }

    public function testNothingIsLeftOutWhenNoInventoryHasARule(): void
    {
        $listed = JobTemplateInventoryOptions::of([$this->inventory(self::STATIC_ID, 'web-hosts', Inventory::TYPE_STATIC, null, null)], false, null);

        $this->assertSame([self::STATIC_ID => 'web-hosts'], $listed['options']);
        $this->assertFalse($listed['gated_omitted']);
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function ruleIdProvider(): array
    {
        return [
            'none' => [null, false],
            'an empty text' => ['', false],
            'zero' => [0, false],
            'zero as text' => ['0', false],
            'a negative number' => [-3, false],
            'no number' => ['abc', false],
            'an ID' => [self::CHANGE_BOARD_ID, true],
            'an ID as text' => ['7', true],
        ];
    }

    /**
     * An inventory counts as gated as the template lock counts it: its
     * approval_rule_id is a positive ID.
     *
     * @dataProvider ruleIdProvider
     */
    public function testOnlyAPositiveRuleIdGatesAnInventory(mixed $ruleId, bool $gated): void
    {
        $rule = $gated ? $this->rule(self::CHANGE_BOARD_ID, 'Change board') : null;
        $inventory = $this->inventory(self::STATIC_ID, 'web-hosts', Inventory::TYPE_STATIC, $ruleId, $rule);

        $forAdmins = JobTemplateInventoryOptions::of([$inventory], true, null);
        $forOthers = JobTemplateInventoryOptions::of([$inventory], false, null);

        $this->assertSame(
            [self::STATIC_ID => $gated ? 'web-hosts · needs approval: Change board' : 'web-hosts'],
            $forAdmins['options']
        );
        $this->assertSame($gated ? [] : [self::STATIC_ID => 'web-hosts'], $forOthers['options']);
        $this->assertSame($gated, $forOthers['gated_omitted']);
    }

    /**
     * Code running before m000088, or a schema cached from before it: an
     * inventory model without the column has no rule.
     */
    public function testAnInventoryWithoutTheColumnHasNoRule(): void
    {
        $inventory = new class () extends Inventory {
            public function attributes(): array
            {
                return ['id', 'name', 'inventory_type', 'project_id'];
            }
        };
        $inventory->setAttribute('id', self::STATIC_ID);
        $inventory->setAttribute('name', 'web-hosts');
        $inventory->setAttribute('inventory_type', Inventory::TYPE_STATIC);

        $listed = JobTemplateInventoryOptions::of([$inventory], false, null);

        $this->assertSame([self::STATIC_ID => 'web-hosts'], $listed['options']);
        $this->assertFalse($listed['gated_omitted']);
    }

    /**
     * The foreign key keeps a rule that an inventory uses; should its row be
     * gone all the same, the inventory still counts as gated, and the mark
     * names the rule by its ID.
     */
    public function testARuleWhoseRowIsGoneIsNamedByItsId(): void
    {
        $inventory = $this->inventory(self::STATIC_ID, 'web-hosts', Inventory::TYPE_STATIC, self::NIGHT_OPS_ID, null);

        $this->assertSame(
            [self::STATIC_ID => 'web-hosts · needs approval: rule #' . self::NIGHT_OPS_ID],
            JobTemplateInventoryOptions::of([$inventory], true, null)['options']
        );
        $this->assertSame([], JobTemplateInventoryOptions::of([$inventory], false, null)['options']);
    }

    /**
     * The select encodes its labels: of() gives the names as stored.
     */
    public function testNamesAreGivenAsStored(): void
    {
        $project = $this->project(self::HOSTILE_NAME);
        $inventory = $this->inventory(self::FILE_ID, self::HOSTILE_NAME, Inventory::TYPE_FILE, self::CHANGE_BOARD_ID, $this->rule(self::CHANGE_BOARD_ID, self::HOSTILE_NAME), $project);

        $this->assertSame(
            [self::FILE_ID => self::HOSTILE_NAME . ' (file, ' . self::HOSTILE_NAME . ') · needs approval: ' . self::HOSTILE_NAME],
            JobTemplateInventoryOptions::of([$inventory], true, null)['options']
        );
    }

    // -- formParams() -------------------------------------------------------------

    /**
     * A new template keeps no inventory: others are offered none with a
     * rule, also when the form names one, and are told so.
     */
    public function testANewTemplateOffersOthersNoInventoryWithARule(): void
    {
        $template = $this->newTemplate(self::FILE_ID);

        $params = JobTemplateInventoryOptions::formParams($template, $this->inventories(), false, false);

        $this->assertSame([self::STATIC_ID => 'web-hosts'], $params['inventoryOptions']);
        $this->assertSame(JobTemplateInventoryOptions::GATED_INVENTORIES_HINT, $params['inventoryHint']);
        $this->assertNull($params['inventoryRuleHint']);
        $this->assertSame(
            'Inventories with an approval rule are not listed: only admins may give a job template such an inventory.',
            JobTemplateInventoryOptions::GATED_INVENTORIES_HINT
        );
        $this->assertSame(' · needs approval: %s', JobTemplateInventoryOptions::GATED_OPTION_SUFFIX);
    }

    /**
     * A saved template keeps its stored inventory in the list, as the
     * template lock locks the form of others: the select shows it selected,
     * and the form, locked, says nothing about inventories left out.
     */
    public function testALockedFormOfOthersKeepsTheStoredInventory(): void
    {
        $template = $this->savedTemplate(self::FILE_ID, self::FILE_ID);

        $params = JobTemplateInventoryOptions::formParams($template, $this->inventories(), false, true);

        $this->assertSame([
            self::STATIC_ID => 'web-hosts',
            self::FILE_ID => 'repo-hosts (file, Site) · needs approval: Change board',
        ], $params['inventoryOptions']);
        $this->assertNull($params['inventoryHint'], 'a locked form: the inventory select is disabled');
        $this->assertNull($params['inventoryRuleHint'], 'the rule select is for admins');
    }

    /**
     * The inventory kept is the stored one, not the one a submission names.
     */
    public function testTheStoredInventoryIsKeptNotTheSubmittedOne(): void
    {
        $template = $this->savedTemplate(self::STATIC_ID, self::DYNAMIC_ID);

        $params = JobTemplateInventoryOptions::formParams($template, $this->inventories(), false, false);

        $this->assertSame([self::STATIC_ID => 'web-hosts'], $params['inventoryOptions']);
        $this->assertSame(JobTemplateInventoryOptions::GATED_INVENTORIES_HINT, $params['inventoryHint']);
    }

    /**
     * @return array<string, array{0: bool, 1: bool, 2: bool, 3: bool}>
     */
    public static function hintProvider(): array
    {
        return [
            'others, an unlocked form, inventories with a rule left out' => [false, false, true, true],
            'others, an unlocked form, no inventory with a rule' => [false, false, false, false],
            'others, a locked form' => [false, true, true, false],
            'admins' => [true, false, true, false],
        ];
    }

    /**
     * @dataProvider hintProvider
     */
    public function testTheHintSaysWhenInventoriesWithARuleWereLeftOut(bool $mayAssign, bool $changeLocked, bool $gatedExists, bool $hint): void
    {
        $inventories = $gatedExists
            ? $this->inventories()
            : [$this->inventory(self::STATIC_ID, 'web-hosts', Inventory::TYPE_STATIC, null, null)];

        $params = JobTemplateInventoryOptions::formParams($this->savedTemplate(self::STATIC_ID, self::STATIC_ID), $inventories, $mayAssign, $changeLocked);

        $this->assertSame($hint ? JobTemplateInventoryOptions::GATED_INVENTORIES_HINT : null, $params['inventoryHint']);
    }

    public function testAdminsAreToldTheRuleOfTheTemplatesInventory(): void
    {
        $template = $this->savedTemplate(self::FILE_ID, self::FILE_ID);

        $params = JobTemplateInventoryOptions::formParams($template, $this->inventories(), true, false);

        $this->assertSame(
            'Its inventory "repo-hosts" has the approval rule "Change board": every launch waits for it. '
            . 'Leave this rule empty, or choose the same one.',
            $params['inventoryRuleHint']
        );
        $this->assertNull($params['inventoryHint']);
        $this->assertCount(3, $params['inventoryOptions'], 'every inventory');
    }

    /**
     * The hint quotes both names as RULES_DIFFER does, and gives them as
     * stored otherwise: the page encodes it.
     */
    public function testTheRuleHintQuotesTheNames(): void
    {
        $inventory = $this->inventory(self::STATIC_ID, self::HOSTILE_NAME, Inventory::TYPE_STATIC, self::CHANGE_BOARD_ID, $this->rule(self::CHANGE_BOARD_ID, self::HOSTILE_NAME));
        $template = $this->savedTemplate(self::STATIC_ID, self::STATIC_ID, $inventory);

        $params = JobTemplateInventoryOptions::formParams($template, [$inventory], true, false);

        $quoted = '"<img src=x onerror=alert(1)> \"q\" \'a\'"';
        $this->assertSame(sprintf(JobTemplateRuleAssignment::INVENTORY_RULE_HINT, $quoted, $quoted), $params['inventoryRuleHint']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function noRuleHintProvider(): array
    {
        return [
            'an inventory without a rule' => ['ungated'],
            'a rule whose row is gone' => ['gone'],
            'no inventory' => ['none'],
            'a user who may not assign rules' => ['other'],
        ];
    }

    /**
     * @dataProvider noRuleHintProvider
     */
    public function testNoRuleHintWithoutARuleOfTheInventory(string $case): void
    {
        $inventory = match ($case) {
            'ungated' => $this->inventory(self::STATIC_ID, 'web-hosts', Inventory::TYPE_STATIC, null, null),
            'gone' => $this->inventory(self::STATIC_ID, 'web-hosts', Inventory::TYPE_STATIC, self::NIGHT_OPS_ID, null),
            'none' => null,
            default => $this->inventory(self::STATIC_ID, 'web-hosts', Inventory::TYPE_STATIC, self::CHANGE_BOARD_ID, $this->rule(self::CHANGE_BOARD_ID, 'Change board')),
        };
        // An inventory ID that inventories() does not hold: the relation stays empty.
        $template = $inventory === null ? $this->savedTemplate(99, 99) : $this->savedTemplate(self::STATIC_ID, self::STATIC_ID, $inventory);

        $params = JobTemplateInventoryOptions::formParams($template, $inventory === null ? [] : [$inventory], $case !== 'other', $case === 'other');

        $this->assertNull($params['inventoryRuleHint']);
    }

    // -- helpers ------------------------------------------------------------------

    /**
     * A static inventory without a rule, a file inventory of project "Site"
     * with the rule "Change board", and a dynamic one without a project with
     * the rule "Night ops" (its ID given as text, as a form posts it).
     *
     * @return list<Inventory>
     */
    private function inventories(): array
    {
        return [
            $this->inventory(self::STATIC_ID, 'web-hosts', Inventory::TYPE_STATIC, null, null),
            $this->inventory(self::FILE_ID, 'repo-hosts', Inventory::TYPE_FILE, self::CHANGE_BOARD_ID, $this->rule(self::CHANGE_BOARD_ID, 'Change board'), $this->project('Site')),
            $this->inventory(self::DYNAMIC_ID, 'cloud', Inventory::TYPE_DYNAMIC, (string)self::NIGHT_OPS_ID, $this->rule(self::NIGHT_OPS_ID, 'Night ops')),
        ];
    }

    private function inventory(int $id, string $name, string $type, mixed $ruleId, ?ApprovalRule $rule, ?Project $project = null): Inventory
    {
        $inventory = new class () extends Inventory {
            public function attributes(): array
            {
                return ['id', 'name', 'inventory_type', 'project_id', 'approval_rule_id'];
            }
        };
        $inventory->setAttribute('id', $id);
        $inventory->setAttribute('name', $name);
        $inventory->setAttribute('inventory_type', $type);
        $inventory->setAttribute('project_id', $project?->id);
        $inventory->setAttribute('approval_rule_id', $ruleId);
        $inventory->populateRelation('approvalRule', $rule);
        $inventory->populateRelation('project', $project);

        return $inventory;
    }

    private function project(string $name): Project
    {
        $project = new class () extends Project {
            public function attributes(): array
            {
                return ['id', 'name'];
            }
        };
        $project->setAttribute('id', 5);
        $project->setAttribute('name', $name);

        return $project;
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
     * A template not saved yet, as a form names inventory #$inventoryId;
     * its inventory relation is empty, so nothing reads the database.
     */
    private function newTemplate(int $inventoryId): JobTemplate
    {
        $template = $this->templateModel();
        $template->setAttribute('inventory_id', $inventoryId);
        $template->populateRelation('inventory', null);

        return $template;
    }

    /**
     * A saved template stored on inventory #$storedInventoryId and now on
     * #$inventoryId (a submission may have changed it), without a rule of
     * its own; its inventory relation holds $inventory, by default the one
     * of inventories() with that ID.
     */
    private function savedTemplate(int $storedInventoryId, int $inventoryId, ?Inventory $inventory = null): JobTemplate
    {
        $template = $this->templateModel();
        $template->setAttribute('id', 41);
        $template->setAttribute('inventory_id', $inventoryId);
        $template->setOldAttributes(['id' => 41, 'inventory_id' => $storedInventoryId, 'approval_rule_id' => null]);
        if ($inventory === null) {
            foreach ($this->inventories() as $candidate) {
                if ((int)$candidate->id === $inventoryId) {
                    $inventory = $candidate;
                }
            }
        }
        $template->populateRelation('inventory', $inventory);
        $template->populateRelation('approvalRule', null);

        return $template;
    }

    private function templateModel(): JobTemplate
    {
        $template = new class () extends JobTemplate {
            public function attributes(): array
            {
                return ['id', 'name', 'project_id', 'inventory_id', 'approval_rule_id', 'deleted_at'];
            }
        };
        $template->setAttribute('name', 'Deploy');
        $template->setAttribute('project_id', 5);

        return $template;
    }
}
