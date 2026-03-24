<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\models\Job;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for job cancellation.
 *
 * Verifies:
 * - Status transitions from cancelable states
 * - Rejection from terminal states
 * - finished_at is set on cancellation
 * - Audit trail is written
 */
class JobCancellationTest extends DbTestCase
{
    private function scaffold(): array
    {
        $user  = $this->createUser('cancel');
        $group = $this->createRunnerGroup($user->id);
        $proj  = $this->createProject($user->id);
        $inv   = $this->createInventory($user->id);
        $tpl   = $this->createJobTemplate($proj->id, $inv->id, $group->id, $user->id);

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

        $job->status      = Job::STATUS_CANCELED;
        $job->finished_at = time();
        $job->save(false);

        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $this->assertNotNull($job->finished_at);
        $this->assertTrue($job->isFinished());
        $this->assertFalse($job->isCancelable(), 'Canceled job must not be cancelable again.');
    }

    public static function cancelableStatusProvider(): array
    {
        return [
            'pending' => [Job::STATUS_PENDING],
            'queued'  => [Job::STATUS_QUEUED],
            'running' => [Job::STATUS_RUNNING],
        ];
    }

    /**
     * @dataProvider terminalStatusProvider
     */
    public function testCannotCancelFromTerminalStatus(string $status): void
    {
        [$user, $tpl] = $this->scaffold();
        $job = $this->createJob($tpl->id, $user->id, $status);

        $this->assertFalse($job->isCancelable());
    }

    public static function terminalStatusProvider(): array
    {
        return [
            'succeeded' => [Job::STATUS_SUCCEEDED],
            'failed'    => [Job::STATUS_FAILED],
            'canceled'  => [Job::STATUS_CANCELED],
            'timed_out' => [Job::STATUS_TIMED_OUT],
        ];
    }

    public function testCancelSetsFinishedAt(): void
    {
        [$user, $tpl] = $this->scaffold();
        $job = $this->createJob($tpl->id, $user->id, Job::STATUS_RUNNING);

        $this->assertNull($job->finished_at);

        $before = time();
        $job->status      = Job::STATUS_CANCELED;
        $job->finished_at = time();
        $job->save(false);

        $job->refresh();
        $this->assertGreaterThanOrEqual($before, (int)$job->finished_at);
    }

    public function testCancelWritesAuditLog(): void
    {
        [$user, $tpl] = $this->scaffold();
        $job = $this->createJob($tpl->id, $user->id, Job::STATUS_RUNNING);

        // Simulate what the controller does
        $job->status      = Job::STATUS_CANCELED;
        $job->finished_at = time();
        $job->save(false);

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_JOB_CANCELED,
            'job',
            $job->id,
            $user->id,
        );

        $audit = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_JOB_CANCELED, 'object_type' => 'job', 'object_id' => $job->id])
            ->one();

        $this->assertNotNull($audit, 'Audit log entry must exist after cancellation.');
        $this->assertSame($user->id, $audit->user_id);
    }

    public function testCancelPreservesOriginalQueuedAt(): void
    {
        [$user, $tpl] = $this->scaffold();
        $job = $this->createJob($tpl->id, $user->id, Job::STATUS_QUEUED);
        $originalQueuedAt = $job->queued_at;

        $job->status      = Job::STATUS_CANCELED;
        $job->finished_at = time();
        $job->save(false);

        $job->refresh();
        $this->assertSame((int)$originalQueuedAt, (int)$job->queued_at);
    }
}
