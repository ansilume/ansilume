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

    public function testStatusLabelReturnsStringForAll(): void
    {
        foreach (['pending', 'running', 'succeeded', 'failed', 'skipped'] as $status) {
            $label = WorkflowJobStep::statusLabel($status);
            $this->assertIsString($label);
            $this->assertNotEmpty($label);
        }
    }

    public function testStatusCssClassReturnsStringForAll(): void
    {
        foreach (['pending', 'running', 'succeeded', 'failed', 'skipped'] as $status) {
            $class = WorkflowJobStep::statusCssClass($status);
            $this->assertIsString($class);
            $this->assertNotEmpty($class);
        }
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
