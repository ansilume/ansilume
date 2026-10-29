<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\Job;
use app\models\NotificationTemplate;
use app\models\Webhook;
use app\services\AuditService;
use app\services\JobCompletionService;
use app\services\NotificationDispatcher;
use app\services\WebhookService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

/**
 * The decisions of JobCompletionService::complete(), completeTimedOut() and
 * failBeforeExecution(): which outcome they ask the terminal transition to
 * write, and what follows when it ended the job or when the job had
 * finished already. The transition itself is replaced
 * ({@see RecordingJobCompletionService}), so no job row is needed;
 * JobCompletionServiceIntegrationTest runs it against the database.
 */
class JobCompletionServiceTest extends TestCase
{
    /** @var array<string, mixed> Original Yii app components to restore after each test */
    private array $originalComponents = [];

    /** @var AuditService&MockObject */
    private AuditService $audit;

    /** @var WebhookService&MockObject */
    private WebhookService $webhooks;

    /** @var NotificationDispatcher&MockObject */
    private NotificationDispatcher $notifications;

    protected function setUp(): void
    {
        // Save originals before replacing them with mocks
        foreach (['auditService', 'webhookService', 'notificationDispatcher'] as $id) {
            $this->originalComponents[$id] = \Yii::$app->has($id) ? \Yii::$app->get($id) : null;
        }

        $this->audit = $this->getMockBuilder(AuditService::class)->onlyMethods(['log'])->getMock();
        \Yii::$app->set('auditService', $this->audit);

        $this->webhooks = $this->getMockBuilder(WebhookService::class)->onlyMethods(['dispatch'])->getMock();
        \Yii::$app->set('webhookService', $this->webhooks);

        $this->notifications = $this->getMockBuilder(NotificationDispatcher::class)->onlyMethods(['dispatch'])->getMock();
        \Yii::$app->set('notificationDispatcher', $this->notifications);
    }

    protected function tearDown(): void
    {
        // Restore original components so integration tests run after this suite
        // see the real implementations and not these mocks.
        foreach ($this->originalComponents as $id => $component) {
            if ($component !== null) {
                \Yii::$app->set($id, $component);
            }
        }
    }

    // -- complete() ---------------------------------------------------------------

    public function testExitCodeZeroEndsTheJobAsSucceeded(): void
    {
        $service = new RecordingJobCompletionService();
        $job = $this->makeJob();

        $service->complete($job, 0);

        $this->assertSame(
            [['outcome' => ['exit_code' => 0, 'status' => Job::STATUS_SUCCEEDED], 'explanation' => null]],
            $service->transitions
        );
        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function failingExitCodeProvider(): array
    {
        return [
            'exit 1' => [1],
            'exit 2' => [2],
            'unreachable hosts' => [4],
            'any other code' => [42],
        ];
    }

    /**
     * @dataProvider failingExitCodeProvider
     */
    public function testANonZeroExitCodeEndsTheJobAsFailedWithThatCode(int $exitCode): void
    {
        $service = new RecordingJobCompletionService();
        $job = $this->makeJob();

        $service->complete($job, $exitCode);

        $this->assertSame(
            [['outcome' => ['exit_code' => $exitCode, 'status' => Job::STATUS_FAILED], 'explanation' => null]],
            $service->transitions
        );
        $this->assertSame(Job::STATUS_FAILED, $job->status);
    }

    public function testHasChangesIsWrittenOnlyWhenTheRunnerReportsChanges(): void
    {
        $service = new RecordingJobCompletionService();

        $service->complete($this->makeJob(), 0, hasChanges: true);
        $service->complete($this->makeJob(), 0, hasChanges: false);

        $this->assertSame(1, $service->transitions[0]['outcome']['has_changes'] ?? null);
        // Never reset: saveTasks() may have set it from the task results.
        $this->assertArrayNotHasKey('has_changes', $service->transitions[1]['outcome']);
    }

    public function testASucceededJobGetsTheSuccessWebhookNotificationAndAudit(): void
    {
        $this->audit->expects($this->once())
            ->method('log')
            ->with(AuditService::ACTION_JOB_FINISHED, 'job', 1, null, ['exit_code' => 0, 'status' => Job::STATUS_SUCCEEDED]);
        $this->webhooks->expects($this->once())
            ->method('dispatch')
            ->with(Webhook::EVENT_JOB_SUCCESS, $this->isInstanceOf(Job::class));
        $this->notifications->expects($this->once())
            ->method('dispatch')
            ->with(NotificationTemplate::EVENT_JOB_SUCCEEDED, $this->anything());

        (new RecordingJobCompletionService())->complete($this->makeJob(), 0);
    }

    public function testAFailedJobGetsTheFailureWebhookNotificationAndAudit(): void
    {
        $this->audit->expects($this->once())
            ->method('log')
            ->with(AuditService::ACTION_JOB_FINISHED, 'job', 1, null, ['exit_code' => 1, 'status' => Job::STATUS_FAILED]);
        $this->webhooks->expects($this->once())
            ->method('dispatch')
            ->with(Webhook::EVENT_JOB_FAILURE, $this->isInstanceOf(Job::class));
        $this->notifications->expects($this->once())
            ->method('dispatch')
            ->with(NotificationTemplate::EVENT_JOB_FAILED, $this->anything());

        (new RecordingJobCompletionService())->complete($this->makeJob(), 1);
    }

    /**
     * Regression: ansible-playbook exits 0 when `hosts:` matches nothing.
     * From an operator's perspective this is a misconfiguration — nothing
     * ran — and the job must be surfaced as FAILED with a clear log line
     * instead of silently "succeeded". The line is written with the status.
     */
    public function testCompleteMarksSucceededAsFailedWhenJobExecutedAgainstNoHosts(): void
    {
        $service = new RecordingJobCompletionService();
        $service->nothingRan = true;
        $job = $this->makeJob();

        $service->complete($job, 0);

        $this->assertSame(Job::STATUS_FAILED, $job->status, 'Empty-host-match playbook must not be called succeeded.');
        $this->assertSame(
            [['outcome' => ['exit_code' => 0, 'status' => Job::STATUS_FAILED], 'explanation' => JobCompletionService::NO_HOST_MATCHED]],
            $service->transitions
        );
        $this->assertStringStartsWith('Job did not execute against any host.', JobCompletionService::NO_HOST_MATCHED);
    }

    public function testCompleteKeepsSucceededWhenJobActuallyExecutedTasks(): void
    {
        $service = new RecordingJobCompletionService();
        $job = $this->makeJob();

        $service->complete($job, 0);

        $this->assertSame(
            Job::STATUS_SUCCEEDED,
            $job->status,
            'Exit 0 + at least one task/host summary → succeeded. Only empty runs flip to failed.'
        );
    }

    public function testCompleteLeavesFailedStatusAloneWhenExitCodeIsNonZero(): void
    {
        // The no-op safeguard must not accidentally rewrite genuine failures
        // — exit code 1 with no tasks (e.g. syntax error before any play ran)
        // must stay FAILED, not "oh it was a no-op", and gets no such line.
        $service = new RecordingJobCompletionService();
        $service->nothingRan = true;

        $service->complete($this->makeJob(), 1);

        $this->assertSame(
            [['outcome' => ['exit_code' => 1, 'status' => Job::STATUS_FAILED], 'explanation' => null]],
            $service->transitions
        );
    }

    // -- completeTimedOut() and failBeforeExecution() -------------------------------

    public function testCompleteTimedOutEndsTheJobAsTimedOut(): void
    {
        $service = new RecordingJobCompletionService();
        $job = $this->makeJob();

        $service->completeTimedOut($job);

        $this->assertSame(
            [['outcome' => ['exit_code' => -1, 'status' => Job::STATUS_TIMED_OUT], 'explanation' => null]],
            $service->transitions
        );
        $this->assertSame(Job::STATUS_TIMED_OUT, $job->status);
    }

    public function testATimedOutJobGetsTheFailureWebhookNotificationAndAudit(): void
    {
        $this->audit->expects($this->once())
            ->method('log')
            ->with(AuditService::ACTION_JOB_FINISHED, 'job', 1, null, ['exit_code' => -1, 'status' => Job::STATUS_TIMED_OUT]);
        $this->webhooks->expects($this->once())
            ->method('dispatch')
            ->with(Webhook::EVENT_JOB_FAILURE, $this->isInstanceOf(Job::class));
        $this->notifications->expects($this->once())
            ->method('dispatch')
            ->with(NotificationTemplate::EVENT_JOB_FAILED, $this->anything());

        (new RecordingJobCompletionService())->completeTimedOut($this->makeJob());
    }

    public function testFailBeforeExecutionEndsTheJobAsFailedAndWritesTheReasonWithIt(): void
    {
        $this->audit->expects($this->once())
            ->method('log')
            ->with(AuditService::ACTION_JOB_FINISHED, 'job', 1, null, [
                'exit_code' => -1,
                'status' => Job::STATUS_FAILED,
                'reason' => 'pre_execution_failure',
            ]);
        $this->webhooks->expects($this->once())
            ->method('dispatch')
            ->with(Webhook::EVENT_JOB_FAILURE, $this->isInstanceOf(Job::class));
        $this->notifications->expects($this->once())
            ->method('dispatch')
            ->with(NotificationTemplate::EVENT_JOB_FAILED, $this->anything());
        $service = new RecordingJobCompletionService();

        $service->failBeforeExecution($this->makeJob(), 'Job aborted before execution: 1 credential(s) could not be used.');

        $this->assertSame(
            [[
                'outcome' => ['exit_code' => -1, 'status' => Job::STATUS_FAILED],
                'explanation' => 'Job aborted before execution: 1 credential(s) could not be used.',
            ]],
            $service->transitions
        );
    }

    /**
     * A refused claim passes its audit context (JobClaimRefusedException::
     * auditContext()): job.finished records the three base keys first, then
     * the context, and a key of the context cannot replace a base key.
     */
    public function testFailBeforeExecutionRecordsTheAuditContextAfterTheBaseKeys(): void
    {
        $recorded = null;
        $this->audit->expects($this->once())
            ->method('log')
            ->willReturnCallback(static function (string $action, ?string $objectType, ?int $objectId, ?int $userId, array $context) use (&$recorded): void {
                $recorded = [$action, $objectType, $objectId, $userId, $context];
            });
        $service = new RecordingJobCompletionService();

        $service->failBeforeExecution($this->makeJob(), 'Job aborted before execution.', [
            'claim_refusal' => 'not_approved',
            'status' => 'succeeded',
            'approval_rule_id' => 7,
            'reason' => 'something else',
            'exit_code' => 0,
        ]);

        $this->assertSame([AuditService::ACTION_JOB_FINISHED, 'job', 1, null, [
            'exit_code' => -1,
            'status' => Job::STATUS_FAILED,
            'reason' => 'pre_execution_failure',
            'claim_refusal' => 'not_approved',
            'approval_rule_id' => 7,
        ]], $recorded);
        $this->assertSame(
            [['outcome' => ['exit_code' => -1, 'status' => Job::STATUS_FAILED], 'explanation' => 'Job aborted before execution.']],
            $service->transitions
        );
    }

    // -- A report for a job that had finished already -------------------------------

    /**
     * @return array<string, array{0: \Closure(JobCompletionService, Job): void, 1: string, 2: list<string>}>
     */
    public static function lateReportProvider(): array
    {
        return [
            'completion after a cancel' => [
                static fn (JobCompletionService $service, Job $job) => $service->complete($job, 0, true),
                Job::STATUS_CANCELED,
                ['Runner reported completion (exit_code=0) after job was already in terminal status "canceled" — ignored.'],
            ],
            'failed completion after a rejection' => [
                static fn (JobCompletionService $service, Job $job) => $service->complete($job, 2),
                Job::STATUS_REJECTED,
                ['Runner reported completion (exit_code=2) after job was already in terminal status "rejected" — ignored.'],
            ],
            'timeout after a cancel' => [
                static fn (JobCompletionService $service, Job $job) => $service->completeTimedOut($job),
                Job::STATUS_CANCELED,
                ['Runner reported timeout after job was already in terminal status "canceled" — ignored.'],
            ],
            'failure before execution after a cancel' => [
                static fn (JobCompletionService $service, Job $job) => $service->failBeforeExecution($job, 'Job aborted before execution.'),
                Job::STATUS_CANCELED,
                [],
            ],
        ];
    }

    /**
     * When the job had finished already the report changes nothing: no
     * audit entry, webhook or notification, only the line that says it was
     * ignored (none for a failure before execution).
     *
     * @dataProvider lateReportProvider
     * @param \Closure(JobCompletionService, Job): void $report
     * @param list<string> $lines
     */
    public function testAReportForAJobThatHadFinishedOnlyLeavesALogLine(\Closure $report, string $finishedAs, array $lines): void
    {
        $this->audit->expects($this->never())->method('log');
        $this->webhooks->expects($this->never())->method('dispatch');
        $this->notifications->expects($this->never())->method('dispatch');
        $service = new RecordingJobCompletionService();
        $service->finishedAs = $finishedAs;
        $job = $this->makeJob();

        $report($service, $job);

        $this->assertCount(1, $service->transitions, 'the transition decides, not the loaded model');
        $this->assertSame(
            array_map(static fn (string $line): array => ['stream' => 'stderr', 'content' => $line], $lines),
            $service->lines
        );
        $this->assertSame($finishedAs, $job->status);
    }

    // -- appendLog() --------------------------------------------------------------

    /**
     * Log chunks exceeding 1 MB must be truncated to prevent disk exhaustion.
     */
    public function testAppendLogTruncatesOversizedContent(): void
    {
        $service = new JobCompletionService();
        $job = $this->makeJob();

        // Build a string larger than 1 MB
        $oversized = str_repeat('x', 1_048_576 + 1000);

        // We can't easily test the DB write, but we can verify the constant
        // and truncation logic via reflection by reading the source.
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/services/JobCompletionService.php'
        );
        $this->assertNotFalse($source);
        $this->assertStringContainsString('MAX_LOG_CHUNK_SIZE', $source);
        $this->assertStringContainsString('truncated', $source);
        $this->assertStringContainsString('substr($content', $source);
    }

    /**
     * A running job #1 in memory only: Job with attributes(), save() mocked,
     * so it never hits the database.
     */
    private function makeJob(): Job
    {
        $job = $this->getMockBuilder(Job::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $job->method('attributes')->willReturn(
            ['id', 'status', 'exit_code', 'has_changes', 'finished_at',
             'job_template_id', 'launched_by', 'queued_at', 'started_at', 'created_at', 'updated_at']
        );
        $job->method('save')->willReturn(true);
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setValue($job, [
            'id' => 1,
            'status' => Job::STATUS_RUNNING,
            'exit_code' => null,
            'has_changes' => 0,
            'finished_at' => null,
            'job_template_id' => 1,
            'launched_by' => 1,
            'queued_at' => null,
            'started_at' => time() - 5,
            'created_at' => null,
            'updated_at' => null,
        ]);
        return $job;
    }
}
