<?php

declare(strict_types=1);

namespace app\services;

use app\models\Inventory;

/**
 * The hosts of a gated inventory are for admins to change. An inventory is
 * gated when its stored approval_rule_id names a rule (a positive ID: the
 * foreign key restricts deleting a rule that an inventory uses): every launch
 * of a job template that uses it waits for that rule (JobTemplateGate). Only
 * users who may assign approval rules (approval-rule.update, see
 * ApprovalRuleAssignmentPolicy::mayAssign()) may change any other column of
 * it than its name and description (OPEN_ATTRIBUTES): its type, content,
 * source path and project, which say where its jobs run, and any column added
 * later; and only they may delete it. Everyone who may change the inventory
 * keeps changing its name and description, and parsing it.
 *
 * Why: otherwise user B changes the hosts of an inventory whose launches A's
 * schedule, trigger token or workflow starts as A, and then approves where B
 * alone chose to run them.
 *
 * Deny by default: every column but the open ones counts, also one added
 * later. A value sent as stored is no change: null and '' are the same, a
 * list or an object always differs and is never cast, and anything else is
 * compared after the column's type cast ("5" and 5 for an ID), or exactly
 * for a name that is no column.
 *
 * InventoryWriteService asks under the inventory's row lock, once it took
 * the locked row as the inventory's stored state, so that a rule assigned or
 * removed meanwhile counts. The hosts of a file or dynamic inventory come
 * from its project's repository (REPOSITORY_HOSTS_NOTE): the lock covers the
 * source path and the project, not the file.
 */
final class GatedInventoryLock
{
    /** The columns of a gated inventory that everyone who may change the inventory may still change. */
    public const OPEN_ATTRIBUTES = ['name', 'description'];

    /** Why a change was refused: web 403 (ForbiddenHttpException), API 403. */
    public const CHANGE_REFUSED = 'This inventory has an approval rule: only admins may change its hosts '
        . '(approval-rule.update). You may change its name and description.';

    /** Why a delete was refused: web 403 (ForbiddenHttpException), API 403. */
    public const DELETE_REFUSED = 'This inventory has an approval rule: only admins may delete it (approval-rule.update).';

    /** The notice on the inventory's page and edit form, for users the lock applies to. */
    public const NOTICE = 'This inventory has an approval rule: every launch of a job template that uses it waits '
        . 'for approval. Only admins (approval-rule.update) may change its hosts or delete it, give a job template '
        . 'this inventory, or change what the job templates that use it run. You may still change its name and '
        . 'description, and parse it.';

    /**
     * A known limit, stated as a fact: the pages show it for file and
     * dynamic inventories, and the docs quote it.
     */
    public const REPOSITORY_HOSTS_NOTE = 'The hosts of a file or dynamic inventory come from its project\'s '
        . 'repository when a runner claims the job, after the approval: the approval rule locks the source path and '
        . 'the project, not the file. Whoever may change that project (its repository URL or branch) or push to its '
        . 'repository changes these hosts without an admin.';

    /**
     * Whether $inventory is gated as stored: its old approval_rule_id is a
     * positive ID. An inventory not saved yet, or a model whose schema has no
     * such column, is not.
     */
    public static function isGated(Inventory $inventory): bool
    {
        $ruleId = $inventory->getOldAttribute('approval_rule_id');

        return is_numeric($ruleId) && (int)$ruleId > 0;
    }

    /**
     * Whether the lock keeps $userId from changing the hosts of $inventory
     * and from deleting it: the inventory is gated as stored, and the user
     * may not assign approval rules. For the inventory's page and edit form
     * (the notice and the disabled fields) and for the write service.
     */
    public function appliesTo(int $userId, Inventory $inventory): bool
    {
        return self::isGated($inventory) && !$this->policy()->mayAssign($userId);
    }

    /**
     * Why $userId may not save the change of $inventory, or null when they
     * may: CHANGE_REFUSED when the lock applies and the change, against the
     * stored state (the old attributes), touches a column that is not open.
     */
    public function changeRefusal(int $userId, Inventory $inventory): ?string
    {
        if (!$this->appliesTo($userId, $inventory)) {
            return null;
        }
        $changed = array_diff(self::changedAttributes($inventory, $inventory->getOldAttributes()), self::OPEN_ATTRIBUTES);

        return $changed === [] ? null : self::CHANGE_REFUSED;
    }

    /**
     * Why $userId may not delete $inventory, or null when they may:
     * DELETE_REFUSED when the lock applies.
     */
    public function deleteRefusal(int $userId, Inventory $inventory): ?string
    {
        return $this->appliesTo($userId, $inventory) ? self::DELETE_REFUSED : null;
    }

    /**
     * The attributes of $inventory whose value differs from $stored, in the
     * order of the model's attributes. An attribute that $stored lacks
     * counts as changed.
     *
     * @param array<string, mixed> $stored the stored state, such as the old attributes
     * @return list<string>
     */
    public static function changedAttributes(Inventory $inventory, #[\SensitiveParameter] array $stored): array
    {
        $changed = [];
        foreach ($inventory->attributes() as $name) {
            $name = (string)$name;
            if (!array_key_exists($name, $stored) || !self::sameValue($name, $inventory->getAttribute($name), $stored[$name])) {
                $changed[] = $name;
            }
        }

        return $changed;
    }

    /**
     * Whether $value is the stored value $stored of the column $name: null
     * and '' are the same; a list or an object always differs and is never
     * cast (a cast would read a list as a value); anything else is compared
     * after the column's type cast, or exactly for a name that is no column.
     */
    private static function sameValue(string $name, #[\SensitiveParameter] mixed $value, #[\SensitiveParameter] mixed $stored): bool
    {
        if (is_array($value) || is_object($value) || is_array($stored) || is_object($stored)) {
            return false;
        }
        $value = $value === '' ? null : $value;
        $stored = $stored === '' ? null : $stored;
        $column = Inventory::getTableSchema()->getColumn($name);

        return $column === null ? $value === $stored : $column->phpTypecast($value) === $column->phpTypecast($stored);
    }

    private function policy(): ApprovalRuleAssignmentPolicy
    {
        /** @var ApprovalRuleAssignmentPolicy $policy */
        $policy = \Yii::$app->get('approvalRuleAssignmentPolicy');

        return $policy;
    }
}
