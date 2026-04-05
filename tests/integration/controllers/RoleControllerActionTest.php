<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\RoleController;
use app\models\AuditLog;
use app\services\RoleService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Exercises RoleController actions against the real RBAC manager. System
 * roles (viewer/operator/admin) are seeded by migrations and are available
 * in the test database.
 */
class RoleControllerActionTest extends WebControllerTestCase
{
    public function testIndexRendersRoles(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $result = $ctrl->actionIndex();

        $this->assertSame('rendered:index', $result);
        $roles = $ctrl->capturedParams['roles'];
        $this->assertIsArray($roles);
        $names = array_column($roles, 'name');
        $this->assertContains('viewer', $names);
        $this->assertContains('operator', $names);
        $this->assertContains('admin', $names);
    }

    public function testViewRendersSystemRole(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $result = $ctrl->actionView('viewer');

        $this->assertSame('rendered:view', $result);
        $this->assertSame('viewer', $ctrl->capturedParams['role']['name']);
        $this->assertTrue($ctrl->capturedParams['role']['isSystem']);
        $this->assertIsArray($ctrl->capturedParams['users']);
    }

    public function testViewRendersCustomRoleWithAssignedUser(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $name = $this->createCustomRole(['project.view']);
        // Assign the role to our user so the users list is populated.
        $auth = \Yii::$app->authManager;
        $role = $auth->getRole($name);
        $auth->assign($role, $user->id);

        $ctrl = $this->makeController();
        $result = $ctrl->actionView($name);

        $this->assertSame('rendered:view', $result);
        $this->assertSame($name, $ctrl->capturedParams['role']['name']);
        $this->assertFalse($ctrl->capturedParams['role']['isSystem']);
        $users = $ctrl->capturedParams['users'];
        $this->assertCount(1, $users);
        $this->assertSame($user->id, (int)$users[0]->id);
    }

    public function testViewThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionView('does-not-exist-' . uniqid());
    }

    public function testCreateRendersFormOnGet(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:create', $result);
        $this->assertArrayHasKey('form', $ctrl->capturedParams);
    }

    public function testCreatePersistsAndRedirects(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $name = 'custom-' . substr(md5(uniqid('', true)), 0, 8);
        $this->setPost([
            'RoleForm' => [
                'name' => $name,
                'description' => 'a test role',
                'permissions' => ['project.view', 'job.view'],
            ],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertInstanceOf(Response::class, $result);
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth->getRole($name));

        $this->assertNotNull(AuditLog::findOne([
            'action' => AuditLog::ACTION_ROLE_CREATED,
            'user_id' => $user->id,
        ]));
    }

    public function testCreateInvalidInputRendersForm(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $this->setPost([
            'RoleForm' => [
                'name' => '', // required → invalid
                'description' => '',
                'permissions' => [],
            ],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:create', $result);
        $this->assertTrue($ctrl->capturedParams['form']->hasErrors());
    }

    public function testUpdateRendersFormOnGet(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $name = $this->createCustomRole(['project.view']);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate($name);

        $this->assertSame('rendered:update', $result);
        $this->assertSame($name, $ctrl->capturedParams['form']->name);
        $this->assertSame(['project.view'], $ctrl->capturedParams['form']->permissions);
        $this->assertSame($name, $ctrl->capturedParams['role']['name']);
    }

    public function testUpdatePersistsChanges(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $name = $this->createCustomRole(['project.view']);

        $this->setPost([
            'RoleForm' => [
                'name' => $name,
                'description' => 'updated description',
                'permissions' => ['project.view', 'job.view'],
            ],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate($name);

        $this->assertInstanceOf(Response::class, $result);

        /** @var RoleService $svc */
        $svc = \Yii::$app->get('roleService');
        $direct = $svc->directPermissions($name);
        sort($direct);
        $this->assertSame(['job.view', 'project.view'], $direct);

        $this->assertNotNull(AuditLog::findOne([
            'action' => AuditLog::ACTION_ROLE_UPDATED,
            'user_id' => $user->id,
        ]));
    }

    public function testUpdateThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionUpdate('does-not-exist-' . uniqid());
    }

    public function testDeleteRemovesCustomRole(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $name = $this->createCustomRole(['project.view']);

        $ctrl = $this->makeController();
        $result = $ctrl->actionDelete($name);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertNull(\Yii::$app->authManager->getRole($name));

        $this->assertNotNull(AuditLog::findOne([
            'action' => AuditLog::ACTION_ROLE_DELETED,
            'user_id' => $user->id,
        ]));
    }

    public function testDeleteRefusesSystemRole(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $result = $ctrl->actionDelete('viewer');

        $this->assertInstanceOf(Response::class, $result);
        // System role still exists.
        $this->assertNotNull(\Yii::$app->authManager->getRole('viewer'));
        // Error flash set.
        $flashes = \Yii::$app->session->getAllFlashes();
        $this->assertArrayHasKey('error', $flashes);
    }

    public function testDeleteThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionDelete('does-not-exist-' . uniqid());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Create a custom role directly via the auth manager so each test has a
     * unique, isolated subject that survives transaction rollback cleanup.
     *
     * @param string[] $permissions
     */
    private function createCustomRole(array $permissions): string
    {
        $name = 'custom-' . substr(md5(uniqid('', true)), 0, 8);
        $auth = \Yii::$app->authManager;
        $role = $auth->createRole($name);
        $role->description = 'test';
        $auth->add($role);
        foreach ($permissions as $permName) {
            $perm = $auth->getPermission($permName);
            if ($perm !== null) {
                $auth->addChild($role, $perm);
            }
        }
        return $name;
    }

    private function makeController(): RoleController
    {
        return new class ('role', \Yii::$app) extends RoleController {
            public string $capturedView = '';
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                $this->capturedView = $view;
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): \yii\web\Response
            {
                $r = new \yii\web\Response();
                $r->content = 'redirected';
                return $r;
            }
        };
    }
}
