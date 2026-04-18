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

    /**
     * Ansible callback records write these exact strings into the
     * `status` column; pin the values so the constants can't drift
     * away from the callback plugin without the test noticing.
     */
    public function testStatusConstantsMatchCallbackStrings(): void
    {
        $this->assertSame('ok', JobTask::STATUS_OK);
        $this->assertSame('changed', JobTask::STATUS_CHANGED);
        $this->assertSame('failed', JobTask::STATUS_FAILED);
        $this->assertSame('skipped', JobTask::STATUS_SKIPPED);
        $this->assertSame('unreachable', JobTask::STATUS_UNREACHABLE);
    }
}
