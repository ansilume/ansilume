<?php

declare(strict_types=1);

namespace app\services;

use app\models\ApprovalRule;
use app\models\JobTemplate;

/**
 * What a gated job template runs is for admins to change. A job template is
 * gated when its stored approval_rule_id names an existing rule. Only users
 * who may assign approval rules (approval-rule.update, see
 * ApprovalRuleAssignmentPolicy::mayAssign()) may change any other column of
 * it, the extra vars and the survey among them, or its ordered credential
 * list. Everyone who may change the template keeps changing its name and
 * description (OPEN_ATTRIBUTES), launching it, managing its trigger token,
 * cloning it (the clone keeps the rule, and so the lock) and deleting it.
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
 * stores: the primary first, then the additional ones in their order.
 *
 * JobTemplateCredentialService asks for every save a user makes, twice:
 * before validation, so that a refused change gets this answer first, and
 * inside the save's transaction, on the template row locked FOR UPDATE as
 * its first statement, so that a rule assigned meanwhile is seen. The changes
 * compared are those the save writes: the template's dirty attributes,
 * against the stored row.
 */
final class GatedJobTemplateLock
{
    /** The columns of a gated template that everyone who may change the template may still change. */
    public const OPEN_ATTRIBUTES = ['name', 'description'];

    /** Why a change was refused: web 403 (ForbiddenHttpException), API 403. */
    public const CHANGE_REFUSED = 'This job template has an approval rule: only admins may change what it runs '
        . '(approval-rule.update). You may change its name and description.';

    /** The notice on the template's page and edit form, for users the lock applies to. */
    public const NOTICE = 'This job template has an approval rule: only admins may change what it runs '
        . '(approval-rule.update). You may still change its name and description, launch it and manage its trigger token.';

    /** The key under which a save the lock refused carries CHANGE_REFUSED among the template's errors. */
    public const ERROR_KEY = 'gated_job_template';

    /** The columns compared as JSON values. */
    private const JSON_ATTRIBUTES = ['extra_vars', 'survey_fields'];

    /** How a decoded JSON value is encoded for the comparison: a float keeps its fraction, so 1.0 is not 1. */
    private const JSON_FLAGS = JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** Prefix of the comparison key of a text that is no JSON: only the same text has that key. */
    private const TEXT_KEY = "text\0";

    private const ROW_SQL = 'SELECT * FROM {{%job_template}} WHERE [[id]] = :id';

    private const CREDENTIALS_SQL = 'SELECT [[credential_id]] FROM {{%job_template_credential}}'
        . ' WHERE [[job_template_id]] = :id ORDER BY [[sort_order]], [[credential_id]]';

    /**
     * Whether the lock keeps $userId from changing what $template runs: the
     * template is saved, its stored rule exists, and the user may not assign
     * approval rules. For the template's page and edit form (the notice and
     * the disabled fields).
     */
    public function appliesTo(int $userId, JobTemplate $template): bool
    {
        return !$template->isNewRecord
            && self::ruleExists($template->getOldAttribute('approval_rule_id'))
            && !$this->policy()->mayAssign($userId);
    }

    /**
     * Whether the lock refuses the save of $template's change by $actorId,
     * or by the signed-in user when $actorId is null (the user the audit
     * entry names; console runs have none, and the lock does not concern
     * them). A refusal adds CHANGE_REFUSED to the template's errors under
     * ERROR_KEY (refused() tells).
     *
     * @param list<mixed>|null $additionalIds the additional credentials the
     *     save stores, in order; null keeps the stored ones
     * @param bool $forUpdate read the template's row FOR UPDATE: inside the
     *     save's transaction, as its first statement
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
     * Why $userId may not save the change of $template, or null when they
     * may: only a change of a gated template is refused, and only for users
     * who may not assign approval rules. A new template is no gated one:
     * whether it may get a rule is ApprovalRuleAssignmentPolicy's to say.
     *
     * @param list<mixed>|null $additionalIds
     */
    private function refusal(int $userId, JobTemplate $template, ?array $additionalIds, bool $forUpdate): ?string
    {
        if ($template->isNewRecord) {
            return null;
        }
        // The row the save updates (its stored ID), first: inside the save's
        // transaction, nothing is read before its lock.
        $templateId = (int)$template->getOldAttribute('id');
        $stored = self::storedRow($templateId, $forUpdate);
        if ($stored === null || !self::ruleExists($stored['approval_rule_id'] ?? null) || $this->policy()->mayAssign($userId)) {
            return null;
        }
        $storedPrimary = self::positiveId($stored['credential_id'] ?? null);
        $changes = self::changesColumns($template, $stored)
            || ($additionalIds !== null && self::changesCredentials($template, $templateId, $storedPrimary, $additionalIds));

        return $changes ? self::CHANGE_REFUSED : null;
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
     * Whether $ruleId names an existing approval rule.
     */
    private static function ruleExists(mixed $ruleId): bool
    {
        $id = self::positiveId($ruleId);

        return $id !== null && ApprovalRule::find()->where(['id' => $id])->exists();
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
     * survey by jsonKey(), every other column after its type cast.
     */
    private static function comparable(string $name, #[\SensitiveParameter] mixed $value): mixed
    {
        if (in_array($name, self::JSON_ATTRIBUTES, true)) {
            return self::jsonKey(is_scalar($value) ? (string)$value : null);
        }
        $column = JobTemplate::getTableSchema()->getColumn($name);

        return $column === null ? $value : $column->phpTypecast($value);
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
     * ones in their order, each once. A submitted value that is no
     * credential ID counts as a change.
     *
     * @param list<mixed> $additionalIds
     */
    private static function changesCredentials(JobTemplate $template, int $templateId, ?int $storedPrimary, array $additionalIds): bool
    {
        $primary = self::positiveId($template->credential_id);
        $submitted = $primary === null ? [] : [$primary];
        foreach ($additionalIds as $candidate) {
            $id = filter_var($candidate, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
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
     * $number as an ID: a positive integer, or a text of one ("" and 0 are
     * none), as a template's integer columns store it.
     */
    private static function positiveId(mixed $number): ?int
    {
        $id = is_numeric($number) ? (int)$number : 0;

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
