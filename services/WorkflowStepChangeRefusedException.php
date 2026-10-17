<?php

declare(strict_types=1);

namespace app\services;

/**
 * A change of a workflow's steps refused, with nothing written, with an
 * HTTP-style status:
 * - 403: gated steps are for admins (ApprovalRuleAssignmentPolicy: approval
 *   steps, and job steps whose job template or its inventory has an
 *   approval rule), or a job template the user may see but not operate
 *   (WorkflowStepWriteService::JOB_TEMPLATE_FORBIDDEN, details
 *   job_template_ids), or the user may no longer operate the project of
 *   every job step, checked again under the lock
 *   (WorkflowStepChangeGuard::TEAM_SCOPE_REFUSED);
 * - 404: the workflow template was deleted meanwhile
 *   (WorkflowStepChangeGuard::TEMPLATE_GONE), or the step is not (or no
 *   longer) one of the workflow (WorkflowStepWriteService::STEP_NOT_FOUND);
 * - 409: a run of the workflow is active (RUNS_ACTIVE, details
 *   workflow_job_ids), or other steps route to the step to remove
 *   (STEP_ROUTED_TO, details step_ids);
 * - 422: a request or a step that cannot be saved: the request's shape
 *   (WorkflowStepCommand, WorkflowStepPosition), another step type
 *   (TYPE_LOCKED), a job template that does not exist for the user
 *   (WorkflowStep::JOB_TEMPLATE_NOT_FOUND, details job_template_ids), the
 *   model's rules, or a position that cannot be taken
 *   (WorkflowStepReorderService).
 *
 * The message is meant for users. $details are the extra fields of the API
 * error, such as workflow_job_ids for an active run.
 */
final class WorkflowStepChangeRefusedException extends \RuntimeException
{
    public const FORBIDDEN = 403;
    public const NOT_FOUND = 404;
    public const CONFLICT = 409;
    public const INVALID = 422;

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $message, public readonly int $status, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
