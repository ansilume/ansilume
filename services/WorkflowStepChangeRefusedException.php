<?php

declare(strict_types=1);

namespace app\services;

/**
 * A change of a workflow's steps refused before anything was written, with
 * an HTTP-style status:
 * - 403: approval steps are for admins (ApprovalRuleAssignmentPolicy);
 * - 404: the workflow template was deleted meanwhile;
 * - 409: a run of the workflow is active, or other steps route to the step
 *   to remove;
 * - 422: a step that cannot be saved.
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
