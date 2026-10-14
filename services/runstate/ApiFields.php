<?php

declare(strict_types=1);

namespace app\services\runstate;

use app\models\WorkflowJob;

/**
 * The arrays the REST API answers for workflow runs, their step executions
 * and the approval requests those wait for (/api/v1/workflow-jobs), built
 * from what WorkflowRunStates derived. Stored statuses stay as they are;
 * what a run or step waits for is waiting_for (Display::WAITING_FOR_*, null
 * when it does not wait).
 */
final class ApiFields
{
    /**
     * A run as the list, cancel and resume answer it: its stored fields,
     * what it waits for, and its current execution (the newest one that is
     * still running; null between two steps and once the run finished).
     *
     * @param RunState $state the run's state (WorkflowRunStates::forRuns() or forRun())
     * @return array<string, mixed>
     */
    public static function run(WorkflowJob $run, RunState $state): array
    {
        $current = $state->current;

        return [
            'id' => $run->id,
            'workflow_template_id' => $run->workflow_template_id,
            'status' => $run->status,
            'launched_by' => $run->launched_by,
            'started_at' => $run->started_at,
            'finished_at' => $run->finished_at,
            'created_at' => $run->created_at,
            'waiting_for' => $state->waitingFor,
            'current_step' => $current === null ? null : [
                'id' => $current->executionId,
                'workflow_step_id' => $current->workflowStepId,
                'step_name' => $current->stepName,
                'step_type' => $current->stepType,
            ],
        ];
    }

    /**
     * A run with its launch extra vars and every step execution, oldest
     * first, as GET /workflow-jobs/{id} answers it.
     *
     * extra_vars: the vars the run was launched with, which every job step
     * received, each password answer as $encrypted$; null when there were
     * none. The sealed answers are never returned.
     *
     * @param RunState $state the run's state with its steps (WorkflowRunStates::forRun())
     * @return array<string, mixed>
     */
    public static function runDetail(WorkflowJob $run, RunState $state): array
    {
        $extraVars = $run->launchExtraVars();

        return self::run($run, $state) + [
            'extra_vars' => $extraVars === [] ? null : (object)$extraVars,
            'steps' => array_map(static fn (StepState $step): array => self::step($step), $state->steps),
        ];
    }

    /**
     * One step execution. It names the step as it was when it ran
     * (step_name, step_type); workflow_step_id is null once the step was
     * removed from the workflow. error says why the step failed without a
     * job, e.g. no access to its job template. job_status is the status of
     * its job (for an approval step: of the placeholder that holds its
     * request). approval_request_id and approval are null when the step has
     * no approval request or the caller may not see it.
     *
     * @return array<string, mixed>
     */
    public static function step(StepState $step): array
    {
        return [
            'id' => $step->executionId,
            'workflow_step_id' => $step->workflowStepId,
            'step_name' => $step->stepName,
            'step_type' => $step->stepType,
            'job_id' => $step->jobId,
            'status' => $step->status,
            'started_at' => $step->startedAt,
            'finished_at' => $step->finishedAt,
            'error' => $step->errorMessage,
            'waiting_for' => $step->waitingFor,
            'job_status' => $step->jobStatus,
            'approval_request_id' => $step->approval?->requestId,
            'approval' => self::approval($step->approval),
        ];
    }

    /**
     * The approval request a step waits or waited for; null without one.
     * The counts leave out the launcher's votes. eligible_count, open_count
     * and can_still_be_approved are filled only for the pending request of
     * the run's current step (WorkflowRunStates::forRun()), else null.
     *
     * @return array<string, mixed>|null
     */
    public static function approval(?ApprovalWait $wait): ?array
    {
        if ($wait === null) {
            return null;
        }

        return [
            'id' => $wait->requestId,
            'status' => $wait->status,
            'approval_rule_id' => $wait->ruleId,
            'approval_rule_name' => $wait->ruleName,
            'approval_count' => $wait->approvalCount,
            'rejection_count' => $wait->rejectionCount,
            'required_approvals' => $wait->requiredApprovals,
            'requested_at' => $wait->requestedAt,
            'expires_at' => $wait->expiresAt,
            'resolved_at' => $wait->resolvedAt,
            'timeout_action' => $wait->timeoutAction,
            'eligible_count' => $wait->eligibleCount,
            'open_count' => $wait->openCount,
            'can_still_be_approved' => $wait->approvable,
        ];
    }
}
