<?php

declare(strict_types=1);

namespace app\services;

use app\components\WorkflowRouteStep;
use app\components\WorkflowStepPosition;

/**
 * The run order of a workflow's steps as a list, for WorkflowRouteMap
 * (withPlacement(); call that): where a placement puts a step. It reads no
 * branches; what a change does to the runs is WorkflowRouteEffects'.
 *
 * @internal
 */
final class WorkflowRouteOrder
{
    /**
     * $steps with $step placed by $placement ('start', 'end', 'keep' or
     * 'after:<step ID>', read as the web form reads them): a step of $steps
     * is moved there, with $step's values; any other step is inserted.
     *
     * @param list<WorkflowRouteStep> $steps in run order
     * @return list<WorkflowRouteStep>
     * @throws \InvalidArgumentException for any other placement, keep for a step not in $steps,
     *     or after a step that is $step itself or not in $steps
     */
    public static function place(array $steps, WorkflowRouteStep $step, string $placement): array
    {
        $rest = array_values(array_filter(
            $steps,
            static fn (WorkflowRouteStep $other): bool => $other->id !== $step->id
        ));
        $index = self::placementIndex($steps, $step, $placement, $rest);

        return array_merge(array_slice($rest, 0, $index), [$step], array_slice($rest, $index));
    }

    /**
     * The index in $rest (the steps without $step) at which $placement puts
     * $step.
     *
     * @param list<WorkflowRouteStep> $steps
     * @param list<WorkflowRouteStep> $rest
     * @throws \InvalidArgumentException see place()
     */
    private static function placementIndex(array $steps, WorkflowRouteStep $step, string $placement, array $rest): int
    {
        if ($placement === WorkflowStepPosition::WEB_KEEP) {
            return self::existingIndex($steps, $step->id);
        }
        $position = WorkflowStepPosition::fromWeb($placement, true);
        if (!$position instanceof WorkflowStepPosition) {
            throw new \InvalidArgumentException(sprintf(WorkflowRouteMap::PLACEMENT_INVALID, $placement));
        }

        return match ($position->kind()) {
            WorkflowStepPosition::KIND_START => 0,
            WorkflowStepPosition::KIND_END => count($rest),
            default => self::afterIndex($step, (int)$position->stepId(), $rest),
        };
    }

    /**
     * The index in $rest right after the step with ID $anchorId.
     *
     * @param list<WorkflowRouteStep> $rest
     * @throws \InvalidArgumentException when $anchorId is $step's own ID or not in $rest
     */
    private static function afterIndex(WorkflowRouteStep $step, int $anchorId, array $rest): int
    {
        if ($anchorId === $step->id) {
            throw new \InvalidArgumentException(sprintf(WorkflowRouteMap::AFTER_ITSELF, $anchorId));
        }

        return self::existingIndex($rest, $anchorId) + 1;
    }

    /**
     * The index of the step with ID $stepId in $steps.
     *
     * @param list<WorkflowRouteStep> $steps
     * @throws \InvalidArgumentException when $steps has no such step
     */
    private static function existingIndex(array $steps, int $stepId): int
    {
        foreach ($steps as $index => $step) {
            if ($step->id === $stepId) {
                return $index;
            }
        }

        throw new \InvalidArgumentException(sprintf(WorkflowRouteMap::NOT_IN_MAP, $stepId));
    }
}
