<?php

declare(strict_types=1);

namespace app\services\runstate;

/**
 * A workflow run with its display state (WorkflowRunStates). A running run
 * takes the state of its current execution, the newest one that is still
 * running; a finished run keeps its stored status. Built eagerly, so reading
 * it runs no query.
 */
final class RunState
{
    /**
     * @param int $runId the run (workflow_job)
     * @param string $status the stored status (WorkflowJob::STATUS_*)
     * @param string $state the display state (Display::STATE_*)
     * @param string|null $waitingFor what the run waits for (Display::WAITING_FOR_*); null when it does not wait
     * @param StepState|null $current the newest running execution of a running run; null when the run
     *     has finished or runs none (between two steps)
     * @param list<StepState> $steps every execution, oldest first (WorkflowRunStates::forRun());
     *     empty from WorkflowRunStates::forRuns()
     */
    public function __construct(
        public readonly int $runId,
        public readonly string $status,
        public readonly string $state,
        public readonly ?string $waitingFor,
        public readonly ?StepState $current,
        public readonly array $steps,
    ) {
    }
}
