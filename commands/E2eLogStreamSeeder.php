<?php

declare(strict_types=1);

namespace app\commands;

use app\models\Job;
use app\models\JobLog;

/**
 * Seeds two jobs exercising the live-log-streaming UI: one finished (with
 * all chunks persisted, no polling expected) and one running (with initial
 * chunks, active polling expected).
 */
class E2eLogStreamSeeder
{
    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    public function seed(int $userId, int $templateId): void
    {
        $this->deleteExisting();

        $finished = $this->createJob(
            $userId,
            $templateId,
            'e2e-logstream-finished',
            Job::STATUS_SUCCEEDED,
            time() - 120,
            time() - 60,
        );
        $this->createLog($finished->id, 1, 'stdout', "PLAY [localhost] ********************************\n");
        $this->createLog($finished->id, 2, 'stdout', "TASK [Gathering Facts] ***************************\nok: [localhost]\n");
        $this->createLog($finished->id, 3, 'stdout', "PLAY RECAP **************************************\nlocalhost : ok=1 changed=0 unreachable=0 failed=0\n");

        $running = $this->createJob(
            $userId,
            $templateId,
            'e2e-logstream-running',
            Job::STATUS_RUNNING,
            time() - 10,
            null,
        );
        $this->createLog($running->id, 1, 'stdout', "PLAY [webservers] *******************************\n");
        $this->createLog($running->id, 2, 'stdout', "TASK [install package] **************************\n");

        ($this->logger)("  Created log-stream fixtures: finished job #{$finished->id} (3 logs), running job #{$running->id} (2 logs).\n");
    }

    private function deleteExisting(): void
    {
        $ids = Job::find()
            ->select('id')
            ->where(['like', 'execution_command', 'e2e-logstream-'])
            ->column();
        if ($ids === []) {
            return;
        }
        JobLog::deleteAll(['job_id' => $ids]);
        Job::deleteAll(['id' => $ids]);
    }

    private function createJob(
        int $userId,
        int $templateId,
        string $executionCommand,
        string $status,
        int $startedAt,
        ?int $finishedAt,
    ): Job {
        $job = new Job();
        $job->job_template_id = $templateId;
        $job->launched_by = $userId;
        $job->status = $status;
        $job->exit_code = $status === Job::STATUS_SUCCEEDED ? 0 : null;
        $job->execution_command = $executionCommand;
        $job->timeout_minutes = 120;
        $job->has_changes = 0;
        $job->queued_at = $startedAt - 5;
        $job->started_at = $startedAt;
        $job->finished_at = $finishedAt;
        $job->created_at = $startedAt - 5;
        $job->updated_at = time();
        $job->save(false);
        return $job;
    }

    private function createLog(int $jobId, int $sequence, string $stream, string $content): void
    {
        $log = new JobLog();
        $log->job_id = $jobId;
        $log->sequence = $sequence;
        $log->stream = $stream;
        $log->content = $content;
        $log->created_at = time();
        $log->save(false);
    }
}
