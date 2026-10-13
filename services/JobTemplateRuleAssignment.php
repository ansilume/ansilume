<?php

declare(strict_types=1);

namespace app\services;

use app\models\ApprovalRule;
use app\models\JobTemplate;

/**
 * The approval rule a request submits for a job template, for the web form
 * and the REST API alike. Choosing the rule that gates a template's launches
 * is for admins (ApprovalRuleAssignmentPolicy): any change by another user is
 * refused before anything is saved, also setting a rule on a new template
 * and removing one. Not submitting the field, or submitting the rule the
 * template has, is no change. A clone keeps its source's rule without a
 * check: copying is not assigning.
 *
 * approval_rule_id is no safe attribute of JobTemplate, so a form or a JSON
 * body never sets it on its own: only apply() does, after the check.
 */
class JobTemplateRuleAssignment
{
    /** The submitted field. */
    public const FIELD = 'approval_rule_id';

    /** The hint of the rule select on the template form. */
    public const FORM_HINT = 'Every launch waits until the rule\'s approvers approve it. '
        . 'Only admins (approval-rule.update) may assign or remove a rule.';

    /**
     * Apply the rule $submitted holds (the form's JobTemplate fields or the
     * JSON body), when it holds one, to $template. $baseline is the rule the
     * template has: the stored one on an update, null on a create.
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
     * and name (null for none). Empty when the rule stays as it is.
     *
     * @return array<string, array{from: array{id: int, name: string|null}|null, to: array{id: int, name: string|null}|null}>
     */
    public function auditContext(JobTemplate $template, ?int $baseline): array
    {
        $current = ApprovalRuleAssignmentPolicy::normalizeRuleId($template->getAttribute(self::FIELD));
        $ruleId = is_int($current) ? $current : null;
        if ($ruleId === $baseline) {
            return [];
        }

        return ['approval_rule' => ['from' => self::describe($baseline), 'to' => self::describe($ruleId)]];
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
