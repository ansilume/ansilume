<?php

declare(strict_types=1);

namespace app\tests\integration\api;

use app\controllers\api\v1\WorkflowTemplatesController;
use app\models\WorkflowTemplate;
use app\tests\integration\DbTestCase;

/**
 * Tests API controller structure for workflow templates.
 */
class WorkflowTemplatesApiTest extends DbTestCase
{
    public function testControllerExtendsBaseApiController(): void
    {
        $ref = new \ReflectionClass(WorkflowTemplatesController::class);
        $this->assertTrue(
            $ref->isSubclassOf(\app\controllers\api\v1\BaseApiController::class),
            'WorkflowTemplatesController must extend BaseApiController'
        );
    }

    public function testActionIndexExists(): void
    {
        $this->assertTrue(method_exists(WorkflowTemplatesController::class, 'actionIndex'));
    }

    public function testActionViewExists(): void
    {
        $this->assertTrue(method_exists(WorkflowTemplatesController::class, 'actionView'));
    }

    public function testActionCreateExists(): void
    {
        $this->assertTrue(method_exists(WorkflowTemplatesController::class, 'actionCreate'));
    }

    public function testActionUpdateExists(): void
    {
        $this->assertTrue(method_exists(WorkflowTemplatesController::class, 'actionUpdate'));
    }

    public function testActionDeleteExists(): void
    {
        $this->assertTrue(method_exists(WorkflowTemplatesController::class, 'actionDelete'));
    }

    public function testActionLaunchExists(): void
    {
        $this->assertTrue(method_exists(WorkflowTemplatesController::class, 'actionLaunch'));
    }

    public function testWorkflowTemplateModelValidation(): void
    {
        $model = new WorkflowTemplate();
        $this->assertFalse($model->validate());
        $this->assertArrayHasKey('name', $model->errors);
    }

    public function testWorkflowTemplateValidWithName(): void
    {
        $model = new WorkflowTemplate();
        $model->name = 'Test Workflow';
        $model->created_by = 1;
        $this->assertTrue($model->validate());
    }

    public function testWorkflowTemplateSoftDelete(): void
    {
        $user = $this->createUser('wf_api');
        $wt = $this->createWorkflowTemplate($user->id);

        $this->assertFalse($wt->isDeleted());
        $wt->softDelete();
        $this->assertTrue($wt->isDeleted());

        // Default find should not return it
        $found = WorkflowTemplate::findOne($wt->id);
        $this->assertNull($found);

        // findWithDeleted should return it
        $found = WorkflowTemplate::findWithDeleted()->andWhere(['id' => $wt->id])->one();
        $this->assertNotNull($found);
    }
}
