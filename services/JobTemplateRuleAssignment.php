<?php

declare(strict_types=1);

namespace app\services;

use app\components\SubmittedId;
use app\components\SurveyDefinition;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\JobTemplate;

/**
 * The approval rule a request submits for a job template, for the web form
 * and the REST API alike. Choosing the rule that gates a template's launches
 * is for admins (ApprovalRuleAssignmentPolicy): any change by another user is
 * refused before anything is saved, also setting a rule on a new template
 * and removing one. Not submitting the field, or submitting the rule the
 * template has, is no change, in any spelling of its ID that the integer
 * rule reads as it: "+5", "5\n" and 5.0 are 5
 * (ApprovalRuleAssignmentPolicy::normalizeRuleId()). A clone keeps its
 * source's rule without a check: copying is not assigning.
 *
 * approval_rule_id is no safe attribute of JobTemplate, so a form or a JSON
 * body never sets it on its own: only apply() does, after the check.
 *
 * One rule per launch: a job template on an inventory with an approval rule
 * has no rule of its own, or the same one (RULES_DIFFER, an error of
 * approval_rule_id: web form, API 422). The template's validation checks it
 * (vetoesDifferentRules(), without a lock) when the save creates the
 * template or changes its rule or its inventory. The save's transaction
 * checks it again (persistVetoes()) on the pair the save stores: the
 * columns it writes as the template holds them, the other one as the
 * template row stores it under the save's row lock, so that a rule given to
 * the inventory, or to the template, and a re-point by another admin
 * meanwhile are seen; and an inventory deleted meanwhile is refused there
 * as not existing (INVENTORY_NOT_FOUND), before its foreign key would
 * refuse the save. A template whose stored pair already differs keeps its
 * name and description open; the launch refuses it
 * (JobLaunchService::RULES_DIFFER).
 */
class JobTemplateRuleAssignment
{
    /** The submitted field. */
    public const FIELD = 'approval_rule_id';

    /** The hint of the rule select on the template form. */
    public const FORM_HINT = 'Every launch waits until the rule\'s approvers approve it. '
        . 'Only admins (approval-rule.update) may assign or remove a rule.';

    /**
     * Why a template's own rule was refused: its inventory has another one.
     * sprintf(): the inventory's name and the rule's name, each as
     * SurveyDefinition::quote() gives it.
     */
    public const RULES_DIFFER = 'Inventory %1$s has the approval rule %2$s: a job template on it may have only that rule '
        . 'as its own, or none.';

    /**
     * The hint of the rule select for admins when the template's inventory
     * has a rule. sprintf(): the inventory's name and the rule's name, each
     * as SurveyDefinition::quote() gives it.
     */
    public const INVENTORY_RULE_HINT = 'Its inventory %1$s has the approval rule %2$s: every launch waits for it. '
        . 'Leave this rule empty, or choose the same one.';

    /**
     * Why a template save was refused because the inventory it stores is
     * gone: deleted after the template's validation, before the save's
     * transaction share-locked it (persistVetoes()). An error of
     * inventory_id (web form, API 422), with the text of the template's own
     * check that its inventory exists: the same answer as for an inventory
     * that never existed.
     */
    public const INVENTORY_NOT_FOUND = 'The selected inventory does not exist.';

    /**
     * The approval rule and the inventory a job template row stores, read
     * without a lock inside the save's transaction (pairToStore()).
     */
    private const STORED_PAIR_SQL = 'SELECT [[approval_rule_id]], [[inventory_id]] FROM {{%job_template}} WHERE [[id]] = :id';

    /**
     * Apply the rule $submitted holds (the form's JobTemplate fields or the
     * JSON body), when it holds one, to $template. $baseline is the rule the
     * template has: the stored one on an update, null on a create. The rule
     * is set as ApprovalRuleAssignmentPolicy::normalizeRuleId() reads it: a
     * whole number as the ID the column stores, anything else as sent, for
     * the integer rule to refuse.
     *
     * @param array<array-key, mixed> $submitted
     * @return string|null why $userId may not make this change (answer 403
     *     and save nothing); null when the rule was applied or not submitted
     */
    public function apply(JobTemplate $template, array $submitted, int $userId, ?int $baseline): ?string
    {
        if (!array_key_exists(self::FIELD, $submitted)) {
            return null;
        }
        $refusal = $this->policy()->templateRuleRefusal($userId, $submitted[self::FIELD], $baseline);
        if ($refusal === null) {
            $template->setAttribute(self::FIELD, ApprovalRuleAssignmentPolicy::normalizeRuleId($submitted[self::FIELD]));
        }

        return $refusal;
    }

    /**
     * Whether $userId may assign and remove rules: the form offers the rule
     * select only then, everyone else sees the rule as text.
     */
    public function mayAssign(int $userId): bool
    {
        return $this->policy()->mayAssign($userId);
    }

    /**
     * The rules the form offers, by ID: the name with the approvals it needs.
     *
     * @return array<int, string>
     */
    public function options(): array
    {
        $options = [];
        foreach (ApprovalRule::find()->orderBy(['name' => SORT_ASC, 'id' => SORT_ASC])->all() as $rule) {
            /** @var ApprovalRule $rule */
            $options[(int)$rule->id] = self::label($rule);
        }

        return $options;
    }

    /**
     * A rule as the form and the template page name it.
     */
    public static function label(ApprovalRule $rule): string
    {
        return sprintf('%s (%d approval(s))', $rule->name, (int)$rule->required_approvals);
    }

    /**
     * The audit context of a save that gives $template another rule than
     * $baseline: approval_rule with the rule before and after, each as ID
     * and name (null for none). Empty when the rule stays as it is. The rule
     * is read as the column stores it (SubmittedId, as InventoryWriteService
     * reads an inventory's), so that the entry names the rule that gates the
     * template: "+5" and 5.0 are rule 5.
     *
     * @return array<string, array{from: array{id: int, name: string|null}|null, to: array{id: int, name: string|null}|null}>
     */
    public function auditContext(JobTemplate $template, ?int $baseline): array
    {
        $ruleId = SubmittedId::of($template->getAttribute(self::FIELD));
        if ($ruleId === $baseline) {
            return [];
        }

        return ['approval_rule' => ['from' => self::describe($baseline), 'to' => self::describe($ruleId)]];
    }

    /**
     * Whether $template's own rule differs from the rule of its inventory,
     * both as the template holds them: then RULES_DIFFER is added to
     * approval_rule_id. A launch waits for one rule only
     * (JobLaunchService::RULES_DIFFER refuses such a launch). Nothing is
     * checked when approval_rule_id or inventory_id has an error already, or
     * the template has no rule of its own.
     *
     * The inventory's rule is read as stored
     * (JobTemplateGate::readInventoryRules()), share-locked with $shareLock:
     * inside a save's transaction, whose template lock
     * (GatedJobTemplateLock) has share-locked that row already, so the read
     * waits for nothing and locks no other row (C16). The template's
     * validation reads it without a lock; the save's transaction checks the
     * pair it stores instead (persistVetoes()).
     *
     * Both IDs are read as the integer rule reads them (SubmittedId), so the
     * check needs no earlier rule to have refused a value that is no ID,
     * such as a JSON number beyond the int range, whose (int) cast warns on
     * PHP 8.5: an own rule that is no ID is none here, its rule reports it.
     */
    public static function vetoesDifferentRules(JobTemplate $template, bool $shareLock): bool
    {
        $ownRuleId = SubmittedId::of($template->getAttribute(self::FIELD));
        if ($template->hasErrors(self::FIELD) || $template->hasErrors('inventory_id') || $ownRuleId === null) {
            return false;
        }
        $inventoryId = (int)SubmittedId::of($template->getAttribute('inventory_id'));

        return self::vetoesPair($template, [$ownRuleId, $inventoryId, false], $shareLock);
    }

    /**
     * The persist veto of a template save (JobTemplateCredentialService),
     * inside its transaction: the template lock first ($lock->vetoes() on
     * the template row FOR UPDATE, then its inventories LOCK IN SHARE MODE),
     * then the pair of own rule and inventory that the save stores
     * (pairToStore()), when it creates the template or changes either:
     * vetoesPair() refuses an inventory that is gone (INVENTORY_NOT_FOUND)
     * and an own rule other than the inventory's (RULES_DIFFER), reading
     * only the inventory row the lock share-locked. True when either
     * vetoes: nothing is saved then.
     *
     * @param list<mixed>|null $additionalIds the additional credentials the
     *     save stores, in order; null keeps the stored ones
     * @param int|null $actorId the user who saves; null: the signed-in user
     */
    public static function persistVetoes(GatedJobTemplateLock $lock, JobTemplate $template, ?array $additionalIds, ?int $actorId): bool
    {
        if ($lock->vetoes($template, $additionalIds, $actorId, true)) {
            return true;
        }
        $pair = self::pairToStore($template);

        return $pair !== null && self::vetoesPair($template, $pair, true);
    }

    /**
     * The own rule and the inventory the save of $template stores, when it
     * creates the template or changes either: save(false) writes the dirty
     * attributes, so a column the save writes counts as the template holds
     * it, the other one as the template row stores it now, which another
     * admin may have changed after the request loaded the template. The row
     * is read without a lock: inside the save's transaction the template
     * lock (GatedJobTemplateLock) holds it FOR UPDATE since the
     * transaction's first statement, so nothing changes it before the
     * commit, and the read sees it as locked. IDs compare by value ("5" is
     * 5, SubmittedId), so a column written with the value the row stores
     * changes nothing. Null when the save stores the pair the row stores,
     * or the row is gone (the save's UPDATE then writes nothing).
     *
     * @return array{0: int|null, 1: int, 2: bool}|null the own rule ID,
     *     the inventory ID, and whether the save creates the template or
     *     changes its inventory
     */
    private static function pairToStore(JobTemplate $template): ?array
    {
        if ($template->isNewRecord) {
            return [SubmittedId::of($template->getAttribute(self::FIELD)), (int)SubmittedId::of($template->getAttribute('inventory_id')), true];
        }
        $written = $template->getDirtyAttributes([self::FIELD, 'inventory_id']);
        if ($written === []) {
            return null;
        }
        $stored = \Yii::$app->db->createCommand(self::STORED_PAIR_SQL, [':id' => (int)$template->getOldAttribute('id')])->queryOne();
        if (!is_array($stored)) {
            return null;
        }
        $storedRuleId = SubmittedId::of($stored[self::FIELD]);
        $storedInventoryId = (int)SubmittedId::of($stored['inventory_id']);
        $toStore = $written + $stored;
        $ruleId = SubmittedId::of($toStore[self::FIELD]);
        $inventoryId = (int)SubmittedId::of($toStore['inventory_id']);
        if ($ruleId === $storedRuleId && $inventoryId === $storedInventoryId) {
            return null;
        }

        return [$ruleId, $inventoryId, $inventoryId !== $storedInventoryId];
    }

    /**
     * Whether the pair $pair of $template (own rule ID, inventory ID, and
     * whether the inventory must still exist) is refused, with the error
     * added to the template: an inventory that must exist and is gone
     * (INVENTORY_NOT_FOUND on inventory_id; a template keeps its stored
     * inventory, which its foreign key keeps from being deleted), or an own
     * rule that differs from the inventory's (RULES_DIFFER on
     * approval_rule_id). The inventory's rule is read as stored
     * (JobTemplateGate::readInventoryRules()), share-locked with
     * $shareLock; nothing is read when there is neither an own rule to
     * compare nor an inventory to look for.
     *
     * @param array{0: int|null, 1: int, 2: bool} $pair
     */
    private static function vetoesPair(JobTemplate $template, array $pair, bool $shareLock): bool
    {
        [$ownRuleId, $inventoryId, $mustExist] = $pair;
        if ($ownRuleId === null && !$mustExist) {
            return false;
        }
        $inventoryRules = JobTemplateGate::readInventoryRules([$inventoryId], $shareLock);
        if ($mustExist && !array_key_exists($inventoryId, $inventoryRules)) {
            $template->addError('inventory_id', self::INVENTORY_NOT_FOUND);

            return true;
        }
        $inventoryRuleId = $inventoryRules[$inventoryId] ?? null;
        if ($ownRuleId === null || $inventoryRuleId === null || $inventoryRuleId === $ownRuleId) {
            return false;
        }
        $inventoryName = Inventory::find()->select('name')->where(['id' => $inventoryId])->scalar();
        $template->addError(self::FIELD, sprintf(
            self::RULES_DIFFER,
            SurveyDefinition::quote((string)$inventoryName),
            SurveyDefinition::quote((string)(self::describe($inventoryRuleId)['name'] ?? ''))
        ));

        return true;
    }

    /**
     * @return array{id: int, name: string|null}|null
     */
    private static function describe(?int $ruleId): ?array
    {
        if ($ruleId === null) {
            return null;
        }
        $name = ApprovalRule::find()->select('name')->where(['id' => $ruleId])->scalar();

        return ['id' => $ruleId, 'name' => is_string($name) ? $name : null];
    }

    private function policy(): ApprovalRuleAssignmentPolicy
    {
        /** @var ApprovalRuleAssignmentPolicy $policy */
        $policy = \Yii::$app->get('approvalRuleAssignmentPolicy');

        return $policy;
    }
}
