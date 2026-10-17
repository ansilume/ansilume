<?php

declare(strict_types=1);

namespace app\components;

use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowRouteMap;

/**
 * A workflow's steps as the REST API answers them (the WorkflowStep schema),
 * for GET, POST and PUT /api/v1/workflow-templates (WorkflowTemplatesController)
 * and the per-step endpoints (WorkflowStepsController), which therefore answer
 * one step exactly as the workflow lists it.
 *
 * Each step has its stored fields, with only the target of its type: a job
 * step's job_template_id, an approval step's approval_rule_id. A target that
 * an older version saved on another step type does not restrict who may see
 * the workflow, so it is never shown. step_order is the internal sort key;
 * position is what counts. Then what the route map works out from all the
 * steps (WorkflowRouteMap::toApi()), the way the engine routes a run:
 * - position: 1-based, in run order (step_order, then ID); position 1 is the
 *   start step (is_start);
 * - reachable: whether a run can reach the step;
 * - cannot_run: why a run that reaches it fails there (job_template_missing,
 *   approval_rule_missing, unknown_type, approval_rules_differ), or null;
 * - routes: where a run goes when the step succeeds, fails, or ends with a
 *   closed approval gate (success, failure, closed_gate: an approval step,
 *   and a job step whose job template or its inventory has an approval
 *   rule), each a WorkflowRouteTarget {kind, via, step_id, position,
 *   run_outcome, loop}, or null for an outcome the step cannot end with.
 * For the workflow itself: its start step (startStepId()) and the steps a
 * run passes when every step succeeds (successPath()).
 *
 * The steps are given in run order, as WorkflowRouteMap::stepsOf() reads
 * them, or as the step write path read them under the workflow's lock
 * (WorkflowStepChange::stepsAfter). Their job templates (with the
 * templates' approval rules, inventories and the inventories' approval
 * rules) and approval rules are loaded in one batch when they are not
 * loaded yet.
 */
final class WorkflowStepSerializer
{
    /** step() was asked for a step that is not among the given ones: its ID. */
    public const NOT_GIVEN = 'Step ID %d is not one of the steps given.';

    /** @var array<int, WorkflowStep> the given steps by ID, in run order */
    private readonly array $steps;

    private readonly ?int $startStepId;

    /**
     * @var array{
     *     step_ids: list<int>,
     *     end: 'succeeds'|'loop'|'missing'|'cannot_run'|null,
     *     loop_to_step_id: int|null,
     *     missing_step_id: int|null
     * }
     */
    private readonly array $successPath;

    /**
     * @var array<int, array{
     *     position: int,
     *     is_start: bool,
     *     reachable: bool,
     *     cannot_run: string|null,
     *     routes: array{
     *         success: array{kind: string, via: string, step_id: int|null, position: int|null, run_outcome: string|null, loop: bool}|null,
     *         failure: array{kind: string, via: string, step_id: int|null, position: int|null, run_outcome: string|null, loop: bool}|null,
     *         closed_gate: array{kind: string, via: string, step_id: int|null, position: int|null, run_outcome: string|null, loop: bool}|null
     *     }
     * }> what the route map works out, by step ID
     */
    private readonly array $readFields;

    /**
     * @param list<WorkflowStep> $steps a workflow's steps in run order
     */
    public function __construct(array $steps)
    {
        $byId = [];
        foreach ($steps as $step) {
            $byId[(int)$step->id] = $step;
        }
        $this->steps = $byId;
        $map = WorkflowRouteMap::fromModels(array_values($steps))->toApi();
        $this->startStepId = $map['start_step_id'];
        $this->successPath = $map['success_path'];
        $this->readFields = $map['steps'];
    }

    /**
     * The steps of $template as stored, in run order (about four queries,
     * however many steps it has).
     */
    public static function forTemplate(WorkflowTemplate $template): self
    {
        return new self(WorkflowRouteMap::stepsOf($template));
    }

    /**
     * Every step, by position.
     *
     * @return list<array<string, mixed>>
     */
    public function steps(): array
    {
        return array_values(array_map(fn (WorkflowStep $step): array => $this->serialize($step), $this->steps));
    }

    /**
     * The step with ID $stepId.
     *
     * @return array<string, mixed>
     * @throws \InvalidArgumentException when it is not one of the given steps
     */
    public function step(int $stepId): array
    {
        $step = $this->steps[$stepId] ?? throw new \InvalidArgumentException(sprintf(self::NOT_GIVEN, $stepId));

        return $this->serialize($step);
    }

    /**
     * The ID of the start step, the step at position 1; null without steps.
     */
    public function startStepId(): ?int
    {
        return $this->startStepId;
    }

    /**
     * The steps a run passes when every step succeeds, from the start step,
     * and how that path ends (WorkflowRouteMap::successPath()).
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
        return $this->successPath;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(WorkflowStep $step): array
    {
        $read = $this->readFields[(int)$step->id];

        return [
            'id' => $step->id,
            'name' => $step->name,
            'step_order' => $step->step_order,
            'step_type' => $step->step_type,
            'job_template_id' => $step->step_type === WorkflowStep::TYPE_JOB ? $step->job_template_id : null,
            'approval_rule_id' => $step->step_type === WorkflowStep::TYPE_APPROVAL ? $step->approval_rule_id : null,
            'on_success_step_id' => $step->on_success_step_id,
            'on_failure_step_id' => $step->on_failure_step_id,
            'on_always_step_id' => $step->on_always_step_id,
            'extra_vars_template' => $step->getParsedExtraVarsTemplate(),
            'position' => $read['position'],
            'is_start' => $read['is_start'],
            'reachable' => $read['reachable'],
            'cannot_run' => $read['cannot_run'],
            'routes' => $read['routes'],
        ];
    }
}
