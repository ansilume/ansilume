<?php

declare(strict_types=1);

namespace app\tests\integration\api;

use app\controllers\api\v1\ApprovalsController;
use app\tests\integration\DbTestCase;

/**
 * Tests API controller structure for approvals.
 */
class ApprovalsApiTest extends DbTestCase
{
    public function testControllerExtendsBaseApiController(): void
    {
        $ref = new \ReflectionClass(ApprovalsController::class);
        $this->assertTrue(
            $ref->isSubclassOf(\app\controllers\api\v1\BaseApiController::class),
            'ApprovalsController must extend BaseApiController'
        );
    }

    public function testActionIndexExists(): void
    {
        $this->assertTrue(method_exists(ApprovalsController::class, 'actionIndex'));
    }

    public function testActionViewExists(): void
    {
        $this->assertTrue(method_exists(ApprovalsController::class, 'actionView'));
    }

    public function testActionApproveExists(): void
    {
        $this->assertTrue(method_exists(ApprovalsController::class, 'actionApprove'));
    }

    public function testActionRejectExists(): void
    {
        $this->assertTrue(method_exists(ApprovalsController::class, 'actionReject'));
    }
}
