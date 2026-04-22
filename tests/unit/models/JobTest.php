<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\Job;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

/**
 * Tests for Job model status helpers — no database required.
 */
class JobTest extends TestCase
{
    public function testAllStatusesAreDefined(): void
    {
        $statuses = Job::statuses();
        $expected = ['pending', 'queued', 'running', 'succeeded', 'failed', 'canceled', 'timed_out', 'pending_approval', 'rejected'];
        $this->assertEqualsCanonicalizing($expected, $statuses);
    }

    public function testIsFinishedForTerminalStatuses(): void
    {
        foreach (['succeeded', 'failed', 'canceled'] as $status) {
            $job = $this->makeJob($status);
            $this->assertTrue($job->isFinished(), "Expected isFinished() for status '{$status}'");
        }
    }

    public function testIsFinishedForRejectedStatus(): void
    {
        $job = $this->makeJob('rejected');
        $this->assertTrue($job->isFinished(), 'Expected isFinished() for status rejected');
    }

    public function testIsNotFinishedForActiveStatuses(): void
    {
        foreach (['pending', 'queued', 'running', 'pending_approval'] as $status) {
            $job = $this->makeJob($status);
            $this->assertFalse($job->isFinished(), "Expected !isFinished() for status '{$status}'");
        }
    }

    public function testIsCancelableForActiveStatuses(): void
    {
        foreach (['pending', 'queued', 'running', 'pending_approval'] as $status) {
            $job = $this->makeJob($status);
            $this->assertTrue($job->isCancelable(), "Expected isCancelable() for status '{$status}'");
        }
    }

    public function testIsNotCancelableForTerminalStatuses(): void
    {
        foreach (['succeeded', 'failed', 'canceled', 'rejected'] as $status) {
            $job = $this->makeJob($status);
            $this->assertFalse($job->isCancelable(), "Expected !isCancelable() for status '{$status}'");
        }
    }

    /**
     * Lock the status → human label map so typo changes fail loudly.
     * @return array<int, array{0: string, 1: string}>
     */
    public static function statusLabelData(): array
    {
        return [
            [Job::STATUS_PENDING, 'Pending'],
            [Job::STATUS_QUEUED, 'Queued'],
            [Job::STATUS_RUNNING, 'Running'],
            [Job::STATUS_SUCCEEDED, 'Succeeded'],
            [Job::STATUS_FAILED, 'Failed'],
            [Job::STATUS_CANCELED, 'Canceled'],
            [Job::STATUS_TIMED_OUT, 'Timed Out'],
            [Job::STATUS_PENDING_APPROVAL, 'Awaiting Approval'],
            [Job::STATUS_REJECTED, 'Rejected'],
        ];
    }

    /** @dataProvider statusLabelData */
    public function testStatusLabelMapsToHumanText(string $status, string $expected): void
    {
        $this->assertSame($expected, Job::statusLabel($status));
    }

    public function testStatusLabelFallsBackToInputForUnknownStatus(): void
    {
        $this->assertSame('wat', Job::statusLabel('wat'));
    }

    /**
     * Lock the status → Bootstrap badge class map. Same statuses can
     * intentionally share a class; assert the concrete value.
     * @return array<int, array{0: string, 1: string}>
     */
    public static function statusCssClassData(): array
    {
        return [
            [Job::STATUS_PENDING, 'secondary'],
            [Job::STATUS_QUEUED, 'secondary'],
            [Job::STATUS_RUNNING, 'primary'],
            [Job::STATUS_SUCCEEDED, 'success'],
            [Job::STATUS_FAILED, 'danger'],
            [Job::STATUS_CANCELED, 'warning'],
            [Job::STATUS_TIMED_OUT, 'danger'],
            [Job::STATUS_PENDING_APPROVAL, 'info'],
            [Job::STATUS_REJECTED, 'danger'],
        ];
    }

    /** @dataProvider statusCssClassData */
    public function testStatusCssClassMapsToExpectedBadge(string $status, string $expected): void
    {
        $this->assertSame($expected, Job::statusCssClass($status));
    }

    public function testStatusCssClassFallsBackToSecondaryForUnknownStatus(): void
    {
        $this->assertSame('secondary', Job::statusCssClass('weird'));
    }

    // ── isTerminalFailure() ────────────────────────────────────────────────
    // Groups failed + timed_out together so the dashboard's "failed jobs"
    // list surfaces timeouts alongside genuine failures. Regression target:
    // prior to this helper, a job killed by the runner-deadline only showed
    // up under the dedicated timed_out filter and was missing from the
    // aggregate failure view.

    public function testIsTerminalFailureForFailed(): void
    {
        $this->assertTrue($this->makeJob('failed')->isTerminalFailure());
    }

    public function testIsTerminalFailureForTimedOut(): void
    {
        $this->assertTrue($this->makeJob('timed_out')->isTerminalFailure());
    }

    public function testIsNotTerminalFailureForOtherStatuses(): void
    {
        foreach (['pending', 'queued', 'running', 'succeeded', 'canceled', 'pending_approval', 'rejected'] as $status) {
            $this->assertFalse(
                $this->makeJob($status)->isTerminalFailure(),
                "Status {$status} must not count as a terminal failure",
            );
        }
    }

    public function testTerminalFailureStatusesReturnsFailedAndTimedOut(): void
    {
        $this->assertSame(['failed', 'timed_out'], Job::terminalFailureStatuses());
    }

    private function makeJob(string $status): Job
    {
        $job = $this->getMockBuilder(Job::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $job->method('attributes')->willReturn(
            ['id', 'status', 'job_template_id', 'launched_by', 'queued_at',
             'started_at', 'finished_at', 'exit_code', 'created_at', 'updated_at']
        );
        $job->method('save')->willReturn(true);
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($job, ['status' => $status]);
        return $job;
    }
}
