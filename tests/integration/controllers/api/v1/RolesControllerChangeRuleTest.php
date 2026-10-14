<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\RolesController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\User;
use app\services\RoleChangePolicy;
use app\services\RoleService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\rbac\DbManager;
use yii\rbac\Role;
use yii\web\NotFoundHttpException;

/**
 * The roles API for a caller who is no superadmin (RoleChangePolicy): POST
 * and PUT with a permission they do not hold answer 403
 * (PERMISSION_REFUSED) after the 422 of a body that does not validate; PUT
 * and DELETE of a role they may not assign answer 403 (CHANGE_REFUSED) after
 * the 404 and before every other check. Each writes nothing, not even the
 * audit entry. Run with the default role viewer of the web application.
 */
final class RolesControllerChangeRuleTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /** @var list<string> the default roles of the manager before the test */
    private array $savedDefaultRoles = [];

    private RolesController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();
        $auth = $this->auth();
        $this->savedDefaultRoles = array_map('strval', $auth->defaultRoles);
        $auth->defaultRoles = ['viewer'];
        $this->ctrl = new RolesController('api/v1/roles', \Yii::$app);
    }

    protected function tearDown(): void
    {
        $this->auth()->defaultRoles = $this->savedDefaultRoles;
        parent::tearDown();
    }

    /**
     * regression: the probe of wave 5c through PUT /api/v1/roles/{name}: a
     * custom role {role.view, role.update} gave itself user.update and
     * approval-rule.update.
     */
    public function testRegressionPutDoesNotLetARoleEditorGiveTheirOwnRolePermissionsTheyLack(): void
    {
        $role = $this->customRole('rapi-editor', ['role.view', 'role.update']);
        $editor = $this->createUserWithRole('rapi-editor', $role->name);
        $this->authenticate($editor);
        $before = $this->auditCount(AuditLog::ACTION_ROLE_UPDATED);
        $this->setBody(['description' => 'escalated', 'permissions' => ['role.view', 'role.update', 'user.update', 'approval-rule.update']]);

        $result = $this->ctrl->actionUpdate($role->name);

        $this->assertError(403, sprintf(RoleChangePolicy::PERMISSION_REFUSED, 'user.update'), $result);
        $this->assertSame(['role.update', 'role.view'], $this->service()->directPermissions($role->name));
        $this->assertSame('rapi test role', $this->service()->getRole($role->name)['description'] ?? null);
        $this->assertFalse($this->auth()->checkAccess($editor->id, 'user.update'));
        $this->assertFalse($this->auth()->checkAccess($editor->id, 'approval-rule.update'));
        $this->assertSame($before, $this->auditCount(AuditLog::ACTION_ROLE_UPDATED), 'no audit entry');
    }

    /**
     * regression: POST /api/v1/roles created a role with any permission.
     */
    public function testRegressionPostRefusesAPermissionTheRoleManagerLacks(): void
    {
        $this->authenticate($this->roleManager('rapi-create'));
        $name = $this->uniqueName();
        $before = $this->auditCount(AuditLog::ACTION_ROLE_CREATED);
        $this->setBody(['name' => $name, 'permissions' => ['role.view', 'user.update']]);

        $result = $this->ctrl->actionCreate();

        $this->assertError(403, sprintf(RoleChangePolicy::PERMISSION_REFUSED, 'user.update'), $result);
        $this->assertNull($this->auth()->getRole($name));
        $this->assertSame($before, $this->auditCount(AuditLog::ACTION_ROLE_CREATED), 'no audit entry');
    }

    /**
     * regression: PUT changed the admin role, or any role with permissions
     * the caller lacks, for whoever held role.update. The 403 comes before
     * the 422 of a body that does not validate.
     */
    public function testRegressionPutOfARoleTheRoleManagerMayNotAssignAnswers403(): void
    {
        $this->authenticate($this->roleManager('rapi-edit'));
        $launcher = $this->customRole('rapi-launcher', ['job.view', 'job.launch']);
        $before = $this->auditCount(AuditLog::ACTION_ROLE_UPDATED);

        foreach (['admin', $launcher->name] as $name) {
            $this->setBody(['description' => 'changed', 'permissions' => ['job.view']]);
            $this->assertError(403, RoleChangePolicy::CHANGE_REFUSED, $this->ctrl->actionUpdate($name), $name);
            $this->setBody(['permissions' => ['no.such-permission']]);
            $this->assertError(403, RoleChangePolicy::CHANGE_REFUSED, $this->ctrl->actionUpdate($name), "{$name}: before the 422");
        }
        $this->assertSame(['job.launch', 'job.view'], $this->service()->directPermissions($launcher->name));
        $this->assertSame($before, $this->auditCount(AuditLog::ACTION_ROLE_UPDATED), 'no audit entry');
    }

    /**
     * regression: DELETE removed any custom role for whoever held
     * role.delete. A system role the caller may not assign answers 403
     * before the 422 of a system role.
     */
    public function testRegressionDeleteOfARoleTheRoleManagerMayNotAssignAnswers403(): void
    {
        $this->authenticate($this->roleManager('rapi-delete'));
        $launcher = $this->customRole('rapi-launcher', ['job.view', 'job.launch']);
        $before = $this->auditCount(AuditLog::ACTION_ROLE_DELETED);

        foreach ([$launcher->name, 'admin'] as $name) {
            $this->assertError(403, RoleChangePolicy::CHANGE_REFUSED, $this->ctrl->actionDelete($name), $name);
        }

        $this->assertNotNull($this->auth()->getRole($launcher->name));
        $this->assertSame($before, $this->auditCount(AuditLog::ACTION_ROLE_DELETED), 'no audit entry');
    }

    public function testAnUnknownRoleAnswers404Before403(): void
    {
        $this->authenticate($this->roleManager('rapi-unknown'));
        $name = 'rapi-ghost-' . uniqid();
        $this->setBody(['permissions' => ['user.update']]);

        foreach (['actionUpdate', 'actionDelete'] as $action) {
            try {
                $this->ctrl->$action($name);
                $this->fail("{$action}: expected a 404");
            } catch (NotFoundHttpException $e) {
                $this->assertSame("Role \"{$name}\" not found.", $e->getMessage(), $action);
            }
        }
    }

    /**
     * A body that does not validate answers 422 before the refusal of a
     * permission: an unknown permission is not "a permission you do not
     * hold".
     */
    public function testA422ComesBeforeTheRefusalOfAPermission(): void
    {
        $this->authenticate($this->roleManager('rapi-422'));

        $this->setBody(['name' => 'Bad Name!', 'permissions' => ['user.update']]);
        $this->assertSame(422, $this->statusOf($this->ctrl->actionCreate()));

        $this->setBody(['permissions' => ['role.view', 'no.such-permission']]);
        $this->assertError(422, 'Unknown permission: no.such-permission', $this->ctrl->actionUpdate($this->roleManagerRole()->name));
    }

    public function testARoleManagerCreatesChangesAndDeletesRolesWithPermissionsTheyHold(): void
    {
        $this->authenticate($this->roleManager('rapi-allowed'));
        $name = $this->uniqueName();

        $this->setBody(['name' => $name, 'permissions' => ['role.view', 'project.view']]);
        $created = $this->ctrl->actionCreate();
        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertSame(['project.view', 'role.view'], $this->dataOf($created)['direct_permissions']);

        $this->setBody(['description' => 'changed', 'permissions' => ['job.view', 'role.update']]);
        $updated = $this->ctrl->actionUpdate($name);
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(['job.view', 'role.update'], $this->dataOf($updated)['direct_permissions']);
        $this->assertSame('changed', $this->dataOf($updated)['description']);

        $this->assertSame(['deleted' => true], $this->dataOf($this->ctrl->actionDelete($name)));
        $this->assertNull($this->auth()->getRole($name));
    }

    /**
     * A superadmin passes the rule: they create a role with every
     * permission, change the admin role, and meet only the refusal of a
     * system role.
     */
    public function testASuperadminMayDoEverything(): void
    {
        $superadmin = $this->createUser('rapi-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $this->authenticate($superadmin);
        $all = array_keys($this->auth()->getPermissions());
        sort($all);
        $name = $this->uniqueName();

        $this->setBody(['name' => $name, 'permissions' => $all]);
        $this->assertSame($all, $this->dataOf($this->ctrl->actionCreate())['direct_permissions']);

        $this->setBody(['description' => 'changed', 'permissions' => $this->service()->directPermissions('admin')]);
        $this->assertSame('changed', $this->dataOf($this->ctrl->actionUpdate('admin'))['description']);

        $this->assertError(422, 'Cannot delete system role.', $this->ctrl->actionDelete('admin'));
        $this->assertSame(['deleted' => true], $this->dataOf($this->ctrl->actionDelete($name)));
    }

    /**
     * A role that is gone by the time it would be deleted, after every check
     * passed, answers 422.
     */
    public function testARoleTheServiceCouldNotDeleteAnswers422(): void
    {
        $this->authenticate($this->roleManager('rapi-gone'));
        $mine = $this->customRole('rapi-gone', ['project.view']);
        $app = \Yii::$app;
        $original = $app->get('roleService');
        $app->set('roleService', new class () extends RoleService {
            public function deleteRole(string $name, RoleChangePolicy $policy): bool
            {
                return false;
            }
        });
        try {
            $this->assertError(422, 'Role could not be deleted.', $this->ctrl->actionDelete($mine->name));
        } finally {
            $app->set('roleService', $original);
        }
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * @param array<string, mixed> $result
     */
    private function assertError(int $status, string $message, array $result, string $what = ''): void
    {
        $this->assertSame($status, \Yii::$app->response->statusCode, $what);
        $this->assertSame(['error' => ['message' => $message]], $result, $what);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function statusOf(array $result): int
    {
        $this->assertArrayHasKey('error', $result);

        return \Yii::$app->response->statusCode;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function dataOf(array $result): array
    {
        $this->assertArrayHasKey('data', $result, (string)json_encode($result));
        $this->assertIsArray($result['data']);

        return $result['data'];
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $component */
        $component = \Yii::$app->user;
        $component->loginByAccessToken($raw);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
    }

    private function service(): RoleService
    {
        /** @var RoleService $service */
        $service = \Yii::$app->get('roleService');

        return $service;
    }

    private function auth(): DbManager
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(DbManager::class, $auth);

        return $auth;
    }

    private function uniqueName(): string
    {
        return 'rapi-' . substr(md5(uniqid('', true)), 0, 8);
    }

    private function auditCount(string $action): int
    {
        return (int)AuditLog::find()->where(['action' => $action])->count();
    }

    /**
     * A custom role with exactly $permissions.
     *
     * @param list<string> $permissions
     */
    private function customRole(string $prefix, array $permissions): Role
    {
        return $this->roleWith($prefix . '-' . substr(md5(uniqid('', true)), 0, 8), $permissions);
    }

    /**
     * The role manager role of these tests: role.view, role.create,
     * role.update and role.delete; one per test.
     */
    private function roleManagerRole(): Role
    {
        return $this->auth()->getRole('rapi-role-manager')
            ?? $this->roleWith('rapi-role-manager', ['role.view', 'role.create', 'role.update', 'role.delete']);
    }

    /**
     * @param list<string> $permissions
     */
    private function roleWith(string $name, array $permissions): Role
    {
        $auth = $this->auth();
        $role = $auth->createRole($name);
        $role->description = 'rapi test role';
        $auth->add($role);
        foreach ($permissions as $permissionName) {
            $permission = $auth->getPermission($permissionName);
            $this->assertNotNull($permission, $permissionName);
            $auth->addChild($role, $permission);
        }

        return $role;
    }

    private function roleManager(string $suffix): User
    {
        return $this->createUserWithRole($suffix, $this->roleManagerRole()->name);
    }
}
