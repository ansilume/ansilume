<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\SurveyDefinition;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\User;
use app\services\GatedJobTemplateLock;
use app\services\JobTemplateRuleAssignment;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Model;

/**
 * One rule per launch: a job template on an inventory with an approval rule
 * has no rule of its own, or the same one (JobTemplateRuleAssignment::
 * RULES_DIFFER, an error of approval_rule_id). The template's validation
 * checks it when a save creates the template or changes its rule or its
 * inventory, and the save's transaction checks it again on the share-locked
 * inventory row (persistVetoes()), after the template lock. A template
 * whose stored pair differs keeps its name open.
 */
class JobTemplateRulesDifferTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use TemplateInventoryLockFixtures;

    private User $admin;
    private User $operator;
    private ApprovalRule $inventoryRule;
    private ApprovalRule $otherRule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('rules-differ-admin', 'admin');
        $this->operator = $this->createUserWithRole('rules-differ-operator', 'operator');
        $this->inventoryRule = $this->createApprovalRule((int)$this->admin->id);
        $this->otherRule = $this->createApprovalRule((int)$this->admin->id);
    }

    // -- vetoesDifferentRules() ----------------------------------------------------------

    public function testAnOwnRuleThatDiffersFromTheInventorysIsAnError(): void
    {
        $template = $this->inventoryGated((int)$this->admin->id, $this->inventoryRule);
        $template->setAttribute('approval_rule_id', (int)$this->otherRule->id);

        $this->assertTrue(JobTemplateRuleAssignment::vetoesDifferentRules($template, false));

        $inventory = Inventory::findOne($template->inventory_id);
        $this->assertNotNull($inventory);
        $this->assertSame([$this->rulesDiffer((string)$inventory->name, (string)$this->inventoryRule->name)], $template->getErrors('approval_rule_id'));
    }

    /**
     * The names come quoted (SurveyDefinition::quote()) and raw otherwise:
     * whoever renders the message encodes it.
     */
    public function testTheMessageQuotesTheNames(): void
    {
        $template = $this->inventoryGated((int)$this->admin->id, $this->inventoryRule);
        $name = '<img src=x onerror=alert(1)> "q" \'a\'';
        Inventory::updateAll(['name' => $name], ['id' => $template->inventory_id]);
        ApprovalRule::updateAll(['name' => $name . ' rule'], ['id' => $this->inventoryRule->id]);
        $template->setAttribute('approval_rule_id', (int)$this->otherRule->id);

        JobTemplateRuleAssignment::vetoesDifferentRules($template, false);

        $this->assertSame(
            sprintf(JobTemplateRuleAssignment::RULES_DIFFER, '"<img src=x onerror=alert(1)> \"q\" \'a\'"', '"<img src=x onerror=alert(1)> \"q\" \'a\' rule"'),
            $template->getFirstError('approval_rule_id')
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function noConflictProvider(): array
    {
        return [
            'no rule of its own' => ['no-own-rule'],
            'the same rule as its inventory' => ['same-rule'],
            'its own rule as a text of digits, the same' => ['same-rule-text'],
            'an inventory without a rule' => ['ungated-inventory'],
            'an inventory that does not exist' => ['missing-inventory'],
        ];
    }

    /**
     * @dataProvider noConflictProvider
     */
    public function testNoConflictNoError(string $case): void
    {
        $template = $case === 'ungated-inventory'
            ? $this->ungatedTemplate((int)$this->admin->id)
            : $this->inventoryGated((int)$this->admin->id, $this->inventoryRule);
        $template->setAttribute('approval_rule_id', match ($case) {
            'no-own-rule' => null,
            'same-rule' => (int)$this->inventoryRule->id,
            'same-rule-text' => (string)$this->inventoryRule->id,
            default => (int)$this->otherRule->id,
        });
        if ($case === 'missing-inventory') {
            $template->setAttribute('inventory_id', 987654321);
        }

        $this->assertFalse(JobTemplateRuleAssignment::vetoesDifferentRules($template, false));
        $this->assertSame([], $template->getErrors());
    }

    /**
     * An error of the rule or of the inventory already says what is wrong,
     * and nothing is read then: the inventory may be one the user may not
     * see, whose name and rule the message would give away.
     *
     * @return array<string, array{0: string}>
     */
    public static function earlierErrorProvider(): array
    {
        return [
            'the rule' => ['approval_rule_id'],
            'the inventory' => ['inventory_id'],
        ];
    }

    /**
     * @dataProvider earlierErrorProvider
     */
    public function testAnEarlierErrorSkipsTheCheck(string $attribute): void
    {
        $template = $this->inventoryGated((int)$this->admin->id, $this->inventoryRule);
        $template->setAttribute('approval_rule_id', (int)$this->otherRule->id);
        $template->addError($attribute, 'Earlier.');
        $vetoed = true;

        $statements = $this->statementsOf(static function () use ($template, &$vetoed): void {
            $vetoed = JobTemplateRuleAssignment::vetoesDifferentRules($template, true);
        });

        $this->assertFalse($vetoed);
        $this->assertSame([$attribute => ['Earlier.']], $template->getErrors());
        $this->assertSame([], $statements, 'nothing is read');
    }

    /**
     * Inside a save's transaction the inventory row is share-locked (by the
     * template lock already); validation reads it without a lock.
     */
    public function testTheShareLockIsTakenOnlyWhenAsked(): void
    {
        $template = $this->inventoryGated((int)$this->admin->id, $this->inventoryRule);
        $template->setAttribute('approval_rule_id', (int)$this->inventoryRule->id);
        $inventoryId = (int)$template->inventory_id;

        $locked = $this->statementsOf(static fn (): bool => JobTemplateRuleAssignment::vetoesDifferentRules($template, true));
        $plain = $this->statementsOf(static fn (): bool => JobTemplateRuleAssignment::vetoesDifferentRules($template, false));

        $this->assertSame(["SELECT * FROM `inventory` WHERE `id` IN ({$inventoryId}) ORDER BY `id` LOCK IN SHARE MODE"], $locked);
        $this->assertSame(["SELECT * FROM `inventory` WHERE `id` IN ({$inventoryId}) ORDER BY `id`"], $plain);
    }

    // -- The template's validation -------------------------------------------------------

    public function testANewTemplateWithAnotherRuleThanItsInventoryIsInvalid(): void
    {
        $inventory = $this->inventoryWithRule((int)$this->admin->id, $this->inventoryRule);
        $new = $this->newTemplateOn((int)$this->createProject((int)$this->admin->id)->id, (int)$inventory->id, (int)$this->admin->id);
        $new->setAttribute('approval_rule_id', (int)$this->otherRule->id);

        $this->assertFalse($new->validate());
        $this->assertSame([$this->rulesDiffer((string)$inventory->name, (string)$this->inventoryRule->name)], $new->getErrors('approval_rule_id'));

        $new->setAttribute('approval_rule_id', (int)$this->inventoryRule->id);
        $this->assertTrue($new->validate(), 'the same rule');
        $new->setAttribute('approval_rule_id', null);
        $this->assertTrue($new->validate(), 'no rule of its own');
    }

    public function testChangingTheRuleOrTheInventoryIsChecked(): void
    {
        $template = $this->inventoryGated((int)$this->admin->id, $this->inventoryRule);
        $template->setAttribute('approval_rule_id', (int)$this->otherRule->id);
        $this->assertFalse($template->validate(), 'another rule of its own');

        $repointed = $this->withOwnRule($this->ungatedTemplate((int)$this->admin->id), $this->otherRule);
        $repointed->setAttribute('inventory_id', (int)$this->inventoryWithRule((int)$this->admin->id, $this->inventoryRule)->id);
        $this->assertFalse($repointed->validate(), 'an inventory with another rule');
        $this->assertNotSame([], $repointed->getErrors('approval_rule_id'));
    }

    /**
     * A template whose stored pair differs (an inventory that got another
     * rule, or data from before) can still be renamed and edited: only a
     * save that creates the template or changes its rule or its inventory is
     * checked, by value, as the form posts the stored inventory as text.
     */
    public function testAStoredPairThatDiffersDoesNotBlockOtherChanges(): void
    {
        $template = $this->withOwnRule($this->inventoryGated((int)$this->admin->id, $this->inventoryRule), $this->otherRule);
        $template->setAttribute('name', 'renamed-' . uniqid('', true));
        $template->setAttribute('playbook', 'other.yml');
        $template->setAttribute('inventory_id', (string)$template->inventory_id);
        $template->setAttribute('approval_rule_id', (string)$this->otherRule->id);

        $this->assertTrue($template->validate(), (string)json_encode($template->errors));
        $this->assertFalse(JobTemplateRuleAssignment::persistVetoes(new GatedJobTemplateLock(), $template, null, (int)$this->admin->id));
    }

    // -- persistVetoes() -----------------------------------------------------------------

    /**
     * The template lock comes first: when it refuses, its reason stands
     * alone, and the rules are not compared.
     */
    public function testTheLocksRefusalComesFirst(): void
    {
        $template = $this->withOwnRule($this->ungatedTemplate((int)$this->admin->id), $this->otherRule);
        $template->setAttribute('inventory_id', (int)$this->inventoryWithRule((int)$this->admin->id, $this->inventoryRule)->id);

        $this->assertTrue(JobTemplateRuleAssignment::persistVetoes(new GatedJobTemplateLock(), $template, null, (int)$this->operator->id));

        $this->assertSame([GatedJobTemplateLock::ERROR_KEY => [GatedJobTemplateLock::CHANGE_REFUSED]], $template->getErrors());
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function pairProvider(): array
    {
        return [
            'a new template with another rule than its inventory' => ['new', true],
            'another rule of its own' => ['rule', true],
            'an inventory with another rule' => ['inventory', true],
            'a rename of a stored pair that differs' => ['rename', false],
            'the same rule as its inventory' => ['same', false],
            'no rule of its own any more' => ['remove', false],
        ];
    }

    /**
     * RULES_DIFFER is checked inside the save only when the pair changes.
     *
     * @dataProvider pairProvider
     */
    public function testRulesDifferIsCheckedWhenThePairChanges(string $change, bool $vetoed): void
    {
        $template = $this->templateFor($change);

        $this->assertSame($vetoed, JobTemplateRuleAssignment::persistVetoes(new GatedJobTemplateLock(), $template, null, (int)$this->admin->id));
        $this->assertSame($vetoed, $template->getErrors('approval_rule_id') !== []);
        $this->assertFalse(GatedJobTemplateLock::refused($template), 'an admin passes the lock');
    }

    // -- Races: the save's transaction checks again ----------------------------------------

    /**
     * Regression: an admin gave a job template a rule of its own other than
     * the rule of its inventory, so that its launches met two rules.
     */
    public function testRegressionAnAdminGaveATemplateARuleOtherThanItsInventorys(): void
    {
        $template = $this->inventoryGated((int)$this->admin->id, $this->inventoryRule);
        $template->setAttribute('approval_rule_id', (int)$this->otherRule->id);

        $saved = $this->templateCredentials()->saveWithCredentials($template, null, [], (int)$this->admin->id);

        $this->assertFalse($saved, 'the save is refused');
        $this->assertNull($this->reloaded($template)->approval_rule_id, 'nothing is written');
        $inventory = Inventory::findOne($template->inventory_id);
        $this->assertNotNull($inventory);
        $this->assertSame(
            [$this->rulesDiffer((string)$inventory->name, (string)$this->inventoryRule->name)],
            $template->getErrors('approval_rule_id')
        );
    }

    /**
     * Regression: an admin re-pointed a job template with a rule of its own
     * to an inventory with another rule.
     */
    public function testRegressionAnAdminRepointedATemplateToAnInventoryWithAnotherRule(): void
    {
        $template = $this->withOwnRule($this->ungatedTemplate((int)$this->admin->id), $this->otherRule);
        $stored = (int)$template->inventory_id;
        $template->setAttribute('inventory_id', (int)$this->inventoryWithRule((int)$this->admin->id, $this->inventoryRule)->id);

        $saved = $this->templateCredentials()->saveWithCredentials($template, null, [], (int)$this->admin->id);

        $this->assertFalse($saved, 'the save is refused');
        $this->assertSame($stored, (int)$this->reloaded($template)->inventory_id, 'nothing is written');
        $this->assertNotSame([], $template->getErrors('approval_rule_id'));
    }

    /**
     * A rule the inventory gets while an admin's save gives the template
     * another rule (here: during its validation) is seen on the
     * share-locked inventory row, and nothing is written.
     *
     * @return array<string, array{0: string}>
     */
    public static function raceProvider(): array
    {
        return [
            'giving the template a rule' => ['rule'],
            're-pointing the template' => ['inventory'],
            'creating the template' => ['new'],
        ];
    }

    /**
     * @dataProvider raceProvider
     */
    public function testARuleTheInventoryGetsWhileTheSaveRunsIsSeenUnderTheShareLock(string $change): void
    {
        $inventory = $this->createInventory((int)$this->admin->id);
        $template = match ($change) {
            'rule' => $this->reloaded($this->createJobTemplate(
                (int)$this->createProject((int)$this->admin->id)->id,
                (int)$inventory->id,
                (int)$this->createRunnerGroup((int)$this->admin->id)->id,
                (int)$this->admin->id
            )),
            'inventory' => $this->withOwnRule($this->ungatedTemplate((int)$this->admin->id), $this->otherRule),
            default => $this->newTemplateOn((int)$this->createProject((int)$this->admin->id)->id, (int)$inventory->id, (int)$this->admin->id),
        };
        $storedInventoryId = $template->isNewRecord ? null : (int)$template->getOldAttribute('inventory_id');
        $template->setAttribute('approval_rule_id', (int)$this->otherRule->id);
        $template->setAttribute('inventory_id', (int)$inventory->id);
        $template->on(Model::EVENT_AFTER_VALIDATE, function () use ($inventory): void {
            $this->setRuleOfInventory((int)$inventory->id, $this->inventoryRule);
        });
        $audits = $this->templateAuditCount();

        $this->assertFalse($this->templateCredentials()->saveWithCredentials($template, null, [], (int)$this->admin->id));

        $this->assertSame([$this->rulesDiffer((string)$inventory->name, (string)$this->inventoryRule->name)], $template->getErrors('approval_rule_id'));
        $this->assertSame($audits, $this->templateAuditCount(), 'nothing is written');
        if ($change === 'new') {
            $this->assertFalse(JobTemplate::find()->where(['name' => $template->name])->exists());
        } else {
            $stored = $this->reloaded($template);
            $this->assertSame($change === 'rule' ? null : (int)$this->otherRule->id, $stored->approval_rule_id === null ? null : (int)$stored->approval_rule_id);
            $this->assertSame($storedInventoryId, (int)$stored->inventory_id);
            $this->assertSame($change === 'rule', $storedInventoryId === (int)$inventory->id, 'only the re-point changes the inventory');
        }
    }

    public function testAnAdminGivesATemplateTheRuleOfItsInventory(): void
    {
        $template = $this->inventoryGated((int)$this->admin->id, $this->inventoryRule);
        $template->setAttribute('approval_rule_id', (int)$this->inventoryRule->id);

        $this->assertTrue($this->templateCredentials()->saveWithCredentials($template, null, [], (int)$this->admin->id), (string)json_encode($template->errors));

        $this->assertSame((int)$this->inventoryRule->id, (int)$this->reloaded($template)->approval_rule_id);
    }

    // -- helpers ------------------------------------------------------------------------

    private function rulesDiffer(string $inventoryName, string $ruleName): string
    {
        return sprintf(JobTemplateRuleAssignment::RULES_DIFFER, SurveyDefinition::quote($inventoryName), SurveyDefinition::quote($ruleName));
    }

    /**
     * A template with the change $change of pairProvider() applied, unsaved.
     */
    private function templateFor(string $change): JobTemplate
    {
        $ownerId = (int)$this->admin->id;
        $gated = $this->inventoryGated($ownerId, $this->inventoryRule);
        switch ($change) {
            case 'new':
                $template = $this->newTemplateOn((int)$gated->project_id, (int)$gated->inventory_id, $ownerId);
                $template->setAttribute('approval_rule_id', (int)$this->otherRule->id);
                return $template;
            case 'rule':
                $gated->setAttribute('approval_rule_id', (int)$this->otherRule->id);
                return $gated;
            case 'inventory':
                $template = $this->withOwnRule($this->ungatedTemplate($ownerId), $this->otherRule);
                $template->setAttribute('inventory_id', (int)$gated->inventory_id);
                return $template;
            case 'rename':
                $template = $this->withOwnRule($gated, $this->otherRule);
                $template->setAttribute('name', 'renamed-' . uniqid('', true));
                return $template;
            case 'same':
                $gated->setAttribute('approval_rule_id', (string)$this->inventoryRule->id);
                return $gated;
            default:
                $template = $this->withOwnRule($gated, $this->otherRule);
                $template->setAttribute('approval_rule_id', null);
                return $template;
        }
    }
}
