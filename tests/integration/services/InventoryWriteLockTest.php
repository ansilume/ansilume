<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\DbErrorSummary;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\Project;
use app\services\GatedInventoryLock;
use app\services\InventoryRuleAssignment;
use app\services\InventoryWriteRefusedException;
use app\services\InventoryWriteService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\InvalidArgumentException;
use yii\base\Model;
use yii\db\BaseActiveRecord;
use yii\db\Exception as DbException;
use yii\db\IntegrityException;
use yii\log\Logger;

/**
 * The inventory write path under its row lock (C16): an update and a delete
 * lock the inventory's row as the first statement of their transaction, read
 * job templates without a lock, check what was committed meanwhile on the
 * locked row, and make an attempt once more that a lock conflict ended, or
 * the foreign key's refusal of an approval rule or a project deleted
 * meanwhile (driver error 1452).
 */
class InventoryWriteLockTest extends DbTestCase
{
    use InventoryWriteFixtures;
    use TeamScopeFixtures;

    private const SAVEPOINT = '~^SAVEPOINT LEVEL\d+$~';
    private const RELEASE = '~^RELEASE SAVEPOINT LEVEL\d+$~';

    /** The errorInfo of the foreign key's refusal of a row that another transaction deleted. */
    private const REFERENCE_DELETED = ['23000', 1452, 'Cannot add or update a child row: a foreign key constraint fails'];

    private InventoryWriteService $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = new InventoryWriteService();
    }

    protected function tearDown(): void
    {
        $this->detachInventoryHandlers();
        parent::tearDown();
    }

    // -- statement order ---------------------------------------------------------

    /**
     * The row lock opens the update's transaction: nothing is read in it
     * before. Job templates are read for the validation (another project,
     * another rule) without a lock, and nothing else is locked.
     */
    public function testAnUpdateLocksTheInventoryRowFirstAndReadsJobTemplatesWithoutALock(): void
    {
        $admin = $this->inventoryAdmin();
        $project = (int)$this->createProject((int)$admin->id)->id;
        $inventory = $this->dynamicInventory((int)$admin->id, $project);
        $this->createJobTemplate($project, (int)$inventory->id, (int)$this->createRunnerGroup((int)$admin->id)->id, (int)$admin->id);
        $inventory->inventory_type = Inventory::TYPE_FILE;
        $rule = $this->createApprovalRule((int)$admin->id);

        $statements = $this->statementsOf(fn () => $this->assertTrue(
            $this->writer->update($inventory, [InventoryRuleAssignment::FIELD => (int)$rule->id], (int)$admin->id, InventoryWriteService::SOURCE_WEB)
        ));

        $trace = implode("\n", $statements);
        $savepoint = $this->indexOf($statements, self::SAVEPOINT);
        $this->assertSame($this->lockStatementOf((int)$inventory->id), $statements[$savepoint + 1] ?? null, "the row lock opens the transaction:\n" . $trace);
        $update = $this->indexOf($statements, '~^UPDATE `inventory` SET~');
        $release = $this->indexOf($statements, self::RELEASE);
        $audit = $this->indexOf($statements, '~^INSERT INTO `audit_log`~');
        $this->assertGreaterThanOrEqual(2, count(preg_grep('~FROM `job_template`~', array_slice($statements, $savepoint, $update - $savepoint)) ?: []), "both job template reads run under the lock:\n" . $trace);
        $this->assertLessThan($release, $update);
        $this->assertLessThan($audit, $release, "the audit follows the commit:\n" . $trace);
        $this->assertSame([$this->lockStatementOf((int)$inventory->id)], $this->lockingReadsOf($statements), "the row lock is the only locking read:\n" . $trace);
        $this->assertSame(Inventory::TYPE_FILE, $this->reloaded((int)$inventory->id)->inventory_type);
    }

    public function testADeleteLocksTheInventoryRowFirstAndReadsItsUsageWithoutALock(): void
    {
        $admin = $this->inventoryAdmin();
        $inventory = $this->staticInventory((int)$admin->id);

        $statements = $this->statementsOf(fn () => $this->writer->delete($inventory, (int)$admin->id, InventoryWriteService::SOURCE_API));

        $trace = implode("\n", $statements);
        $savepoint = $this->indexOf($statements, self::SAVEPOINT);
        $this->assertSame($this->lockStatementOf((int)$inventory->id), $statements[$savepoint + 1] ?? null, "the row lock opens the transaction:\n" . $trace);
        $usage = $this->indexOf($statements, '~FROM `job_template`~');
        $delete = $this->indexOf($statements, '~^DELETE FROM `inventory`~');
        $this->assertLessThan($usage, $savepoint + 1);
        $this->assertLessThan($delete, $usage);
        $this->assertLessThan($this->indexOf($statements, self::RELEASE), $delete);
        $this->assertSame([$this->lockStatementOf((int)$inventory->id)], $this->lockingReadsOf($statements), "the row lock is the only locking read:\n" . $trace);
    }

    /**
     * The approval rule is refused before anything is read under a lock.
     */
    public function testARefusedRuleChangeLocksNothing(): void
    {
        $operator = $this->inventoryOperator();
        $inventory = $this->staticInventory((int)$operator->id);
        $rule = $this->createApprovalRule((int)$operator->id);

        $statements = $this->statementsOf(fn () => $this->refusalOf(
            fn () => $this->writer->update($inventory, [InventoryRuleAssignment::FIELD => (int)$rule->id], (int)$operator->id, InventoryWriteService::SOURCE_WEB)
        ));

        $this->assertSame([], $this->lockingReadsOf($statements));
        $this->assertSame([], preg_grep(self::SAVEPOINT, $statements));
    }

    // -- changes made meanwhile --------------------------------------------------

    /**
     * A rule an admin gave the inventory after the user loaded it locks its
     * hosts: the lock reads the locked row, not the loaded one.
     */
    public function testARuleGivenMeanwhileLocksTheHosts(): void
    {
        $operator = $this->inventoryOperator();
        $inventory = $this->staticInventory((int)$operator->id);
        $stored = $this->storedRow((int)$inventory->id);
        $rule = $this->createApprovalRule((int)$operator->id);
        $this->setStoredRule((int)$inventory->id, (int)$rule->id);
        $inventory->content = "all:\n  hosts:\n    db1.example.com:\n";

        $refusal = $this->refusalOf(fn () => $this->writer->update($inventory, [], (int)$operator->id, InventoryWriteService::SOURCE_WEB));

        $this->assertSame(GatedInventoryLock::CHANGE_REFUSED, $refusal->getMessage());
        $after = $this->storedRow((int)$inventory->id);
        $this->assertSame((int)$rule->id, (int)$after['approval_rule_id'], 'the rule given meanwhile stays');
        unset($after['approval_rule_id'], $stored['approval_rule_id']);
        $this->assertSame($stored, $after, 'nothing else changed');
    }

    /**
     * The rule the request did not send stays: a rename keeps a rule given
     * meanwhile.
     */
    public function testARenameKeepsARuleGivenMeanwhile(): void
    {
        $operator = $this->inventoryOperator();
        $inventory = $this->staticInventory((int)$operator->id);
        $rule = $this->createApprovalRule((int)$operator->id);
        $this->setStoredRule((int)$inventory->id, (int)$rule->id);
        $inventory->name = 'inv-lock-renamed';

        $this->assertTrue($this->writer->update($inventory, [InventoryRuleAssignment::FIELD => null], (int)$operator->id, InventoryWriteService::SOURCE_WEB));

        $stored = $this->reloaded((int)$inventory->id);
        $this->assertSame('inv-lock-renamed', $stored->name);
        $this->assertSame((int)$rule->id, $stored->getAttribute('approval_rule_id'));
        $this->assertArrayNotHasKey('approval_rule', $this->inventoryAudits(AuditLog::ACTION_INVENTORY_UPDATED, (int)$inventory->id)[0] ?? []);
    }

    public function testARuleRemovedMeanwhileUnlocksTheHosts(): void
    {
        $operator = $this->inventoryOperator();
        $inventory = $this->gate($this->staticInventory((int)$operator->id), $this->createApprovalRule((int)$operator->id));
        $this->setStoredRule((int)$inventory->id, null);
        $inventory->content = "all:\n  hosts:\n    db1.example.com:\n";

        $this->assertTrue($this->writer->update($inventory, [], (int)$operator->id, InventoryWriteService::SOURCE_WEB));

        $this->assertSame("all:\n  hosts:\n    db1.example.com:\n", $this->reloaded((int)$inventory->id)->content);
    }

    /**
     * Content an admin changed after the user loaded the inventory stays
     * when the user only renames it.
     */
    public function testContentChangedMeanwhileIsKeptByARename(): void
    {
        $operator = $this->inventoryOperator();
        $inventory = $this->staticInventory((int)$operator->id);
        Inventory::updateAll(['content' => "all:\n  hosts:\n    meanwhile.example.com:\n"], ['id' => $inventory->id]);
        $inventory->name = 'inv-lock-renamed-content';

        $this->assertTrue($this->writer->update($inventory, [], (int)$operator->id, InventoryWriteService::SOURCE_WEB));

        $stored = $this->reloaded((int)$inventory->id);
        $this->assertSame('inv-lock-renamed-content', $stored->name);
        $this->assertSame("all:\n  hosts:\n    meanwhile.example.com:\n", $stored->content);
        $this->assertSame(['name'], $this->inventoryAudits(AuditLog::ACTION_INVENTORY_UPDATED, (int)$inventory->id)[0]['changed_fields'] ?? null);
    }

    /**
     * A job template on the inventory got another rule of its own after the
     * admin loaded the inventory: the rule is refused (RULES_DIFFER).
     */
    public function testAJobTemplatesRuleGivenMeanwhileRefusesTheInventorysRule(): void
    {
        $admin = $this->inventoryAdmin();
        $inventory = $this->staticInventory((int)$admin->id);
        $template = $this->templateOn((int)$inventory->id, (int)$admin->id, null, 'inv-lock-template');
        $stored = $this->storedRow((int)$inventory->id);
        JobTemplate::updateAll(['approval_rule_id' => (int)$this->createApprovalRule((int)$admin->id)->id], ['id' => $template->id]);
        $rule = $this->createApprovalRule((int)$admin->id);

        $saved = $this->writer->update($inventory, [InventoryRuleAssignment::FIELD => (int)$rule->id], (int)$admin->id, InventoryWriteService::SOURCE_API);

        $this->assertFalse($saved);
        $this->assertSame([sprintf(InventoryRuleAssignment::RULES_DIFFER, '"inv-lock-template"')], $inventory->getErrors(InventoryRuleAssignment::FIELD));
        $this->assertSame($stored, $this->storedRow((int)$inventory->id));
        $this->assertFalse($this->hasInventoryAudit((int)$inventory->id));
    }

    /**
     * A dynamic inventory whose source path was cleared after the admin
     * loaded it gets no rule: its jobs would use hosts no rule gates.
     */
    public function testASourcePathClearedMeanwhileRefusesTheRule(): void
    {
        $admin = $this->inventoryAdmin();
        $inventory = $this->dynamicInventory((int)$admin->id, null);
        Inventory::updateAll(['source_path' => null], ['id' => $inventory->id]);
        $stored = $this->storedRow((int)$inventory->id);
        $rule = $this->createApprovalRule((int)$admin->id);

        $saved = $this->writer->update($inventory, [InventoryRuleAssignment::FIELD => (int)$rule->id], (int)$admin->id, InventoryWriteService::SOURCE_WEB);

        $this->assertFalse($saved);
        $this->assertSame([Inventory::SOURCE_PATH_REQUIRED_WHEN_GATED], $inventory->getErrors('source_path'));
        $this->assertSame($stored, $this->storedRow((int)$inventory->id));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function writeProvider(): array
    {
        return ['an update' => ['update'], 'a delete' => ['delete']];
    }

    /**
     * @dataProvider writeProvider
     */
    public function testAnInventoryDeletedMeanwhileIsNotFound(string $write): void
    {
        $admin = $this->inventoryAdmin();
        $inventory = $this->staticInventory((int)$admin->id);
        $this->assertSame(1, Inventory::deleteAll(['id' => $inventory->id]));

        $refusal = $this->refusalOf(fn () => $this->write($write, $inventory, (int)$admin->id));

        $this->assertSame(InventoryWriteRefusedException::NOT_FOUND, $refusal->status);
        $this->assertSame(sprintf('Inventory #%d not found.', (int)$inventory->id), $refusal->getMessage());
        $this->assertNull(Inventory::findOne($inventory->id));
        $this->assertFalse($this->hasInventoryAudit((int)$inventory->id));
    }

    /**
     * The inventory moved meanwhile into a project the user may not operate:
     * refused under the lock (TEAM_SCOPE_REFUSED).
     *
     * @dataProvider writeProvider
     */
    public function testAnInventoryMovedMeanwhileOutOfTheUsersReachIsRefused(string $write): void
    {
        $scope = $this->teamScope();
        $member = (int)$scope['member']->id;
        $inventory = $this->staticInventory((int)$scope['admin']->id, (int)$scope['own']->project_id);
        Inventory::updateAll(['project_id' => (int)$scope['foreign']->project_id], ['id' => $inventory->id]);
        $stored = $this->storedRow((int)$inventory->id);
        $inventory->name = 'inv-lock-moved';

        $refusal = $this->refusalOf(fn () => $this->write($write, $inventory, $member));

        $this->assertSame(InventoryWriteRefusedException::FORBIDDEN, $refusal->status);
        $this->assertSame(InventoryWriteService::TEAM_SCOPE_REFUSED, $refusal->getMessage());
        $this->assertSame($stored, $this->storedRow((int)$inventory->id));
        $this->assertFalse($this->hasInventoryAudit((int)$inventory->id));
    }

    /**
     * @return array<string, array{0: string, 1: bool}> the project the inventory moves to, and whether the member
     *     may move it there
     */
    public static function targetProjectProvider(): array
    {
        return [
            'a project of another team' => ['foreign', false],
            'a project the team only views' => ['viewed', false],
            'a project the team operates' => ['own', true],
            'a project of no team' => ['open', true],
            'no project' => ['none', true],
        ];
    }

    /**
     * The project it moves to, too, as the model's integer rule reads it.
     *
     * @dataProvider targetProjectProvider
     */
    public function testTheProjectItMovesToIsCheckedUnderTheLock(string $target, bool $allowed): void
    {
        $scope = $this->teamScope();
        $member = (int)$scope['member']->id;
        $inventory = $this->staticInventory((int)$scope['admin']->id, (int)$scope['own']->project_id);
        $inventory->project_id = $target === 'none' ? '' : '0' . $scope[$target]->project_id;

        if (!$allowed) {
            $refusal = $this->refusalOf(fn () => $this->writer->update($inventory, [], $member, InventoryWriteService::SOURCE_WEB));
            $this->assertSame(InventoryWriteService::TEAM_SCOPE_REFUSED, $refusal->getMessage());
            $this->assertSame((int)$scope['own']->project_id, $this->reloaded((int)$inventory->id)->project_id);
            return;
        }
        $this->assertTrue($this->writer->update($inventory, [], $member, InventoryWriteService::SOURCE_WEB));
        $this->assertSame($target === 'none' ? null : (int)$scope[$target]->project_id, $this->reloaded((int)$inventory->id)->project_id);
    }

    public function testARuleGivenMeanwhileRefusesTheDelete(): void
    {
        $deleter = $this->inventoryDeleter();
        $inventory = $this->staticInventory((int)$deleter->id);
        $this->setStoredRule((int)$inventory->id, (int)$this->createApprovalRule((int)$deleter->id)->id);

        $refusal = $this->refusalOf(fn () => $this->writer->delete($inventory, (int)$deleter->id, InventoryWriteService::SOURCE_WEB));

        $this->assertSame(GatedInventoryLock::DELETE_REFUSED, $refusal->getMessage());
        $this->assertNotNull(Inventory::findOne($inventory->id));
    }

    /**
     * A model whose schema has no approval_rule_id (a schema cached from
     * before m000088) cannot take the locked row: the update fails without
     * writing, instead of passing over the rule it cannot see.
     */
    public function testAModelThatCannotSeeTheRuleColumnWritesNothing(): void
    {
        $operator = $this->inventoryOperator();
        $inventory = $this->gate($this->staticInventory((int)$operator->id), $this->createApprovalRule((int)$operator->id));
        $stored = $this->storedRow((int)$inventory->id);
        $legacy = new class () extends Inventory {
            public function attributes(): array
            {
                return array_values(array_diff(parent::attributes(), ['approval_rule_id']));
            }
        };
        $stale = $legacy::findOne($inventory->id);
        $this->assertInstanceOf(Inventory::class, $stale);
        $stale->content = "all:\n  hosts:\n    db1.example.com:\n";

        try {
            $this->writer->update($stale, [], (int)$operator->id, InventoryWriteService::SOURCE_WEB);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('has no attribute named "approval_rule_id"', $e->getMessage());
        }

        $this->assertSame($stored, $this->storedRow((int)$inventory->id));
        $this->assertFalse($this->hasInventoryAudit((int)$inventory->id));
    }

    // -- lock conflicts ----------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: array{0: string, 1: int, 2: string}}>
     */
    public static function lockConflictProvider(): array
    {
        return [
            'a deadlock' => ['update', ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction']],
            'a lock wait timeout' => ['update', ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction']],
            'a deadlock of a create' => ['create', ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction']],
            'a deadlock after the insert' => ['create-after', ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction']],
            'a deadlock of a delete' => ['delete', ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction']],
        ];
    }

    /**
     * A lock conflict in the first attempt is rolled back, and the second
     * attempt writes once, with one audit entry.
     *
     * @dataProvider lockConflictProvider
     * @param array{0: string, 1: int, 2: string} $errorInfo
     */
    public function testALockConflictInTheFirstAttemptIsRetried(string $write, array $errorInfo): void
    {
        $admin = $this->inventoryAdmin();
        [$inventory, $action] = $this->prepareWrite($write, (int)$admin->id);
        $attempts = $this->conflictOnce($write, $errorInfo);

        $statements = $this->statementsOf($action);

        $this->assertSame(2, $attempts->count, 'two attempts');
        $id = (int)$inventory->id;
        if ($write === 'delete') {
            $this->assertNull(Inventory::findOne($id));
            $this->assertCount(1, $this->inventoryAudits(AuditLog::ACTION_INVENTORY_DELETED, $id));
            $this->assertSame(2, count(preg_grep('~^' . preg_quote($this->lockStatementOf($id), '~') . '$~', $statements) ?: []));
            return;
        }
        $this->assertSame('inv-lock-retried', $this->reloaded($id)->name);
        if ($write === 'update') {
            $this->assertCount(1, $this->inventoryAudits(AuditLog::ACTION_INVENTORY_UPDATED, $id));
            $this->assertSame(2, count(preg_grep('~^' . preg_quote($this->lockStatementOf($id), '~') . '$~', $statements) ?: []), 'the row lock again');
            return;
        }
        $this->assertSame(1, (int)Inventory::find()->where(['name' => 'inv-lock-retried'])->count(), 'one row');
        $this->assertCount(1, $this->inventoryAudits(AuditLog::ACTION_INVENTORY_CREATED, $id));
    }

    /**
     * A lock conflict in both attempts is thrown: nothing is written.
     *
     * @dataProvider writeProvider
     */
    public function testALockConflictInBothAttemptsIsThrown(string $write): void
    {
        $admin = $this->inventoryAdmin();
        $inventory = $this->staticInventory((int)$admin->id);
        $stored = $this->storedRow((int)$inventory->id);
        $inventory->name = 'inv-lock-never';
        $attempts = $this->conflictAlways($write === 'delete' ? BaseActiveRecord::EVENT_BEFORE_DELETE : BaseActiveRecord::EVENT_BEFORE_UPDATE, ['40001', 1213, 'Deadlock found']);

        try {
            $this->write($write, $inventory, (int)$admin->id);
            $this->fail('Expected the lock conflict.');
        } catch (DbException $e) {
            $this->assertSame(['40001', 1213, 'Deadlock found'], $e->errorInfo);
        }

        $this->assertSame(2, $attempts->count);
        $this->assertSame($stored, $this->storedRow((int)$inventory->id));
        $this->assertFalse($this->hasInventoryAudit((int)$inventory->id));
    }

    /**
     * Any other database error is thrown at once, without a second attempt.
     */
    public function testAnyOtherDatabaseErrorIsNotRetried(): void
    {
        $admin = $this->inventoryAdmin();
        $inventory = $this->staticInventory((int)$admin->id);
        $stored = $this->storedRow((int)$inventory->id);
        $inventory->name = 'inv-lock-failed';
        $attempts = $this->conflictAlways(BaseActiveRecord::EVENT_BEFORE_UPDATE, ['HY000', 1105, 'Unknown error']);

        try {
            $this->writer->update($inventory, [], (int)$admin->id, InventoryWriteService::SOURCE_WEB);
            $this->fail('Expected the database error.');
        } catch (DbException $e) {
            $this->assertSame(['HY000', 1105, 'Unknown error'], $e->errorInfo);
        }

        $this->assertSame(1, $attempts->count);
        $this->assertSame($stored, $this->storedRow((int)$inventory->id));
    }

    // -- a row the save names, deleted meanwhile -----------------------------------

    /**
     * @return array<string, array{0: string, 1: string}> the write, and the row it names that is deleted meanwhile
     */
    public static function deletedReferenceProvider(): array
    {
        return [
            'an update gives the inventory a rule' => ['update', 'rule'],
            'an update moves the inventory into a project' => ['update', 'project'],
            'a create with a rule' => ['create', 'rule'],
            'a create in a project' => ['create', 'project'],
        ];
    }

    /**
     * Regression (2.12.0 review, LOCKS-1): an approval rule or a project
     * deleted while an inventory save named it ended the save in the foreign
     * key's refusal, an IntegrityException with driver error 1452, and so in
     * a server error. The delete holds the row's lock until it commits, so
     * the save meets no lock conflict, and only lock conflicts were made once
     * more. Now that refusal makes the attempt once more too: its validation
     * names the row that is gone (422, or the form with the error), and
     * nothing is written.
     *
     * The first attempt's statement meets the refusal (thrown from its model
     * event, as InventoryWriteRaceTest shows the database does); the row goes
     * before the second attempt validates.
     *
     * @dataProvider deletedReferenceProvider
     */
    public function testRegressionARowDeletedMeanwhileEndedTheSaveInAForeignKeyError(string $write, string $reference): void
    {
        $admin = $this->inventoryAdmin();
        $rule = $this->createApprovalRule((int)$admin->id);
        $project = $this->createProject((int)$admin->id);
        $inventory = $write === 'create' ? $this->newStaticInventory('inv-lock-reference-gone') : $this->staticInventory((int)$admin->id);
        $stored = $write === 'create' ? null : $this->storedRow((int)$inventory->id);
        $submitted = [];
        if ($reference === 'rule') {
            $submitted[InventoryRuleAssignment::FIELD] = (int)$rule->id;
        } else {
            $inventory->project_id = (int)$project->id;
        }
        $statements = $this->refusedOnce($write, self::REFERENCE_DELETED);
        $validations = $this->deletedBeforeTheSecondValidation(static function () use ($reference, $rule, $project): void {
            $reference === 'rule' ? ApprovalRule::deleteAll(['id' => $rule->id]) : Project::deleteAll(['id' => $project->id]);
        });
        $created = $this->createdAudits();

        $saved = $this->savedOrDatabaseError(fn (): bool => $write === 'create'
            ? $this->writer->create($inventory, $submitted, (int)$admin->id, InventoryWriteService::SOURCE_API)
            : $this->writer->update($inventory, $submitted, (int)$admin->id, InventoryWriteService::SOURCE_API));

        $this->assertSame(false, $saved, 'not saved, with the validation error, not the foreign key\'s refusal');
        $this->assertSame([1, 2], [$statements->count, $validations->count], 'the second attempt validated, and wrote nothing');
        $this->assertSame(
            $reference === 'rule'
                ? [InventoryRuleAssignment::FIELD => [Inventory::APPROVAL_RULE_NOT_FOUND]]
                : ['project_id' => ['The selected project does not exist.']],
            $inventory->getErrors()
        );
        if ($stored === null) {
            $this->assertFalse(Inventory::find()->where(['name' => 'inv-lock-reference-gone'])->exists(), 'no inventory');
            $this->assertSame($created, $this->createdAudits(), 'no audit entry');
            return;
        }
        $this->assertSame($stored, $this->storedRow((int)$inventory->id), 'nothing written');
        $this->assertFalse($this->hasInventoryAudit((int)$inventory->id));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function saveProvider(): array
    {
        return ['an update' => ['update'], 'a create' => ['create']];
    }

    /**
     * The foreign key's refusal in both attempts is thrown, as a second lock
     * conflict is: nothing is written.
     *
     * @dataProvider saveProvider
     */
    public function testAForeignKeyRefusalInBothAttemptsIsThrown(string $write): void
    {
        $admin = $this->inventoryAdmin();
        $inventory = $write === 'update' ? $this->staticInventory((int)$admin->id) : $this->newStaticInventory('inv-lock-refused-twice');
        $stored = $write === 'update' ? $this->storedRow((int)$inventory->id) : null;
        $inventory->name = 'inv-lock-refused-twice';
        $attempts = $this->refusedAlways($write === 'update' ? BaseActiveRecord::EVENT_BEFORE_UPDATE : BaseActiveRecord::EVENT_BEFORE_INSERT, self::REFERENCE_DELETED);

        $saved = $this->savedOrDatabaseError(fn (): bool => $write === 'update'
            ? $this->writer->update($inventory, [], (int)$admin->id, InventoryWriteService::SOURCE_WEB)
            : $this->writer->create($inventory, [], (int)$admin->id, InventoryWriteService::SOURCE_WEB));

        $this->assertSame([IntegrityException::class, '23000', '1452'], $saved, 'the second refusal is thrown');
        $this->assertSame(2, $attempts->count);
        if ($stored !== null) {
            $this->assertSame($stored, $this->storedRow((int)$inventory->id));
            $this->assertFalse($this->hasInventoryAudit((int)$inventory->id));
            return;
        }
        $this->assertFalse(Inventory::find()->where(['name' => 'inv-lock-refused-twice'])->exists());
    }

    /**
     * @return array<string, array{0: class-string<DbException>, 1: array{0: string, 1: int, 2: string}}>
     */
    public static function otherIntegrityErrorProvider(): array
    {
        return [
            'a duplicate key' => [IntegrityException::class, ['23000', 1062, 'Duplicate entry']],
            'a parent row still referenced' => [IntegrityException::class, ['23000', 1451, 'Cannot delete or update a parent row']],
            'error 1452 that is no integrity error' => [DbException::class, ['23000', 1452, 'Cannot add or update a child row']],
        ];
    }

    /**
     * Only the refusal of a row that is gone (an IntegrityException with
     * driver error 1452) is made once more: any other integrity error is
     * thrown at once.
     *
     * @dataProvider otherIntegrityErrorProvider
     * @param class-string<DbException> $class
     * @param array{0: string, 1: int, 2: string} $errorInfo
     */
    public function testAnyOtherIntegrityErrorIsNotRetried(string $class, array $errorInfo): void
    {
        $admin = $this->inventoryAdmin();
        $inventory = $this->staticInventory((int)$admin->id);
        $stored = $this->storedRow((int)$inventory->id);
        $inventory->name = 'inv-lock-integrity';
        $attempts = new \stdClass();
        $attempts->count = 0;
        $this->on(Inventory::class, BaseActiveRecord::EVENT_BEFORE_UPDATE, static function () use ($attempts, $class, $errorInfo): void {
            $attempts->count++;
            throw new $class('Refused', $errorInfo, $errorInfo[0]);
        });

        $saved = $this->savedOrDatabaseError(fn (): bool => $this->writer->update($inventory, [], (int)$admin->id, InventoryWriteService::SOURCE_WEB));

        $this->assertSame([$class, $errorInfo[0], (string)$errorInfo[1]], $saved);
        $this->assertSame(1, $attempts->count, 'one attempt');
        $this->assertSame($stored, $this->storedRow((int)$inventory->id));
    }

    // -- helpers -----------------------------------------------------------------

    /**
     * A new static inventory named $name, with a YAML mapping, not saved.
     */
    private function newStaticInventory(string $name): Inventory
    {
        $inventory = new Inventory();
        $inventory->name = $name;
        $inventory->inventory_type = Inventory::TYPE_STATIC;
        $inventory->content = "all:\n  hosts:\n    web1.example.com:\n";

        return $inventory;
    }

    /**
     * Throws an IntegrityException with $errorInfo from the first attempt's
     * statement of $write (its model event: before the insert of a create,
     * before the update otherwise); counts the statements.
     *
     * @param array{0: string, 1: int, 2: string} $errorInfo
     */
    private function refusedOnce(string $write, array $errorInfo): \stdClass
    {
        $statements = new \stdClass();
        $statements->count = 0;
        $event = $write === 'create' ? BaseActiveRecord::EVENT_BEFORE_INSERT : BaseActiveRecord::EVENT_BEFORE_UPDATE;
        $this->on(Inventory::class, $event, static function () use ($statements, $errorInfo): void {
            $statements->count++;
            if ($statements->count === 1) {
                throw new IntegrityException('Integrity constraint violation', $errorInfo, $errorInfo[0]);
            }
        });

        return $statements;
    }

    /**
     * @param array{0: string, 1: int, 2: string} $errorInfo
     */
    private function refusedAlways(string $event, array $errorInfo): \stdClass
    {
        $attempts = new \stdClass();
        $attempts->count = 0;
        $this->on(Inventory::class, $event, static function () use ($attempts, $errorInfo): void {
            $attempts->count++;
            throw new IntegrityException('Integrity constraint violation', $errorInfo, $errorInfo[0]);
        });

        return $attempts;
    }

    /**
     * Runs $delete when an inventory validates for the second time, before
     * its rules run: another transaction deleted the row meanwhile, and the
     * second attempt sees it. Counts the validations.
     */
    private function deletedBeforeTheSecondValidation(callable $delete): \stdClass
    {
        $validations = new \stdClass();
        $validations->count = 0;
        $this->on(Inventory::class, Model::EVENT_BEFORE_VALIDATE, static function () use ($validations, $delete): void {
            $validations->count++;
            if ($validations->count === 2) {
                $delete();
            }
        });

        return $validations;
    }

    /**
     * Whether $save saved; a database error it throws as its class, SQLSTATE
     * and driver error (DbErrorSummary::of()), so that the assertion names it.
     *
     * @param callable(): bool $save
     * @return bool|array{0: string, 1: string, 2: string}
     */
    private function savedOrDatabaseError(callable $save): bool|array
    {
        try {
            return $save();
        } catch (DbException $e) {
            return DbErrorSummary::of($e);
        }
    }

    /**
     * The number of inventory.created entries.
     */
    private function createdAudits(): int
    {
        return (int)AuditLog::find()->where(['action' => AuditLog::ACTION_INVENTORY_CREATED])->count();
    }

    /**
     * Throws a database error with $errorInfo from the first attempt of
     * $write, through the model event of its statement (after it for
     * create-after); counts the attempts.
     *
     * @param array{0: string, 1: int, 2: string} $errorInfo
     */
    private function conflictOnce(string $write, array $errorInfo): \stdClass
    {
        $attempts = new \stdClass();
        $attempts->count = 0;
        $event = match ($write) {
            'create' => BaseActiveRecord::EVENT_BEFORE_INSERT,
            'create-after' => BaseActiveRecord::EVENT_AFTER_INSERT,
            'delete' => BaseActiveRecord::EVENT_BEFORE_DELETE,
            default => BaseActiveRecord::EVENT_BEFORE_UPDATE,
        };
        $this->on(Inventory::class, $event, static function () use ($attempts, $errorInfo): void {
            $attempts->count++;
            if ($attempts->count === 1) {
                throw new DbException('Lock conflict', $errorInfo);
            }
        });

        return $attempts;
    }

    /**
     * @param array{0: string, 1: int, 2: string} $errorInfo
     */
    private function conflictAlways(string $event, array $errorInfo): \stdClass
    {
        $attempts = new \stdClass();
        $attempts->count = 0;
        $this->on(Inventory::class, $event, static function () use ($attempts, $errorInfo): void {
            $attempts->count++;
            throw new DbException('Lock conflict', $errorInfo);
        });

        return $attempts;
    }

    /**
     * The inventory of $write and the action that writes it.
     *
     * @return array{0: Inventory, 1: callable(): void}
     */
    private function prepareWrite(string $write, int $userId): array
    {
        if ($write === 'create' || $write === 'create-after') {
            $inventory = new Inventory();
            $inventory->name = 'inv-lock-retried';
            $inventory->inventory_type = Inventory::TYPE_STATIC;
            $inventory->content = "all:\n  hosts:\n    web1.example.com:\n";

            return [$inventory, fn () => $this->assertTrue($this->writer->create($inventory, [], $userId, InventoryWriteService::SOURCE_API))];
        }
        $inventory = $this->staticInventory($userId);
        $inventory->name = 'inv-lock-retried';

        return [$inventory, fn () => $this->write($write, $inventory, $userId)];
    }

    private function write(string $write, Inventory $inventory, int $userId): void
    {
        if ($write === 'delete') {
            $this->writer->delete($inventory, $userId, InventoryWriteService::SOURCE_WEB);
            return;
        }
        $this->assertTrue($this->writer->update($inventory, [], $userId, InventoryWriteService::SOURCE_WEB));
    }

    private function dynamicInventory(int $createdBy, ?int $projectId): Inventory
    {
        $inventory = $this->staticInventory($createdBy, $projectId);
        $inventory->inventory_type = Inventory::TYPE_DYNAMIC;
        $inventory->content = null;
        $inventory->source_path = 'inventories/aws_ec2.yml';
        $inventory->save(false);

        return $this->reloaded((int)$inventory->id);
    }

    private function lockStatementOf(int $id): string
    {
        return 'SELECT * FROM `inventory` WHERE `id` = ' . $id . ' FOR UPDATE';
    }

    /**
     * The statements of $statements that lock rows: FOR UPDATE or LOCK IN
     * SHARE MODE.
     *
     * @param list<string> $statements
     * @return list<string>
     */
    private function lockingReadsOf(array $statements): array
    {
        return array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'FOR UPDATE') || str_contains($sql, 'LOCK IN SHARE MODE')
        ));
    }

    /**
     * The SQL statements $action sends, in order.
     *
     * @return list<string>
     */
    private function statementsOf(callable $action): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $action();
        } finally {
            \Yii::setLogger($previous);
        }
        $statements = [];
        foreach ($logger->messages as $message) {
            if (
                in_array($message[2], ['yii\db\Command::query', 'yii\db\Command::execute'], true)
                && $message[1] === Logger::LEVEL_INFO
                && is_string($message[0])
            ) {
                $statements[] = $message[0];
            }
        }

        return $statements;
    }

    /**
     * @param list<string> $statements
     */
    private function indexOf(array $statements, string $pattern): int
    {
        foreach ($statements as $index => $statement) {
            if (preg_match($pattern, $statement) === 1) {
                return $index;
            }
        }
        $this->fail("No statement matches {$pattern}:\n" . implode("\n", $statements));
    }
}
