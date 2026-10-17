<?php

declare(strict_types=1);

namespace app\components;

/**
 * Where an approval rule is used: inventories that it gates, live job
 * templates that it gates, steps of live workflows that carry it, and
 * pending approval requests whose job still waits for the decision. A rule
 * in use cannot be deleted.
 *
 * Lists hold only what the viewer may see; totals count everything, so the
 * summary never names hidden resources. Resolved requests are history, not
 * usage: they keep their decisions when the rule is deleted.
 */
final class ApprovalRuleUsage
{
    /** The summary of a rule in use: the quoted rule name, then what uses it. */
    public const SUMMARY_IN_USE = 'Approval rule %s is in use by %s.';

    /** The summary of a rule that nothing uses: the quoted rule name. */
    public const SUMMARY_NOT_IN_USE = 'Approval rule %s is not in use.';

    /** Shown, instead of the Delete button, on the page of a rule in use. */
    public const DELETE_BLOCKED = 'In use: remove it from the inventories, job templates and workflow steps below, '
        . 'and let its pending requests be decided or their jobs canceled, before deleting it.';

    /** The usage card of a rule that nothing uses. */
    public const NOT_IN_USE = 'Not used by any inventory, job template, workflow step or pending approval request.';

    /** The usage card's line for the inventories the viewer may not see; sprintf(): their number. */
    public const HIDDEN_INVENTORIES = '+ %d inventory(ies) in projects you cannot see';

    /** What summary() names, in its order: each kind of use with its count (sprintf()). */
    private const PART_INVENTORIES = '%d inventory(ies)';
    private const PART_JOB_TEMPLATES = '%d job template(s)';
    private const PART_WORKFLOW_STEPS = '%d workflow step(s)';
    private const PART_PENDING_REQUESTS = '%d pending approval request(s)';

    /**
     * The inventories come last, with defaults, so that callers written
     * before rules gated inventories keep working.
     *
     * @param list<array{id: int, name: string, project_id: int, project_name: string|null}> $jobTemplates
     * @param list<array{workflow_template_id: int, workflow_template_name: string, step_id: int, step_name: string}> $workflowSteps
     * @param list<array{id: int, name: string, project_id: int|null, project_name: string|null}> $inventories
     */
    public function __construct(
        public readonly string $ruleName,
        public readonly array $jobTemplates,
        public readonly int $jobTemplateTotal,
        public readonly array $workflowSteps,
        public readonly int $workflowStepTotal,
        public readonly int $pendingRequestCount,
        public readonly array $inventories = [],
        public readonly int $inventoryTotal = 0,
    ) {
    }

    public function isInUse(): bool
    {
        return $this->counts() !== [];
    }

    public function hiddenInventoryCount(): int
    {
        return max(0, $this->inventoryTotal - count($this->inventories));
    }

    public function hiddenJobTemplateCount(): int
    {
        return max(0, $this->jobTemplateTotal - count($this->jobTemplates));
    }

    public function hiddenWorkflowStepCount(): int
    {
        return max(0, $this->workflowStepTotal - count($this->workflowSteps));
    }

    /**
     * One sentence for flashes and API errors. It names only the rule and
     * counts the rest: the viewer may not see everything that uses it.
     */
    public function summary(): string
    {
        $name = SurveyDefinition::quote($this->ruleName);
        $parts = [];
        foreach ($this->counts() as $pattern => $count) {
            $parts[] = sprintf($pattern, $count);
        }
        if ($parts === []) {
            return sprintf(self::SUMMARY_NOT_IN_USE, $name);
        }
        $last = array_pop($parts);
        $list = $parts === [] ? $last : implode(', ', $parts) . ' and ' . $last;

        return sprintf(self::SUMMARY_IN_USE, $name, $list);
    }

    /**
     * @return array{in_use: bool, inventories: list<array{id: int, name: string, project_id: int|null, project_name: string|null}>, hidden_inventory_count: int, job_templates: list<array{id: int, name: string, project_id: int, project_name: string|null}>, hidden_job_template_count: int, workflow_steps: list<array{workflow_template_id: int, workflow_template_name: string, step_id: int, step_name: string}>, hidden_workflow_step_count: int, pending_request_count: int}
     */
    public function toArray(): array
    {
        return [
            'in_use' => $this->isInUse(),
            'inventories' => $this->inventories,
            'hidden_inventory_count' => $this->hiddenInventoryCount(),
            'job_templates' => $this->jobTemplates,
            'hidden_job_template_count' => $this->hiddenJobTemplateCount(),
            'workflow_steps' => $this->workflowSteps,
            'hidden_workflow_step_count' => $this->hiddenWorkflowStepCount(),
            'pending_request_count' => $this->pendingRequestCount,
        ];
    }

    /**
     * The kinds of use that count, in summary()'s order: each part's
     * pattern with its count above 0.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        return array_filter([
            self::PART_INVENTORIES => $this->inventoryTotal,
            self::PART_JOB_TEMPLATES => $this->jobTemplateTotal,
            self::PART_WORKFLOW_STEPS => $this->workflowStepTotal,
            self::PART_PENDING_REQUESTS => $this->pendingRequestCount,
        ], static fn (int $count): bool => $count > 0);
    }
}
