<?php

declare(strict_types=1);

namespace app\services;

use app\components\WorkflowRoute;
use app\components\WorkflowRouteStep;
use app\models\WorkflowStep;

/**
 * "Steps that route here" on the page that edits a step
 * (WorkflowStepFormView::ROUTED_BY_TITLE): exactly the steps that keep the
 * step from being removed (WorkflowStepChangeGuard::stepsRoutingTo(), every
 * other step whose stored On Success, On Failure or Always names it), by
 * position (WorkflowStepsTable::STEP_LABEL), each with a note when no run
 * takes its branch to the step: an On Success, or a job step's On Failure,
 * that the Always branch overrides (ROUTED_BY_ALWAYS_WINS), a pause step's
 * On Failure (ROUTED_BY_PAUSE_FAILURE), and every branch of a step that
 * cannot run (ROUTED_BY_CANNOT_RUN, or ROUTED_BY_UNKNOWN_TYPE for a type no
 * save accepts).
 *
 * Regression: the list named only branches that runs take, so a step whose
 * removal was refused for a branch the Always branch overrides, or for a
 * pause step's On Failure, read "No other step routes here.".
 *
 * @phpstan-type RoutedBy array{label: string, note: string|null}
 * @internal
 */
final class WorkflowStepRoutedBy
{
    /**
     * The steps of $map whose IDs are $routingIds (the steps that route to
     * the step $stepId), in run order; an ID the map does not have, such as
     * a step added since the map was read, is left out.
     *
     * @param list<int> $routingIds
     * @return list<RoutedBy>
     */
    public static function of(WorkflowRouteMap $map, int $stepId, array $routingIds): array
    {
        $routing = array_flip($routingIds);
        $entries = [];
        foreach ($map->steps() as $index => $step) {
            if (isset($routing[$step->id])) {
                $entries[] = [
                    'label' => WorkflowStepsTable::stepLabel($index + 1, $step->name),
                    'note' => self::note($map, $step, $stepId),
                ];
            }
        }

        return $entries;
    }

    /**
     * Why no run takes a branch of $step to the step $stepId; null when a
     * run takes one.
     */
    private static function note(WorkflowRouteMap $map, WorkflowRouteStep $step, int $stepId): ?string
    {
        if ($map->outcomes($step) === []) {
            return WorkflowStepFormView::ROUTED_BY_UNKNOWN_TYPE;
        }
        if ($step->problem !== null) {
            return WorkflowStepFormView::ROUTED_BY_CANNOT_RUN;
        }
        if (self::takesBranchTo($map, $step, $stepId)) {
            return null;
        }

        return $step->type === WorkflowStep::TYPE_PAUSE && $step->onFailure === $stepId
            ? WorkflowStepFormView::ROUTED_BY_PAUSE_FAILURE
            : WorkflowStepFormView::ROUTED_BY_ALWAYS_WINS;
    }

    /**
     * Whether some outcome of $step takes a branch of it that names the step
     * $stepId (an empty branch to the next step names none).
     */
    private static function takesBranchTo(WorkflowRouteMap $map, WorkflowRouteStep $step, int $stepId): bool
    {
        foreach ($map->outcomes($step) as $outcome) {
            $route = $map->route($step, $outcome);
            if ($route !== null && $route->via !== WorkflowRoute::VIA_NEXT && $route->stepId === $stepId) {
                return true;
            }
        }

        return false;
    }
}
