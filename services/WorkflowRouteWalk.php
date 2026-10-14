<?php

declare(strict_types=1);

namespace app\services;

use app\components\WorkflowRoute;
use app\components\WorkflowRouteStep;
use app\models\WorkflowStep;

/**
 * Follows the branches of a route map, for WorkflowRouteMap (isReachable(),
 * isReachableWithoutApproval() and successPath(); call those): which steps
 * a run can reach from the start step, which of them it can reach without
 * passing an approval step, and which steps it passes when every step
 * succeeds.
 *
 * A step that cannot run (WorkflowRouteStep::$problem: a missing job
 * template or approval rule, an unknown type) fails the run without
 * following a branch, as the engine does (WorkflowExecutionService::
 * failStep()), so no walk goes on from it.
 *
 * @internal
 */
final class WorkflowRouteWalk
{
    /**
     * The IDs of the steps of $map a run can reach: the start step, and
     * every step a branch leads to that some outcome of a reachable step
     * that can run takes.
     *
     * Regression: the steps after a step that cannot run counted as
     * reachable through its branches, which no run takes.
     *
     * @return array<int, true>
     */
    public static function reachable(WorkflowRouteMap $map): array
    {
        return self::walk($map, false);
    }

    /**
     * The IDs of the steps of $map a run can reach without passing an
     * approval step: as reachable(), but the branch an approval step takes
     * once it is approved is not followed. After a closed gate (rejected,
     * timed out or canceled) its On Failure is, as that passes no approval.
     *
     * @return array<int, true>
     */
    public static function reachableWithoutApproval(WorkflowRouteMap $map): array
    {
        return self::walk($map, true);
    }

    /**
     * The success path of $map (see WorkflowRouteMap::successPath()).
     *
     * @return array{
     *     step_ids: list<int>,
     *     end: 'succeeds'|'loop'|'missing'|'cannot_run'|null,
     *     loop_to_step_id: int|null,
     *     missing_step_id: int|null
     * }
     */
    public static function successPath(WorkflowRouteMap $map): array
    {
        $ids = [];
        $step = $map->start();
        while ($step !== null) {
            $ids[] = $step->id;
            $route = $step->problem === null ? $map->route($step, WorkflowRouteMap::OUTCOME_SUCCESS) : null;
            if ($route === null) {
                return self::path($ids, WorkflowRouteMap::PATH_CANNOT_RUN);
            }
            if ($route->kind !== WorkflowRoute::KIND_STEP) {
                return $route->kind === WorkflowRoute::KIND_END
                    ? self::path($ids, WorkflowRouteMap::PATH_SUCCEEDS)
                    : self::path($ids, WorkflowRouteMap::PATH_MISSING, null, $route->stepId);
            }
            if (in_array($route->stepId, $ids, true)) {
                return self::path($ids, WorkflowRouteMap::PATH_LOOP, $route->stepId);
            }
            $step = $map->find((int)$route->stepId);
        }

        return self::path($ids, null);
    }

    /**
     * The IDs of the steps of $map that runs reach from the start step;
     * with $approvalStops, without passing an approval step that was
     * approved.
     *
     * @return array<int, true>
     */
    private static function walk(WorkflowRouteMap $map, bool $approvalStops): array
    {
        $start = $map->start();
        if ($start === null) {
            return [];
        }
        $reached = [$start->id => true];
        $queue = [$start];
        $next = 0;
        while (isset($queue[$next])) {
            $step = $queue[$next++];
            foreach (self::outcomesFollowed($map, $step, $approvalStops) as $outcome) {
                $route = $map->route($step, $outcome);
                $target = $route !== null && $route->kind === WorkflowRoute::KIND_STEP ? $map->find((int)$route->stepId) : null;
                if ($target !== null && !isset($reached[$target->id])) {
                    $reached[$target->id] = true;
                    $queue[] = $target;
                }
            }
        }

        return $reached;
    }

    /**
     * The outcomes of $step whose branches a walk follows: none for a step
     * that cannot run; with $approvalStops, an approval step's without its
     * success.
     *
     * @return list<'success'|'failure'|'closed_gate'>
     */
    private static function outcomesFollowed(WorkflowRouteMap $map, WorkflowRouteStep $step, bool $approvalStops): array
    {
        if ($step->problem !== null) {
            return [];
        }
        $outcomes = $map->outcomes($step);
        if ($approvalStops && $step->type === WorkflowStep::TYPE_APPROVAL) {
            return array_values(array_diff($outcomes, [WorkflowRouteMap::OUTCOME_SUCCESS]));
        }

        return $outcomes;
    }

    /**
     * The success path that ends with $end.
     *
     * @param list<int> $ids
     * @param 'succeeds'|'loop'|'missing'|'cannot_run'|null $end
     * @return array{
     *     step_ids: list<int>,
     *     end: 'succeeds'|'loop'|'missing'|'cannot_run'|null,
     *     loop_to_step_id: int|null,
     *     missing_step_id: int|null
     * }
     */
    private static function path(array $ids, ?string $end, ?int $loopTo = null, ?int $missing = null): array
    {
        return ['step_ids' => $ids, 'end' => $end, 'loop_to_step_id' => $loopTo, 'missing_step_id' => $missing];
    }
}
