<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\WorkflowJob;
use app\services\WorkflowCancellationService;

/**
 * A WorkflowCancellationService whose cancel of run #$failingRunId throws
 * (default: a deadlock whose message carries SECRET_VALUE, which no log may
 * show); run #$finishedRunId reports that it had finished meanwhile; every
 * other run is canceled for real.
 */
final class FailingWorkflowCancellation extends WorkflowCancellationService
{
    /** A value the default error's message carries. */
    public const SECRET_VALUE = 'secret-value-4711';

    private readonly \Throwable $error;

    public function __construct(
        private readonly int $failingRunId,
        private readonly int $finishedRunId = 0,
        ?\Throwable $error = null,
    ) {
        $this->error = $error ?? new \yii\db\Exception(
            "SQLSTATE[40001]: Serialization failure: UPDATE `workflow_job` SET `error_message`='" . self::SECRET_VALUE . "'",
            ['40001', 1213, 'Deadlock found when trying to get lock'],
        );
        parent::__construct();
    }

    public function cancelRun(WorkflowJob $run, ?int $actorId, string $reason): bool
    {
        if ((int)$run->id === $this->failingRunId) {
            throw $this->error;
        }
        if ((int)$run->id === $this->finishedRunId) {
            return false;
        }

        return parent::cancelRun($run, $actorId, $reason);
    }
}
