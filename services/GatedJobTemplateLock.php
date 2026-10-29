<?php

declare(strict_types=1);

namespace app\services;

use app\components\SubmittedId;
use app\models\JobTemplate;

/**
 * What a gated job template runs is for admins to change. A job template is
 * gated when its stored approval_rule_id names an existing rule, or else
 * when its stored inventory has an approval rule (JobTemplateGate: its own
 * rule first, else its inventory's). Only users who may assign approval
 * rules (approval-rule.update, see ApprovalRuleAssignmentPolicy::mayAssign())
 * may change any other column of it, the extra vars, the survey and the
 * inventory among them, or its ordered credential list: CHANGE_REFUSED for
 * a template with its own rule, CHANGE_REFUSED_INVENTORY for one gated
 * through its inventory. Everyone who may change the template keeps
 * changing its name and description (OPEN_ATTRIBUTES), launching it,
 * managing its trigger token, cloning it (the clone keeps the rule and the
 * inventory, and so the lock) and deleting it.
 *
 * Giving a job template an inventory with an approval rule gates it, so
 * that is for admins too, on a new template as well (GATED_INVENTORY_REFUSED,
 * GatedInventoryChoice). An inventory the user may not see is never refused
 * here: the template's validation answers that it does not exist.
 *
 * Why: otherwise user B changes a template that A's schedule, trigger token
 * or workflow launches as A, and then approves what B alone chose.
 *
 * Deny by default: every column but the open ones counts, also one added
 * later. A value sent as stored is no change. Values are compared after the
 * column's type cast ("5" and 5, true and 1, "" and null for an ID), the
 * extra vars and the survey as JSON values (jsonKey(): whitespace and
 * escapes do not count; another value, another type, 1 and 1.0, {} and [],
 * or another order of keys does), and the credentials as the list the save
 * stores: the primary first, then the additional ones in their order. A
 * float that no int holds in an integer column (a JSON body's 1e30 or
 * 1e999) is a change: no stored value is one, and it has no type cast (on
 * PHP 8.5 its (int) cast warns). The inventory and the primary credential
 * are read as the integer rule reads an ID (SubmittedId), so the lock reads
 * the ID the save stores; a value the rule refuses is none, and never saved.
 * So are the additional credentials, where a value the rule refuses is a
 * change (changesCredentials()).
 *
 * JobTemplateCredentialService asks for every save a user makes, twice:
 * before validation, so that a refused change gets this answer first, and
 * inside the save's transaction, so that a rule assigned meanwhile, to the
 * template or to an inventory, is seen. The changes compared are those the
 * save writes: the template's dirty attributes, against the stored row.
 *
 * Lock order (C16), inside the save's transaction: the template row FOR
 * UPDATE as its first statement (an existing template), then, as the next
 * statement and in ONE statement, the stored and the new inventory LOCK IN
 * SHARE MODE in ascending ID order (JobTemplateGate::readInventoryRules()).
 * A new template share-locks its one inventory as its first statement. No
 * later statement of the save locks another inventory row: the persist veto
 * (JobTemplateRuleAssignment::persistVetoes(): whether the inventory the
 * save stores still exists, and whether the template's own rule is its
 * rule) reads the template row without a lock and that inventory row,
 * which this lock share-locked already. A launch locks in the same order
 * (JobLaunchService); an inventory save locks only its own row and never
 * takes a locking read of job_template (InventoryWriteService), so the
 * orders cannot cross, with one exception. A project delete
 * (ProjectDeletionService) locks the project row and then, through the
 * foreign key's ON DELETE SET NULL (fk_inventory_project_id), the rows of
 * the project's inventories, while a save locks the row of the template's
 * project only when it writes the template (the check of its foreign key),
 * after its inventories. So a save that moves a template into a project
 * being deleted, or creates one there, while its inventory belongs to that
 * project, can deadlock with the delete (before 2.12.0 it ended in a
 * foreign key error): either way the save writes nothing and answers a
 * server error, and saving again answers that the project does not exist.
 */
final class GatedJobTemplateLock
{
    /** The columns of a gated template that everyone who may change the template may still change. */
    public const OPEN_ATTRIBUTES = ['name', 'description'];

    /** Why a change of a template with its own rule was refused: web 403 (ForbiddenHttpException), API 403. */
    public const CHANGE_REFUSED = 'This job template has an approval rule: only admins may change what it runs '
        . '(approval-rule.update). You may change its name and description.';

    /** Why a change of a template gated through its inventory was refused: web 403, API 403. */
    public const CHANGE_REFUSED_INVENTORY = 'The inventory of this job template has an approval rule: only admins may '
        . 'change what it runs (approval-rule.update). You may change its name and description.';

    /** The notice on the page and edit form of a template with its own rule, for users the lock applies to. */
    public const NOTICE = 'This job template has an approval rule: only admins may change what it runs '
        . '(approval-rule.update). You may still change its name and description, launch it and manage its trigger token.';

    /** The notice on the page and edit form of a template gated through its inventory, for users the lock applies to. */
    public const NOTICE_INVENTORY = 'The inventory of this job template has an approval rule: only admins may change '
        . 'what it runs (approval-rule.update). You may still change its name and description, launch it and manage '
        . 'its trigger token.';

    /** Why giving a job template an inventory with an approval rule was refused: web 403, API 403. */
    public const GATED_INVENTORY_REFUSED = 'The selected inventory has an approval rule: only admins may give a job '
        . 'template an inventory with an approval rule (approval-rule.update).';

    /** The key under which a save the lock refused carries its reason among the template's errors (refusalOf()). */
    public const ERROR_KEY = 'gated_job_template';

    /** The change refusal by what gates the stored template (GatedInventoryChoice::storedSource()). */
    private const CHANGE_REFUSALS = [
        JobTemplateGate::SOURCE_JOB_TEMPLATE => self::CHANGE_REFUSED,
        JobTemplateGate::SOURCE_INVENTORY => self::CHANGE_REFUSED_INVENTORY,
    ];

    /** The notice by what gates the stored template. */
    private const NOTICES = [
        JobTemplateGate::SOURCE_JOB_TEMPLATE => self::NOTICE,
        JobTemplateGate::SOURCE_INVENTORY => self::NOTICE_INVENTORY,
    ];

    /** The columns compared as JSON values. */
    private const JSON_ATTRIBUTES = ['extra_vars', 'survey_fields'];

    /** How a decoded JSON value is encoded for the comparison: a float keeps its fraction, so 1.0 is not 1. */
    private const JSON_FLAGS = JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** Prefix of the comparison key of a text that is no JSON: only the same text has that key. */
    private const TEXT_KEY = "text\0";

    /** The end of the int range as a float (2^63): no int holds a float at or beyond it, or below its negative. */
    private const INT_RANGE_END = 2 ** 63;

    private const ROW_SQL = 'SELECT * FROM {{%job_template}} WHERE [[id]] = :id';

    private const CREDENTIALS_SQL = 'SELECT [[credential_id]] FROM {{%job_template_credential}}'
        . ' WHERE [[job_template_id]] = :id ORDER BY [[sort_order]], [[credential_id]]';

    /**
     * Whether the lock keeps $userId from changing what $template runs
     * (notice() has a text). For the template's page and edit form (the
     * notice and the disabled fields).
     */
    public function appliesTo(int $userId, JobTemplate $template): bool
    {
        return $this->notice($userId, $template) !== null;
    }

    /**
     * The notice for $userId on the page and edit form of $template, or null
     * when the lock does not keep them from changing what it runs: null for
     * a template not saved yet, for an ungated one and for users who may
     * assign approval rules; else NOTICE when its stored rule exists, or
     * NOTICE_INVENTORY when its stored inventory has a rule. Reads the
     * stored values as loaded, the inventory's rule as stored, without a
     * lock.
     */
    public function notice(int $userId, JobTemplate $template): ?string
    {
        if ($template->isNewRecord) {
            return null;
        }
        $inventoryId = self::positiveId($template->getOldAttribute('inventory_id')) ?? 0;
        $source = GatedInventoryChoice::storedSource(
            $template->getOldAttribute('approval_rule_id'),
            $inventoryId,
            JobTemplateGate::readInventoryRules([$inventoryId], false)
        );

        return $source === null || $this->policy()->mayAssign($userId) ? null : self::NOTICES[$source];
    }

    /**
     * Whether the lock refuses the save of $template's change by $actorId,
     * or by the signed-in user when $actorId is null (the user the audit
     * entry names; console runs have none, and the lock does not concern
     * them). A refusal adds its reason (CHANGE_REFUSED,
     * CHANGE_REFUSED_INVENTORY or GATED_INVENTORY_REFUSED) to the template's
     * errors under ERROR_KEY (refused() tells, refusalOf() says which).
     *
     * @param list<mixed>|null $additionalIds the additional credentials the
     *     save stores, in order; null keeps the stored ones
     * @param bool $forUpdate read the template's row FOR UPDATE, then its
     *     stored and its new inventory LOCK IN SHARE MODE (a new template:
     *     its inventory, first): inside the save's transaction, as its first
     *     statements
     */
    public function vetoes(JobTemplate $template, ?array $additionalIds, ?int $actorId, bool $forUpdate): bool
    {
        $actorId ??= self::signedInUserId();
        $refusal = $actorId === null ? null : $this->refusal($actorId, $template, $additionalIds, $forUpdate);
        if ($refusal === null) {
            return false;
        }
        $template->addError(self::ERROR_KEY, $refusal);

        return true;
    }

    /**
     * Whether the lock refused the last save of $template (vetoes()).
     */
    public static function refused(JobTemplate $template): bool
    {
        return $template->getErrors(self::ERROR_KEY) !== [];
    }

    /**
     * Why the lock refused the last save of $template (vetoes()): the web
     * and the API answer 403 with it. Null when it did not refuse.
     */
    public static function refusalOf(JobTemplate $template): ?string
    {
        return $template->getFirstError(self::ERROR_KEY);
    }

    /**
     * Why $userId may not save the change of $template, or null when they
     * may. A new template is refused only an inventory with an approval
     * rule (GatedInventoryChoice::refusal()); whether it may get a rule of
     * its own is ApprovalRuleAssignmentPolicy's to say. A stored one is
     * decided on its stored row: an ungated one is refused only a new
     * inventory with a rule; a gated one, for users who may not assign
     * approval rules, every change of a column but the open ones or of its
     * credential list, with the text of what gates it (CHANGE_REFUSALS).
     * Re-pointing a gated template to an inventory without a rule is a
     * change of a column, so it is refused too.
     *
     * @param list<mixed>|null $additionalIds
     */
    private function refusal(int $userId, JobTemplate $template, ?array $additionalIds, bool $forUpdate): ?string
    {
        $inventoryId = self::positiveId($template->getAttribute('inventory_id')) ?? 0;
        if ($template->isNewRecord) {
            // Its one inventory, first: a new template has no row to lock.
            $rules = JobTemplateGate::readInventoryRules([$inventoryId], $forUpdate);

            return GatedInventoryChoice::refusal($userId, $template, null, $rules);
        }
        // The row the save updates (its stored ID), first: inside the save's
        // transaction, nothing is read before its lock.
        $templateId = (int)$template->getOldAttribute('id');
        $stored = self::storedRow($templateId, $forUpdate);
        if ($stored === null) {
            return null;
        }
        // Then the stored and the new inventory, in one statement, in
        // ascending ID order: no later statement locks another inventory row.
        $storedInventoryId = self::positiveId($stored['inventory_id'] ?? null) ?? 0;
        $rules = JobTemplateGate::readInventoryRules([$storedInventoryId, $inventoryId], $forUpdate);
        $source = GatedInventoryChoice::storedSource($stored['approval_rule_id'] ?? null, $storedInventoryId, $rules);
        if ($source === null) {
            return GatedInventoryChoice::refusal($userId, $template, $storedInventoryId, $rules);
        }
        if ($this->policy()->mayAssign($userId)) {
            return null;
        }
        $storedPrimary = self::positiveId($stored['credential_id'] ?? null);
        $changes = self::changesColumns($template, $stored)
            || ($additionalIds !== null && self::changesCredentials($template, $templateId, $storedPrimary, $additionalIds));

        return $changes ? self::CHANGE_REFUSALS[$source] : null;
    }

    /**
     * The template's stored row, read FOR UPDATE when $forUpdate; null when
     * it no longer exists.
     *
     * @return array<string, mixed>|null
     */
    private static function storedRow(int $templateId, bool $forUpdate): ?array
    {
        $row = \Yii::$app->db
            ->createCommand(self::ROW_SQL . ($forUpdate ? ' FOR UPDATE' : ''), [':id' => $templateId])
            ->queryOne();

        return is_array($row) ? $row : null;
    }

    /**
     * Whether the save writes another value than $stored holds into a column
     * that is not open.
     *
     * @param array<string, mixed> $stored the template's stored row
     */
    private static function changesColumns(JobTemplate $template, #[\SensitiveParameter] array $stored): bool
    {
        foreach (array_keys($template->getDirtyAttributes()) as $name) {
            $name = (string)$name;
            if (in_array($name, self::OPEN_ATTRIBUTES, true)) {
                continue;
            }
            if (
                !array_key_exists($name, $stored)
                || self::comparable($name, $template->getAttribute($name)) !== self::comparable($name, $stored[$name])
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A column's value as the comparison sees it: the extra vars and the
     * survey by jsonKey(), every other column after its type cast; a float
     * that no int holds, in an integer column, as it is (noInt()).
     */
    private static function comparable(string $name, #[\SensitiveParameter] mixed $value): mixed
    {
        if (in_array($name, self::JSON_ATTRIBUTES, true)) {
            return self::jsonKey(is_scalar($value) ? (string)$value : null);
        }
        $column = JobTemplate::getTableSchema()->getColumn($name);
        if ($column === null || ($column->phpType === 'integer' && self::noInt($value))) {
            return $value;
        }

        return $column->phpTypecast($value);
    }

    /**
     * Whether $number, an integer column's value, is a float that no int
     * holds: one beyond the int range, or infinity, as a JSON body gives
     * 1e30, 9223372036854775808 and 1e999. The column's type cast is an
     * (int) cast, which warns on it since PHP 8.5; as it is, it differs from
     * every value the column stores.
     */
    private static function noInt(mixed $number): bool
    {
        return is_float($number) && !($number >= -self::INT_RANGE_END && $number < self::INT_RANGE_END);
    }

    /**
     * The comparison key of an extra vars or survey text: '' for none (null
     * or ''); else the text decoded and encoded again, twice, once with whole
     * numbers beyond PHP's int range as numbers and once as their digits, so
     * that neither two such numbers nor such a number and the string of its
     * digits compare equal; TEXT_KEY and the text itself for a text that is
     * no JSON, or holds a number too large for JSON.
     */
    private static function jsonKey(#[\SensitiveParameter] ?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        $keys = [];
        foreach ([0, JSON_BIGINT_AS_STRING] as $flags) {
            $decoded = json_decode($text, false, 512, $flags);
            $encoded = json_last_error() === JSON_ERROR_NONE ? json_encode($decoded, self::JSON_FLAGS) : false;
            if ($encoded === false) {
                return self::TEXT_KEY . $text;
            }
            $keys[] = $encoded;
        }

        return implode("\0", $keys);
    }

    /**
     * Whether the credential list the save stores differs from the one
     * template #$templateId has: the primary first, then the additional
     * ones in their order, each once. Each additional one is read as the
     * save reads it (positiveId(), as JobTemplateCredentialService does):
     * the stored IDs written another way ("05") are no change, and a
     * submitted value that is no credential ID, which the save refuses,
     * counts as a change.
     *
     * @param list<mixed> $additionalIds
     */
    private static function changesCredentials(JobTemplate $template, int $templateId, ?int $storedPrimary, array $additionalIds): bool
    {
        $primary = self::positiveId($template->credential_id);
        $submitted = $primary === null ? [] : [$primary];
        foreach ($additionalIds as $candidate) {
            $id = self::positiveId($candidate);
            if ($id === null) {
                return true;
            }
            $submitted[] = $id;
        }
        $stored = array_map('intval', \Yii::$app->db->createCommand(self::CREDENTIALS_SQL, [':id' => $templateId])->queryColumn());
        if ($storedPrimary !== null) {
            array_unshift($stored, $storedPrimary);
        }

        return array_values(array_unique($submitted)) !== array_values(array_unique($stored));
    }

    /**
     * $number as an ID: a positive whole number as the integer rule reads it
     * (SubmittedId), as a template's integer columns store it; "", 0 and
     * anything the rule refuses (a number beyond the int range included) are
     * none.
     */
    private static function positiveId(mixed $number): ?int
    {
        $id = SubmittedId::of($number) ?? 0;

        return $id > 0 ? $id : null;
    }

    private static function signedInUserId(): ?int
    {
        return \Yii::$app->has('user') && !\Yii::$app->user->isGuest ? (int)\Yii::$app->user->id : null;
    }

    private function policy(): ApprovalRuleAssignmentPolicy
    {
        /** @var ApprovalRuleAssignmentPolicy $policy */
        $policy = \Yii::$app->get('approvalRuleAssignmentPolicy');

        return $policy;
    }
}
