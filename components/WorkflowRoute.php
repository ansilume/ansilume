<?php

declare(strict_types=1);

namespace app\components;

/**
 * Where one branch of a workflow step leads, as the route map
 * (app\services\WorkflowRouteMap) resolves it the way the engine does
 * (WorkflowStepRouter): to a step of the workflow, to the end of the run,
 * or to a step that no longer exists, which fails the run.
 *
 * via names what decided it: the On Success, On Failure or Always branch, or
 * an empty branch, which goes on with the next step in the run order
 * ("next"; after the last step the run ends with the step's outcome).
 */
final class WorkflowRoute
{
    /** It leads to a step of the workflow. */
    public const KIND_STEP = 'step';

    /** The run ends here. */
    public const KIND_END = 'end';

    /** It leads to a step that no longer exists: the run fails. */
    public const KIND_MISSING = 'missing';

    public const VIA_ON_SUCCESS = 'on_success';
    public const VIA_ON_FAILURE = 'on_failure';
    public const VIA_ON_ALWAYS = 'on_always';

    /** The branch is empty: the next step in the run order. */
    public const VIA_NEXT = 'next';

    /** The run ends as succeeded. */
    public const RUN_SUCCEEDS = 'succeeds';

    /** The run ends as failed. */
    public const RUN_FAILS = 'fails';

    /**
     * @param 'step'|'end'|'missing' $kind
     * @param 'on_success'|'on_failure'|'on_always'|'next' $via
     * @param int|null $stepId the step it leads to (step), or the ID that no longer
     *     exists (missing); null for the end
     * @param int|null $position the 1-based position of the step it leads to (step only)
     * @param 'succeeds'|'fails'|null $runOutcome how the run ends (end and missing); null
     *     when it leads to a step, and for an Always branch of a job step that ends the run,
     *     which ends it with the step's outcome
     * @param bool $loop whether it leads back to the step itself or an earlier one
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $via,
        public readonly ?int $stepId,
        public readonly ?int $position,
        public readonly ?string $runOutcome,
        public readonly bool $loop,
    ) {
    }

    /**
     * Whether this and $other lead to the same place: the same step, the
     * same missing step, or the end with the same run outcome, whichever
     * branch decided it.
     */
    public function sameTarget(self $other): bool
    {
        return $this->kind === $other->kind
            && $this->stepId === $other->stepId
            && $this->runOutcome === $other->runOutcome;
    }

    /**
     * The route as the API writes it (WorkflowRouteTarget).
     *
     * @return array{kind: string, via: string, step_id: int|null, position: int|null, run_outcome: string|null, loop: bool}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'via' => $this->via,
            'step_id' => $this->stepId,
            'position' => $this->position,
            'run_outcome' => $this->runOutcome,
            'loop' => $this->loop,
        ];
    }
}
