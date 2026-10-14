<?php

declare(strict_types=1);

namespace app\services;

use app\components\WorkflowOrderEffects;
use app\components\WorkflowRouteStep;
use app\models\WorkflowStep;

/**
 * What a change of a workflow's steps did, for WorkflowRouteMap
 * (effectsSince(); call that): the route maps before and after the change,
 * compared the way the engine routes a run (WorkflowOrderEffects).
 *
 * Regression: a job step counted as guarded when it came after the first
 * approval step by position, whatever the branches said. An approval step
 * placed after a step whose On Success (or Always) names another step is
 * skipped by every run that succeeds, and no warning said so; nor did one
 * when an edit of a branch let runs reach a job step without passing the
 * approval step.
 *
 * @internal
 */
final class WorkflowRouteEffects
{
    /**
     * The effects of the change from $before to $after. $newStepId: the ID
     * of the step the change added, if any; $changedStepId: the ID of the
     * step it added, moved or edited, if any.
     */
    public static function between(
        WorkflowRouteMap $before,
        WorkflowRouteMap $after,
        ?int $newStepId,
        ?int $changedStepId
    ): WorkflowOrderEffects {
        $approval = self::firstApproval($after);

        return new WorkflowOrderEffects(
            $before->start()?->id,
            $after->start()?->id,
            $approval?->id,
            self::unguardedJobSteps($before, $after, $approval === null ? null : $newStepId),
            self::skippedApprovalSteps($before, $after, $changedStepId)
        );
    }

    /**
     * The job steps of $after, by position, that runs reach without passing
     * an approval step: those that were guarded in $before (runs reached
     * them only through an approval step), and the new step $newStepId.
     *
     * @return list<int>
     */
    private static function unguardedJobSteps(WorkflowRouteMap $before, WorkflowRouteMap $after, ?int $newStepId): array
    {
        $unguarded = [];
        foreach ($after->steps() as $step) {
            $exposed = $step->id === $newStepId || self::isGuarded($before, $step->id);
            if ($exposed && $step->type === WorkflowStep::TYPE_JOB && $after->isReachableWithoutApproval($step->id)) {
                $unguarded[] = $step->id;
            }
        }

        return $unguarded;
    }

    /**
     * Whether runs reach the step $stepId of $map, and only through an
     * approval step that was approved.
     */
    private static function isGuarded(WorkflowRouteMap $map, int $stepId): bool
    {
        return $map->isReachable($stepId) && !$map->isReachableWithoutApproval($stepId);
    }

    /**
     * The approval steps of $after, by position, that are not on its
     * success path, but were on the success path of $before or are the
     * step $changedStepId.
     *
     * @return list<int>
     */
    private static function skippedApprovalSteps(WorkflowRouteMap $before, WorkflowRouteMap $after, ?int $changedStepId): array
    {
        $now = array_flip($after->successPath()['step_ids']);
        $was = array_flip($before->successPath()['step_ids']);
        $skipped = [];
        foreach ($after->steps() as $step) {
            $concerned = $step->id === $changedStepId || isset($was[$step->id]);
            if ($concerned && $step->type === WorkflowStep::TYPE_APPROVAL && !isset($now[$step->id])) {
                $skipped[] = $step->id;
            }
        }

        return $skipped;
    }

    /**
     * The first approval step of $map by position (the type compared
     * exactly); null when it has none.
     */
    private static function firstApproval(WorkflowRouteMap $map): ?WorkflowRouteStep
    {
        foreach ($map->steps() as $step) {
            if ($step->type === WorkflowStep::TYPE_APPROVAL) {
                return $step;
            }
        }

        return null;
    }
}
