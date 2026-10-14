<?php

declare(strict_types=1);

namespace app\services;

use app\components\WorkflowStepChange;
use app\models\AuditLog;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;

/**
 * The audit entries of the step write path (WorkflowStepWriteService): one
 * per change that wrote something, on the workflow template, as the user who
 * made it. Each entry's context starts with the step's identity (step_id,
 * step_name, step_type, job_template_id, approval_rule_id: the step after
 * the change, or as it was when it was removed), then:
 * - workflow-template.step.added: its routes (on_success_step_id,
 *   on_failure_step_id, on_always_step_id), position, step_count and
 *   source;
 * - workflow-template.step.updated: changes, {field: {from, to}} with
 *   position when the step moved, the variable mapping only as
 *   {changed: true}, never its values; and source;
 * - workflow-template.step.moved: from_position, to_position, order_before
 *   and order_after (the step IDs in run order), direction (up or down, for
 *   ▲ and ▼ only) and source;
 * - workflow-template.step.removed: the position it had, and source.
 * A change that wrote nothing (UNCHANGED, or MOVED to where the step stood)
 * has none. The source is where the change came from: web or api.
 */
final class WorkflowStepAudit
{
    /** The action of each kind of change that writes. */
    private const ACTIONS = [
        WorkflowStepChange::ADDED => AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_ADDED,
        WorkflowStepChange::UPDATED => AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_UPDATED,
        WorkflowStepChange::MOVED => AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_MOVED,
        WorkflowStepChange::REMOVED => AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_REMOVED,
    ];

    public function __construct(private readonly AuditService $auditService)
    {
    }

    /**
     * The entry of $change on $template, as $userId; none for UNCHANGED or
     * for a move that kept the step where it stood.
     * $direction: up or down for a move by ▲ or ▼, null otherwise.
     */
    public function record(WorkflowTemplate $template, int $userId, WorkflowStepChange $change, ?string $direction = null): void
    {
        $action = self::ACTIONS[$change->kind] ?? null;
        if ($action === null || ($change->kind === WorkflowStepChange::MOVED && !$change->moved())) {
            return;
        }
        $this->auditService->log(
            $action,
            'workflow_template',
            (int)$template->id,
            $userId,
            self::identity($change->step) + self::context($change, $direction)
        );
    }

    /**
     * The keys of $change's entry after the step's identity.
     *
     * @return array<string, mixed>
     */
    private static function context(WorkflowStepChange $change, ?string $direction): array
    {
        return match ($change->kind) {
            WorkflowStepChange::ADDED => self::added($change),
            WorkflowStepChange::UPDATED => ['changes' => self::changes($change->changes), 'source' => $change->source],
            WorkflowStepChange::MOVED => self::moved($change, $direction),
            default => ['position' => $change->from, 'source' => $change->source],
        };
    }

    /**
     * @return array{on_success_step_id: int|null, on_failure_step_id: int|null, on_always_step_id: int|null, position: int, step_count: int, source: string}
     */
    private static function added(WorkflowStepChange $change): array
    {
        $step = $change->step;

        return [
            'on_success_step_id' => self::id($step->on_success_step_id),
            'on_failure_step_id' => self::id($step->on_failure_step_id),
            'on_always_step_id' => self::id($step->on_always_step_id),
            'position' => $change->to,
            'step_count' => $change->count(),
            'source' => $change->source,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function moved(WorkflowStepChange $change, ?string $direction): array
    {
        return [
            'from_position' => $change->from,
            'to_position' => $change->to,
            'order_before' => self::ids($change->stepsBefore),
            'order_after' => self::ids($change->stepsAfter),
        ] + ($direction === null ? [] : ['direction' => $direction]) + ['source' => $change->source];
    }

    /**
     * The changes as the entry keeps them: the variable mapping only as
     * {changed: true}, never its values.
     *
     * @param array<string, array{from: mixed, to: mixed}> $changes
     * @return array<string, mixed>
     */
    private static function changes(array $changes): array
    {
        $audited = $changes;
        if (array_key_exists('extra_vars_template', $audited)) {
            $audited['extra_vars_template'] = ['changed' => true];
        }

        return $audited;
    }

    /**
     * @return array{step_id: int, step_name: string, step_type: string, job_template_id: int|null, approval_rule_id: int|null}
     */
    private static function identity(WorkflowStep $step): array
    {
        return [
            'step_id' => (int)$step->id,
            'step_name' => (string)$step->name,
            'step_type' => (string)$step->step_type,
            'job_template_id' => self::id($step->job_template_id),
            'approval_rule_id' => self::id($step->approval_rule_id),
        ];
    }

    private static function id(mixed $value): ?int
    {
        return $value === null ? null : (int)$value;
    }

    /**
     * @param list<WorkflowStep> $steps
     * @return list<int>
     */
    private static function ids(array $steps): array
    {
        return array_map(static fn (WorkflowStep $step): int => (int)$step->id, $steps);
    }
}
