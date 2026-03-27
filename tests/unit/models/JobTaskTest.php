<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\JobTask;
use PHPUnit\Framework\TestCase;

/**
 * Tests for JobTask model — statusCssClass() and status constants.
 * No database required.
 */
class JobTaskTest extends TestCase
{
    public function testStatusCssClassForOk(): void
    {
        $this->assertSame('success', JobTask::statusCssClass(JobTask::STATUS_OK));
    }

    public function testStatusCssClassForChanged(): void
    {
        $this->assertSame('warning', JobTask::statusCssClass(JobTask::STATUS_CHANGED));
    }

    public function testStatusCssClassForFailed(): void
    {
        $this->assertSame('danger', JobTask::statusCssClass(JobTask::STATUS_FAILED));
    }

    public function testStatusCssClassForSkipped(): void
    {
        $this->assertSame('secondary', JobTask::statusCssClass(JobTask::STATUS_SKIPPED));
    }

    public function testStatusCssClassForUnreachable(): void
    {
        $this->assertSame('dark', JobTask::statusCssClass(JobTask::STATUS_UNREACHABLE));
    }

    public function testStatusCssClassForUnknownReturnsSecondary(): void
    {
        $this->assertSame('secondary', JobTask::statusCssClass('something_else'));
    }

    public function testStatusConstantsAreUnique(): void
    {
        $statuses = [
            JobTask::STATUS_OK,
            JobTask::STATUS_CHANGED,
            JobTask::STATUS_FAILED,
            JobTask::STATUS_SKIPPED,
            JobTask::STATUS_UNREACHABLE,
        ];
        $this->assertSame(count($statuses), count(array_unique($statuses)));
    }

    public function testStatusConstantsAreStrings(): void
    {
        foreach (
            [
            JobTask::STATUS_OK,
            JobTask::STATUS_CHANGED,
            JobTask::STATUS_FAILED,
            JobTask::STATUS_SKIPPED,
            JobTask::STATUS_UNREACHABLE,
            ] as $status
        ) {
            $this->assertIsString($status);
            $this->assertNotEmpty($status);
        }
    }
}
