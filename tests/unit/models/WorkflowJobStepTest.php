<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\WorkflowJobStep;
use PHPUnit\Framework\TestCase;

class WorkflowJobStepTest extends TestCase
{
    public function testStatusConstants(): void
    {
        $this->assertSame('pending', WorkflowJobStep::STATUS_PENDING);
        $this->assertSame('running', WorkflowJobStep::STATUS_RUNNING);
        $this->assertSame('succeeded', WorkflowJobStep::STATUS_SUCCEEDED);
        $this->assertSame('failed', WorkflowJobStep::STATUS_FAILED);
        $this->assertSame('skipped', WorkflowJobStep::STATUS_SKIPPED);
    }

    public function testIsFinishedForTerminalStatuses(): void
    {
        foreach (['succeeded', 'failed', 'skipped'] as $status) {
            $model = $this->makeModel($status);
            $this->assertTrue($model->isFinished(), "Expected isFinished() for '{$status}'");
        }
    }

    public function testIsNotFinishedForActiveStatuses(): void
    {
        foreach (['pending', 'running'] as $status) {
            $model = $this->makeModel($status);
            $this->assertFalse($model->isFinished(), "Expected !isFinished() for '{$status}'");
        }
    }

    public function testGetParsedOutputVarsEmpty(): void
    {
        $model = $this->makeModel('pending');
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($model, array_merge($ref->getValue($model), ['output_vars' => null]));

        $this->assertSame([], $model->getParsedOutputVars());
    }

    public function testGetParsedOutputVarsWithJson(): void
    {
        $model = $this->makeModel('succeeded');
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($model, array_merge($ref->getValue($model), ['output_vars' => '{"hosts": "web1"}']));

        $this->assertSame(['hosts' => 'web1'], $model->getParsedOutputVars());
    }

    /** @return array<int, array{0: string, 1: string}> */
    public static function statusLabelData(): array
    {
        return [
            [WorkflowJobStep::STATUS_PENDING, 'Pending'],
            [WorkflowJobStep::STATUS_RUNNING, 'Running'],
            [WorkflowJobStep::STATUS_SUCCEEDED, 'Succeeded'],
            [WorkflowJobStep::STATUS_FAILED, 'Failed'],
            [WorkflowJobStep::STATUS_SKIPPED, 'Skipped'],
        ];
    }

    /** @dataProvider statusLabelData */
    public function testStatusLabelMapsToHumanText(string $status, string $expected): void
    {
        $this->assertSame($expected, WorkflowJobStep::statusLabel($status));
    }

    public function testStatusLabelFallsBackToInputForUnknownStatus(): void
    {
        $this->assertSame('weird', WorkflowJobStep::statusLabel('weird'));
    }

    /** @return array<int, array{0: string, 1: string}> */
    public static function statusCssClassData(): array
    {
        return [
            [WorkflowJobStep::STATUS_PENDING, 'secondary'],
            [WorkflowJobStep::STATUS_RUNNING, 'primary'],
            [WorkflowJobStep::STATUS_SUCCEEDED, 'success'],
            [WorkflowJobStep::STATUS_FAILED, 'danger'],
            [WorkflowJobStep::STATUS_SKIPPED, 'info'],
        ];
    }

    /** @dataProvider statusCssClassData */
    public function testStatusCssClassMapsToExpectedBadge(string $status, string $expected): void
    {
        $this->assertSame($expected, WorkflowJobStep::statusCssClass($status));
    }

    public function testStatusCssClassFallsBackToSecondaryForUnknownStatus(): void
    {
        $this->assertSame('secondary', WorkflowJobStep::statusCssClass('weird'));
    }

    private function makeModel(string $status): WorkflowJobStep
    {
        $model = $this->createPartialMock(WorkflowJobStep::class, []);
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($model, ['status' => $status]);
        return $model;
    }
}
