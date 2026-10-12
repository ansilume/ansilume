<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\models\Job;
use app\models\JobLog;
use app\models\JobTask;
use app\models\JobTemplate;
use app\models\RunnerGroup;
use app\models\User;
use app\services\JobClaimService;
use app\services\JobCompletionService;
use app\tests\integration\DbTestCase;
use yii\log\Logger;

/**
 * When the claim lowered a job's verbosity for a runner that cannot take
 * encrypted survey answers, the job log says so at its end, after the
 * runner's output, once the job finishes or fails. The note is never
 * written at claim time (it would sort among the runner's first chunks),
 * and a failure to write it never keeps the job from finishing.
 */
class JobCompletionVerbosityNoteTest extends DbTestCase
{
    private const NOTE = '[ansilume] Verbosity was lowered from 5 to 3: this runner does not support encrypted survey '
        . 'answers and would print them at higher verbosity. Update the runner image.';

    private JobCompletionService $completion;
    private User $user;
    private JobTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var JobCompletionService $completion */
        $completion = \Yii::$app->get('jobCompletionService');
        $this->completion = $completion;
        $this->user = $this->createUser('verbosity-note');
        $this->template = $this->createPasswordSurveyTemplate((int)$this->user->id);
    }

    public function testAFinishedJobGetsTheNoteAfterTheRunnersOutput(): void
    {
        $job = $this->claimed(5, null);
        $this->runnerOutput($job, 3);

        $this->completion->complete($job, 0);

        $logs = $this->logs($job);
        $this->assertCount(4, $logs);
        $last = end($logs);
        $this->assertSame(self::NOTE, $last->content);
        $this->assertSame(JobLog::STREAM_STDERR, $last->stream);
        $this->assertSame(3, (int)$last->sequence, 'one past the runner output');
        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status);
    }

    public function testAFailedJobGetsTheNoteAfterTheOtherSystemLines(): void
    {
        $job = $this->claimed(4, 'vault_password_source');
        JobTask::deleteAll(['job_id' => $job->id]);

        $this->completion->complete($job, 0);

        $logs = $this->logs($job);
        $this->assertSame(Job::STATUS_FAILED, $job->status, 'no host matched');
        $this->assertCount(2, $logs);
        $this->assertStringStartsWith('Job did not execute against any host.', (string)$logs[0]->content);
        $this->assertSame(str_replace('from 5', 'from 4', self::NOTE), $logs[1]->content);
        $this->assertSame(2, (int)$logs[1]->sequence);
    }

    public function testATimedOutJobGetsTheNote(): void
    {
        $job = $this->claimed(5, null);
        $this->runnerOutput($job, 2);

        $this->completion->completeTimedOut($job);

        $logs = $this->logs($job);
        $this->assertSame(Job::STATUS_TIMED_OUT, $job->status);
        $this->assertSame(self::NOTE, end($logs)->content);
        $this->assertSame(2, (int)end($logs)->sequence);
    }

    /**
     * @return array<string, array{0: int, 1: string|null, 2: bool}>
     */
    public static function noNoteProvider(): array
    {
        return [
            'runner with the capability' => [5, 'vault_password_source,secret_extra_vars', true],
            'older runner, verbosity 3' => [3, null, true],
            'older runner, no answers' => [5, null, false],
        ];
    }

    /**
     * @dataProvider noNoteProvider
     */
    public function testNothingWasLoweredSoThereIsNoNote(int $verbosity, ?string $capabilities, bool $withAnswers): void
    {
        $job = $this->claimed($verbosity, $capabilities, $withAnswers);
        $this->runnerOutput($job, 1);

        $this->completion->complete($job, 0);

        $this->assertSame(['runner chunk 0'], array_map(static fn (JobLog $log): string => (string)$log->content, $this->logs($job)));
    }

    /**
     * Only the command the claim built counts, not a mention of the pin
     * somewhere in it.
     */
    public function testACommandThatOnlyMentionsThePinGetsNoNote(): void
    {
        $job = $this->claimed(5, 'vault_password_source,secret_extra_vars');
        $job->execution_command = 'ansible-playbook site.yml --limit ANSIBLE_VERBOSITY=0 -vvvvv';
        $job->save(false);

        $this->completion->complete($job, 0);

        $this->assertSame([], $this->logs($job));
    }

    /**
     * failBeforeExecution() runs inside the claim, also for a job claimed
     * before (reclaim): no note there.
     */
    public function testAJobFailedBeforeExecutionGetsNoNote(): void
    {
        $job = $this->claimed(5, null);

        $this->completion->failBeforeExecution($job, 'Job aborted before execution.');

        $this->assertSame(['Job aborted before execution.'], array_map(static fn (JobLog $log): string => (string)$log->content, $this->logs($job)));
    }

    /**
     * A runner reporting after the job was canceled changes nothing.
     */
    public function testALateCompletionGetsNoNote(): void
    {
        $job = $this->claimed(5, null);
        $this->completion->cancel($job, (int)$this->user->id);

        $this->completion->complete($job, 0);
        $this->completion->completeTimedOut($job);

        foreach ($this->logs($job) as $log) {
            $this->assertStringNotContainsString('Verbosity was lowered', (string)$log->content);
        }
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
    }

    /**
     * The note is an extra: when it cannot be written the job still
     * finishes, and the log line names the job and the error class only.
     */
    public function testANoteThatCannotBeWrittenIsLoggedAndTheJobStillFinishes(): void
    {
        $job = $this->claimed(5, null);
        $service = new class extends JobCompletionService {
            public function appendLog(Job $job, string $stream, string $content, int $sequence): void
            {
                throw new \yii\db\Exception("SQLSTATE[22003]: Numeric value out of range\nThe SQL being executed was: INSERT INTO `job_log` (`content`) VALUES ('note-marker-5150')");
            }
        };
        $logger = new Logger();
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $service->complete($job, 0);
        } finally {
            \Yii::setLogger($previous);
        }

        $job->refresh();
        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status);
        $this->assertNotNull($job->finished_at);
        $this->assertNotNull(AuditLog::find()->where(['action' => AuditLog::ACTION_JOB_FINISHED, 'object_id' => $job->id])->one());
        $own = array_values(array_filter($logger->messages, static fn (array $m): bool => $m[2] === JobCompletionService::class));
        $this->assertCount(1, $own);
        $this->assertSame(Logger::LEVEL_WARNING, $own[0][1]);
        $this->assertSame("JobCompletionService: the verbosity note for job #{$job->id} could not be written (yii\\db\\Exception).", $own[0][0]);
    }

    // -- Helpers ----------------------------------------------------------------------

    /**
     * A job of the password survey template, claimed by a runner with
     * $capabilities, with one task result (so it counts as having run).
     */
    private function claimed(int $verbosity, ?string $capabilities, bool $withAnswers = true): Job
    {
        $this->template->verbosity = $verbosity;
        $this->template->save(false);
        $job = $withAnswers
            ? $this->createJobWithSealedSecrets($this->template, (int)$this->user->id)
            : $this->createJobWithSealedSecrets($this->template, (int)$this->user->id, []);
        $runner = $this->createRunner((int)$this->template->runner_group_id, (int)$this->user->id);
        $runner->capabilities = $capabilities;
        $runner->save(false);
        $group = RunnerGroup::findOne($this->template->runner_group_id);
        $this->assertNotNull($group);
        /** @var JobClaimService $claim */
        $claim = \Yii::$app->get('jobClaimService');
        $this->assertIsArray($claim->claimNextPayload($group, $runner));
        $this->assertSame([], $this->logs($job), 'nothing is written at claim time');

        $task = new JobTask();
        $task->job_id = $job->id;
        $task->sequence = 0;
        $task->task_name = 'ping';
        $task->task_action = 'ping';
        $task->host = 'localhost';
        $task->status = 'ok';
        $task->changed = 0;
        $task->duration_ms = 1;
        $task->created_at = time();
        $task->save(false);
        $job->refresh();

        return $job;
    }

    private function runnerOutput(Job $job, int $chunks): void
    {
        for ($sequence = 0; $sequence < $chunks; $sequence++) {
            $this->completion->appendLog($job, JobLog::STREAM_STDOUT, 'runner chunk ' . $sequence, $sequence);
        }
    }

    /**
     * @return list<JobLog>
     */
    private function logs(Job $job): array
    {
        return JobLog::find()->where(['job_id' => $job->id])->orderBy(['sequence' => SORT_ASC, 'id' => SORT_ASC])->all();
    }
}
