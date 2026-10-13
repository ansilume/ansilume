<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\services\JobCancellationService;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for job cancellation, through the service the web and
 * API cancel actions call (JobCancellationService::cancel()).
 *
 * Verifies:
 * - Status transitions from cancelable states
 * - Refusal from terminal states, without a write
 * - finished_at is set on cancellation, queued_at is kept
 * - Audit trail is written
 *
 * These tests set the status on the model by hand before; they did not
 * exercise the code the actions run.
 */
class JobCancellationTest extends DbTestCase
{
    /**
     * @return array{0: User, 1: JobTemplate}
     */
    private function scaffold(): array
    {
        $user = $this->createUser('cancel');
        $group = $this->createRunnerGroup($user->id);
        $proj = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $tpl = $this->createJobTemplate($proj->id, $inv->id, $group->id, $user->id);

        return [$user, $tpl];
    }

    /**
     * @dataProvider cancelableStatusProvider
     */
    public function testCancelFromCancelableStatus(string $status): void
    {
        [$user, $tpl] = $this->scaffold();
        $job = $this->createJob($tpl->id, $user->id, $status);

        $this->assertTrue($job->isCancelable());
        $this->assertTrue($this->service()->cancel($job, $user->id));

        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $this->assertNotNull($job->finished_at);
        $this->assertTrue($job->isFinished());
        $this->assertFalse($job->isCancelable(), 'Canceled job must not be cancelable again.');
        $this->assertFalse($this->service()->cancel($job, $user->id), 'A second cancel changes nothing.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function cancelableStatusProvider(): array
    {
        return [
            'pending' => [Job::STATUS_PENDING],
            'queued' => [Job::STATUS_QUEUED],
            'running' => [Job::STATUS_RUNNING],
            'pending_approval' => [Job::STATUS_PENDING_APPROVAL],
        ];
    }

    /**
     * @dataProvider terminalStatusProvider
     */
    public function testCannotCancelFromTerminalStatus(string $status): void
    {
        [$user, $tpl] = $this->scaffold();
        $job = $this->createJob($tpl->id, $user->id, $status);
        $stored = Job::findOne($job->id)?->getAttributes();

        $this->assertFalse($job->isCancelable());
        $this->assertFalse($this->service()->cancel($job, $user->id));

        $this->assertSame($stored, Job::findOne($job->id)?->getAttributes(), 'Nothing was written.');
        $this->assertSame([], $this->cancelAudits($job));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function terminalStatusProvider(): array
    {
        return [
            'succeeded' => [Job::STATUS_SUCCEEDED],
            'failed' => [Job::STATUS_FAILED],
            'canceled' => [Job::STATUS_CANCELED],
            'timed_out' => [Job::STATUS_TIMED_OUT],
            'rejected' => [Job::STATUS_REJECTED],
        ];
    }

    public function testCancelSetsFinishedAt(): void
    {
        [$user, $tpl] = $this->scaffold();
        $job = $this->createJob($tpl->id, $user->id, Job::STATUS_RUNNING);

        $this->assertNull($job->finished_at);

        $before = time();
        $this->service()->cancel($job, $user->id);

        $job->refresh();
        $this->assertGreaterThanOrEqual($before, (int)$job->finished_at);
    }

    public function testCancelWritesAuditLog(): void
    {
        [$user, $tpl] = $this->scaffold();
        $job = $this->createJob($tpl->id, $user->id, Job::STATUS_RUNNING);

        $this->service()->cancel($job, $user->id);

        $audits = $this->cancelAudits($job);
        $this->assertCount(1, $audits, 'Audit log entry must exist after cancellation.');
        $this->assertSame($user->id, $audits[0]->user_id);
    }

    public function testCancelPreservesOriginalQueuedAt(): void
    {
        [$user, $tpl] = $this->scaffold();
        $job = $this->createJob($tpl->id, $user->id, Job::STATUS_QUEUED);
        $originalQueuedAt = $job->queued_at;

        $this->service()->cancel($job, $user->id);

        $job->refresh();
        $this->assertSame((int)$originalQueuedAt, (int)$job->queued_at);
    }

    private function service(): JobCancellationService
    {
        /** @var JobCancellationService $service */
        $service = \Yii::$app->get('jobCancellationService');

        return $service;
    }

    /**
     * @return list<AuditLog>
     */
    private function cancelAudits(Job $job): array
    {
        /** @var list<AuditLog> $audits */
        $audits = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_JOB_CANCELED, 'object_type' => 'job', 'object_id' => $job->id])
            ->all();

        return $audits;
    }
}
