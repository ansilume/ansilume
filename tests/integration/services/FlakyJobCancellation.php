<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Job;
use app\services\JobCancellationService;

/**
 * A JobCancellationService whose first calls of cancelWithinWorkflow() throw
 * the given errors, one per call, in order; later calls cancel for real.
 * With $always, every call throws the last error given. Counts the calls.
 */
final class FlakyJobCancellation extends JobCancellationService
{
    public int $withinWorkflowCalls = 0;

    /** Every call throws the last error given. */
    public bool $always = false;

    /** @var list<\Throwable> */
    private array $errors;

    private \Throwable $last;

    public function __construct(\Throwable $error, \Throwable ...$more)
    {
        $this->errors = [$error, ...array_values($more)];
        $this->last = $this->errors[count($this->errors) - 1];
        parent::__construct();
    }

    public function cancelWithinWorkflow(
        Job $job,
        ?int $actorId,
        int $workflowJobId,
        string $reason = self::REASON_WORKFLOW_CANCELED
    ): bool {
        $this->withinWorkflowCalls++;
        if ($this->always) {
            throw $this->last;
        }
        $error = array_shift($this->errors);
        if ($error !== null) {
            throw $error;
        }

        return parent::cancelWithinWorkflow($job, $actorId, $workflowJobId, $reason);
    }
}
