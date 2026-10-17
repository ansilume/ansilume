<?php

declare(strict_types=1);

namespace app\services;

use app\components\SubmittedId;
use app\components\SurveyDefinition;
use app\models\ApprovalRule;
use app\models\Inventory;

/**
 * The approval rule a request submits for an inventory, for the web form and
 * the REST API alike: every launch of a job template that uses the inventory
 * waits for it (JobTemplateGate). Choosing it is for admins
 * (ApprovalRuleAssignmentPolicy, as for job templates): any change by another
 * user is refused before anything is saved, also setting a rule on a new
 * inventory and removing one. Not submitting the field, or submitting the
 * rule the inventory has, is no change.
 *
 * approval_rule_id is no safe attribute of Inventory, so a form or a JSON
 * body never sets it on its own: only apply() does, after the check, and it
 * reads the field itself, so the body goes no further.
 *
 * One approval rule per launch: an inventory gets no rule while a job
 * template on it has another rule of its own (conflictRefusal(),
 * RULES_DIFFER), which InventoryWriteService checks under the inventory's row
 * lock.
 */
final class InventoryRuleAssignment
{
    /** The submitted field. */
    public const FIELD = 'approval_rule_id';

    /** The hint of the rule select on the inventory form. */
    public const FORM_HINT = 'Every launch of a job template that uses this inventory waits until the rule\'s '
        . 'approvers approve it. Only admins (approval-rule.update) may assign or remove a rule.';

    /**
     * The validation error of a rule that job templates on the inventory
     * cannot share. sprintf(): the visible templates' names, quoted and
     * joined with ', ', then ' and ' and RULES_DIFFER_HIDDEN when some are
     * hidden (or only that part).
     */
    public const RULES_DIFFER = 'Job templates that use this inventory have another approval rule of their own: %s. '
        . 'A launch waits for one rule only, so give them this rule or none first.';

    /** The part of RULES_DIFFER for job templates the user may not see; sprintf(): their number. */
    public const RULES_DIFFER_HIDDEN = '%d in projects you cannot see';

    /** The badge of a gated inventory in the inventory list. */
    public const GATED_BADGE = 'needs approval';

    /** The title of that badge; sprintf(): the rule's name. */
    public const GATED_TITLE = 'Every launch of a job template that uses this inventory waits for approval (rule "%s").';

    /**
     * Apply the rule $submitted holds (the form's Inventory fields or the
     * JSON body), when it holds one, to $inventory. $baseline is the rule the
     * inventory has: the stored one on an update, null on a create.
     *
     * @param array<array-key, mixed> $submitted
     * @return string|null why $userId may not make this change (answer 403
     *     and save nothing); null when the rule was applied or not submitted
     */
    public function apply(Inventory $inventory, #[\SensitiveParameter] array $submitted, int $userId, ?int $baseline): ?string
    {
        if (!array_key_exists(self::FIELD, $submitted)) {
            return null;
        }
        $ruleId = $submitted[self::FIELD];
        $refusal = $this->policy()->templateRuleRefusal($userId, $ruleId, $baseline);
        if ($refusal === null) {
            $inventory->setAttribute(self::FIELD, ApprovalRuleAssignmentPolicy::normalizeRuleId($ruleId));
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
     * The rules the form offers, by ID, labeled as on the job template form.
     *
     * @return array<int, string>
     */
    public function options(): array
    {
        return (new JobTemplateRuleAssignment())->options();
    }

    /**
     * The rule an inventory has as stored: null for an inventory not saved
     * yet, or without a rule. The baseline of an update.
     */
    public static function storedRuleId(Inventory $inventory): ?int
    {
        $stored = $inventory->getOldAttribute(self::FIELD);

        return is_numeric($stored) ? (int)$stored : null;
    }

    /**
     * The audit context of a save that gives an inventory the rule $to
     * instead of $from: approval_rule with the rule before and after, each as
     * ID and name (null for none). Empty when the rule stays as it is.
     *
     * @return array<string, array{from: array{id: int, name: string|null}|null, to: array{id: int, name: string|null}|null}>
     */
    public function auditContext(?int $from, ?int $to): array
    {
        if ($from === $to) {
            return [];
        }

        return ['approval_rule' => ['from' => self::ruleOf($from), 'to' => self::ruleOf($to)]];
    }

    /**
     * Why $inventory may not have the rule it is given (RULES_DIFFER), or
     * null when it may: live job templates on it have another rule of their
     * own. The names are those $viewerId may see; the rest are counted.
     * Plain reads only (InventoryUsageService).
     */
    public function conflictRefusal(Inventory $inventory, int $viewerId): ?string
    {
        $ruleId = SubmittedId::of($inventory->getAttribute(self::FIELD));
        if ($ruleId === null) {
            return null;
        }
        $found = $this->usage()->templatesWithAnotherRule((int)$inventory->getAttribute('id'), $ruleId, $viewerId);
        if ($found['total'] === 0) {
            return null;
        }

        return sprintf(self::RULES_DIFFER, self::namesOf($found['names'], $found['total'] - count($found['names'])));
    }

    /**
     * The job templates RULES_DIFFER names: the visible ones quoted, then
     * how many are hidden.
     *
     * @param list<string> $names
     */
    private static function namesOf(array $names, int $hidden): string
    {
        $visible = implode(', ', array_map(static fn (string $name): string => SurveyDefinition::quote($name), $names));
        if ($hidden <= 0) {
            return $visible;
        }
        $hiddenPart = sprintf(self::RULES_DIFFER_HIDDEN, $hidden);

        return $visible === '' ? $hiddenPart : $visible . ' and ' . $hiddenPart;
    }

    /**
     * @return array{id: int, name: string|null}|null
     */
    private static function ruleOf(?int $ruleId): ?array
    {
        if ($ruleId === null) {
            return null;
        }
        $rule = ApprovalRule::findOne($ruleId);

        return ['id' => $ruleId, 'name' => $rule instanceof ApprovalRule ? (string)$rule->name : null];
    }

    private function policy(): ApprovalRuleAssignmentPolicy
    {
        /** @var ApprovalRuleAssignmentPolicy $policy */
        $policy = \Yii::$app->get('approvalRuleAssignmentPolicy');

        return $policy;
    }

    private function usage(): InventoryUsageService
    {
        /** @var InventoryUsageService $usage */
        $usage = \Yii::$app->get('inventoryUsageService');

        return $usage;
    }
}
