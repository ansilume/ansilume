<?php

declare(strict_types=1);

namespace app\services;

use app\components\DbErrorSummary;
use app\components\InventoryUsage;
use app\components\SubmittedId;
use app\models\AuditLog;
use app\models\Inventory;
use yii\base\Component;
use yii\db\IntegrityException;

/**
 * Creates, updates and deletes inventories for the web UI and the REST API.
 * $submitted is the form's Inventory fields or the JSON body: only its
 * approval rule is read from it (InventoryRuleAssignment); the caller has
 * loaded the other fields into the model. A refusal is an
 * InventoryWriteRefusedException, thrown before anything is written; false
 * means the model carries the validation errors.
 *
 * - created_by is the user who creates the inventory, never a submitted value.
 * - The approval rule is for admins to assign or remove (403 ASSIGN_REFUSED,
 *   before the transaction begins).
 * - A gated inventory's hosts are for admins to change, and it is for them to
 *   delete (GatedInventoryLock, 403).
 * - One approval rule per launch: an inventory gets no rule while a job
 *   template on it has another rule of its own
 *   (InventoryRuleAssignment::RULES_DIFFER, a validation error).
 * - An inventory that job templates use, deleted ones included, is not
 *   deleted (409, DELETE_IN_USE with the usage): the foreign key would refuse
 *   it, and a deleted job template keeps its inventory for the history of its
 *   jobs until its project is deleted.
 *
 * An update and a delete run in one transaction whose first statement locks
 * the inventory's own row (LOCK_SQL): nothing is read before it, so the
 * checks and the validation read the locked row and what others committed
 * before it. The request's changes are taken onto the locked row: every
 * attribute the request did not change takes the stored value, so a change
 * made meanwhile stays and is validated with it. Under the lock, in this
 * order: 404 when the row is gone (NOT_FOUND); 403 when the user may no
 * longer operate its project, or the project it moves to (TEAM_SCOPE_REFUSED);
 * 403 from GatedInventoryLock; then the validation, or the usage of a delete.
 *
 * Lock order (as JobLaunchService, GatedJobTemplateLock and
 * JobTemplateGate::readInventoryRules() state it): a launch and a template
 * save lock the job_template row first, then share-lock the inventory rows,
 * in one statement in ascending ID order. An inventory save or delete locks
 * only its own row, as the first statement of its transaction, and then reads
 * job_template, approval_rule and project rows with plain reads only: it
 * never takes a locking read of a job_template row, which would cross the
 * order of launches and template saves and end in deadlocks.
 *
 * An attempt is rolled back and made once more when a deadlock or a lock wait
 * timeout ends it, or when the foreign key refuses its insert or update
 * because the approval rule or the project it names was deleted meanwhile
 * (an IntegrityException with driver error 1452). A save meets that refusal,
 * not a lock conflict, when an admin deletes the rule, or someone the
 * project, at the same moment: the delete holds the row's lock, the save's
 * foreign key check waits for it and finds the row gone once the delete has
 * committed. Create repeats its validation and insert, update its
 * transaction from the row lock on, delete its transaction. The second
 * attempt sees what the other transaction committed, so a rule or project
 * deleted meanwhile ends in the validation error ('The selected approval
 * rule does not exist.', 'The selected project does not exist.'), and
 * nothing is written. A lock conflict or such a refusal in the second
 * attempt, and any other database error at once, is thrown: nothing is
 * written, and the error handlers log it with its codes only. A model whose
 * schema lacks a column of the locked row (a schema cached from before a
 * migration) cannot take that row: the update or delete fails without
 * writing.
 *
 * Audit (object inventory #id; refusals write nothing; values are never
 * recorded): inventory.created {name, type, project_id, changed_fields (the
 * TRACKED_FIELDS with a value), approval_rule? {from: null, to}, source};
 * inventory.updated {name, changed_fields (may be []), approval_rule? {from,
 * to} only when it changes, source}; inventory.deleted {name, type,
 * approval_rule? {from, to: null}, source}.
 */
class InventoryWriteService extends Component
{
    public const SOURCE_WEB = 'web';
    public const SOURCE_API = 'api';

    /** The 404 of an inventory deleted meanwhile; sprintf(): its ID. */
    public const NOT_FOUND = 'Inventory #%d not found.';

    /**
     * The 403 of a user who may no longer operate the inventory's project,
     * or the project it moves to, checked again under the row lock: the text
     * of the API's own check. The web answers it in the words of its own
     * check (BaseController::MODIFY_FORBIDDEN).
     */
    public const TEAM_SCOPE_REFUSED = 'Forbidden.';

    /** The 409 of a delete of an inventory in use; sprintf(): InventoryUsage::summary(). */
    public const DELETE_IN_USE = '%s Point its job templates at another inventory first. A deleted job template keeps '
        . 'its inventory for the history of its jobs until its project is deleted, so deleting the template does '
        . 'not free it.';

    /** The fields whose change the audit names (changed_fields), in this order. */
    public const TRACKED_FIELDS = ['name', 'description', 'inventory_type', 'content', 'source_path', 'project_id'];

    /** Reads the inventory's row and locks it until the transaction ends: the transaction's first statement. */
    public const LOCK_SQL = 'SELECT * FROM {{%inventory}} WHERE [[id]] = :id FOR UPDATE';

    /** A delete that a model event refused; sprintf(): the inventory's ID. */
    private const DELETE_VETOED = 'Deleting inventory #%d was refused.';

    /**
     * The driver error of a foreign key that refuses an insert or update
     * because the row it names is gone (MariaDB/MySQL 1452, "Cannot add or
     * update a child row"), as DbErrorSummary::of() gives it.
     */
    private const REFERENCE_DELETED = '1452';

    /**
     * Creates $inventory as $userId.
     *
     * @param array<array-key, mixed> $submitted the form's Inventory fields or the JSON body
     * @throws InventoryWriteRefusedException 403 for a rule the user may not assign
     */
    public function create(Inventory $inventory, #[\SensitiveParameter] array $submitted, int $userId, string $source): bool
    {
        $inventory->created_by = $userId;
        $assignment = new InventoryRuleAssignment();
        self::refuse($assignment->apply($inventory, $submitted, $userId, null));
        if (!$this->withRetry(static fn (): bool => self::insert($inventory))) {
            return false;
        }
        $this->audit(AuditLog::ACTION_INVENTORY_CREATED, (int)$inventory->id, $userId, [
            'name' => (string)$inventory->name,
            'type' => (string)$inventory->inventory_type,
            'project_id' => SubmittedId::of($inventory->project_id),
            'changed_fields' => self::filledFields($inventory),
        ] + $assignment->auditContext(null, self::ruleIdOf($inventory)) + ['source' => $source]);

        return true;
    }

    /**
     * Saves the changes of $inventory as $userId. The vault check of the job
     * templates that use the inventory is redone afterwards.
     *
     * @param array<array-key, mixed> $submitted the form's Inventory fields or the JSON body
     * @throws InventoryWriteRefusedException 403 or 404, before anything is written
     */
    public function update(Inventory $inventory, #[\SensitiveParameter] array $submitted, int $userId, string $source): bool
    {
        $baseline = InventoryRuleAssignment::storedRuleId($inventory);
        self::refuse((new InventoryRuleAssignment())->apply($inventory, $submitted, $userId, $baseline));
        // What the request changes, against the inventory as it was loaded.
        $requested = GatedInventoryLock::changedAttributes($inventory, $inventory->getOldAttributes());
        $entry = $this->withRetry(fn (): ?array => $this->updateLocked($inventory, $requested, $userId));
        if ($entry === null) {
            return false;
        }
        $this->audit(AuditLog::ACTION_INVENTORY_UPDATED, (int)$inventory->id, $userId, $entry + ['source' => $source]);
        /** @var VaultCheckService $vaultChecks */
        $vaultChecks = \Yii::$app->get('vaultCheckService');
        $vaultChecks->checkTemplateIds($vaultChecks->templateIdsUsingInventory($inventory));
        unset($inventory->approvalRule, $inventory->project);

        return true;
    }

    /**
     * Deletes $inventory as $userId, unless job templates use it. A
     * reference the usage did not count (the foreign key refused the delete)
     * is counted again after the rollback and answered as in use.
     *
     * @throws InventoryWriteRefusedException 403, 404 or 409, with nothing deleted
     */
    public function delete(Inventory $inventory, int $userId, string $source): void
    {
        try {
            $entry = $this->withRetry(fn (): array => $this->deleteLocked($inventory, $userId));
        } catch (IntegrityException) {
            throw self::inUse($this->usage()->forInventory($inventory, $userId));
        }
        $this->audit(AuditLog::ACTION_INVENTORY_DELETED, (int)$inventory->id, $userId, $entry + ['source' => $source]);
    }

    /**
     * One attempt of create(): validates and inserts. A new record each time:
     * an attempt rolled back after its insert left the model saved.
     */
    private static function insert(Inventory $inventory): bool
    {
        $inventory->setIsNewRecord(true);
        unset($inventory->id);
        if (!$inventory->validate()) {
            return false;
        }
        $inventory->save(false);

        return true;
    }

    /**
     * One attempt of update(), inside its transaction: the row lock first,
     * then the checks on the locked row, then the validation and the save.
     *
     * @param list<string> $requested the attributes the request changes
     * @return array<string, mixed>|null the audit entry without its source;
     *     null when the validation failed
     */
    private function updateLocked(Inventory $inventory, array $requested, int $userId): ?array
    {
        $this->lockAndRebase($inventory, $requested);
        $this->checkScope($userId, $inventory, in_array('project_id', $requested, true));
        self::refuse((new GatedInventoryLock())->changeRefusal($userId, $inventory));
        if (!self::validates($inventory, $requested, $userId)) {
            return null;
        }
        $entry = self::updateEntry($inventory);
        $inventory->save(false);

        return $entry;
    }

    /**
     * The inventory.updated entry of the save about to be made, without its
     * source: the changes against the locked row, read before the save makes
     * the new values the stored ones.
     *
     * @return array<string, mixed>
     */
    private static function updateEntry(Inventory $inventory): array
    {
        $changed = GatedInventoryLock::changedAttributes($inventory, $inventory->getOldAttributes());
        $rule = (new InventoryRuleAssignment())->auditContext(
            InventoryRuleAssignment::storedRuleId($inventory),
            self::ruleIdOf($inventory)
        );

        return ['name' => (string)$inventory->name, 'changed_fields' => array_values(array_intersect(self::TRACKED_FIELDS, $changed))]
            + $rule;
    }

    /**
     * One attempt of delete(), inside its transaction: the row lock first,
     * then team scope, the lock of a gated inventory (before the usage is
     * read), the usage, and the delete.
     *
     * @return array<string, mixed> the audit entry without its source
     */
    private function deleteLocked(Inventory $inventory, int $userId): array
    {
        $this->lockAndRebase($inventory, []);
        $this->checkScope($userId, $inventory, false);
        self::refuse((new GatedInventoryLock())->deleteRefusal($userId, $inventory));
        $usage = $this->usage()->forInventory($inventory, $userId);
        if ($usage->isInUse()) {
            throw self::inUse($usage);
        }
        $entry = ['name' => (string)$inventory->name, 'type' => (string)$inventory->inventory_type]
            + (new InventoryRuleAssignment())->auditContext(InventoryRuleAssignment::storedRuleId($inventory), null);
        if ($inventory->delete() === false) {
            throw new \RuntimeException(sprintf(self::DELETE_VETOED, (int)$inventory->id));
        }

        return $entry;
    }

    /**
     * Locks the inventory's row (LOCK_SQL), the first statement of the
     * transaction, and takes it as the inventory's stored state.
     *
     * @param list<string> $requested
     * @throws InventoryWriteRefusedException 404 when the row is gone
     */
    private function lockAndRebase(Inventory $inventory, array $requested): void
    {
        $id = (int)$inventory->getOldAttribute('id');
        $stored = Inventory::getDb()->createCommand(self::LOCK_SQL, [':id' => $id])->queryOne();
        if (!is_array($stored)) {
            throw new InventoryWriteRefusedException(sprintf(self::NOT_FOUND, $id), InventoryWriteRefusedException::NOT_FOUND);
        }
        /** @var array<string, mixed> $stored */
        self::rebase($inventory, $requested, $stored);
    }

    /**
     * Takes the locked row $stored as the inventory's stored state: every
     * attribute gets the stored value as its old value, and every attribute
     * the request did not change also as its value. A column the model does
     * not know throws (InvalidArgumentException): nothing is written.
     *
     * @param list<string> $requested
     * @param array<string, mixed> $stored the locked row, as the database returns it
     */
    private static function rebase(Inventory $inventory, array $requested, #[\SensitiveParameter] array $stored): void
    {
        $schema = Inventory::getTableSchema();
        foreach ($stored as $name => $raw) {
            $column = $schema->getColumn($name);
            $value = $column === null ? $raw : $column->phpTypecast($raw);
            $inventory->setOldAttribute($name, $value);
            if (!in_array($name, $requested, true)) {
                $inventory->setAttribute($name, $value);
            }
        }
    }

    /**
     * 403 (TEAM_SCOPE_REFUSED) unless $userId may operate the inventory's
     * stored project and, when it moves, the project it moves to (the ID as
     * the model's integer rule reads it).
     */
    private function checkScope(int $userId, Inventory $inventory, bool $moves): void
    {
        $checker = $this->checker();
        $allowed = $checker->canOperateChildResource($userId, SubmittedId::of($inventory->getOldAttribute('project_id')))
            && (!$moves || $checker->canOperateChildResource($userId, SubmittedId::of($inventory->project_id)));
        if (!$allowed) {
            throw new InventoryWriteRefusedException(self::TEAM_SCOPE_REFUSED, InventoryWriteRefusedException::FORBIDDEN);
        }
    }

    /**
     * The validation of an update: the model's rules, and, when the request
     * changes the approval rule, whether job templates on the inventory have
     * another rule of their own (InventoryRuleAssignment::conflictRefusal()).
     *
     * @param list<string> $requested
     */
    private static function validates(Inventory $inventory, array $requested, int $userId): bool
    {
        $inventory->validate();
        if (in_array(InventoryRuleAssignment::FIELD, $requested, true) && !$inventory->hasErrors(InventoryRuleAssignment::FIELD)) {
            $conflict = (new InventoryRuleAssignment())->conflictRefusal($inventory, $userId);
            if ($conflict !== null) {
                $inventory->addError(InventoryRuleAssignment::FIELD, $conflict);
            }
        }

        return !$inventory->hasErrors();
    }

    /**
     * Runs $attempt in a transaction, and once more when a new attempt can
     * get past what ended it (retryable()); the second attempt's error, and
     * any other error of the first, is thrown.
     *
     * @template T
     * @param callable(): T $attempt
     * @return T
     */
    private function withRetry(callable $attempt): mixed
    {
        try {
            return $this->inTransaction($attempt);
        } catch (\Throwable $e) {
            if (!self::retryable($e)) {
                throw $e;
            }
        }

        return $this->inTransaction($attempt);
    }

    /**
     * Whether a new attempt can get past $e: a deadlock or a lock wait
     * timeout (DbErrorSummary::isLockConflict()), or the foreign key's
     * refusal of an approval rule or a project that another transaction
     * deleted meanwhile (an IntegrityException with driver error
     * REFERENCE_DELETED, read from its errorInfo, never from its message).
     * The new attempt's validation sees the delete and names the row.
     */
    private static function retryable(\Throwable $e): bool
    {
        return DbErrorSummary::isLockConflict($e)
            || ($e instanceof IntegrityException && DbErrorSummary::of($e)[2] === self::REFERENCE_DELETED);
    }

    /**
     * Runs $work in one transaction: committed when it returns, rolled back
     * when it throws.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function inTransaction(callable $work): mixed
    {
        $transaction = Inventory::getDb()->beginTransaction();
        try {
            $result = $work();
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $result;
    }

    /**
     * @throws InventoryWriteRefusedException 403 with $refusal, unless it is null
     */
    private static function refuse(?string $refusal): void
    {
        if ($refusal !== null) {
            throw new InventoryWriteRefusedException($refusal, InventoryWriteRefusedException::FORBIDDEN);
        }
    }

    private static function inUse(InventoryUsage $usage): InventoryWriteRefusedException
    {
        return new InventoryWriteRefusedException(
            sprintf(self::DELETE_IN_USE, $usage->summary()),
            InventoryWriteRefusedException::CONFLICT,
            ['used_by' => $usage->toArray()]
        );
    }

    /**
     * The TRACKED_FIELDS of a new inventory that have a value, in their order.
     *
     * @return list<string>
     */
    private static function filledFields(Inventory $inventory): array
    {
        return array_values(array_filter(
            self::TRACKED_FIELDS,
            static fn (string $field): bool => !in_array($inventory->getAttribute($field), [null, ''], true)
        ));
    }

    /**
     * The approval rule ID the inventory is given, as the model's integer
     * rule reads it; null for none.
     */
    private static function ruleIdOf(Inventory $inventory): ?int
    {
        return SubmittedId::of($inventory->getAttribute(InventoryRuleAssignment::FIELD));
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function audit(string $action, int $inventoryId, int $userId, array $entry): void
    {
        /** @var AuditService $audit */
        $audit = \Yii::$app->get('auditService');
        $audit->log($action, 'inventory', $inventoryId, $userId, $entry);
    }

    private function usage(): InventoryUsageService
    {
        /** @var InventoryUsageService $usage */
        $usage = \Yii::$app->get('inventoryUsageService');

        return $usage;
    }

    private function checker(): ProjectAccessChecker
    {
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');

        return $checker;
    }
}
