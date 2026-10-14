<?php

declare(strict_types=1);

namespace app\services\runstate;

/**
 * Where a job comes from and the approval it waits or waited for, for the job
 * page: the workflow run and step the job was created for, its approval
 * request, and whether it is the placeholder of an approval step. Built by
 * ApprovalRequestContexts::forJob().
 */
final class JobContext
{
    /**
     * @param WorkflowOrigin|null $origin the workflow run and step the job was created for; null when
     *     it belongs to no workflow step, or the viewer may not view that workflow
     * @param ApprovalWait|null $approval the job's approval request; null when it has none, or the
     *     viewer may not see it (ApprovalWaits::forJobs())
     * @param bool $placeholder whether the job is the placeholder of an approval step, which only holds
     *     the step's approval request and never runs (by the step's type when it ran). False for a
     *     placeholder whose execution 2.9.0 and older deleted with its step: nothing tells it apart
     *     from a job whose template was purged.
     */
    public function __construct(
        public readonly ?WorkflowOrigin $origin,
        public readonly ?ApprovalWait $approval,
        public readonly bool $placeholder,
    ) {
    }

    /**
     * The link fields of the API's JobDetail: the job's approval request,
     * null when it has none or the viewer may not see it; the run and the
     * step execution (workflow job step) it was created for, null when it
     * belongs to no workflow step or the viewer may not view its workflow.
     *
     * @return array{approval_request_id: int|null, workflow_job_id: int|null, workflow_job_step_id: int|null}
     */
    public function apiFields(): array
    {
        return [
            'approval_request_id' => $this->approval?->requestId,
            'workflow_job_id' => $this->origin?->workflowJobId,
            'workflow_job_step_id' => $this->origin?->executionId,
        ];
    }
}
