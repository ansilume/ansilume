<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\Job;
use app\services\JobCompletionService;

/**
 * Test double: JobCompletionService without its database writes.
 *
 * - transition() records the outcome and the explanation it is asked to
 *   write. While $finishedAs is null it ends the job, and the job shows the
 *   outcome as the refresh after the real UPDATE would; otherwise the job
 *   had finished already (a cancel came first) and shows $finishedAs.
 * - appendLog() records the job log lines instead of inserting them.
 * - didNothing() answers $nothingRan.
 */
final class RecordingJobCompletionService extends JobCompletionService
{
    /** @var list<array{outcome: array<string, int|string>, explanation: string|null}> */
    public array $transitions = [];

    /** @var list<array{stream: string, content: string}> */
    public array $lines = [];

    /** Whether the job ran against no host (no task results, no host summaries). */
    public bool $nothingRan = false;

    /** The status of a job that had finished before the report; null: the report ends the job. */
    public ?string $finishedAs = null;

    protected function didNothing(Job $job): bool
    {
        return $this->nothingRan;
    }

    protected function transition(Job $job, array $outcome, ?string $explanation): bool
    {
        $this->transitions[] = ['outcome' => $outcome, 'explanation' => $explanation];
        if ($this->finishedAs !== null) {
            $job->status = $this->finishedAs;

            return false;
        }
        foreach ($outcome as $name => $value) {
            $job->setAttribute($name, $value);
        }
        $job->finished_at = time();

        return true;
    }

    public function appendLog(Job $job, string $stream, string $content, int $sequence): void
    {
        $this->lines[] = ['stream' => $stream, 'content' => $content];
    }
}
