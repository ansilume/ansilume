<?php

declare(strict_types=1);

namespace app\services;

use app\components\WorkflowOrderEffects;
use app\components\WorkflowRoute;
use app\components\WorkflowRouteStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;

/**
 * Where every branch of a workflow's steps leads, worked out the way the
 * engine routes a run (WorkflowStepRouter::route() and
 * WorkflowExecutionService::advance()), without running anything: the one
 * source of the route labels, the success path, the "unreachable" marker,
 * the API's route fields, and the messages and warnings about a change of
 * the steps and the previews of one.
 *
 * The steps are kept in run order (by step_order, ties by ID; see
 * WorkflowTemplate::getSteps()); a step's position is its 1-based index in
 * it, and position 1 is the start step.
 *
 * What a step can end with (outcomes()), by its exact type:
 * - a job step: success or failure, and a closed gate when its job template
 *   has an approval rule (a gated job step: the job never ran because its
 *   approval was rejected, timed out or canceled);
 * - an approval step: success (approved) or a closed gate;
 * - a pause step: success (resumed);
 * - any other type: nothing, as dispatch fails such a step.
 *
 * Which branch an outcome takes: after a closed gate only On Failure; after
 * a success Always, else On Success; after a failure Always, else On Failure.
 *
 * What a branch value means: 0 ends the run, which succeeds after a success
 * and fails otherwise; a step ID of the workflow leads to that step, and is a
 * loop when that step's position is not after the step's own; any other ID
 * is a step that no longer exists, and the run fails. An empty branch after
 * a closed gate ends the run as failed; any other empty branch goes on with
 * the next position, and after the last step the run ends with the step's
 * outcome.
 *
 * A step that cannot run (WorkflowRouteStep::$problem) still has the routes
 * of its type, as the API lists them, but a run that reaches it fails there
 * without following any of them: no walk of the map goes on from it
 * (isReachable(), successPath()).
 *
 * The map is pure: only forTemplate(), stepsOf() and fromModels() read the
 * database. WorkflowRouteOrder places steps, WorkflowRouteWalk follows the
 * branches, and WorkflowRouteEffects compares two maps; call the map, not
 * them. WorkflowRouteMapEngineAgreementTest compares the map with the
 * router and with real runs.
 */
final class WorkflowRouteMap
{
    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_FAILURE = 'failure';
    public const OUTCOME_CLOSED_GATE = 'closed_gate';

    /** The success path ends with the end of the run, which succeeds. */
    public const PATH_SUCCEEDS = 'succeeds';

    /** The success path leads back to a step it passed: runs repeat until a step fails. */
    public const PATH_LOOP = 'loop';

    /** The success path leads to a step that no longer exists: the run fails there. */
    public const PATH_MISSING = 'missing';

    /** The success path reaches a step that cannot run: the run fails there. */
    public const PATH_CANNOT_RUN = 'cannot_run';

    /** The relations of a step that WorkflowRouteStep::fromModel() reads. */
    public const RELATIONS = WorkflowRouteStep::RELATIONS;

    /** A list of steps names one step twice: its ID. */
    public const STEP_TWICE = 'Step ID %d is listed twice.';

    /** A step that is not part of the map: its ID. */
    public const NOT_IN_MAP = 'Step ID %d is not part of this workflow.';

    /** A placement other than start, end, keep and after:<step ID>: the value. */
    public const PLACEMENT_INVALID = 'Unknown placement "%s": use start, end, keep or after:<step ID>.';

    /** A step to be placed after itself: its ID. */
    public const AFTER_ITSELF = 'Step ID %d cannot be placed after itself.';

    /** @var list<WorkflowRouteStep> */
    private readonly array $steps;

    /** @var array<int, int> step ID => position */
    private readonly array $positions;

    /** @var array<int, true>|null the IDs of the reachable steps, once worked out */
    private ?array $reachable = null;

    /** @var array<int, true>|null the IDs of the steps reachable without passing an approval step, once worked out */
    private ?array $reachableWithoutApproval = null;

    /**
     * @var array{
     *     step_ids: list<int>,
     *     end: 'succeeds'|'loop'|'missing'|'cannot_run'|null,
     *     loop_to_step_id: int|null,
     *     missing_step_id: int|null
     * }|null the success path, once worked out
     */
    private ?array $successPath = null;

    /**
     * @param list<WorkflowRouteStep> $steps in run order
     * @throws \InvalidArgumentException when a step ID is listed twice
     */
    private function __construct(array $steps)
    {
        $positions = [];
        foreach ($steps as $index => $step) {
            if (isset($positions[$step->id])) {
                throw new \InvalidArgumentException(sprintf(self::STEP_TWICE, $step->id));
            }
            $positions[$step->id] = $index + 1;
        }
        $this->steps = $steps;
        $this->positions = $positions;
    }

    /**
     * The map of $steps, given in run order. Reads nothing.
     *
     * @param list<WorkflowRouteStep> $steps
     * @throws \InvalidArgumentException when a step ID is listed twice
     */
    public static function fromSteps(array $steps): self
    {
        return new self(array_values($steps));
    }

    /**
     * The map of the step models $models, given in run order. Their job
     * templates (with their approval rules) and approval rules are loaded in
     * one batch for the models that do not have them loaded yet.
     *
     * @param list<WorkflowStep> $models
     * @throws \InvalidArgumentException when a step ID is listed twice
     */
    public static function fromModels(array $models): self
    {
        return new self(WorkflowRouteStep::fromModels($models));
    }

    /**
     * The map of $template's steps as stored: about four queries, however
     * many steps it has (stepsOf()).
     */
    public static function forTemplate(WorkflowTemplate $template): self
    {
        return self::fromModels(self::stepsOf($template));
    }

    /**
     * $template's steps in run order, with what the map reads loaded (each
     * step's job template with its approval rule, and its approval rule): a
     * page that shows the steps builds the map from them with fromModels()
     * without another query, and links their targets.
     *
     * @return list<WorkflowStep>
     */
    public static function stepsOf(WorkflowTemplate $template): array
    {
        /** @var list<WorkflowStep> $models */
        $models = $template->getSteps()->with(self::RELATIONS)->all();

        return $models;
    }

    /**
     * The steps in run order.
     *
     * @return list<WorkflowRouteStep>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * The 1-based position of the step with ID $stepId; null when the map
     * has no such step.
     */
    public function position(int $stepId): ?int
    {
        return $this->positions[$stepId] ?? null;
    }

    /**
     * The step with ID $stepId; null when the map has no such step.
     */
    public function find(int $stepId): ?WorkflowRouteStep
    {
        $position = $this->position($stepId);

        return $position === null ? null : $this->steps[$position - 1];
    }

    /**
     * The start step (position 1); null when there are no steps.
     */
    public function start(): ?WorkflowRouteStep
    {
        return $this->steps[0] ?? null;
    }

    /**
     * What $step can end with, by its exact type (see the class description).
     *
     * @return list<'success'|'failure'|'closed_gate'>
     */
    public function outcomes(WorkflowRouteStep $step): array
    {
        return match ($step->type) {
            WorkflowStep::TYPE_JOB => $step->gatedRuleName === null
                ? [self::OUTCOME_SUCCESS, self::OUTCOME_FAILURE]
                : [self::OUTCOME_SUCCESS, self::OUTCOME_FAILURE, self::OUTCOME_CLOSED_GATE],
            WorkflowStep::TYPE_APPROVAL => [self::OUTCOME_SUCCESS, self::OUTCOME_CLOSED_GATE],
            WorkflowStep::TYPE_PAUSE => [self::OUTCOME_SUCCESS],
            default => [],
        };
    }

    /**
     * Where a run goes when $step ends with $outcome (an OUTCOME_* value);
     * null when the step cannot end with it. $step's branches are read from
     * $step, its position from the map.
     *
     * @throws \InvalidArgumentException when $step is not part of the map
     */
    public function route(WorkflowRouteStep $step, string $outcome): ?WorkflowRoute
    {
        $position = $this->positionOf($step);
        if (!in_array($outcome, $this->outcomes($step), true)) {
            return null;
        }
        if ($outcome === self::OUTCOME_CLOSED_GATE) {
            return $this->resolve($position, $step->onFailure, WorkflowRoute::VIA_ON_FAILURE, WorkflowRoute::RUN_FAILS, false);
        }
        $run = $outcome === self::OUTCOME_SUCCESS ? WorkflowRoute::RUN_SUCCEEDS : WorkflowRoute::RUN_FAILS;
        if ($step->onAlways !== null) {
            return $this->resolve($position, $step->onAlways, WorkflowRoute::VIA_ON_ALWAYS, $run, false);
        }

        return $outcome === self::OUTCOME_SUCCESS
            ? $this->resolve($position, $step->onSuccess, WorkflowRoute::VIA_ON_SUCCESS, $run, true)
            : $this->resolve($position, $step->onFailure, WorkflowRoute::VIA_ON_FAILURE, $run, true);
    }

    /**
     * Where $step's Always branch leads; null when it has none, or when the
     * step cannot end with anything (an unknown type). An approval or pause
     * step takes it only on success (approved, resumed), so an end succeeds;
     * a job step takes it after a success and after a failure, so an end
     * ends the run with the step's outcome (run outcome null).
     *
     * @throws \InvalidArgumentException when $step is not part of the map
     */
    public function always(WorkflowRouteStep $step): ?WorkflowRoute
    {
        $position = $this->positionOf($step);
        if ($step->onAlways === null || $this->outcomes($step) === []) {
            return null;
        }
        $run = $step->type === WorkflowStep::TYPE_JOB ? null : WorkflowRoute::RUN_SUCCEEDS;

        return $this->resolve($position, $step->onAlways, WorkflowRoute::VIA_ON_ALWAYS, $run, false);
    }

    /**
     * Whether a run can reach the step with ID $stepId: it is the start step,
     * or a branch that some outcome of a reachable step takes leads to it. A
     * step that cannot run fails the run there and leads nowhere, so a step
     * that only it leads to is not reachable.
     */
    public function isReachable(int $stepId): bool
    {
        $this->reachable ??= WorkflowRouteWalk::reachable($this);

        return isset($this->reachable[$stepId]);
    }

    /**
     * Whether a run can reach the step with ID $stepId without passing an
     * approval step that was approved (isReachable(), but no walk goes on
     * from an approval step's success; its On Failure after a closed gate
     * passes no approval). A job step that runs reach, but not this way, is
     * guarded by an approval step.
     */
    public function isReachableWithoutApproval(int $stepId): bool
    {
        $this->reachableWithoutApproval ??= WorkflowRouteWalk::reachableWithoutApproval($this);

        return isset($this->reachableWithoutApproval[$stepId]);
    }

    /**
     * The steps a run passes when every step succeeds, from the start step,
     * and how that path ends: the end of the run (succeeds), a step it passed
     * before (loop, loop_to_step_id), a step that no longer exists (missing,
     * missing_step_id), or a step that cannot run (cannot_run, the last of
     * step_ids). end is null when there are no steps.
     *
     * @return array{
     *     step_ids: list<int>,
     *     end: 'succeeds'|'loop'|'missing'|'cannot_run'|null,
     *     loop_to_step_id: int|null,
     *     missing_step_id: int|null
     * }
     */
    public function successPath(): array
    {
        return $this->successPath ??= WorkflowRouteWalk::successPath($this);
    }

    /**
     * The map with $step placed by $placement, as the web form and the Move
     * to… menu name places: start, end, after:<step ID>, or keep (where it
     * is). A step the map has is moved there, with $step's values; one it
     * does not have (such as NEW_STEP_ID) is inserted. Nothing is written.
     *
     * @param string $placement 'start', 'end', 'keep' or 'after:<step ID>'
     * @throws \InvalidArgumentException for any other placement, keep for a step the map
     *     does not have, or after a step that is $step itself or not part of the map
     */
    public function withPlacement(WorkflowRouteStep $step, string $placement): self
    {
        return new self(WorkflowRouteOrder::place($this->steps, $step, $placement));
    }

    /**
     * What the change from $before to this map did to the runs
     * (WorkflowOrderEffects), from the branches: the start step, the job
     * steps that runs reached only through an approval step and now reach
     * without passing one, and the approval steps that are not on the
     * success path any more. $newStepId is the ID of a step that was added,
     * if any: a new job step that runs reach without passing an approval
     * step counts too. $changedStepId is the ID of the step the change
     * added, moved or edited (by default the new one): an approval step
     * off the success path counts also when it was off it before.
     */
    public function effectsSince(self $before, ?int $newStepId = null, ?int $changedStepId = null): WorkflowOrderEffects
    {
        return WorkflowRouteEffects::between($before, $this, $newStepId, $changedStepId ?? $newStepId);
    }

    /**
     * The map as the API writes it: the start step's ID, the success path,
     * and per step (by ID, in run order) its position, whether runs start
     * with it, whether a run can reach it, why it cannot run (null when it
     * can), and where each outcome leads (null for an outcome it cannot end
     * with).
     *
     * @return array{
     *     start_step_id: int|null,
     *     success_path: array{
     *         step_ids: list<int>,
     *         end: 'succeeds'|'loop'|'missing'|'cannot_run'|null,
     *         loop_to_step_id: int|null,
     *         missing_step_id: int|null
     *     },
     *     steps: array<int, array{
     *         position: int,
     *         is_start: bool,
     *         reachable: bool,
     *         cannot_run: string|null,
     *         routes: array{
     *             success: array{kind: string, via: string, step_id: int|null, position: int|null, run_outcome: string|null, loop: bool}|null,
     *             failure: array{kind: string, via: string, step_id: int|null, position: int|null, run_outcome: string|null, loop: bool}|null,
     *             closed_gate: array{kind: string, via: string, step_id: int|null, position: int|null, run_outcome: string|null, loop: bool}|null
     *         }
     *     }>
     * }
     */
    public function toApi(): array
    {
        $steps = [];
        foreach ($this->steps as $index => $step) {
            $steps[$step->id] = [
                'position' => $index + 1,
                'is_start' => $index === 0,
                'reachable' => $this->isReachable($step->id),
                'cannot_run' => $step->problem,
                'routes' => [
                    'success' => $this->route($step, self::OUTCOME_SUCCESS)?->toArray(),
                    'failure' => $this->route($step, self::OUTCOME_FAILURE)?->toArray(),
                    'closed_gate' => $this->route($step, self::OUTCOME_CLOSED_GATE)?->toArray(),
                ],
            ];
        }

        return ['start_step_id' => $this->start()?->id, 'success_path' => $this->successPath(), 'steps' => $steps];
    }

    /**
     * @throws \InvalidArgumentException when $step is not part of the map
     */
    private function positionOf(WorkflowRouteStep $step): int
    {
        return $this->positions[$step->id] ?? throw new \InvalidArgumentException(sprintf(self::NOT_IN_MAP, $step->id));
    }

    /**
     * Where the branch value $branch of the step at $position leads, when
     * $via decided it. $run is how the run ends at an end; $fallThrough:
     * whether an empty branch goes on with the next step (else it ends the
     * run).
     *
     * @param 'on_success'|'on_failure'|'on_always'|'next' $via
     * @param 'succeeds'|'fails'|null $run
     */
    private function resolve(int $position, ?int $branch, string $via, ?string $run, bool $fallThrough): WorkflowRoute
    {
        if ($branch === null && $fallThrough) {
            return $this->next($position, $run);
        }
        if ($branch === null || $branch === WorkflowStep::END_WORKFLOW) {
            return new WorkflowRoute(WorkflowRoute::KIND_END, $via, null, null, $run, false);
        }
        $target = $this->positions[$branch] ?? null;

        return $target === null
            ? new WorkflowRoute(WorkflowRoute::KIND_MISSING, $via, $branch, null, WorkflowRoute::RUN_FAILS, false)
            : new WorkflowRoute(WorkflowRoute::KIND_STEP, $via, $branch, $target, null, $target <= $position);
    }

    /**
     * The step after the one at $position, or the end of the run with $run
     * after the last step.
     *
     * @param 'succeeds'|'fails'|null $run
     */
    private function next(int $position, ?string $run): WorkflowRoute
    {
        $next = $this->steps[$position] ?? null;

        return $next === null
            ? new WorkflowRoute(WorkflowRoute::KIND_END, WorkflowRoute::VIA_NEXT, null, null, $run, false)
            : new WorkflowRoute(WorkflowRoute::KIND_STEP, WorkflowRoute::VIA_NEXT, $next->id, $position + 1, null, false);
    }
}
