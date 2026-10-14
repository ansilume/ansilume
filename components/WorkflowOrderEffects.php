<?php

declare(strict_types=1);

namespace app\components;

/**
 * What a change of a workflow's steps did that the user should be told
 * about (app\services\WorkflowRouteMap::effectsSince()), worked out from the
 * branches the way the engine routes a run: which step runs start with
 * before and after it, which job steps runs now reach without passing an
 * approval step, and which approval steps runs whose steps all succeed do
 * not pass (they are not on the success path).
 *
 * A job step is guarded when runs reach it and every route from the start
 * step to it passes an approval step that was approved; the On Failure
 * branch an approval step takes after a closed gate passes no approval.
 */
final class WorkflowOrderEffects
{
    /**
     * @param int|null $startBefore the ID of the start step before the change; null without steps
     * @param int|null $startAfter the ID of the start step after it; null without steps
     * @param int|null $firstApprovalAfter the ID of the first approval step after it, by position;
     *     null when the workflow has none
     * @param list<int> $unguardedJobStepIds by position after the change: the job steps that were
     *     guarded before and that runs now reach without passing an approval step, and a new job
     *     step that runs reach without passing one while the workflow has an approval step
     * @param list<int> $skippedApprovalStepIds by position after the change: the approval steps
     *     that are not on the success path after it, so that runs whose steps all succeed do not
     *     pass them, while they were on it before or are the step the change added, moved or
     *     edited
     */
    public function __construct(
        public readonly ?int $startBefore,
        public readonly ?int $startAfter,
        public readonly ?int $firstApprovalAfter,
        public readonly array $unguardedJobStepIds,
        public readonly array $skippedApprovalStepIds = [],
    ) {
    }

    /**
     * Whether runs start with another step now. Not when the workflow had no
     * steps before (the first step added) or has none now (the last one
     * removed).
     */
    public function startChanged(): bool
    {
        return $this->startBefore !== null && $this->startAfter !== null && $this->startBefore !== $this->startAfter;
    }
}
