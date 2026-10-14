<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\RoleController;
use app\models\AuditLog;
use app\models\RoleForm;
use app\models\User;
use app\services\RoleChangePolicy;
use app\services\RoleService;
use app\tests\integration\TeamScopeFixtures;
use yii\rbac\DbManager;
use yii\rbac\Role;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Roles pages (RoleController) for a user who is no superadmin
 * (RoleChangePolicy): they create roles only with permissions they hold,
 * open, save or delete only roles they may assign, and add to those only
 * permissions they hold. A refused permission is an error of the Permissions
 * field; a role they may not change answers 403, after the 404 of a role
 * that does not exist. Run with the default role viewer of the web
 * application (config/web.php).
 */
final class RoleControllerChangeRuleTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /** @var list<string> the default roles of the manager before the test */
    private array $savedDefaultRoles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $auth = $this->auth();
        $this->savedDefaultRoles = array_map('strval', $auth->defaultRoles);
        $auth->defaultRoles = ['viewer'];
    }

    protected function tearDown(): void
    {
        $this->auth()->defaultRoles = $this->savedDefaultRoles;
        parent::tearDown();
    }

    /**
     * regression: the probe of wave 5c through the Edit Role form. A custom
     * role {role.view, role.update} gave itself user.update and
     * approval-rule.update, and its holder then held both. Now the form
     * comes back with the refusal on the Permissions field, and nothing is
     * written, not even the audit entry.
     */
    public function testRegressionTheEditFormDoesNotLetARoleEditorGiveTheirOwnRolePermissionsTheyLack(): void
    {
        $role = $this->customRole('rcr-editor', ['role.view', 'role.update']);
        $editor = $this->createUserWithRole('rcr-editor', $role->name);
        $this->loginAs($editor);
        $before = $this->auditCount(AuditLog::ACTION_ROLE_UPDATED);
        $this->setPost(['RoleForm' => [
            'name' => $role->name,
            'description' => 'escalated',
            'permissions' => ['role.view', 'role.update', 'user.update', 'approval-rule.update'],
        ]]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate($role->name);

        $this->assertSame('rendered:update', $result);
        $form = $ctrl->capturedParams['form'];
        $this->assertInstanceOf(RoleForm::class, $form);
        $this->assertSame(sprintf(RoleChangePolicy::PERMISSION_REFUSED, 'user.update'), $form->getFirstError('permissions'));
        $this->assertSame(['role.update', 'role.view'], $this->service()->directPermissions($role->name));
        $this->assertSame('rcr test role', $this->service()->getRole($role->name)['description'] ?? null);
        $this->assertFalse($this->auth()->checkAccess($editor->id, 'user.update'));
        $this->assertFalse($this->auth()->checkAccess($editor->id, 'approval-rule.update'));
        $this->assertSame($before, $this->auditCount(AuditLog::ACTION_ROLE_UPDATED), 'no audit entry');
        $this->assertSame([], \Yii::$app->session->getAllFlashes());
    }

    /**
     * regression: the New Role form created a role with any permission.
     */
    public function testRegressionTheNewRoleFormRefusesAPermissionTheRoleManagerLacks(): void
    {
        $this->loginAs($this->roleManager('rcr-create'));
        $name = $this->uniqueName();
        $before = $this->auditCount(AuditLog::ACTION_ROLE_CREATED);
        $this->setPost(['RoleForm' => ['name' => $name, 'description' => '', 'permissions' => ['role.view', 'approval-rule.update']]]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:create', $result);
        $form = $ctrl->capturedParams['form'];
        $this->assertInstanceOf(RoleForm::class, $form);
        $this->assertSame(sprintf(RoleChangePolicy::PERMISSION_REFUSED, 'approval-rule.update'), $form->getFirstError('permissions'));
        $this->assertNull($this->auth()->getRole($name));
        $this->assertSame($before, $this->auditCount(AuditLog::ACTION_ROLE_CREATED), 'no audit entry');
    }

    /**
     * regression: the Edit Role form of the admin role, and of any role with
     * permissions the user lacks, opened and saved for whoever held
     * role.update.
     */
    public function testRegressionTheEditFormOfARoleTheRoleManagerMayNotAssignAnswers403(): void
    {
        $this->loginAs($this->roleManager('rcr-edit'));
        $launcher = $this->customRole('rcr-launcher', ['job.view', 'job.launch']);

        foreach (['admin', $launcher->name] as $name) {
            $this->assertForbidden(fn () => $this->makeController()->actionUpdate($name), "GET {$name}");
        }

        $before = $this->auditCount(AuditLog::ACTION_ROLE_UPDATED);
        $this->setPost(['RoleForm' => ['name' => $launcher->name, 'description' => 'changed', 'permissions' => ['job.view']]]);
        $this->assertForbidden(fn () => $this->makeController()->actionUpdate($launcher->name), 'POST');
        $this->assertSame(['job.launch', 'job.view'], $this->service()->directPermissions($launcher->name));
        $this->assertSame($before, $this->auditCount(AuditLog::ACTION_ROLE_UPDATED), 'no audit entry');
    }

    /**
     * regression: Delete removed any custom role for whoever held
     * role.delete. The admin role answers 403 too, before the refusal of a
     * system role.
     */
    public function testRegressionDeleteOfARoleTheRoleManagerMayNotAssignAnswers403(): void
    {
        $this->loginAs($this->roleManager('rcr-delete'));
        $launcher = $this->customRole('rcr-launcher', ['job.view', 'job.launch']);
        $before = $this->auditCount(AuditLog::ACTION_ROLE_DELETED);

        foreach ([$launcher->name, 'admin'] as $name) {
            $this->assertForbidden(fn () => $this->makeController()->actionDelete($name), $name);
        }

        $this->assertNotNull($this->auth()->getRole($launcher->name));
        $this->assertSame($before, $this->auditCount(AuditLog::ACTION_ROLE_DELETED), 'no audit entry');
        $this->assertSame([], \Yii::$app->session->getAllFlashes());
    }

    public function testARoleManagerAddsPermissionsTheyHoldToARoleTheyMayAssign(): void
    {
        $manager = $this->roleManager('rcr-allowed');
        $this->loginAs($manager);
        $role = $this->customRole('rcr-allowed', ['project.view']);
        $this->setPost(['RoleForm' => ['name' => $role->name, 'description' => 'changed', 'permissions' => ['project.view', 'role.view', 'job.view']]]);

        $result = $this->makeController()->actionUpdate($role->name);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(['job.view', 'project.view', 'role.view'], $this->service()->directPermissions($role->name));
        $this->assertNotNull(AuditLog::findOne(['action' => AuditLog::ACTION_ROLE_UPDATED, 'user_id' => $manager->id]));
        $this->assertSame(['success' => "Role \"{$role->name}\" updated."], \Yii::$app->session->getAllFlashes());
    }

    public function testARoleManagerCreatesAndDeletesARoleWithPermissionsTheyHold(): void
    {
        $this->loginAs($this->roleManager('rcr-create-ok'));
        $name = $this->uniqueName();
        $this->setPost(['RoleForm' => ['name' => $name, 'description' => '', 'permissions' => ['role.view', 'project.view']]]);

        $this->assertInstanceOf(Response::class, $this->makeController()->actionCreate());
        $this->assertSame(['project.view', 'role.view'], $this->service()->directPermissions($name));

        $this->assertInstanceOf(Response::class, $this->makeController()->actionDelete($name));
        $this->assertNull($this->auth()->getRole($name));
    }

    /**
     * The forms offer only what the user may grant: the role manager holds
     * the role.* permissions and, through the default role, the viewer's.
     */
    public function testTheFormsOfferOnlyThePermissionsTheRoleManagerHolds(): void
    {
        $this->loginAs($this->roleManager('rcr-offer'));

        $ctrl = $this->makeController();
        $ctrl->actionCreate();
        $create = $ctrl->capturedParams['grantable'];
        $ctrl->actionUpdate($this->roleManagerRole()->name);
        $update = $ctrl->capturedParams['grantable'];

        foreach ([$create, $update] as $grantable) {
            $this->assertIsArray($grantable);
            $this->assertSame(\app\helpers\PermissionCatalog::allPermissionNames(), array_keys($grantable));
            $this->assertTrue($grantable['role.update']);
            $this->assertTrue($grantable['project.view'], 'held through the default role');
            $this->assertFalse($grantable['user.update']);
            $this->assertFalse($grantable['approval-rule.update']);
        }
    }

    public function testTheRolePageSaysWhetherTheUserMayChangeTheRole(): void
    {
        $this->loginAs($this->roleManager('rcr-page'));

        foreach (['admin' => false, 'operator' => false, 'viewer' => true, $this->roleManagerRole()->name => true] as $name => $mayChange) {
            $ctrl = $this->makeController();
            $ctrl->actionView((string)$name);
            $this->assertSame($mayChange, $ctrl->capturedParams['mayChange'], (string)$name);
        }
    }

    public function testAnUnknownRoleAnswers404Before403(): void
    {
        $this->loginAs($this->roleManager('rcr-unknown'));
        $name = 'rcr-ghost-' . uniqid();

        foreach (['actionUpdate', 'actionDelete'] as $action) {
            try {
                $this->makeController()->$action($name);
                $this->fail("{$action}: expected a 404");
            } catch (NotFoundHttpException $e) {
                $this->assertSame("Role \"{$name}\" not found.", $e->getMessage(), $action);
            }
        }
    }

    /**
     * A superadmin opens and saves the admin role's Edit form and deletes a
     * role with every permission.
     */
    public function testASuperadminMayChangeEveryRole(): void
    {
        $superadmin = $this->createUser('rcr-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $this->loginAs($superadmin);
        $all = $this->customRole('rcr-all', array_keys($this->auth()->getPermissions()));

        $this->assertSame('rendered:update', $this->makeController()->actionUpdate('admin'));
        $this->setPost(['RoleForm' => ['name' => 'admin', 'description' => 'changed', 'permissions' => $this->service()->directPermissions('admin')]]);
        $this->assertInstanceOf(Response::class, $this->makeController()->actionUpdate('admin'));
        $this->assertSame('changed', $this->service()->getRole('admin')['description'] ?? null);
        $this->assertInstanceOf(Response::class, $this->makeController()->actionDelete($all->name));
        $this->assertNull($this->auth()->getRole($all->name));
    }

    /**
     * A role that is gone by the time it would be deleted, after every check
     * passed, answers 404.
     */
    public function testARoleTheServiceCouldNotDeleteAnswers404(): void
    {
        $this->loginAs($this->roleManager('rcr-gone'));
        $mine = $this->customRole('rcr-gone', ['project.view']);
        $app = \Yii::$app;
        $original = $app->get('roleService');
        $app->set('roleService', new class () extends RoleService {
            public function deleteRole(string $name, RoleChangePolicy $policy): bool
            {
                return false;
            }
        });
        try {
            $this->expectException(NotFoundHttpException::class);
            $this->expectExceptionMessage("Role \"{$mine->name}\" not found.");
            $this->makeController()->actionDelete($mine->name);
        } finally {
            $app->set('roleService', $original);
        }
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * @param callable(): mixed $call
     */
    private function assertForbidden(callable $call, string $what): void
    {
        try {
            $call();
        } catch (ForbiddenHttpException $e) {
            $this->assertSame(RoleChangePolicy::CHANGE_REFUSED, $e->getMessage(), $what);
            return;
        }
        $this->fail("{$what}: expected a 403");
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
        return 'rcr-' . substr(md5(uniqid('', true)), 0, 8);
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
        return $this->auth()->getRole('rcr-role-manager')
            ?? $this->roleWith('rcr-role-manager', ['role.view', 'role.create', 'role.update', 'role.delete']);
    }

    /**
     * @param list<string> $permissions
     */
    private function roleWith(string $name, array $permissions): Role
    {
        $auth = $this->auth();
        $role = $auth->createRole($name);
        $role->description = 'rcr test role';
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

    /**
     * The controller, keeping what its actions render (CapturesParams).
     */
    private function makeController(): RoleController
    {
        return new class ('role', \Yii::$app) extends RoleController {
            use CapturesParams;
        };
    }
}
