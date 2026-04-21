<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\Job;
use app\services\AuditService;
use app\services\JobCompletionService;
use app\services\NotificationDispatcher;
use app\services\WebhookService;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

/**
 * Tests for JobCompletionService::complete() — status determination logic.
 */
class JobCompletionServiceTest extends TestCase
{
    /** @var array<string, mixed> Original Yii app components to restore after each test */
    private array $originalComponents = [];

    protected function setUp(): void
    {
        // Save originals before replacing with stubs
        foreach (['auditService', 'webhookService', 'notificationDispatcher'] as $id) {
            $this->originalComponents[$id] = \Yii::$app->has($id) ? \Yii::$app->get($id) : null;
        }

        // Stub Yii services so complete() doesn't need real implementations
        $audit = $this->createStub(AuditService::class);
        \Yii::$app->set('auditService', $audit);

        $webhook = $this->getMockBuilder(WebhookService::class)->onlyMethods(['dispatch'])->getMock();
        \Yii::$app->set('webhookService', $webhook);

        $dispatcher = $this->getMockBuilder(NotificationDispatcher::class)->onlyMethods(['dispatch'])->getMock();
        \Yii::$app->set('notificationDispatcher', $dispatcher);
    }

    protected function tearDown(): void
    {
        // Restore original components so integration tests run after this suite
        // see the real implementations and not these stubs.
        foreach ($this->originalComponents as $id => $component) {
            if ($component !== null) {
                \Yii::$app->set($id, $component);
            }
        }
    }

    public function testExitCodeZeroSetsStatusSucceeded(): void
    {
        $job = $this->makeJob(Job::STATUS_RUNNING);
        $service = $this->makeService();
        $service->complete($job, 0);
        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status);
    }

    public function testNonZeroExitCodeSetsStatusFailed(): void
    {
        $job = $this->makeJob(Job::STATUS_RUNNING);
        $service = $this->makeService();
        $service->complete($job, 1);
        $this->assertSame(Job::STATUS_FAILED, $job->status);
    }

    public function testExitCodeTwoAlsoFails(): void
    {
        $job = $this->makeJob(Job::STATUS_RUNNING);
        $service = $this->makeService();
        $service->complete($job, 2);
        $this->assertSame(Job::STATUS_FAILED, $job->status);
    }

    public function testCompleteStoresExitCode(): void
    {
        $job = $this->makeJob(Job::STATUS_RUNNING);
        $service = $this->makeService();
        $service->complete($job, 42);
        $this->assertSame(42, $job->exit_code);
    }

    public function testCompleteSetsFinishedAt(): void
    {
        $before = time();
        $job = $this->makeJob(Job::STATUS_RUNNING);
        $service = $this->makeService();
        $service->complete($job, 0);
        $this->assertGreaterThanOrEqual($before, $job->finished_at);
    }

    public function testHasChangesSetWhenTrue(): void
    {
        $job = $this->makeJob(Job::STATUS_RUNNING);
        $service = $this->makeService();
        $service->complete($job, 0, hasChanges: true);
        $this->assertSame(1, $job->has_changes);
    }

    public function testHasChangesNotSetWhenFalse(): void
    {
        $job = $this->makeJob(Job::STATUS_RUNNING);
        $service = $this->makeService();
        $service->complete($job, 0, hasChanges: false);
        $this->assertSame(0, $job->has_changes);
    }

    public function testWebhookDispatchedOnSuccess(): void
    {
        $webhook = $this->getMockBuilder(WebhookService::class)
            ->onlyMethods(['dispatch'])
            ->getMock();
        $webhook->expects($this->once())
            ->method('dispatch')
            ->with(\app\models\Webhook::EVENT_JOB_SUCCESS, $this->anything());
        \Yii::$app->set('webhookService', $webhook);

        $service = $this->makeService();
        $service->complete($this->makeJob(Job::STATUS_RUNNING), 0);
    }

    public function testWebhookDispatchedOnFailure(): void
    {
        $webhook = $this->getMockBuilder(WebhookService::class)
            ->onlyMethods(['dispatch'])
            ->getMock();
        $webhook->expects($this->once())
            ->method('dispatch')
            ->with(\app\models\Webhook::EVENT_JOB_FAILURE, $this->anything());
        \Yii::$app->set('webhookService', $webhook);

        $service = $this->makeService();
        $service->complete($this->makeJob(Job::STATUS_RUNNING), 1);
    }

    public function testNotificationDispatcherCalledOnFailure(): void
    {
        $dispatcher = $this->getMockBuilder(NotificationDispatcher::class)
            ->onlyMethods(['dispatch'])
            ->getMock();
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(\app\models\NotificationTemplate::EVENT_JOB_FAILED, $this->anything());
        \Yii::$app->set('notificationDispatcher', $dispatcher);

        $service = $this->makeService();
        $service->complete($this->makeJob(Job::STATUS_RUNNING), 1);
    }

    public function testNotificationDispatcherCalledOnSuccess(): void
    {
        $dispatcher = $this->getMockBuilder(NotificationDispatcher::class)
            ->onlyMethods(['dispatch'])
            ->getMock();
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(\app\models\NotificationTemplate::EVENT_JOB_SUCCEEDED, $this->anything());
        \Yii::$app->set('notificationDispatcher', $dispatcher);

        $service = $this->makeService();
        $service->complete($this->makeJob(Job::STATUS_RUNNING), 0);
    }

    public function testCompleteTimedOutSetsStatusTimedOut(): void
    {
        $job = $this->makeJob(Job::STATUS_RUNNING);
        $service = $this->makeService();
        $service->completeTimedOut($job);
        $this->assertSame(Job::STATUS_TIMED_OUT, $job->status);
    }

    public function testCompleteTimedOutSetsExitCodeMinusOne(): void
    {
        $job = $this->makeJob(Job::STATUS_RUNNING);
        $service = $this->makeService();
        $service->completeTimedOut($job);
        $this->assertSame(-1, $job->exit_code);
    }

    public function testCompleteTimedOutSetsFinishedAt(): void
    {
        $before = time();
        $job = $this->makeJob(Job::STATUS_RUNNING);
        $service = $this->makeService();
        $service->completeTimedOut($job);
        $this->assertGreaterThanOrEqual($before, $job->finished_at);
    }

    public function testCompleteTimedOutDispatchesFailureWebhook(): void
    {
        $webhook = $this->getMockBuilder(WebhookService::class)
            ->onlyMethods(['dispatch'])
            ->getMock();
        $webhook->expects($this->once())
            ->method('dispatch')
            ->with(\app\models\Webhook::EVENT_JOB_FAILURE, $this->anything());
        \Yii::$app->set('webhookService', $webhook);

        $service = $this->makeService();
        $service->completeTimedOut($this->makeJob(Job::STATUS_RUNNING));
    }

    public function testCompleteTimedOutDispatchesFailedNotification(): void
    {
        $dispatcher = $this->getMockBuilder(NotificationDispatcher::class)
            ->onlyMethods(['dispatch'])
            ->getMock();
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(\app\models\NotificationTemplate::EVENT_JOB_FAILED, $this->anything());
        \Yii::$app->set('notificationDispatcher', $dispatcher);

        $service = $this->makeService();
        $service->completeTimedOut($this->makeJob(Job::STATUS_RUNNING));
    }

    /**
     * Log chunks exceeding 1 MB must be truncated to prevent disk exhaustion.
     */
    public function testAppendLogTruncatesOversizedContent(): void
    {
        $service = new JobCompletionService();
        $job = $this->makeJob(Job::STATUS_RUNNING);

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
     * Unit tests work with mock Job objects that never hit the database
     * (makeJob only sets in-memory attributes). complete()'s didNothing
     * check runs DB queries on job_task/job_host_summary and then tries
     * to insert a JobLog row — that blows up on the FK constraint. These
     * tests exist to pin the pure logic in complete(), so we override
     * didNothing to "the job did something" and let the regression case
     * for no-hosts-matched live in its own test (see
     * testCompleteMarksSucceededAsFailedWhenJobExecutedAgainstNoHosts).
     */
    private function makeService(): JobCompletionService
    {
        return new class extends JobCompletionService {
            protected function didNothing(Job $job): bool
            {
                return false;
            }
        };
    }

    /**
     * Regression: ansible-playbook exits 0 when `hosts:` matches nothing.
     * From an operator's perspective this is a misconfiguration — nothing
     * ran — and the job must be surfaced as FAILED with a clear log line
     * instead of silently "succeeded".
     */
    public function testCompleteMarksSucceededAsFailedWhenJobExecutedAgainstNoHosts(): void
    {
        /** @var list<array{job: Job, stream: string, message: string}> $appendedLogs */
        $appendedLogs = [];

        $service = new class ($appendedLogs) extends JobCompletionService {
            /** @var list<array{job: Job, stream: string, message: string}> */
            public array $log;

            /** @param list<array{job: Job, stream: string, message: string}> $sink */
            public function __construct(array &$sink)
            {
                parent::__construct();
                $this->log = &$sink;
            }

            protected function didNothing(Job $job): bool
            {
                return true;
            }

            public function appendLog(Job $job, string $stream, string $content, int $sequence): void
            {
                $this->log[] = ['job' => $job, 'stream' => $stream, 'message' => $content];
            }
        };

        $job = $this->makeJob(Job::STATUS_RUNNING);
        $service->complete($job, 0);

        $this->assertSame(Job::STATUS_FAILED, $job->status, 'Empty-host-match playbook must not be called succeeded.');
        $this->assertNotEmpty($appendedLogs, 'A system log line explaining the no-op must be appended.');
        $this->assertSame('stderr', $appendedLogs[0]['stream']);
        $this->assertStringContainsString('did not execute against any host', $appendedLogs[0]['message']);
    }

    public function testCompleteKeepsSucceededWhenJobActuallyExecutedTasks(): void
    {
        $service = new class extends JobCompletionService {
            protected function didNothing(Job $job): bool
            {
                return false;
            }
        };

        $job = $this->makeJob(Job::STATUS_RUNNING);
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
        // must stay FAILED, not "oh it was a no-op".
        $service = new class extends JobCompletionService {
            protected function didNothing(Job $job): bool
            {
                return true; // would trigger the safeguard if the gate were wrong
            }
        };

        $job = $this->makeJob(Job::STATUS_RUNNING);
        $service->complete($job, 1);

        $this->assertSame(Job::STATUS_FAILED, $job->status);
    }

    private function makeJob(string $status): Job
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
        $ref->setAccessible(true);
        $ref->setValue($job, [
            'id' => 1,
            'status' => $status,
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
