<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\models\Job;
use app\models\JobHostSummary;
use app\models\JobLog;
use app\models\JobTask;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\JobCancellationService;
use app\services\JobCompletionService;
use app\services\NotificationDispatcher;
use app\services\RunnerReportNumbers;
use app\services\WorkflowExecutionService;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for JobCompletionService using a real database.
 * Tests complete(), completeTimedOut(), failBeforeExecution(), appendLog(),
 * and saveTasks() against actual DB rows, including reports that lose the
 * race against a cancel that committed after the job was loaded.
 */
class JobCompletionServiceIntegrationTest extends DbTestCase
{
    private JobCompletionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var JobCompletionService $service */
        $service = \Yii::$app->get('jobCompletionService');
        $this->service = $service;
    }

    /** Some tests swap in recorders; restore the real services. */
    protected function tearDown(): void
    {
        \Yii::$app->set('webhookService', ['class' => \app\services\WebhookService::class]);
        \Yii::$app->set('notificationDispatcher', ['class' => NotificationDispatcher::class]);
        \Yii::$app->set('workflowExecutionService', ['class' => WorkflowExecutionService::class]);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // complete()
    // -------------------------------------------------------------------------

    public function testCompleteSetsSucceededStatusOnExitZero(): void
    {
        $job = $this->makeRunningJob();
        // Without at least one task or host summary, complete() now treats
        // exit-0 as "no hosts matched" and flips the status to FAILED.
        // This test exercises the normal succeeded path, so seed a task.
        $this->seedTaskFor($job, 'ok');

        $this->service->complete($job, 0);

        $job->refresh();
        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status);
    }

    /**
     * Regression: ansible-playbook returns exit 0 and an empty PLAY RECAP
     * when the `hosts:` pattern matches nothing. With the old behaviour
     * this showed up as "succeeded" in the UI even though nothing ran.
     * complete() must now flip these runs to FAILED and append a clear
     * log line so the operator sees why.
     */
    public function testCompleteWithNoTasksAndNoHostSummariesIsMarkedFailed(): void
    {
        $job = $this->makeRunningJob();

        $this->service->complete($job, 0);

        $job->refresh();
        $this->assertSame(
            Job::STATUS_FAILED,
            $job->status,
            'A run with no executed tasks and no host summary must not report as succeeded.',
        );

        $logs = JobLog::find()
            ->where(['job_id' => $job->id, 'stream' => 'stderr'])
            ->orderBy(['sequence' => SORT_DESC])
            ->limit(1)
            ->one();
        $this->assertNotNull($logs, 'An explanatory stderr log entry must be appended.');
        $this->assertStringContainsString('did not execute against any host', (string)$logs->content);
    }

    public function testCompleteWithOnlyHostSummaryIsStillSucceeded(): void
    {
        // A host summary alone (e.g. fact-gathering play with no tasks) is
        // still "something happened" — the safeguard must not fire.
        $job = $this->makeRunningJob();
        $summary = new JobHostSummary();
        $summary->job_id = $job->id;
        $summary->host = 'localhost';
        $summary->ok = 1;
        $summary->changed = 0;
        $summary->failed = 0;
        $summary->unreachable = 0;
        $summary->skipped = 0;
        $summary->rescued = 0;
        $summary->created_at = time();
        $summary->save(false);

        $this->service->complete($job, 0);

        $job->refresh();
        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status);
    }

    public function testCompleteSetsFailedStatusOnNonZeroExit(): void
    {
        $job = $this->makeRunningJob();

        $this->service->complete($job, 1);

        $job->refresh();
        $this->assertSame(Job::STATUS_FAILED, $job->status);
    }

    public function testCompletePersistsExitCode(): void
    {
        $job = $this->makeRunningJob();

        $this->service->complete($job, 42);

        $job->refresh();
        $this->assertSame(42, (int)$job->exit_code);
    }

    public function testCompletePersistsFinishedAt(): void
    {
        $before = time();
        $job    = $this->makeRunningJob();

        $this->service->complete($job, 0);

        $job->refresh();
        $this->assertGreaterThanOrEqual($before, (int)$job->finished_at);
    }

    public function testCompleteWithHasChangesMarksJob(): void
    {
        $job = $this->makeRunningJob();

        $this->service->complete($job, 0, hasChanges: true);

        $job->refresh();
        $this->assertSame(1, (int)$job->has_changes);
    }

    public function testCompleteWritesAuditLog(): void
    {
        $job    = $this->makeRunningJob();
        $before = (int)\app\models\AuditLog::find()->count();

        $this->service->complete($job, 0);

        $this->assertGreaterThan($before, (int)\app\models\AuditLog::find()->count());
    }

    // -------------------------------------------------------------------------
    // Late-report race guard (regression): complete() / completeTimedOut()
    // must not overwrite an already-terminal status (cancel, timeout, reject).
    // -------------------------------------------------------------------------

    public function testCompleteOnCanceledJobLeavesStatusUntouched(): void
    {
        $job = $this->makeCanceledJob();
        $originalFinishedAt = (int)$job->finished_at;

        $this->service->complete($job, 0, hasChanges: true);

        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status, 'Cancel decision must survive a late runner complete() call.');
        $this->assertSame($originalFinishedAt, (int)$job->finished_at, 'finished_at must not shift on the late report.');
        $this->assertNull($job->exit_code, 'exit_code from the late report must not be stored.');
        $this->assertSame(0, (int)$job->has_changes);
    }

    public function testCompleteOnCanceledJobAppendsForensicLog(): void
    {
        $job = $this->makeCanceledJob();

        $this->service->complete($job, 0);

        $log = JobLog::find()
            ->where(['job_id' => $job->id, 'stream' => JobLog::STREAM_STDERR])
            ->orderBy(['sequence' => SORT_DESC])
            ->one();
        $this->assertNotNull($log);
        $this->assertSame(
            'Runner reported completion (exit_code=0) after job was already in terminal status "canceled" — ignored.',
            (string)$log->content
        );
    }

    public function testCompleteOnCanceledJobDoesNotWriteFinishedAudit(): void
    {
        $job = $this->makeCanceledJob();
        $auditBefore = (int)\app\models\AuditLog::find()
            ->where(['object_type' => 'job', 'object_id' => $job->id, 'action' => 'job.finished'])
            ->count();

        $this->service->complete($job, 0);

        $auditAfter = (int)\app\models\AuditLog::find()
            ->where(['object_type' => 'job', 'object_id' => $job->id, 'action' => 'job.finished'])
            ->count();
        $this->assertSame($auditBefore, $auditAfter, 'Late complete() must not emit a second job.finished audit entry.');
    }

    public function testCompleteTimedOutOnCanceledJobLeavesStatusUntouched(): void
    {
        $job = $this->makeCanceledJob();
        $originalFinishedAt = (int)$job->finished_at;

        $this->service->completeTimedOut($job);

        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $this->assertSame($originalFinishedAt, (int)$job->finished_at);
    }

    // -------------------------------------------------------------------------
    // A cancel that commits after the runner API (or the claim) loaded the job
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function staleCompletionProvider(): array
    {
        return [
            'success report' => [0, true],
            'failure report' => [2, true],
            'success report of a run against no host' => [0, false],
        ];
    }

    /**
     * Regression: a runner's completion overwrote a cancel that committed
     * after the runner had loaded the job. complete() checked the loaded
     * model, which still said running, and saved over the canceled job, so
     * it ended as succeeded or failed although the cancel had succeeded,
     * with a job.finished audit entry, a webhook and a notification on top.
     *
     * @dataProvider staleCompletionProvider
     */
    public function testACompletionDoesNotOverwriteACancelThatCommittedAfterTheLoad(int $exitCode, bool $withTaskResults): void
    {
        $job = $this->makeRunningJob();
        if ($withTaskResults) {
            $this->seedTaskFor($job, 'ok');
        }
        $canceledAt = $this->cancelMeanwhile($job);
        $webhooks = $this->captureWebhooks();
        $notifications = $this->captureNotifications();

        $this->service->complete($job, $exitCode, hasChanges: true);

        $this->assertStillCanceled($job, $canceledAt);
        $this->assertSame(Job::STATUS_CANCELED, $job->status, 'the model shows the status the job has now');
        $this->assertSame(
            [sprintf(JobCompletionService::LATE_COMPLETION, $exitCode, Job::STATUS_CANCELED)],
            $this->logLinesOf($job),
            'only the line that says the report was ignored, no line about hosts'
        );
        $this->assertSame([], $webhooks->events);
        $this->assertSame([], $notifications->events);
    }

    /**
     * Regression: a runner's timeout report overwrote a cancel that
     * committed after the runner had loaded the job.
     */
    public function testATimeoutReportDoesNotOverwriteACancelThatCommittedAfterTheLoad(): void
    {
        $job = $this->makeRunningJob();
        $canceledAt = $this->cancelMeanwhile($job);
        $webhooks = $this->captureWebhooks();
        $notifications = $this->captureNotifications();

        $this->service->completeTimedOut($job);

        $this->assertStillCanceled($job, $canceledAt);
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $this->assertSame(
            ['Runner reported timeout after job was already in terminal status "canceled" — ignored.'],
            $this->logLinesOf($job)
        );
        $this->assertSame([], $webhooks->events);
        $this->assertSame([], $notifications->events);
    }

    /**
     * Regression: a failure before execution (a credential of the claimed
     * job cannot be used) overwrote a cancel that committed after the claim
     * had loaded the job. The reason is not written either: the job never
     * failed.
     */
    public function testAFailureBeforeExecutionDoesNotOverwriteACancelThatCommittedAfterTheLoad(): void
    {
        $job = $this->makeRunningJob();
        $canceledAt = $this->cancelMeanwhile($job);
        $webhooks = $this->captureWebhooks();
        $notifications = $this->captureNotifications();

        $this->service->failBeforeExecution($job, 'Job aborted before execution: 1 credential(s) could not be used.');

        $this->assertStillCanceled($job, $canceledAt);
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $this->assertSame([], $this->logLinesOf($job));
        $this->assertSame([], $webhooks->events);
        $this->assertSame([], $notifications->events);
    }

    /**
     * Regression: the late completion of a canceled workflow step's job
     * advanced the run a second time. The cancel had failed the step and
     * the run had taken its failure route; the completion overwrote the job
     * as succeeded and moved the step on again, into its success route.
     */
    public function testALateCompletionDoesNotAdvanceTheWorkflowOfACanceledJobAgain(): void
    {
        $user = $this->createUser();
        $template = $this->createJobTemplate(
            (int)$this->createProject((int)$user->id)->id,
            (int)$this->createInventory((int)$user->id)->id,
            (int)$this->createRunnerGroup((int)$user->id)->id,
            (int)$user->id
        );
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        $first = $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_JOB, (int)$template->id);
        $onSuccess = $this->createWorkflowStep((int)$workflow->id, 20, WorkflowStep::TYPE_JOB, (int)$template->id);
        $onFailure = $this->createWorkflowStep((int)$workflow->id, 30, WorkflowStep::TYPE_PAUSE);
        $first->on_success_step_id = $onSuccess->id;
        $first->on_failure_step_id = $onFailure->id;
        $first->save(false);
        $job = $this->createJob((int)$template->id, (int)$user->id, Job::STATUS_RUNNING);
        $run = $this->runningRunAt($first, $job);
        $this->seedTaskFor($job, 'ok');
        $this->cancelMeanwhile($job);
        $executions = $this->executionsOf($run);
        $engine = $this->recordWorkflowAdvances();

        $this->service->complete($job, 0);

        $this->assertSame([], $engine->advancedJobIds, 'the late report does not move the run');
        $this->assertSame($executions, $this->executionsOf($run), 'no execution changed and none started');
        $this->assertFalse(
            WorkflowJobStep::find()->where(['workflow_job_id' => $run->id, 'workflow_step_id' => $onSuccess->id])->exists(),
            'the success route never ran'
        );
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
    }

    /**
     * @return array<string, array{0: \Closure(JobCompletionService, Job): void}>
     */
    public static function reportProvider(): array
    {
        return [
            'completion' => [static fn (JobCompletionService $service, Job $job) => $service->complete($job, 0)],
            'timeout' => [static fn (JobCompletionService $service, Job $job) => $service->completeTimedOut($job)],
            'failure before execution' => [
                static fn (JobCompletionService $service, Job $job) => $service->failBeforeExecution($job, 'Job aborted before execution.'),
            ],
        ];
    }

    /**
     * A report that ends the job of a workflow step moves the run on, once:
     * the engine ends the step and follows its route.
     *
     * @dataProvider reportProvider
     * @param \Closure(JobCompletionService, Job): void $report
     */
    public function testAReportThatEndsTheJobOfAWorkflowStepMovesTheRunOn(\Closure $report): void
    {
        $user = $this->createUser();
        $template = $this->createJobTemplate(
            (int)$this->createProject((int)$user->id)->id,
            (int)$this->createInventory((int)$user->id)->id,
            (int)$this->createRunnerGroup((int)$user->id)->id,
            (int)$user->id
        );
        $step = $this->createWorkflowStep((int)$this->createWorkflowTemplate((int)$user->id)->id, 10, WorkflowStep::TYPE_JOB, (int)$template->id);
        $job = $this->createJob((int)$template->id, (int)$user->id, Job::STATUS_RUNNING);
        $this->seedTaskFor($job, 'ok');
        $run = $this->runningRunAt($step, $job);
        $engine = $this->recordWorkflowAdvances();

        $report($this->service, $job);

        $this->assertSame([(int)$job->id], $engine->advancedJobIds);
        $execution = WorkflowJobStep::findOne(['workflow_job_id' => $run->id, 'job_id' => $job->id]);
        $this->assertNotNull($execution);
        $this->assertNotSame(WorkflowJobStep::STATUS_RUNNING, $execution->status, 'the step ended with its job');
    }

    /**
     * The line that explains a failure is written in the same transaction
     * as the status: a failed job without its reason is never committed.
     */
    public function testAJobWhoseExplanationCannotBeWrittenIsNotEnded(): void
    {
        $job = $this->makeRunningJob();
        $service = new class extends JobCompletionService {
            public function appendLog(Job $job, string $stream, string $content, int $sequence): void
            {
                throw new \yii\db\Exception('SQLSTATE[HY000]: General error: 1030 Got error 28 "No space left on device" from storage engine');
            }
        };

        try {
            $service->failBeforeExecution($job, 'Job aborted before execution.');
            $this->fail('expected the failed log write to end the call');
        } catch (\yii\db\Exception $e) {
            $this->assertStringContainsString('1030', $e->getMessage());
        }

        $stored = Job::findOne($job->id);
        $this->assertNotNull($stored);
        $this->assertSame(Job::STATUS_RUNNING, $stored->status, 'rolled back with the line');
        $this->assertNull($stored->finished_at);
        $this->assertNull($stored->exit_code);
    }

    /**
     * The runner API answers with the job's status from the model: after
     * the transition it shows what was stored.
     */
    public function testTheModelShowsTheStoredOutcome(): void
    {
        $job = $this->makeRunningJob();
        $this->seedTaskFor($job, 'ok');
        $before = time();

        $this->service->complete($job, 0, hasChanges: true);

        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status);
        $this->assertSame(0, $job->exit_code);
        $this->assertSame(1, (int)$job->has_changes);
        $this->assertGreaterThanOrEqual($before, (int)$job->finished_at);
        $this->assertGreaterThanOrEqual($before, (int)$job->updated_at);
        $this->assertSame([], $job->getDirtyAttributes(), 'nothing left for a later save() to write again');
    }

    // -------------------------------------------------------------------------
    // appendLog()
    // -------------------------------------------------------------------------

    public function testAppendLogCreatesJobLogRecord(): void
    {
        $job    = $this->makeRunningJob();
        $before = (int)JobLog::find()->where(['job_id' => $job->id])->count();

        $this->service->appendLog($job, JobLog::STREAM_STDOUT, 'Hello output', 1);

        $this->assertSame($before + 1, (int)JobLog::find()->where(['job_id' => $job->id])->count());
    }

    public function testAppendLogPersistsContent(): void
    {
        $job = $this->makeRunningJob();

        $this->service->appendLog($job, JobLog::STREAM_STDOUT, 'Test content', 1);

        $log = JobLog::find()->where(['job_id' => $job->id])->one();
        $this->assertNotNull($log);
        $this->assertSame('Test content', $log->content);
        $this->assertSame(JobLog::STREAM_STDOUT, $log->stream);
        $this->assertSame(1, $log->sequence);
    }

    public function testAppendLogSupportsStderrStream(): void
    {
        $job = $this->makeRunningJob();

        $this->service->appendLog($job, JobLog::STREAM_STDERR, 'Error output', 2);

        $log = JobLog::find()->where(['job_id' => $job->id, 'stream' => JobLog::STREAM_STDERR])->one();
        $this->assertNotNull($log);
        $this->assertSame('Error output', $log->content);
    }

    public function testAppendLogPreservesSequence(): void
    {
        $job = $this->makeRunningJob();

        $this->service->appendLog($job, JobLog::STREAM_STDOUT, 'line1', 10);
        $this->service->appendLog($job, JobLog::STREAM_STDOUT, 'line2', 20);

        $logs = JobLog::find()->where(['job_id' => $job->id])->orderBy(['sequence' => SORT_ASC])->all();
        $this->assertSame(10, $logs[0]->sequence);
        $this->assertSame(20, $logs[1]->sequence);
    }

    // -------------------------------------------------------------------------
    // saveTasks()
    // -------------------------------------------------------------------------

    public function testSaveTasksCreatesJobTaskRecords(): void
    {
        $job    = $this->makeRunningJob();
        $before = (int)JobTask::find()->where(['job_id' => $job->id])->count();

        $this->service->saveTasks($job, [
            ['seq' => 1, 'name' => 'Install nginx', 'action' => 'apt', 'host' => 'web1', 'status' => 'ok', 'changed' => false, 'duration_ms' => 120],
            ['seq' => 2, 'name' => 'Start nginx',   'action' => 'service', 'host' => 'web1', 'status' => 'ok', 'changed' => true, 'duration_ms' => 30],
        ]);

        $this->assertSame($before + 2, (int)JobTask::find()->where(['job_id' => $job->id])->count());
    }

    public function testSaveTasksCreatesHostSummaries(): void
    {
        $job = $this->makeRunningJob();

        $this->service->saveTasks($job, [
            ['seq' => 1, 'name' => 'task', 'action' => 'apt', 'host' => 'web1', 'status' => 'ok', 'changed' => false, 'duration_ms' => 0],
        ]);

        $summary = JobHostSummary::findOne(['job_id' => $job->id, 'host' => 'web1']);
        $this->assertNotNull($summary);
    }

    public function testSaveTasksCountsChangedTasksInSummary(): void
    {
        $job = $this->makeRunningJob();

        $this->service->saveTasks($job, [
            ['seq' => 1, 'name' => 'a', 'action' => 'copy', 'host' => 'web1', 'status' => 'ok', 'changed' => true, 'duration_ms' => 0],
            ['seq' => 2, 'name' => 'b', 'action' => 'copy', 'host' => 'web1', 'status' => 'ok', 'changed' => true, 'duration_ms' => 0],
        ]);

        $summary = JobHostSummary::findOne(['job_id' => $job->id, 'host' => 'web1']);
        $this->assertNotNull($summary);
        $this->assertSame(2, (int)$summary->changed);
    }

    public function testSaveTasksSetsHasChangesOnJob(): void
    {
        $job = $this->makeRunningJob();

        $this->service->saveTasks($job, [
            ['seq' => 1, 'name' => 'task', 'action' => 'copy', 'host' => 'h1', 'status' => 'ok', 'changed' => true, 'duration_ms' => 0],
        ]);

        $job->refresh();
        $this->assertSame(1, (int)$job->has_changes);
    }

    public function testSaveTasksHandlesEmptyTaskList(): void
    {
        $job = $this->makeRunningJob();

        $this->service->saveTasks($job, []);

        $this->assertSame(0, (int)JobTask::find()->where(['job_id' => $job->id])->count());
    }

    public function testSaveTasksKeepsHasChangesFalseWhenNothingChanged(): void
    {
        $job = $this->makeRunningJob();

        $this->service->saveTasks($job, [
            ['seq' => 1, 'name' => 'Gather facts', 'action' => 'setup', 'host' => 'web1', 'status' => 'ok',
                'changed' => false, 'duration_ms' => 500],
        ]);

        $job->refresh();
        $this->assertSame(0, (int)$job->has_changes);
    }

    public function testSaveTasksMapsAllFields(): void
    {
        $job = $this->makeRunningJob();

        $this->service->saveTasks($job, [
            ['seq' => 5, 'name' => 'Deploy app', 'action' => 'copy', 'host' => 'srv1', 'status' => 'ok',
                'changed' => true, 'duration_ms' => 1500],
        ]);

        $task = JobTask::find()->where(['job_id' => $job->id])->one();
        $this->assertNotNull($task);
        $this->assertSame(5, $task->sequence);
        $this->assertSame('Deploy app', $task->task_name);
        $this->assertSame('copy', $task->task_action);
        $this->assertSame('srv1', $task->host);
        $this->assertSame('ok', $task->status);
        $this->assertSame(1, (int)$task->changed);
        $this->assertSame(1500, (int)$task->duration_ms);
    }

    public function testSaveTasksHandlesMultipleHosts(): void
    {
        $job = $this->makeRunningJob();

        $this->service->saveTasks($job, [
            ['seq' => 1, 'name' => 'ping', 'action' => 'ping', 'host' => 'web1', 'status' => 'ok',
                'changed' => false, 'duration_ms' => 10],
            ['seq' => 2, 'name' => 'ping', 'action' => 'ping', 'host' => 'web2', 'status' => 'ok',
                'changed' => false, 'duration_ms' => 12],
            ['seq' => 3, 'name' => 'ping', 'action' => 'ping', 'host' => 'db1', 'status' => 'unreachable',
                'changed' => false, 'duration_ms' => 5000],
        ]);

        $records = JobTask::find()->where(['job_id' => $job->id])->orderBy('sequence')->all();
        $this->assertCount(3, $records);
        $this->assertSame('web1', $records[0]->host);
        $this->assertSame('web2', $records[1]->host);
        $this->assertSame('db1', $records[2]->host);
        $this->assertSame('unreachable', $records[2]->status);
    }

    public function testSaveTasksAnswersNullWhenItSavedTheTasks(): void
    {
        $job = $this->makeRunningJob();

        $this->assertNull($this->service->saveTasks($job, [['seq' => 0, 'host' => 'web1', 'duration_ms' => 3]]));

        $this->assertSame(1, (int)JobTask::find()->where(['job_id' => $job->id])->count());
    }

    /**
     * Regression: a task with a JSON number beyond the int range failed
     * saveTasks() with an ErrorException on PHP 8.5 ("The float INF is not
     * representable as an int, cast occurred"), after the tasks before it
     * were saved. Now the list is refused whole: the refusal is answered,
     * and no task, host summary or has_changes is written.
     */
    public function testSaveTasksRefusesAListWithATaskItCannotSaveAndWritesNothing(): void
    {
        $job = $this->makeRunningJob();
        $body = json_decode('{"tasks": [{"seq": 0, "host": "web1", "changed": true}, {"seq": 1, "host": "web1", "duration_ms": 1e999}]}', true);
        $this->assertIsArray($body);
        $this->assertIsArray($body['tasks']);

        $this->assertSame(
            sprintf(RunnerReportNumbers::TASK_NUMBER_REFUSED, 1, 'duration_ms'),
            $this->service->saveTasks($job, $body['tasks'])
        );

        $this->assertSame(0, (int)JobTask::find()->where(['job_id' => $job->id])->count());
        $this->assertSame(0, (int)JobHostSummary::find()->where(['job_id' => $job->id])->count());
        $job->refresh();
        $this->assertSame(0, (int)$job->has_changes);
    }

    public function testSaveTasksAppliesDefaultsForMissingFields(): void
    {
        $job = $this->makeRunningJob();

        $this->service->saveTasks($job, [['seq' => 0]]);

        $task = JobTask::find()->where(['job_id' => $job->id])->one();
        $this->assertNotNull($task);
        $this->assertSame('', $task->task_name);
        $this->assertSame('', $task->task_action);
        $this->assertSame('', $task->host);
        $this->assertSame('ok', $task->status);
        $this->assertSame(0, (int)$task->changed);
        $this->assertSame(0, (int)$task->duration_ms);
    }

    // -------------------------------------------------------------------------
    // cancel() — operator-initiated cancellation
    // -------------------------------------------------------------------------

    public function testCancelFlipsStatusAndStampsFinishedAt(): void
    {
        $job = $this->makeRunningJob();
        $before = time();

        $this->assertTrue($this->service->cancel($job, $job->launched_by));

        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $this->assertGreaterThanOrEqual($before, (int)$job->finished_at);
    }

    public function testCancelWritesJobCanceledAudit(): void
    {
        $job = $this->makeRunningJob();
        $auditBefore = (int)\app\models\AuditLog::find()
            ->where(['action' => 'job.canceled', 'object_type' => 'job', 'object_id' => $job->id])
            ->count();

        $this->service->cancel($job, $job->launched_by);

        $auditAfter = (int)\app\models\AuditLog::find()
            ->where(['action' => 'job.canceled', 'object_type' => 'job', 'object_id' => $job->id])
            ->count();
        $this->assertSame($auditBefore + 1, $auditAfter);
    }

    public function testCancelOnAlreadyFinishedJobIsNoop(): void
    {
        // Defensive: a double-cancel race or a cancel-after-success must
        // not tear down the previous terminal decision. The first writer
        // wins.
        $job = $this->makeCanceledJob();
        $originalFinishedAt = (int)$job->finished_at;

        $this->assertFalse($this->service->cancel($job, $job->launched_by));

        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $this->assertSame($originalFinishedAt, (int)$job->finished_at);
    }

    public function testCancelAdvancesParentWorkflow(): void
    {
        // Regression: the controller used to flip Job.status by hand and
        // skipped the workflow-advance hook entirely, leaving the
        // WorkflowJobStep on STATUS_RUNNING and the parent WorkflowJob on
        // STATUS_RUNNING even after the child job was canceled. Cancel must
        // call onChildJobCompleted so the step transitions to FAILED and
        // the workflow follows on_failure (or completes).
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        $wfTemplate = $this->createWorkflowTemplate($user->id);
        $wfStep = $this->createWorkflowStep(
            $wfTemplate->id,
            10,
            \app\models\WorkflowStep::TYPE_JOB,
            $template->id,
        );

        $wfJob = new \app\models\WorkflowJob();
        $wfJob->workflow_template_id = $wfTemplate->id;
        $wfJob->launched_by = $user->id;
        $wfJob->status = \app\models\WorkflowJob::STATUS_RUNNING;
        $wfJob->started_at = time();
        $wfJob->current_step_id = $wfStep->id;
        $wfJob->created_at = time();
        $wfJob->updated_at = time();
        $wfJob->save(false);

        $job = $this->createJob($template->id, $user->id, Job::STATUS_RUNNING);
        $wjs = new \app\models\WorkflowJobStep();
        $wjs->workflow_job_id = $wfJob->id;
        $wjs->workflow_step_id = $wfStep->id;
        $wjs->job_id = $job->id;
        $wjs->status = \app\models\WorkflowJobStep::STATUS_RUNNING;
        $wjs->started_at = time();
        $wjs->save(false);

        $this->service->cancel($job, $user->id);

        $wjs->refresh();
        $wfJob->refresh();
        $this->assertSame(\app\models\WorkflowJobStep::STATUS_FAILED, $wjs->status);
        $this->assertNotNull($wjs->finished_at);
        // No on_failure target → workflow completes with a non-running status.
        $this->assertNotSame(\app\models\WorkflowJob::STATUS_RUNNING, $wfJob->status);
    }

    // -------------------------------------------------------------------------
    // failBeforeExecution()
    // -------------------------------------------------------------------------

    public function testFailBeforeExecutionEndsTheJobAsFailedWithTheReason(): void
    {
        $job = $this->makeRunningJob();
        $webhooks = $this->captureWebhooks();

        $this->service->failBeforeExecution($job, "Job aborted before execution: 1 credential(s) could not be used.");

        $job->refresh();
        $this->assertSame(Job::STATUS_FAILED, $job->status);
        $this->assertSame(-1, (int)$job->exit_code);
        $this->assertNotNull($job->finished_at);
        $log = JobLog::find()->where(['job_id' => $job->id, 'stream' => JobLog::STREAM_STDERR])->one();
        $this->assertNotNull($log);
        $this->assertStringContainsString('could not be used', (string)$log->content);
        $audit = \app\models\AuditLog::find()
            ->where(['action' => \app\models\AuditLog::ACTION_JOB_FINISHED, 'object_id' => $job->id])
            ->one();
        $this->assertNotNull($audit);
        $this->assertSame(
            ['exit_code' => -1, 'status' => Job::STATUS_FAILED, 'reason' => 'pre_execution_failure'],
            json_decode((string)$audit->metadata, true)
        );
        $this->assertSame([\app\models\Webhook::EVENT_JOB_FAILURE], $webhooks->events);
    }

    /**
     * A refused claim's audit context follows the base keys in the stored
     * job.finished entry, and cannot replace them.
     */
    public function testFailBeforeExecutionStoresTheAuditContextAfterTheBaseKeys(): void
    {
        $job = $this->makeRunningJob();
        $this->captureWebhooks();

        $this->service->failBeforeExecution($job, 'Job aborted before execution.', [
            'claim_refusal' => 'inventory_missing',
            'inventory_id' => 42,
            'status' => Job::STATUS_SUCCEEDED,
        ]);

        $audit = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_JOB_FINISHED, 'object_type' => 'job', 'object_id' => $job->id])
            ->one();
        $this->assertNotNull($audit);
        $this->assertSame(
            [
                'exit_code' => -1,
                'status' => Job::STATUS_FAILED,
                'reason' => 'pre_execution_failure',
                'claim_refusal' => 'inventory_missing',
                'inventory_id' => 42,
            ],
            json_decode((string)$audit->metadata, true)
        );
        $this->assertSame(['Job aborted before execution.'], $this->logLinesOf($job));
    }

    public function testFailBeforeExecutionLeavesAFinishedJobUntouched(): void
    {
        $job = $this->makeCanceledJob();
        $webhooks = $this->captureWebhooks();

        $this->service->failBeforeExecution($job, 'too late');

        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $this->assertNull($job->exit_code);
        $this->assertSame(0, (int)JobLog::find()->where(['job_id' => $job->id])->count());
        $this->assertSame([], $webhooks->events);
    }

    public function testCompleteTimedOutStillSendsTheFailureWebhook(): void
    {
        $job = $this->makeRunningJob();
        $webhooks = $this->captureWebhooks();

        $before = time();

        $this->service->completeTimedOut($job);

        $job->refresh();
        $this->assertSame(Job::STATUS_TIMED_OUT, $job->status);
        $this->assertSame(-1, (int)$job->exit_code);
        $this->assertGreaterThanOrEqual($before, (int)$job->finished_at);
        $this->assertSame([\app\models\Webhook::EVENT_JOB_FAILURE], $webhooks->events);
    }

    /**
     * Replaces webhookService with a recorder for the rest of the test.
     *
     * @return object{events: list<string>}
     */
    private function captureWebhooks(): object
    {
        $recorder = new class extends \app\services\WebhookService {
            /** @var list<string> */
            public array $events = [];

            public function dispatch(string $event, Job $job): void
            {
                $this->events[] = $event;
            }
        };
        \Yii::$app->set('webhookService', $recorder);

        return $recorder;
    }

    /**
     * Replaces notificationDispatcher with a recorder for the rest of the test.
     *
     * @return object{events: list<string>}
     */
    private function captureNotifications(): object
    {
        $recorder = new class extends NotificationDispatcher {
            /** @var list<string> */
            public array $events = [];

            public function dispatch(string $event, array $payload = []): void
            {
                $this->events[] = $event;
            }
        };
        \Yii::$app->set('notificationDispatcher', $recorder);

        return $recorder;
    }

    /**
     * Replaces workflowExecutionService with one that records the jobs it
     * is told have completed, then does what the real one does.
     *
     * @return object{advancedJobIds: list<int>}
     */
    private function recordWorkflowAdvances(): object
    {
        $engine = new class extends WorkflowExecutionService {
            /** @var list<int> */
            public array $advancedJobIds = [];

            public function onChildJobCompleted(Job $job): void
            {
                $this->advancedJobIds[] = (int)$job->id;
                parent::onChildJobCompleted($job);
            }
        };
        \Yii::$app->set('workflowExecutionService', $engine);

        return $engine;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * An operator cancels the job through its own copy of it, as a web or
     * API request does while the runner holds $job: the cancel commits
     * after $job was loaded, and $job still says running.
     *
     * @return int the finished_at the cancel wrote
     */
    private function cancelMeanwhile(Job $job): int
    {
        $copy = Job::findOne($job->id);
        $this->assertNotNull($copy);
        /** @var JobCancellationService $cancellation */
        $cancellation = \Yii::$app->get('jobCancellationService');
        $this->assertTrue($cancellation->cancel($copy, (int)$job->launched_by));
        $this->assertSame(Job::STATUS_RUNNING, $job->status, 'the runner\'s model is stale');

        return (int)$copy->finished_at;
    }

    /**
     * The job is as the cancel left it: canceled at $canceledAt, without an
     * exit code, changes or a job.finished audit entry.
     */
    private function assertStillCanceled(Job $job, int $canceledAt): void
    {
        $stored = Job::findOne($job->id);
        $this->assertNotNull($stored);
        $this->assertSame(Job::STATUS_CANCELED, $stored->status, 'the cancel stays');
        $this->assertSame($canceledAt, (int)$stored->finished_at, 'finished_at is the cancel\'s');
        $this->assertNull($stored->exit_code, 'no exit code from the late report');
        $this->assertSame(0, (int)$stored->has_changes);
        $this->assertFalse(
            AuditLog::find()->where(['action' => AuditLog::ACTION_JOB_FINISHED, 'object_type' => 'job', 'object_id' => $job->id])->exists(),
            'no job.finished audit entry'
        );
    }

    /**
     * @return list<string> the job's log lines, in order
     */
    private function logLinesOf(Job $job): array
    {
        /** @var list<JobLog> $logs */
        $logs = JobLog::find()->where(['job_id' => $job->id])->orderBy(['sequence' => SORT_ASC, 'id' => SORT_ASC])->all();

        return array_map(static fn (JobLog $log): string => (string)$log->content, $logs);
    }

    /**
     * A running run of $step's workflow whose step $step runs $job.
     */
    private function runningRunAt(WorkflowStep $step, Job $job): WorkflowJob
    {
        $run = new WorkflowJob();
        $run->workflow_template_id = $step->workflow_template_id;
        $run->launched_by = $job->launched_by;
        $run->status = WorkflowJob::STATUS_RUNNING;
        $run->started_at = time();
        $run->current_step_id = $step->id;
        $run->created_at = time();
        $run->updated_at = time();
        $run->save(false);
        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = $run->id;
        $execution->workflow_step_id = $step->id;
        $execution->job_id = $job->id;
        $execution->status = WorkflowJobStep::STATUS_RUNNING;
        $execution->started_at = time();
        $execution->save(false);

        return $run;
    }

    /**
     * @return array<int, array{step: int|null, status: string, job: int|null}> the run's step executions by ID
     */
    private function executionsOf(WorkflowJob $run): array
    {
        $executions = [];
        /** @var WorkflowJobStep $execution */
        foreach (WorkflowJobStep::find()->where(['workflow_job_id' => $run->id])->orderBy(['id' => SORT_ASC])->all() as $execution) {
            $executions[(int)$execution->id] = [
                'step' => $execution->workflow_step_id === null ? null : (int)$execution->workflow_step_id,
                'status' => (string)$execution->status,
                'job' => $execution->job_id === null ? null : (int)$execution->job_id,
            ];
        }

        return $executions;
    }

    private function makeRunningJob(): Job
    {
        $user     = $this->createUser();
        $group    = $this->createRunnerGroup($user->id);
        $project  = $this->createProject($user->id);
        $inv      = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);
        return $this->createJob($template->id, $user->id, Job::STATUS_RUNNING);
    }

    /**
     * Build a job that has already been canceled through the cancel the
     * web and API controllers use — status + finished_at set, but no
     * exit_code. The race-guard tests use this to simulate a runner that
     * finishes the playbook after the operator already pulled the plug.
     */
    private function makeCanceledJob(): Job
    {
        $job = $this->makeRunningJob();
        /** @var JobCancellationService $cancellation */
        $cancellation = \Yii::$app->get('jobCancellationService');
        $this->assertTrue($cancellation->cancel($job, (int)$job->launched_by));
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        return $job;
    }

    /** Seed a minimal JobTask row — enough to clear the "did nothing" gate. */
    private function seedTaskFor(Job $job, string $status): void
    {
        $task = new JobTask();
        $task->job_id = $job->id;
        $task->sequence = 0;
        $task->task_name = 'seeded';
        $task->task_action = 'debug';
        $task->host = 'localhost';
        $task->status = $status;
        $task->changed = 0;
        $task->duration_ms = 0;
        $task->save(false);
    }
}
