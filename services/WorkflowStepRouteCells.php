<?php

declare(strict_types=1);

namespace app\services;

use app\components\WorkflowRoute;
use app\components\WorkflowRouteStep;
use app\models\WorkflowStep;

/**
 * The On Success, On Failure and Always cells of a row of the steps table
 * (WorkflowStepsTable), from the route map, which resolves each branch the
 * way the engine routes a run:
 *
 * - On Success: where a success leads; "—" when Always is taken instead.
 * - On Failure: where a failure leads, ", also after a failure" when an
 *   empty branch leads where On Success leads; "—" when Always is taken
 *   instead. A pause step never fails ("—"). An approval step's cell is its
 *   closed gate (rejected, timed out or canceled), which follows only On
 *   Failure: empty and 0 both end the run as failed. A gated job step's cell
 *   has two parts, a job that ran and failed, and a refused approval, which
 *   merge into one when both lead to the same place.
 * - Always: "—" when empty; else where it leads, with a note on when an
 *   approval, gated job or pause step takes it.
 * - A step that cannot run (a missing job template or approval rule, an
 *   unknown type) fails the run without following a branch: "—" everywhere.
 *
 * Each cell has a kind (KIND_*) and its parts, each a text with an optional
 * title: an empty branch says that it moves with the steps, a loop that it
 * repeats steps, a missing step that the run fails, "—" why the branch is not
 * used.
 *
 * @phpstan-type RoutePart array{text: string, title: string|null}
 * @phpstan-type RouteCell array{kind: string, parts: list<RoutePart>, note: string|null}
 * @internal
 */
final class WorkflowStepRouteCells
{
    /** An empty branch that goes on with the next step. */
    public const KIND_NEXT = 'next';

    /** A branch to a later step. */
    public const KIND_STEP = 'step';

    /** A branch back to the step itself or an earlier one. */
    public const KIND_LOOP = 'loop';

    /** The run ends. */
    public const KIND_END = 'end';

    /** A branch to a step that no longer exists: the run fails. */
    public const KIND_MISSING = 'missing';

    /** A branch the step never takes, and an empty Always branch. */
    public const KIND_UNUSED = 'unused';

    /** A gated job step's On Failure, whose two parts lead to different places. */
    public const KIND_MIXED = 'mixed';

    /** An outcome the map does not give a step that can run (a caller error); sprintf(): the outcome, the step ID. */
    private const NO_ROUTE = 'No %s route for step ID %d.';

    public function __construct(private readonly WorkflowRouteMap $map)
    {
    }

    /**
     * The three route cells of $step, a step of the map.
     *
     * @return array{success: RouteCell, failure: RouteCell, always: RouteCell}
     */
    public function of(WorkflowRouteStep $step): array
    {
        if ($step->problem !== null) {
            $unused = self::unused(WorkflowStepsTable::CANNOT_RUN_TITLE);

            return ['success' => $unused, 'failure' => $unused, 'always' => $unused];
        }

        return ['success' => $this->success($step), 'failure' => $this->failure($step), 'always' => $this->always($step)];
    }

    /**
     * @return RouteCell
     */
    private function success(WorkflowRouteStep $step): array
    {
        $success = $this->route($step, WorkflowRouteMap::OUTCOME_SUCCESS);

        return $success->via === WorkflowRoute::VIA_ON_ALWAYS
            ? self::unused(WorkflowStepsTable::ALWAYS_WINS_TITLE)
            : $this->cell($success);
    }

    /**
     * @return RouteCell
     */
    private function failure(WorkflowRouteStep $step): array
    {
        return match (true) {
            $step->type === WorkflowStep::TYPE_PAUSE => self::unused(WorkflowStepsTable::PAUSE_FAILURE_TITLE),
            $step->type === WorkflowStep::TYPE_APPROVAL => $this->cell($this->route($step, WorkflowRouteMap::OUTCOME_CLOSED_GATE)),
            $step->gatedRuleName !== null => $this->gatedFailure($step),
            $step->onAlways !== null => self::unused(WorkflowStepsTable::ALWAYS_WINS_TITLE),
            default => $this->jobFailure($step),
        };
    }

    /**
     * A job step's On Failure.
     *
     * @return RouteCell
     */
    private function jobFailure(WorkflowRouteStep $step): array
    {
        $failure = $this->route($step, WorkflowRouteMap::OUTCOME_FAILURE);
        $suffix = $this->fallsThroughLikeSuccess($step, $failure) ? WorkflowStepsTable::ALSO_AFTER_FAILURE : '';

        return $this->cell($failure, $suffix);
    }

    /**
     * A gated job step's On Failure: a job that ran and failed (unless
     * Always is taken instead), and a refused approval, which never takes
     * Always; one part when both lead to the same place.
     *
     * @return RouteCell
     */
    private function gatedFailure(WorkflowRouteStep $step): array
    {
        $gate = $this->route($step, WorkflowRouteMap::OUTCOME_CLOSED_GATE);
        $refused = $this->cell($gate, WorkflowStepsTable::AFTER_REFUSED_APPROVAL, WorkflowStepsTable::REFUSED_APPROVAL_TITLE);
        if ($step->onAlways !== null) {
            return $refused;
        }
        $failure = $this->route($step, WorkflowRouteMap::OUTCOME_FAILURE);
        if ($failure->sameTarget($gate)) {
            return $this->cell($failure);
        }
        $suffix = $this->fallsThroughLikeSuccess($step, $failure)
            ? WorkflowStepsTable::ALSO_AFTER_FAILED_JOB
            : WorkflowStepsTable::AFTER_FAILED_JOB;

        return [
            'kind' => self::KIND_MIXED,
            'parts' => [$this->cell($failure, $suffix)['parts'][0], $refused['parts'][0]],
            'note' => null,
        ];
    }

    /**
     * @return RouteCell
     */
    private function always(WorkflowRouteStep $step): array
    {
        $always = $this->map->always($step);
        if ($always === null) {
            return self::unused(null);
        }
        $note = match (true) {
            $step->type === WorkflowStep::TYPE_APPROVAL => WorkflowStepsTable::APPROVAL_ALWAYS_NOTE,
            $step->type === WorkflowStep::TYPE_PAUSE => WorkflowStepsTable::PAUSE_ALWAYS_NOTE,
            $step->gatedRuleName !== null => WorkflowStepsTable::GATED_JOB_ALWAYS_NOTE,
            default => null,
        };

        return ['kind' => self::kindOf($always), 'parts' => $this->cell($always)['parts'], 'note' => $note];
    }

    /**
     * Whether $failure is an empty branch that leads where $step's success
     * leads.
     */
    private function fallsThroughLikeSuccess(WorkflowRouteStep $step, WorkflowRoute $failure): bool
    {
        return $failure->via === WorkflowRoute::VIA_NEXT
            && $failure->sameTarget($this->route($step, WorkflowRouteMap::OUTCOME_SUCCESS));
    }

    /**
     * Where $step's $outcome leads; the step can end with it.
     */
    private function route(WorkflowRouteStep $step, string $outcome): WorkflowRoute
    {
        return $this->map->route($step, $outcome)
            ?? throw new \LogicException(sprintf(self::NO_ROUTE, $outcome, $step->id));
    }

    /**
     * The cell of one route: its label with $suffix, and $title, or the
     * title of its kind.
     *
     * @return RouteCell
     */
    private function cell(WorkflowRoute $route, string $suffix = '', ?string $title = null): array
    {
        return [
            'kind' => self::kindOf($route),
            'parts' => [['text' => $this->label($route) . $suffix, 'title' => $title ?? self::titleOf($route)]],
            'note' => null,
        ];
    }

    /**
     * @return RouteCell
     */
    private static function unused(?string $title): array
    {
        return [
            'kind' => self::KIND_UNUSED,
            'parts' => [['text' => WorkflowStepsTable::ROUTE_UNUSED, 'title' => $title]],
            'note' => null,
        ];
    }

    private function label(WorkflowRoute $route): string
    {
        if ($route->kind === WorkflowRoute::KIND_MISSING) {
            return sprintf(WorkflowStepsTable::ROUTE_MISSING, (int)$route->stepId);
        }
        if ($route->kind === WorkflowRoute::KIND_END) {
            return match ($route->runOutcome) {
                WorkflowRoute::RUN_SUCCEEDS => WorkflowStepsTable::ROUTE_END_SUCCEEDS,
                WorkflowRoute::RUN_FAILS => WorkflowStepsTable::ROUTE_END_FAILS,
                default => WorkflowStepsTable::ROUTE_END_WITH_STEP,
            };
        }
        $target = WorkflowStepsTable::stepLabel((int)$route->position, (string)$this->map->find((int)$route->stepId)?->name);

        return sprintf(self::pattern($route), $target);
    }

    /**
     * The label pattern of a route to a step.
     */
    private static function pattern(WorkflowRoute $route): string
    {
        return match (self::kindOf($route)) {
            self::KIND_NEXT => WorkflowStepsTable::ROUTE_TO_NEXT,
            self::KIND_LOOP => WorkflowStepsTable::ROUTE_LOOP,
            default => WorkflowStepsTable::ROUTE_TO,
        };
    }

    private static function kindOf(WorkflowRoute $route): string
    {
        return match (true) {
            $route->kind === WorkflowRoute::KIND_END => self::KIND_END,
            $route->kind === WorkflowRoute::KIND_MISSING => self::KIND_MISSING,
            $route->via === WorkflowRoute::VIA_NEXT => self::KIND_NEXT,
            $route->loop => self::KIND_LOOP,
            default => self::KIND_STEP,
        };
    }

    private static function titleOf(WorkflowRoute $route): ?string
    {
        return match (self::kindOf($route)) {
            self::KIND_NEXT => WorkflowStepsTable::NEXT_TITLE,
            self::KIND_LOOP => WorkflowStepsTable::LOOP_TITLE,
            self::KIND_MISSING => WorkflowStepsTable::MISSING_TITLE,
            default => null,
        };
    }
}
