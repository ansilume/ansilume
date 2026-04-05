<?php

declare(strict_types=1);

namespace app\tests\integration\api;

use app\controllers\api\v1\RolesController;
use app\tests\integration\DbTestCase;

/**
 * Tests API controller structure for roles.
 *
 * Behavioral logic (create/update/delete/system-role protection) is covered
 * by RoleServiceTest. The E2E rbac specs exercise the actual HTTP routes
 * via Playwright.
 */
class RolesApiTest extends DbTestCase
{
    public function testControllerExtendsBaseApiController(): void
    {
        $ref = new \ReflectionClass(RolesController::class);
        $this->assertTrue(
            $ref->isSubclassOf(\app\controllers\api\v1\BaseApiController::class),
            'RolesController must extend BaseApiController'
        );
    }

    public function testAllActionsExist(): void
    {
        foreach (['actionIndex', 'actionView', 'actionCreate', 'actionUpdate', 'actionDelete', 'actionPermissions'] as $method) {
            $this->assertTrue(
                method_exists(RolesController::class, $method),
                "RolesController::{$method} must exist"
            );
        }
    }

    public function testCsrfDisabled(): void
    {
        $ctrl = new RolesController('roles', \Yii::$app);
        $this->assertFalse($ctrl->enableCsrfValidation);
    }
}
