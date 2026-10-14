<?php

declare(strict_types=1);

namespace app\services\runstate;

/**
 * One step execution of a workflow run with its display state
 * (WorkflowRunStates): the stored status, the state derived from it, its
 * job and what it waits for. Built eagerly, so reading it runs no query.
 */
final class StepState
{
    /**
     * @param int $executionId the execution (workflow_job_step)
     * @param int|null $workflowStepId the step; null once it was removed from its workflow
     * @param string $stepName the step's name when it ran (WorkflowJobStep::stepName())
     * @param string|null $stepType the step's type when it ran, as stored (WorkflowJobStep::stepType())
     * @param string $status the stored status (WorkflowJobStep::STATUS_*)
     * @param string $state the display state (Display::STATE_*)
     * @param string|null $waitingFor what the step waits for (Display::WAITING_FOR_*); null when it does not wait
     * @param int|null $jobId its job: the job of a job step, the placeholder of an approval step
     * @param string|null $jobStatus the job's status (Job::STATUS_*)
     * @param string|null $jobTemplateName the job's template, also a deleted one; null without one,
     *     or when the viewer may not see its project
     * @param int|null $startedAt when the execution started
     * @param int|null $finishedAt when it finished
     * @param int|null $waitingSince since when it waits: the approval request's time, the job's
     *     queue time or the execution's start (see WorkflowRunStates); null when it does not wait
     * @param string|null $errorMessage why it failed, or why the run could not go on after it
     * @param ApprovalWait|null $approval its job's approval request; null without one, or when
     *     the viewer may not see it
     * @param string|null $decisionNote how that request was decided, as the viewer may see it
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Immutable DTO; splitting reduces cohesion without benefit.
     */
    public function __construct(
        public readonly int $executionId,
        public readonly ?int $workflowStepId,
        public readonly string $stepName,
        public readonly ?string $stepType,
        public readonly string $status,
        public readonly string $state,
        public readonly ?string $waitingFor,
        public readonly ?int $jobId,
        public readonly ?string $jobStatus,
        public readonly ?string $jobTemplateName,
        public readonly ?int $startedAt,
        public readonly ?int $finishedAt,
        public readonly ?int $waitingSince,
        public readonly ?string $errorMessage,
        public readonly ?ApprovalWait $approval,
        public readonly ?string $decisionNote,
    ) {
    }
}
