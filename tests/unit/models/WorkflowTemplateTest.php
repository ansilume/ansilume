<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\WorkflowTemplate;
use PHPUnit\Framework\TestCase;

class WorkflowTemplateTest extends TestCase
{
    public function testTableName(): void
    {
        $this->assertSame('{{%workflow_template}}', WorkflowTemplate::tableName());
    }

    public function testIsDeletedReturnsFalseForNull(): void
    {
        $model = $this->createPartialMock(WorkflowTemplate::class, []);
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($model, ['deleted_at' => null]);

        $this->assertFalse($model->isDeleted());
    }

    public function testIsDeletedReturnsTrueForTimestamp(): void
    {
        $model = $this->createPartialMock(WorkflowTemplate::class, []);
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($model, ['deleted_at' => time()]);

        $this->assertTrue($model->isDeleted());
    }
}
