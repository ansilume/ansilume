<?php

declare(strict_types=1);

namespace app\services;

use app\components\WorkflowRouteStep;
use app\models\JobTemplate;
use app\models\WorkflowStep;

/**
 * The Target cell of a row of the steps table (WorkflowStepsTable): the job
 * template of a job step, the approval rule of an approval step, linked; or
 * why a run that reaches the step fails there.
 *
 * Only the target of the step's type is shown: steps saved by older versions
 * can carry another one, such as a job template on a pause step, possibly of
 * a project the user may not see.
 *
 * @phpstan-type Target array{text: string, route: array<int|string, int|string>|null, problem: string|null}
 * @internal
 */
final class WorkflowStepTargetCell
{
    /**
     * The Target cell of $step, whose model $model has its job template
     * (with that template's approval rule, its inventory and that
     * inventory's approval rule) and its approval rule loaded: text and
     * route of the link (route null: no link), and the problem (null when
     * the step can run).
     *
     * @return Target
     */
    public static function of(WorkflowRouteStep $step, WorkflowStep $model): array
    {
        return match ($step->type) {
            WorkflowStep::TYPE_JOB => self::jobTemplate($step, $model),
            WorkflowStep::TYPE_APPROVAL => self::approvalRule($model),
            WorkflowStep::TYPE_PAUSE => self::cell(WorkflowStepsTable::NO_TARGET, null, null),
            default => self::cell('', null, WorkflowStepsTable::TARGET_UNKNOWN_TYPE),
        };
    }

    /**
     * A job step's job template; the relation leaves deleted templates out.
     * A gated job step whose approval rule no longer exists cannot run, nor
     * can one whose job template and inventory have different rules.
     *
     * @return Target
     */
    private static function jobTemplate(WorkflowRouteStep $step, WorkflowStep $model): array
    {
        $template = $model->jobTemplate;
        if ($template === null) {
            return self::cell('', null, self::gone(
                (int)$model->job_template_id,
                WorkflowStepsTable::TARGET_TEMPLATE_GONE,
                WorkflowStepsTable::TARGET_TEMPLATE_NONE
            ));
        }

        return self::cell(
            (string)$template->name,
            ['/job-template/view', 'id' => (int)$template->id],
            self::gateProblem($step, $template)
        );
    }

    /**
     * Why the gate of the job step $step keeps it from running, from its
     * job template $template: the gating rule is gone (its ID from the
     * inventory when the inventory gates the step, else from the job
     * template), or the job template and its inventory have different
     * rules. Null when nothing does.
     */
    private static function gateProblem(WorkflowRouteStep $step, JobTemplate $template): ?string
    {
        if ($step->problem === WorkflowRouteStep::PROBLEM_APPROVAL_RULES_DIFFER) {
            return WorkflowStepsTable::TARGET_RULES_DIFFER;
        }
        if ($step->problem !== WorkflowRouteStep::PROBLEM_APPROVAL_RULE_MISSING) {
            return null;
        }
        $gate = JobTemplateGate::of($template);
        $ruleId = $step->gatedBy === JobTemplateGate::SOURCE_INVENTORY ? $gate->inventoryRuleId : $gate->templateRuleId;

        return self::gone((int)$ruleId, WorkflowStepsTable::TARGET_RULE_GONE, WorkflowStepsTable::TARGET_RULE_NONE);
    }

    /**
     * @return Target
     */
    private static function approvalRule(WorkflowStep $model): array
    {
        $rule = $model->approvalRule;
        if ($rule === null) {
            return self::cell('', null, self::gone(
                (int)$model->approval_rule_id,
                WorkflowStepsTable::TARGET_RULE_GONE,
                WorkflowStepsTable::TARGET_RULE_NONE
            ));
        }

        return self::cell((string)$rule->name, ['/approval-rule/view', 'id' => (int)$rule->id], null);
    }

    /**
     * $gone with the ID $id a step stores, or $none when it stores none.
     */
    private static function gone(int $id, string $gone, string $none): string
    {
        return $id > 0 ? sprintf($gone, $id) : $none;
    }

    /**
     * @param array<int|string, int|string>|null $route
     * @return Target
     */
    private static function cell(string $text, ?array $route, ?string $problem): array
    {
        return ['text' => $text, 'route' => $route, 'problem' => $problem];
    }
}
