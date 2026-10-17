<?php

declare(strict_types=1);

namespace app\services;

use app\components\ApprovalRuleUsage;
use app\components\SurveyDefinition;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use yii\base\Component;
use yii\db\Exception as DbException;
use yii\db\IntegrityException;
use yii\db\Query;

/**
 * Creates, updates and deletes approval rules for the web UI and the REST
 * API.
 *
 * - created_by is the user who creates the rule, never a submitted value.
 * - Audit entries carry the source (web or api) and the approver settings:
 *   all of them on create, each changed field with its value before and
 *   after on update. None of them is a secret.
 * - Delete refuses a rule in use ({@see ApprovalRuleUsage}: inventories,
 *   job templates, workflow steps, pending requests); there is no force. It
 *   runs in one transaction that locks the rule row, so nothing can start
 *   using the rule between the check and the delete; the RESTRICT foreign
 *   keys of inventories, job templates and workflow steps refuse anything
 *   that does anyway, and the answer is the same refusal, with the usage
 *   counted again. A reference that the usage still does not count is
 *   refused with DELETE_REFUSED_UNLISTED, never as "not in use". A save
 *   that assigns the rule at the same moment can end the delete's
 *   transaction in a deadlock or a lock wait timeout instead: the delete
 *   then runs once more, sees what that save committed, and refuses (a
 *   second lock conflict is thrown). An inventory save that names the rule
 *   while the delete removes it makes its attempt once more as well
 *   (InventoryWriteService) and answers that the rule does not exist.
 *   Soft-deleted job templates and the steps of soft-deleted workflows
 *   cannot run: their reference is cleared instead of blocking the delete.
 *   Inventories have no soft delete, so an inventory with the rule always
 *   blocks it. Resolved requests keep their history without the rule.
 */
class ApprovalRuleWriteService extends Component
{
    public const SOURCE_WEB = 'web';
    public const SOURCE_API = 'api';

    /** The refusal of a delete: the usage summary, then what to do. */
    public const DELETE_REFUSED = '%s Remove it from them, and let pending requests be decided or their jobs canceled, before deleting it.';

    /**
     * The refusal of a delete that the database refused although the usage
     * counts nothing: the quoted rule name.
     */
    public const DELETE_REFUSED_UNLISTED = 'Approval rule %s was not deleted: the database still refers to it. Nothing was changed.';

    /** Reads an approval rule row and locks it until the transaction ends. */
    private const LOCK_SQL = 'SELECT * FROM {{%approval_rule}} WHERE [[id]] = :id FOR UPDATE';

    /** SQLSTATE of a transaction rolled back for a deadlock (serialization failure). */
    private const SQLSTATE_SERIALIZATION_FAILURE = '40001';

    /** MariaDB error codes of a lock conflict: deadlock, lock wait timeout. */
    private const LOCK_CONFLICT_CODES = [1213, 1205];

    /** The fields an update audits with their values before and after. */
    private const TRACKED_FIELDS = [
        'name',
        'description',
        'approver_type',
        'approver_config',
        'required_approvals',
        'timeout_minutes',
        'timeout_action',
    ];

    /** The approver settings a create and a delete audit. */
    private const SETTINGS = ['approver_type', 'approver_config', 'required_approvals', 'timeout_minutes', 'timeout_action'];

    public function create(ApprovalRule $rule, int $createdBy, string $source): bool
    {
        $rule->created_by = $createdBy;
        if (!$rule->save()) {
            return false;
        }
        $this->audit(AuditLog::ACTION_APPROVAL_RULE_CREATED, (int)$rule->id, ['name' => $rule->name] + self::settings($rule) + ['source' => $source]);

        return true;
    }

    public function update(ApprovalRule $rule, string $source): bool
    {
        if (!$rule->validate()) {
            return false;
        }
        // Before the save, which makes the new values the old ones.
        $changes = self::changes($rule);
        $rule->save(false);
        $this->audit(AuditLog::ACTION_APPROVAL_RULE_UPDATED, (int)$rule->id, [
            'name' => $rule->name,
            'changes' => $changes,
            'source' => $source,
        ]);

        return true;
    }

    /**
     * Deletes the rule unless it is in use. The usage is the one the
     * refusal names: what the viewer may see, plus counts of the rest. A
     * rule another request deleted meanwhile counts as deleted, without a
     * second audit entry. An attempt that ends in a lock conflict (deadlock,
     * lock wait timeout) is rolled back and made once more; the second
     * attempt's lock conflict is thrown.
     *
     * @return array{deleted: bool, usage: ApprovalRuleUsage}
     * @throws DbException a lock conflict in both attempts, or any other database error
     */
    public function delete(ApprovalRule $rule, ?int $viewerId, string $source): array
    {
        try {
            $attempt = $this->deleteInTransaction($rule, $viewerId);
        } catch (DbException $e) {
            if (!self::isLockConflict($e)) {
                throw $e;
            }
            // The other transaction won: typically a save that assigns this
            // rule. The second attempt waits for its lock and sees what it
            // committed, so a new use ends in the refusal, not in an error.
            $attempt = $this->deleteInTransaction($rule, $viewerId);
        }
        if ($attempt['audit'] !== null) {
            $this->audit(AuditLog::ACTION_APPROVAL_RULE_DELETED, (int)$rule->id, $attempt['audit'] + ['source' => $source]);
        }

        return ['deleted' => $attempt['deleted'], 'usage' => $attempt['usage']];
    }

    /**
     * The message that refuses the delete of a rule in use. A usage that
     * counts nothing comes from a delete that the foreign keys refused for
     * a reference the usage does not list: it says so, instead of "not in
     * use".
     */
    public static function refusal(ApprovalRuleUsage $usage): string
    {
        if (!$usage->isInUse()) {
            return sprintf(self::DELETE_REFUSED_UNLISTED, SurveyDefinition::quote($usage->ruleName));
        }

        return sprintf(self::DELETE_REFUSED, $usage->summary());
    }

    /**
     * One attempt of delete(), in one transaction that locks the rule row
     * first. Any error rolls the transaction back before it is thrown.
     *
     * @return array{deleted: bool, usage: ApprovalRuleUsage, audit: array<string, mixed>|null}
     *     audit: the approval-rule.deleted entry without its source; null when this attempt deleted nothing
     */
    private function deleteInTransaction(ApprovalRule $rule, ?int $viewerId): array
    {
        $transaction = ApprovalRule::getDb()->beginTransaction();
        try {
            $locked = ApprovalRule::findBySql(self::LOCK_SQL, [':id' => (int)$rule->id])->one();
            if (!$locked instanceof ApprovalRule) {
                $transaction->commit();
                $nothing = new ApprovalRuleUsage((string)$rule->name, [], 0, [], 0, 0);
                return ['deleted' => true, 'usage' => $nothing, 'audit' => null];
            }
            $usage = $this->usage()->forRule($locked, $viewerId);
            if ($usage->isInUse()) {
                $transaction->rollBack();
                return ['deleted' => false, 'usage' => $usage, 'audit' => null];
            }
            $detached = $this->detachUnusable((int)$locked->id);
            if ($locked->delete() === false) {
                throw new \RuntimeException(sprintf('Deleting approval rule #%d was refused.', (int)$locked->id));
            }
            $transaction->commit();
        } catch (IntegrityException) {
            // A reference the check did not see: the foreign keys refused it.
            $transaction->rollBack();
            return ['deleted' => false, 'usage' => $this->usage()->forRule($rule, $viewerId), 'audit' => null];
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return [
            'deleted' => true,
            'usage' => $usage,
            'audit' => ['name' => $locked->name] + self::settings($locked) + $detached,
        ];
    }

    /**
     * Whether MariaDB refused the statement for a lock conflict: a deadlock
     * (SQLSTATE 40001, error 1213) or a lock wait timeout (error 1205).
     */
    private static function isLockConflict(DbException $e): bool
    {
        $info = is_array($e->errorInfo) ? $e->errorInfo : [];

        return ($info[0] ?? null) === self::SQLSTATE_SERIALIZATION_FAILURE
            || in_array((int)($info[1] ?? 0), self::LOCK_CONFLICT_CODES, true);
    }

    /**
     * Clears the rule from soft-deleted job templates and from the steps of
     * soft-deleted workflows: they cannot run, and no restore exists.
     *
     * @return array{detached_job_templates: int, detached_workflow_steps: int}
     */
    private function detachUnusable(int $ruleId): array
    {
        $command = ApprovalRule::getDb()->createCommand();
        $templates = $command->update(
            JobTemplate::tableName(),
            ['approval_rule_id' => null],
            ['and', ['approval_rule_id' => $ruleId], ['not', ['deleted_at' => null]]]
        )->execute();
        $deletedWorkflows = (new Query())->select('id')->from(WorkflowTemplate::tableName())->where(['not', ['deleted_at' => null]]);
        $steps = $command->update(
            WorkflowStep::tableName(),
            ['approval_rule_id' => null],
            ['and', ['approval_rule_id' => $ruleId], ['in', 'workflow_template_id', $deletedWorkflows]]
        )->execute();

        return ['detached_job_templates' => $templates, 'detached_workflow_steps' => $steps];
    }

    /**
     * @return array<string, mixed> the approver settings as the audit records them
     */
    private static function settings(ApprovalRule $rule): array
    {
        $settings = [];
        foreach (self::SETTINGS as $field) {
            $settings[$field] = self::auditValue($field, $rule->getAttribute($field));
        }

        return $settings;
    }

    /**
     * @return array<string, array{from: mixed, to: mixed}> the tracked fields the update changes
     */
    private static function changes(ApprovalRule $rule): array
    {
        $changes = [];
        foreach (self::TRACKED_FIELDS as $field) {
            $from = self::auditValue($field, $rule->getOldAttribute($field));
            $to = self::auditValue($field, $rule->getAttribute($field));
            if ($from !== $to) {
                $changes[$field] = ['from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    /**
     * A field's value as the audit records it, so that a form's text and
     * the stored value compare equal: the approver config decoded, numbers
     * as integers, and an empty value as null.
     */
    private static function auditValue(string $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($field === 'approver_config' && is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : $value;
        }
        if (($field === 'required_approvals' || $field === 'timeout_minutes') && is_numeric($value)) {
            return (int)$value;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function audit(string $action, int $ruleId, array $entry): void
    {
        /** @var AuditService $audit */
        $audit = \Yii::$app->get('auditService');
        $audit->log($action, 'approval_rule', $ruleId, null, $entry);
    }

    private function usage(): ApprovalRuleUsageService
    {
        /** @var ApprovalRuleUsageService $service */
        $service = \Yii::$app->get('approvalRuleUsageService');

        return $service;
    }
}
