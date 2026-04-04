<?php

declare(strict_types=1);

namespace app\tests\integration\api;

use app\controllers\api\v1\WorkflowJobsController;
use app\tests\integration\DbTestCase;

/**
 * Tests API controller structure for workflow jobs.
 */
class WorkflowJobsApiTest extends DbTestCase
{
    public function testControllerExtendsBaseApiController(): void
    {
        $ref = new \ReflectionClass(WorkflowJobsController::class);
        $this->assertTrue(
            $ref->isSubclassOf(\app\controllers\api\v1\BaseApiController::class),
            'WorkflowJobsController must extend BaseApiController'
        );
    }

    public function testActionIndexExists(): void
    {
        $this->assertTrue(method_exists(WorkflowJobsController::class, 'actionIndex'));
    }

    public function testActionViewExists(): void
    {
        $this->assertTrue(method_exists(WorkflowJobsController::class, 'actionView'));
    }

    public function testActionCancelExists(): void
    {
        $this->assertTrue(method_exists(WorkflowJobsController::class, 'actionCancel'));
    }
}
