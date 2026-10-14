<?php

declare(strict_types=1);

namespace app\services;

use app\components\WorkflowRouteStep;
use app\models\WorkflowStep;

/**
 * The three branch selects of the step form (WorkflowStepFormView): their
 * choices, the label of the empty choice (the prompt) and the selected value.
 *
 * - On Success: "Next in the list", the end of the workflow (the run
 *   succeeds), and the steps.
 * - On Failure: "Next in the list", the end of the workflow (the run fails),
 *   and the steps. A new job step ends the workflow when it fails, so Add
 *   Step selects the end. An approval step's empty On Failure ends the run
 *   as failed, so Edit Step offers it as the only end choice; a stored 0,
 *   which does the same, keeps its value. Add Step's script detaches the
 *   second end choice when the type becomes approval.
 * - Always: not set, and the steps; no end, as an end is chosen in On Success
 *   and On Failure.
 *
 * Add Step offers the workflow's steps, Edit Step every step, later ones and
 * the step itself ("(this step)", a retry) included. A stored value that no
 * choice holds (0 on Always, a step that no longer exists) is offered too,
 * with its label, so that a save never drops it unseen; the model's rules
 * refuse a step that no longer exists. An approval or pause step's Always
 * branch from an older version is selected under On Success, where the save
 * stores it (WorkflowStepFormView::LEGACY_ALWAYS_NOTE).
 *
 * @phpstan-type RouteSelect array{items: array<int, string>, prompt: string|null, value: string|null}
 * @internal
 */
final class WorkflowStepFormRoutes
{
    /** The step types that took an Always branch only on success (approved, resumed). */
    private const SUCCESS_ONLY_TYPES = [WorkflowStep::TYPE_APPROVAL, WorkflowStep::TYPE_PAUSE];

    /**
     * @param WorkflowRouteStep|null $editing the step Edit Step changes; null for Add Step
     */
    public function __construct(
        private readonly WorkflowRouteMap $map,
        private readonly ?WorkflowRouteStep $editing,
    ) {
    }

    /**
     * The selects for $model, a step of type $type, whose "Next in the list"
     * is $successPrompt after a success and $failurePrompt after a failure.
     *
     * @return array{success: RouteSelect, failure: RouteSelect, always: RouteSelect}
     */
    public function of(WorkflowStep $model, string $type, string $successPrompt, string $failurePrompt): array
    {
        $steps = $this->stepItems();

        return [
            'success' => $this->success($model, $type, $successPrompt, $steps),
            'failure' => $this->failure($model, $type, $failurePrompt, $steps),
            'always' => $this->always($model, $steps),
        ];
    }

    /**
     * Whether $model, a step of type $type, has an Always branch from an
     * older version, which only an approval or pause step can have.
     */
    public static function hasLegacyAlways(WorkflowStep $model, string $type): bool
    {
        return in_array($type, self::SUCCESS_ONLY_TYPES, true) && self::value($model->on_always_step_id) !== null;
    }

    /**
     * @param array<int, string> $steps
     * @return RouteSelect
     */
    private function success(WorkflowStep $model, string $type, string $prompt, array $steps): array
    {
        $value = self::hasLegacyAlways($model, $type)
            ? self::value($model->on_always_step_id)
            : self::value($model->on_success_step_id);
        $items = [WorkflowStep::END_WORKFLOW => WorkflowStepFormView::END_SUCCEEDS_OPTION] + $this->missing($value) + $steps;

        return ['items' => $items, 'prompt' => $prompt, 'value' => $value];
    }

    /**
     * @param array<int, string> $steps
     * @return RouteSelect
     */
    private function failure(WorkflowStep $model, string $type, string $prompt, array $steps): array
    {
        $value = self::value($model->on_failure_step_id);
        if ($this->editing === null && $type === WorkflowStep::TYPE_JOB && $value === null) {
            $value = (string)WorkflowStep::END_WORKFLOW;
        }
        $end = [WorkflowStep::END_WORKFLOW => WorkflowStepFormView::END_FAILS_OPTION];
        if ($type !== WorkflowStep::TYPE_APPROVAL) {
            return ['items' => $end + $this->missing($value) + $steps, 'prompt' => $prompt, 'value' => $value];
        }
        if ($this->editing !== null && $value === (string)WorkflowStep::END_WORKFLOW) {
            return ['items' => $end + $steps, 'prompt' => null, 'value' => $value];
        }
        $ends = $this->editing === null ? $end : [];

        return ['items' => $ends + $this->missing($value) + $steps, 'prompt' => WorkflowStepFormView::END_FAILS_OPTION, 'value' => $value];
    }

    /**
     * @param array<int, string> $steps
     * @return RouteSelect
     */
    private function always(WorkflowStep $model, array $steps): array
    {
        $value = self::value($model->on_always_step_id);
        $end = $value === (string)WorkflowStep::END_WORKFLOW
            ? [WorkflowStep::END_WORKFLOW => WorkflowStepsTable::ROUTE_END_WITH_STEP]
            : [];

        return ['items' => $end + $this->missing($value) + $steps, 'prompt' => WorkflowStepFormView::ALWAYS_NONE_OPTION, 'value' => $value];
    }

    /**
     * The steps to choose, by ID, in run order: "3. Deploy", "2. Approve
     * (approval step)", and on Edit Step "1. Build (this step)".
     *
     * @return array<int, string>
     */
    private function stepItems(): array
    {
        $items = [];
        foreach ($this->map->steps() as $index => $step) {
            $items[$step->id] = $step->id === $this->editing?->id
                ? sprintf(WorkflowStepFormView::THIS_STEP_OPTION, WorkflowStepsTable::stepLabel($index + 1, $step->name))
                : WorkflowStepsTable::choiceLabelIn($this->map, $step->id);
        }

        return $items;
    }

    /**
     * The choice of a selected step that no longer exists; none otherwise.
     *
     * @return array<int, string>
     */
    private function missing(?string $value): array
    {
        $stepId = (int)$value;
        if ($stepId <= 0 || $this->map->position($stepId) !== null) {
            return [];
        }

        return [$stepId => sprintf(WorkflowStepsTable::ROUTE_MISSING, $stepId)];
    }

    /**
     * A branch value as the select compares it: null for none (the empty
     * choice), else its whole number as text ("012" is "12"); null for
     * anything else, such as a value the form never offers.
     */
    private static function value(mixed $value): ?string
    {
        if (is_int($value) && $value >= 0) {
            return (string)$value;
        }
        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }
        $digits = ltrim($value, '0');

        return $digits === '' ? (string)WorkflowStep::END_WORKFLOW : $digits;
    }
}
