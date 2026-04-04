<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\WorkflowJob;
use PHPUnit\Framework\TestCase;

class WorkflowJobTest extends TestCase
{
    public function testStatusConstants(): void
    {
        $this->assertSame('running', WorkflowJob::STATUS_RUNNING);
        $this->assertSame('succeeded', WorkflowJob::STATUS_SUCCEEDED);
        $this->assertSame('failed', WorkflowJob::STATUS_FAILED);
        $this->assertSame('canceled', WorkflowJob::STATUS_CANCELED);
    }

    public function testStatusesReturnsAll(): void
    {
        $statuses = WorkflowJob::statuses();
        $this->assertCount(4, $statuses);
        $this->assertContains('running', $statuses);
        $this->assertContains('succeeded', $statuses);
        $this->assertContains('failed', $statuses);
        $this->assertContains('canceled', $statuses);
    }

    public function testIsFinishedForTerminalStatuses(): void
    {
        foreach (['succeeded', 'failed', 'canceled'] as $status) {
            $model = $this->makeModel($status);
            $this->assertTrue($model->isFinished(), "Expected isFinished() for '{$status}'");
        }
    }

    public function testIsNotFinishedForRunning(): void
    {
        $model = $this->makeModel('running');
        $this->assertFalse($model->isFinished());
    }

    public function testStatusLabelReturnsStringForAll(): void
    {
        foreach (WorkflowJob::statuses() as $status) {
            $label = WorkflowJob::statusLabel($status);
            $this->assertIsString($label);
            $this->assertNotEmpty($label);
        }
    }

    public function testStatusCssClassReturnsStringForAll(): void
    {
        foreach (WorkflowJob::statuses() as $status) {
            $class = WorkflowJob::statusCssClass($status);
            $this->assertIsString($class);
            $this->assertNotEmpty($class);
        }
    }

    private function makeModel(string $status): WorkflowJob
    {
        $model = $this->createPartialMock(WorkflowJob::class, []);
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($model, ['status' => $status]);
        return $model;
    }
}
