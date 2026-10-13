<?php

declare(strict_types=1);

namespace app\components;

/**
 * The answer to a cancel or a resume of a workflow run that failed with a
 * database error (web: a danger flash; API: 500 with the message), and its
 * log line, which names the exception's class, SQLSTATE and driver error
 * code (DbErrorSummary), never its message: a database error's message
 * carries the SQL statement with its values.
 *
 * What such an error leaves differs with where it struck, so the message
 * tells the user to load the run again (its page in the web UI, GET
 * /api/v1/workflow-jobs/{id} in the API) and what to do then:
 * - a cancel that failed before it claimed the run left the run running,
 *   and can be tried again; one that failed after the claim left it
 *   canceled, and the maintenance run ends its steps and cancels their
 *   waiting jobs (StrandedJobSweep);
 * - a resume that failed before it moved the pause step on left the step
 *   waiting, and can be tried again; one that failed later, while the run
 *   went on to its next step, left the run where it stopped (it may have
 *   ended as failed, or wait at no step; such a run can be canceled).
 */
final class WorkflowRunOperationFailure
{
    /** The operation that failed: a cancel of the run. */
    public const CANCEL = 'cancel';

    /** The operation that failed: a resume of the run's pause step. */
    public const RESUME = 'resume';

    /** sprintf(): the run's ID. */
    public const CANCEL_FAILED = 'Canceling workflow run #%d failed with a database error; see the application log. '
        . 'Load the run again: a run that still runs can be canceled again; for a canceled run, the maintenance run '
        . 'ends its steps and cancels their waiting jobs.';

    /** sprintf(): the run's ID. */
    public const RESUME_FAILED = 'Resuming workflow run #%d failed with a database error; see the application log. '
        . 'Load the run again: a pause step that still waits can be resumed again.';

    /**
     * Logged at error level, category app\components\WorkflowRunOperationFailure.
     * sprintf(): the run's ID, the operation (CANCEL or RESUME), then the
     * exception's class, SQLSTATE and driver error code (DbErrorSummary).
     */
    public const LOG = 'Workflow run #%d: the %s failed with a database error: %s (SQLSTATE %s, driver error %s).';

    /**
     * Log $error, a database error of $operation (CANCEL or RESUME) on run
     * #$runId, and return the message for the user.
     */
    public static function report(string $operation, int $runId, \Throwable $error): string
    {
        \Yii::error(sprintf(self::LOG, $runId, $operation, ...DbErrorSummary::of($error)), self::class);

        return sprintf($operation === self::RESUME ? self::RESUME_FAILED : self::CANCEL_FAILED, $runId);
    }
}
