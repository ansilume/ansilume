<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\WorkflowRunOperationFailure;
use PHPUnit\Framework\TestCase;
use yii\db\Exception as DbException;

/**
 * The answer to a cancel or a resume of a workflow run that failed with a
 * database error. The web UI shows it as a flash and the API answers it
 * with 500 (WorkflowJobControllerDbErrorTest, WorkflowJobsControllerTest),
 * so it tells both what to do: load the run again, on its page or with
 * GET /api/v1/workflow-jobs/{id}.
 */
class WorkflowRunOperationFailureTest extends TestCase
{
    /**
     * regression: the answers told API callers to reload the page; they say
     * to load the run again, and nothing else changed in them (docs/troubleshooting.md
     * and web/openapi.yaml quote them).
     */
    public function testRegressionTheAnswersSayToLoadTheRunAgainNotToReloadThePage(): void
    {
        $error = new DbException('SQLSTATE[40001]: Deadlock found', ['40001', 1213, 'Deadlock found']);

        $this->assertSame(
            'Canceling workflow run #12 failed with a database error; see the application log. '
                . 'Load the run again: a run that still runs can be canceled again; for a canceled run, '
                . 'the maintenance run ends its steps and cancels their waiting jobs.',
            WorkflowRunOperationFailure::report(WorkflowRunOperationFailure::CANCEL, 12, $error)
        );
        $this->assertSame(
            'Resuming workflow run #12 failed with a database error; see the application log. '
                . 'Load the run again: a pause step that still waits can be resumed again.',
            WorkflowRunOperationFailure::report(WorkflowRunOperationFailure::RESUME, 12, $error)
        );
        foreach ([WorkflowRunOperationFailure::CANCEL_FAILED, WorkflowRunOperationFailure::RESUME_FAILED] as $message) {
            $this->assertStringNotContainsString('Reload the page', $message);
        }
    }
}
