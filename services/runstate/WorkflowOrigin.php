<?php

declare(strict_types=1);

namespace app\services\runstate;

/**
 * Where a job comes from in a workflow: the run (workflow job) and the step
 * execution (workflow job step) it was created for. A job step's execution
 * launches the step's job; an approval step's execution creates its
 * placeholder, a job that only holds the step's approval request and never
 * runs.
 *
 * ApprovalRequestContexts builds it only for a viewer who may view the
 * workflow (team scoping).
 */
final class WorkflowOrigin
{
    /**
     * @param int $workflowJobId the run
     * @param int $executionId the step execution the job was created for
     * @param int $workflowTemplateId the workflow
     * @param string $workflowName the workflow's name, also when the workflow was deleted since
     * @param string $stepName the step's name when it ran (WorkflowJobStep::stepName()), also when
     *     the step was removed since
     * @param bool $placeholder whether the job is the placeholder of an approval step (by the step's
     *     type when it ran, WorkflowJobStep::isApprovalPlaceholder())
     * @param bool $workflowDeleted whether the workflow was deleted since: its page answers 404, so the
     *     pages name it without a link, with Texts::WORKFLOW_DELETED after its name
     */
    public function __construct(
        public readonly int $workflowJobId,
        public readonly int $executionId,
        public readonly int $workflowTemplateId,
        public readonly string $workflowName,
        public readonly string $stepName,
        public readonly bool $placeholder,
        public readonly bool $workflowDeleted = false,
    ) {
    }
}
