<?php

declare(strict_types=1);

namespace app\services;

use app\components\WorkflowRoute;
use app\components\WorkflowRouteStep;
use app\components\WorkflowStepPosition;
use app\models\WorkflowStep;

/**
 * The Position choices of the step form (WorkflowStepFormView), each with
 * what the page says about it before the form is sent: where the step would
 * run (preview), the warnings (another start step; a job step that runs
 * would reach without passing the approval step they pass now; an approval
 * step that would not be on the success path), and what "Next in the list"
 * would be for On Success and On Failure.
 *
 * Add Step offers the end (the default), the start, and after each step but
 * the last (after the last is the end). Edit Step offers to keep the step
 * where it is (the default), and only the places that move it
 * (WorkflowStepsTable::movesOf()).
 *
 * Every choice is simulated on the route map (WorkflowRouteMap::withPlacement()
 * and effectsSince()), which follows the branches as runs do; nothing is read
 * or written. The preview says that the step runs after the step above it
 * only when that step's success leads to it, and before the step below it
 * only when its own success leads there.
 *
 * Regression: the preview and the warnings went by position only. An
 * approval step placed after a step whose On Success named the next step
 * read 'Runs after "Build", before "Deploy".', with no warning, while every
 * run that succeeded skipped it.
 *
 * @phpstan-type PositionOption array{
 *     label: string,
 *     preview: string,
 *     warn: string,
 *     warnJob: string,
 *     warnApproval: string,
 *     successPrompt: string,
 *     failurePrompt: string
 * }
 * @internal
 */
final class WorkflowStepFormPositions
{
    /** Between two warnings of one choice. */
    private const WARNING_SEPARATOR = ' ';

    /**
     * The choices of Add Step on $map, by value; none without steps (the
     * new step is the only one).
     *
     * @return array<string, PositionOption>
     */
    public static function forAdd(WorkflowRouteMap $map): array
    {
        $steps = $map->steps();
        $last = end($steps);
        if ($last === false) {
            return [];
        }
        $new = new WorkflowRouteStep(WorkflowRouteStep::NEW_STEP_ID, '', WorkflowStep::TYPE_JOB, null, null, null);
        $options = [
            WorkflowStepPosition::KIND_END => self::addOption($map, $new, WorkflowStepPosition::KIND_END, sprintf(
                WorkflowStepFormView::POSITION_END,
                WorkflowStepsTable::choiceLabelIn($map, $last->id)
            )),
            WorkflowStepPosition::KIND_START => self::addOption($map, $new, WorkflowStepPosition::KIND_START, WorkflowStepFormView::POSITION_START),
        ];
        foreach (array_slice($steps, 0, -1) as $step) {
            $value = WorkflowStepPosition::WEB_AFTER_PREFIX . $step->id;
            $label = sprintf(WorkflowStepFormView::POSITION_AFTER, WorkflowStepsTable::choiceLabelIn($map, $step->id));
            $options[$value] = self::addOption($map, $new, $value, $label);
        }

        return $options;
    }

    /**
     * The choices of Edit Step for $step, a step of $map, by value.
     *
     * @return array<string, PositionOption>
     */
    public static function forEdit(WorkflowRouteMap $map, WorkflowRouteStep $step): array
    {
        $keep = sprintf(WorkflowStepFormView::POSITION_KEEP, (int)$map->position($step->id));
        $options = [WorkflowStepPosition::WEB_KEEP => self::editOption($map, $step, WorkflowStepPosition::WEB_KEEP, $keep)];
        $steps = $map->steps();
        $last = $steps[count($steps) - 1]->id;
        foreach (WorkflowStepsTable::movesOf($map, $step) as $value => $anchor) {
            $label = match ($value) {
                WorkflowStepPosition::KIND_START => WorkflowStepFormView::POSITION_START,
                WorkflowStepPosition::KIND_END => sprintf(WorkflowStepFormView::POSITION_END, WorkflowStepsTable::choiceLabelIn($map, $last)),
                default => sprintf(WorkflowStepFormView::POSITION_AFTER, WorkflowStepsTable::choiceLabelIn($map, (int)$anchor?->id)),
            };
            $options[$value] = self::editOption($map, $step, $value, $label);
        }

        return $options;
    }

    /**
     * A choice of Add Step: the start warning holds for a step of any type,
     * the approval warning only for a job step (warnJob), and the success
     * path warning only for an approval step (warnApproval). Whether the new
     * step is on the success path does not depend on its type: no branch of
     * a step before it changes with it.
     *
     * @return PositionOption
     */
    private static function addOption(WorkflowRouteMap $map, WorkflowRouteStep $new, string $value, string $label): array
    {
        $placed = $map->withPlacement($new, $value);
        $effects = $placed->effectsSince($map, $new->id);
        $warn = $effects->startChanged()
            ? sprintf(WorkflowStepFormView::WILL_START_THIS, self::name($map, $effects->startBefore))
            : '';
        $warnJob = in_array($new->id, $effects->unguardedJobStepIds, true)
            ? sprintf(WorkflowStepFormView::THIS_WILL_PRECEDE_APPROVAL, self::name($map, $effects->firstApprovalAfter))
            : '';
        $warnApproval = in_array($new->id, $placed->successPath()['step_ids'], true)
            ? ''
            : WorkflowStepFormView::THIS_APPROVAL_OFF_PATH;

        return self::option($placed, $new, $label, [$warn, $warnJob, $warnApproval]);
    }

    /**
     * A choice of Edit Step, whose warnings hold for the step's own type.
     *
     * @return PositionOption
     */
    private static function editOption(WorkflowRouteMap $map, WorkflowRouteStep $step, string $value, string $label): array
    {
        $placed = $map->withPlacement($step, $value);
        $effects = $placed->effectsSince($map, null, $step->id);
        $warnings = [];
        if ($effects->startChanged()) {
            $warnings[] = $effects->startAfter === $step->id
                ? sprintf(WorkflowStepFormView::WILL_START_THIS, self::name($map, $effects->startBefore))
                : sprintf(WorkflowStepFormView::WILL_START_OTHER, self::name($map, $effects->startAfter));
        }
        foreach ($effects->unguardedJobStepIds as $jobStepId) {
            $warnings[] = self::approvalWarning($map, $step, $jobStepId, (int)$effects->firstApprovalAfter);
        }
        foreach ($effects->skippedApprovalStepIds as $approvalId) {
            $warnings[] = $approvalId === $step->id
                ? WorkflowStepFormView::THIS_APPROVAL_OFF_PATH
                : sprintf(WorkflowStepsTable::WILL_SKIP_APPROVAL, self::name($map, $approvalId));
        }

        return self::option($placed, $step, $label, [implode(self::WARNING_SEPARATOR, $warnings), '', '']);
    }

    /**
     * The warning that the job step $jobStepId no longer waits for the
     * approval step $approvalId, said from $step, the step being edited.
     */
    private static function approvalWarning(WorkflowRouteMap $map, WorkflowRouteStep $step, int $jobStepId, int $approvalId): string
    {
        if ($jobStepId === $step->id) {
            return sprintf(WorkflowStepFormView::THIS_WILL_PRECEDE_APPROVAL, self::name($map, $approvalId));
        }
        if ($approvalId === $step->id) {
            return sprintf(WorkflowStepFormView::WILL_PRECEDE_THIS_APPROVAL, self::name($map, $jobStepId));
        }

        return sprintf(WorkflowStepsTable::WILL_PRECEDE_APPROVAL, self::name($map, $jobStepId), self::name($map, $approvalId));
    }

    /**
     * A choice that puts $step where it is in $placed, with the warnings
     * $warnings: for any type, for a job step, for an approval step.
     *
     * @param array{0: string, 1: string, 2: string} $warnings
     * @return PositionOption
     */
    private static function option(WorkflowRouteMap $placed, WorkflowRouteStep $step, string $label, array $warnings): array
    {
        $after = $placed->steps()[(int)$placed->position($step->id)] ?? null;

        return [
            'label' => $label,
            'preview' => self::preview($placed, $step),
            'warn' => $warnings[0],
            'warnJob' => $warnings[1],
            'warnApproval' => $warnings[2],
            'successPrompt' => $after === null
                ? WorkflowStepFormView::NEXT_OPTION_NONE_SUCCEEDS
                : sprintf(WorkflowStepFormView::NEXT_OPTION, $after->name),
            'failurePrompt' => $after === null
                ? WorkflowStepFormView::NEXT_OPTION_NONE_FAILS
                : sprintf(WorkflowStepFormView::NEXT_FAILURE_OPTION, $after->name),
        ];
    }

    /**
     * Where $step runs in $placed: after the step above it when a success
     * there leads to it, or what happens instead; before the step below it
     * (or last) when its own success leads there (ends the run).
     */
    private static function preview(WorkflowRouteMap $placed, WorkflowRouteStep $step): string
    {
        $steps = $placed->steps();
        $index = (int)$placed->position($step->id) - 1;
        $before = $steps[$index - 1] ?? null;
        $after = $steps[$index + 1] ?? null;
        if ($before === null) {
            return self::previewAtStart($placed, $step, $after);
        }
        $skipped = self::skippedAfter($placed, $before, $step);
        if ($skipped !== null) {
            return $skipped;
        }
        $success = $placed->route($step, WorkflowRouteMap::OUTCOME_SUCCESS);

        return match (true) {
            $after !== null && self::leadsTo($success, $after) => sprintf(WorkflowStepFormView::PREVIEW_BETWEEN, $before->name, $after->name),
            $after === null && $success?->kind === WorkflowRoute::KIND_END => sprintf(WorkflowStepFormView::PREVIEW_END, $before->name),
            default => sprintf(WorkflowStepFormView::PREVIEW_AFTER, $before->name),
        };
    }

    /**
     * Where $step runs at the start of $placed, before $after (none: null).
     */
    private static function previewAtStart(WorkflowRouteMap $placed, WorkflowRouteStep $step, ?WorkflowRouteStep $after): string
    {
        if ($after === null) {
            return WorkflowStepFormView::PREVIEW_ONLY;
        }

        return self::leadsTo($placed->route($step, WorkflowRouteMap::OUTCOME_SUCCESS), $after)
            ? sprintf(WorkflowStepFormView::PREVIEW_START, $after->name)
            : WorkflowStepFormView::PREVIEW_FIRST;
    }

    /**
     * What a run does after $before, the step right above $step in
     * $placed, when it does not go on with $step after a success there; null
     * when it does.
     */
    private static function skippedAfter(WorkflowRouteMap $placed, WorkflowRouteStep $before, WorkflowRouteStep $step): ?string
    {
        $route = $before->problem === null ? $placed->route($before, WorkflowRouteMap::OUTCOME_SUCCESS) : null;
        if ($route === null) {
            return sprintf(WorkflowStepFormView::PREVIEW_AFTER_BROKEN, $before->name);
        }
        if ($route->kind !== WorkflowRoute::KIND_STEP) {
            return sprintf(WorkflowStepFormView::PREVIEW_SKIPPED_END, $before->name);
        }

        return $route->stepId === $step->id
            ? null
            : sprintf(WorkflowStepFormView::PREVIEW_SKIPPED_TO, $before->name, self::name($placed, $route->stepId));
    }

    /**
     * Whether $route leads to the step $target.
     */
    private static function leadsTo(?WorkflowRoute $route, WorkflowRouteStep $target): bool
    {
        return $route !== null && $route->kind === WorkflowRoute::KIND_STEP && $route->stepId === $target->id;
    }

    /**
     * The name of the step with ID $stepId of $map; '' for none.
     */
    private static function name(WorkflowRouteMap $map, ?int $stepId): string
    {
        return $stepId === null ? '' : (string)$map->find($stepId)?->name;
    }
}
