<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\DbErrorSummary;
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
 * The persist veto of a job template save
 * (JobTemplateRuleAssignment::persistVetoes()) judges the pair of approval
 * rule and inventory that the save stores: a column the save writes as the
 * template holds it, the other one as the template row stores it under the
 * save's row lock, whoever changed it after the request loaded the template.
 * An inventory deleted after the template's validation is the validation
 * error "The selected inventory does not exist." on inventory_id, never the
 * foreign key's server error. Nothing is written when it vetoes.
 *
 * One database connection cannot run two transactions at once, so the other
 * admin's change and the delete of the inventory run where they land in
 * production: after the request loaded the template, or after its
 * validation, before the save's transaction locks the rows. That the pair is
 * read after the row lock is shown by the statements the save sends.
 */
class JobTemplateSavePairTest extends WebControllerTestCase
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
        $this->admin = $this->createUserWithRole('save-pair-admin', 'admin');
        $this->operator = $this->createUserWithRole('save-pair-operator', 'operator');
        $this->inventoryRule = $this->createApprovalRule((int)$this->admin->id);
        $this->otherRule = $this->createApprovalRule((int)$this->admin->id);
    }

    // -- The pair the save stores (LOCKS-2) ----------------------------------------------

    /**
     * Another admin's change of the column this save does not write,
     * committed after the request loaded the template.
     *
     * @return array<string, array{0: string}>
     */
    public static function otherColumnProvider(): array
    {
        return [
            'an own rule given after the load, then a re-point' => ['rule-then-inventory'],
            'a re-point after the load, then an own rule' => ['inventory-then-rule'],
        ];
    }

    /**
     * Regression (2.12.0 review, LOCKS-2): the save's transaction compared
     * the template's own rule with its inventory's rule as the request had
     * loaded them, not as the save stores them. The save writes only the
     * column it changes, so after another admin's change of the other column
     * the template stored two different rules, and every launch of it was
     * refused (JobLaunchService::RULES_DIFFER).
     *
     * @dataProvider otherColumnProvider
     */
    public function testRegressionAnotherAdminsChangeOfTheOtherColumnWasNotChecked(string $order): void
    {
        $adminId = (int)$this->admin->id;
        $template = $this->ungatedTemplate($adminId);
        $gated = $this->inventoryWithRule($adminId, $this->inventoryRule);
        $loaded = $this->reloaded($template);
        if ($order === 'rule-then-inventory') {
            $this->withOwnRule($template, $this->otherRule);
            $loaded->setAttribute('inventory_id', (int)$gated->id);
        } else {
            JobTemplate::updateAll(['inventory_id' => $gated->id], ['id' => $template->id]);
            $loaded->setAttribute('approval_rule_id', (int)$this->otherRule->id);
        }
        $stored = $this->pairOf($template);
        $audits = $this->templateAuditCount();

        $saved = $this->templateCredentials()->saveWithCredentials($loaded, null, [], $adminId);

        $this->assertFalse($saved, 'the save is refused');
        $this->assertSame($stored, $this->pairOf($template), 'nothing is written: the template keeps what the other admin stored');
        $this->assertSame($audits, $this->templateAuditCount(), 'no audit entry');
        $this->assertSame([$this->rulesDiffer($gated, $this->inventoryRule)], $loaded->getErrors('approval_rule_id'));
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function keptOwnRuleProvider(): array
    {
        return [
            'to an inventory with the same rule' => [true],
            'to an inventory without a rule' => [false],
        ];
    }

    /**
     * The pair the save stores decides, not the pair the request loaded: the
     * own rule another admin gave the template after the load stays, and a
     * re-point to an inventory with the same rule, or with none, is saved.
     *
     * @dataProvider keptOwnRuleProvider
     */
    public function testAnOwnRuleGivenAfterTheLoadStaysWhenTheRepointMatchesIt(bool $sameRule): void
    {
        $adminId = (int)$this->admin->id;
        $template = $this->ungatedTemplate($adminId);
        $target = $this->inventoryWithRule($adminId, $sameRule ? $this->inventoryRule : null);
        $loaded = $this->reloaded($template);
        $this->withOwnRule($template, $this->inventoryRule);
        $loaded->setAttribute('inventory_id', (int)$target->id);

        $this->assertTrue($this->templateCredentials()->saveWithCredentials($loaded, null, [], $adminId), (string)json_encode($loaded->errors));

        $this->assertSame([(int)$this->inventoryRule->id, (int)$target->id], $this->pairOf($template));
    }

    /**
     * A column the save writes with the value the row stores ("5" for 5, as
     * the form posts it) changes nothing: a template whose stored pair
     * already differs (from before, or changed in the database) keeps its
     * name open through the whole save.
     */
    public function testAColumnWrittenWithItsStoredValueIsNoChange(): void
    {
        $adminId = (int)$this->admin->id;
        $template = $this->ungatedTemplate($adminId);
        $gated = $this->inventoryWithRule($adminId, $this->inventoryRule);
        JobTemplate::updateAll(['approval_rule_id' => $this->otherRule->id, 'inventory_id' => $gated->id], ['id' => $template->id]);
        $loaded = $this->reloaded($template);
        $loaded->setAttribute('name', 'renamed-' . uniqid('', true));
        $loaded->setAttribute('inventory_id', (string)$gated->id);
        $loaded->setAttribute('approval_rule_id', (string)$this->otherRule->id);

        $this->assertTrue($this->templateCredentials()->saveWithCredentials($loaded, null, [], $adminId), (string)json_encode($loaded->errors));

        $this->assertSame($loaded->name, $this->reloaded($template)->name);
        $this->assertSame([(int)$this->otherRule->id, (int)$gated->id], $this->pairOf($template));
    }

    /**
     * A template whose row is gone has no pair the save could change: its
     * UPDATE writes nothing, and the persist veto adds no error.
     */
    public function testATemplateWhoseRowIsGoneIsNotChecked(): void
    {
        $adminId = (int)$this->admin->id;
        $template = $this->ungatedTemplate($adminId);
        $template->setAttribute('inventory_id', (int)$this->inventoryWithRule($adminId, $this->inventoryRule)->id);
        $template->setAttribute('approval_rule_id', (int)$this->otherRule->id);
        JobTemplate::deleteAll(['id' => $template->id]);

        $this->assertFalse(JobTemplateRuleAssignment::persistVetoes(new GatedJobTemplateLock(), $template, null, $adminId));

        $this->assertSame([], $template->getErrors());
    }

    /**
     * The pair the row stores is read without a lock, after the row lock:
     * the save's first statement locks the row FOR UPDATE, so no other save
     * changes it before the commit, and the read sees the row as locked. The
     * save locks the template row once, and the persist veto reads only the
     * inventory row the template lock share-locked already.
     */
    public function testThePairIsReadAfterTheRowLockWithoutANewLock(): void
    {
        $adminId = (int)$this->admin->id;
        $template = $this->withOwnRule($this->ungatedTemplate($adminId), $this->inventoryRule);
        $storedInventoryId = (int)$template->inventory_id;
        $target = $this->inventoryWithRule($adminId, $this->inventoryRule);
        $template->setAttribute('inventory_id', (string)$target->id);
        $saved = false;

        $statements = $this->statementsOf(function () use ($template, $adminId, &$saved): void {
            $saved = $this->templateCredentials()->saveWithCredentials($template, null, [], $adminId);
        });

        $this->assertTrue($saved, (string)json_encode($template->errors));
        $id = (int)$template->id;
        $savepoint = $this->statementIndex($statements, '~^SAVEPOINT LEVEL\d+$~');
        $row = $this->statementIndex($statements, '~^SELECT \* FROM `job_template` WHERE `id` = ' . $id . ' FOR UPDATE$~');
        $pair = $this->statementIndex($statements, '~^SELECT `approval_rule_id`, `inventory_id` FROM `job_template` WHERE `id` = ' . $id . '$~');
        $update = $this->statementIndex($statements, '~^UPDATE `job_template` SET .* WHERE `id`=' . $id . '$~s');
        $listing = implode("\n", $statements);
        $this->assertSame($savepoint + 1, $row, $listing);
        $this->assertGreaterThan($row + 1, $pair, "after the row lock and the inventories' share lock:\n" . $listing);
        $this->assertLessThan($update, $pair, $listing);
        $this->assertCount(1, preg_grep('~`job_template`.*(FOR UPDATE|LOCK IN SHARE MODE)$~', $statements), "one lock of the template row:\n" . $listing);
        $locks = $this->inventoryLocks($statements);
        $ids = [$storedInventoryId, (int)$target->id];
        sort($ids);
        $this->assertSame($ids, $locks[$row + 1] ?? null, $listing);
        $this->assertSame([$row + 1, $pair + 1], array_keys($locks), "the persist veto reads the new inventory again, right after the pair:\n" . $listing);
        $this->assertSame([(int)$target->id], $locks[$pair + 1]);
    }

    // -- An inventory deleted while the save runs (LOCKS-5) --------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function deletedInventoryProvider(): array
    {
        return [
            'an admin re-points a template' => ['repoint', 'admin'],
            'an operator re-points a template' => ['repoint', 'operator'],
            'an admin creates a template' => ['create', 'admin'],
            'an operator creates a template' => ['create', 'operator'],
        ];
    }

    /**
     * Regression (2.12.0 review, LOCKS-5): an inventory deleted after the
     * template's validation (here: right after it), before the save's
     * transaction share-locked it, failed the save on the foreign key, an
     * IntegrityException (SQLSTATE 23000, driver error 1452) and so HTTP
     * 500, although the share-locked read had found no row for it. Now the
     * save is refused with the validation error, and nothing is written.
     *
     * @dataProvider deletedInventoryProvider
     */
    public function testRegressionAnInventoryDeletedWhileTheSaveRanFailedOnTheForeignKey(string $change, string $role): void
    {
        $adminId = (int)$this->admin->id;
        $target = $this->createInventory($adminId);
        $template = $change === 'create'
            ? $this->newTemplateOn((int)$this->createProject($adminId)->id, (int)$target->id, $adminId)
            : $this->ungatedTemplate($adminId);
        $storedInventoryId = $template->isNewRecord ? null : (int)$template->inventory_id;
        $template->setAttribute('inventory_id', (int)$target->id);
        $template->on(Model::EVENT_AFTER_VALIDATE, static function () use ($target): void {
            Inventory::deleteAll(['id' => $target->id]);
        });
        $audits = $this->templateAuditCount();
        $notFound = static fn (): string => JobTemplateRuleAssignment::INVENTORY_NOT_FOUND;

        [$saved, $error] = $this->saveCatchingDbErrors($template, (int)($role === 'admin' ? $this->admin->id : $this->operator->id));

        $this->assertNull($error, 'no database error: the foreign key never refuses the save');
        $this->assertFalse($saved, 'the save is refused');
        $this->assertFalse(Inventory::find()->where(['id' => $target->id])->exists(), 'precondition: the inventory is gone');
        if ($storedInventoryId === null) {
            $this->assertFalse(JobTemplate::find()->where(['name' => $template->name])->exists(), 'nothing is written');
        } else {
            $this->assertSame($storedInventoryId, (int)$this->reloaded($template)->inventory_id, 'nothing is written');
        }
        $this->assertSame($audits, $this->templateAuditCount(), 'no audit entry');
        $this->assertSame(['inventory_id' => [$notFound()]], $template->getErrors());
    }

    /**
     * The check needs no inventory read when the save stores the inventory
     * it has and no rule of its own: removing the own rule reads nothing.
     */
    public function testRemovingTheOwnRuleReadsNoInventory(): void
    {
        $adminId = (int)$this->admin->id;
        $template = $this->withOwnRule($this->inventoryGated($adminId, $this->inventoryRule), $this->inventoryRule);
        $template->setAttribute('approval_rule_id', null);
        $vetoed = true;

        $statements = $this->statementsOf(function () use ($template, $adminId, &$vetoed): void {
            $vetoed = JobTemplateRuleAssignment::persistVetoes(new GatedJobTemplateLock(), $template, null, $adminId);
        });

        $this->assertFalse($vetoed);
        $locks = $this->inventoryLocks($statements);
        $this->assertCount(1, $locks, "only the template lock's statement:\n" . implode("\n", $statements));
    }

    // -- helpers ------------------------------------------------------------------------

    /**
     * The own rule and the inventory template #id stores.
     *
     * @return array{0: int|null, 1: int}
     */
    private function pairOf(JobTemplate $template): array
    {
        $stored = $this->reloaded($template);

        return [$stored->approval_rule_id === null ? null : (int)$stored->approval_rule_id, (int)$stored->inventory_id];
    }

    private function rulesDiffer(Inventory $inventory, ApprovalRule $rule): string
    {
        return sprintf(JobTemplateRuleAssignment::RULES_DIFFER, SurveyDefinition::quote((string)$inventory->name), SurveyDefinition::quote((string)$rule->name));
    }

    /**
     * Saves $template as user #$actorId, as the web form and the API do: a
     * new template with no additional credentials, a stored one keeping its.
     *
     * @return array{0: bool|null, 1: string|null} whether the save went
     *     through (null when it threw), and the database error it threw, as
     *     its class, SQLSTATE and driver error code (DbErrorSummary)
     */
    private function saveCatchingDbErrors(JobTemplate $template, int $actorId): array
    {
        try {
            return [$this->templateCredentials()->saveWithCredentials($template, $template->isNewRecord ? [] : null, [], $actorId), null];
        } catch (\yii\db\Exception $e) {
            return [null, implode(' ', DbErrorSummary::of($e))];
        }
    }
}
