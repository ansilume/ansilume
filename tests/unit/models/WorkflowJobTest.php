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

    /** @return array<int, array{0: string, 1: string}> */
    public static function statusLabelData(): array
    {
        return [
            [WorkflowJob::STATUS_RUNNING, 'Running'],
            [WorkflowJob::STATUS_SUCCEEDED, 'Succeeded'],
            [WorkflowJob::STATUS_FAILED, 'Failed'],
            [WorkflowJob::STATUS_CANCELED, 'Canceled'],
        ];
    }

    /** @dataProvider statusLabelData */
    public function testStatusLabelMapsToHumanText(string $status, string $expected): void
    {
        $this->assertSame($expected, WorkflowJob::statusLabel($status));
    }

    public function testStatusLabelFallsBackToInputForUnknownStatus(): void
    {
        $this->assertSame('weird', WorkflowJob::statusLabel('weird'));
    }

    /** @return array<int, array{0: string, 1: string}> */
    public static function statusCssClassData(): array
    {
        return [
            [WorkflowJob::STATUS_RUNNING, 'primary'],
            [WorkflowJob::STATUS_SUCCEEDED, 'success'],
            [WorkflowJob::STATUS_FAILED, 'danger'],
            [WorkflowJob::STATUS_CANCELED, 'warning'],
        ];
    }

    /** @dataProvider statusCssClassData */
    public function testStatusCssClassMapsToExpectedBadge(string $status, string $expected): void
    {
        $this->assertSame($expected, WorkflowJob::statusCssClass($status));
    }

    public function testStatusCssClassFallsBackToSecondaryForUnknownStatus(): void
    {
        $this->assertSame('secondary', WorkflowJob::statusCssClass('weird'));
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
