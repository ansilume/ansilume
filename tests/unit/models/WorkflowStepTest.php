<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\WorkflowStep;
use PHPUnit\Framework\TestCase;

class WorkflowStepTest extends TestCase
{
    public function testTypeConstants(): void
    {
        $this->assertSame('job', WorkflowStep::TYPE_JOB);
        $this->assertSame('approval', WorkflowStep::TYPE_APPROVAL);
        $this->assertSame('pause', WorkflowStep::TYPE_PAUSE);
    }

    public function testTypeLabelsReturnsAll(): void
    {
        $labels = WorkflowStep::typeLabels();
        $this->assertArrayHasKey('job', $labels);
        $this->assertArrayHasKey('approval', $labels);
        $this->assertArrayHasKey('pause', $labels);
    }

    public function testGetParsedExtraVarsTemplateEmpty(): void
    {
        $step = $this->createPartialMock(WorkflowStep::class, []);
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($step, ['extra_vars_template' => null]);

        $this->assertSame([], $step->getParsedExtraVarsTemplate());
    }

    public function testGetParsedExtraVarsTemplateWithJson(): void
    {
        $step = $this->createPartialMock(WorkflowStep::class, []);
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($step, ['extra_vars_template' => '{"target": "hosts"}']);

        $this->assertSame(['target' => 'hosts'], $step->getParsedExtraVarsTemplate());
    }
}
