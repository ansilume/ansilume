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
        $expected = ['pending', 'queued', 'running', 'succeeded', 'failed', 'canceled', 'timed_out'];
        $this->assertEqualsCanonicalizing($expected, $statuses);
    }

    public function testIsFinishedForTerminalStatuses(): void
    {
        foreach (['succeeded', 'failed', 'canceled'] as $status) {
            $job = $this->makeJob($status);
            $this->assertTrue($job->isFinished(), "Expected isFinished() for status '{$status}'");
        }
    }

    public function testIsNotFinishedForActiveStatuses(): void
    {
        foreach (['pending', 'queued', 'running'] as $status) {
            $job = $this->makeJob($status);
            $this->assertFalse($job->isFinished(), "Expected !isFinished() for status '{$status}'");
        }
    }

    public function testIsCancelableForActiveStatuses(): void
    {
        foreach (['pending', 'queued', 'running'] as $status) {
            $job = $this->makeJob($status);
            $this->assertTrue($job->isCancelable(), "Expected isCancelable() for status '{$status}'");
        }
    }

    public function testIsNotCancelableForTerminalStatuses(): void
    {
        foreach (['succeeded', 'failed', 'canceled'] as $status) {
            $job = $this->makeJob($status);
            $this->assertFalse($job->isCancelable(), "Expected !isCancelable() for status '{$status}'");
        }
    }

    public function testStatusLabelReturnsString(): void
    {
        foreach (Job::statuses() as $status) {
            $label = Job::statusLabel($status);
            $this->assertIsString($label);
            $this->assertNotEmpty($label);
        }
    }

    public function testStatusCssClassReturnsString(): void
    {
        foreach (Job::statuses() as $status) {
            $class = Job::statusCssClass($status);
            $this->assertIsString($class);
            $this->assertNotEmpty($class);
        }
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
