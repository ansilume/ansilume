<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\RoleController;
use app\models\RoleForm;
use app\models\User;
use app\tests\integration\TeamScopeFixtures;
use yii\rbac\DbManager;
use yii\rbac\Role;

/**
 * What the Roles pages offer a user who is no superadmin
 * (app\services\RoleChangePolicy), rendered from views/role/*.php: a role's
 * page offers Edit and Delete only for a role they may assign, and the role
 * form disables the permissions they do not hold, with a hint. Run with the
 * default role viewer of the web application (config/web.php).
 */
final class RolePagesTest extends WebControllerTestCase
{
    use RendersRealViews;
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
     * regression: a role's page offered Edit to whoever held role.update and
     * Delete to whoever held role.delete, for the admin role and for roles
     * with permissions they lack too.
     */
    public function testRegressionTheRolePageOffersEditAndDeleteOnlyForARoleTheUserMayAssign(): void
    {
        $this->loginAs($this->roleManager('rpg-page'));
        $launcher = $this->roleWith('rpg-launcher-' . uniqid(), ['job.view', 'job.launch']);
        $mine = $this->roleWith('rpg-mine-' . uniqid(), ['project.view']);

        foreach (['admin', $launcher->name] as $name) {
            $page = $this->xpath($this->page('actionView', $name, 'view'));
            $this->assertSame(0, $page->query('//a[normalize-space()="Edit"]')->length, "{$name}: no Edit");
            $this->assertSame(0, $page->query('//form[contains(@action, "/role/delete")]')->length, "{$name}: no Delete");
        }

        $page = $this->xpath($this->page('actionView', $mine->name, 'view'));
        $this->assertSame(1, $page->query('//a[normalize-space()="Edit"][contains(@href, "/role/update")]')->length, 'Edit for a role they may assign');
        $this->assertSame(1, $page->query('//form[contains(@action, "/role/delete")]')->length, 'Delete for a role they may assign');
    }

    /**
     * The New Role form disables the permissions the role manager does not
     * hold and says why; the ones they hold, through their role or the
     * default role, stay enabled.
     */
    public function testTheRoleFormDisablesThePermissionsTheUserDoesNotHold(): void
    {
        $this->loginAs($this->roleManager('rpg-form'));

        $html = $this->page('actionCreate', null, 'create');
        $form = $this->xpath($html);

        foreach (['user.update', 'approval-rule.update', 'job.launch'] as $permission) {
            $this->assertSame(1, $form->query($this->checkbox($permission) . '[@disabled]')->length, "{$permission}: disabled");
        }
        foreach (['role.view', 'role.update', 'project.view'] as $permission) {
            $this->assertSame(1, $form->query($this->checkbox($permission) . '[not(@disabled)]')->length, "{$permission}: enabled");
        }
        $this->assertSame(1, $form->query('//div[contains(@class, "role-permissions-hint")][normalize-space()="' . RoleForm::NOT_HELD_HINT . '"]')->length);
    }

    /**
     * A permission they ticked and may not grant comes back ticked but
     * disabled, with the refusal: sending the form again drops it.
     */
    public function testARefusedPermissionComesBackDisabled(): void
    {
        $this->loginAs($this->roleManager('rpg-refused'));
        $this->setPost(['RoleForm' => ['name' => 'rpg-refused-' . substr(md5(uniqid('', true)), 0, 6), 'permissions' => ['role.view', 'user.update']]]);

        $form = $this->xpath($this->page('actionCreate', null, 'create'));

        $this->assertSame(1, $form->query($this->checkbox('user.update') . '[@disabled][@checked]')->length);
        $this->assertSame(1, $form->query($this->checkbox('role.view') . '[not(@disabled)][@checked]')->length);
    }

    public function testASuperadminSeesEveryPermissionEnabledAndNoHint(): void
    {
        $superadmin = $this->createUser('rpg-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $this->loginAs($superadmin);

        $form = $this->xpath($this->page('actionUpdate', 'admin', 'update'));

        $this->assertGreaterThan(0, $form->query('//input[@name="RoleForm[permissions][]"]')->length);
        $this->assertSame(0, $form->query('//input[@name="RoleForm[permissions][]"][@disabled]')->length);
        $this->assertSame(0, $form->query('//div[contains(@class, "role-permissions-hint")]')->length);
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * The page $action renders, with the parameters it gave its view.
     */
    private function page(string $action, ?string $name, string $view): string
    {
        $ctrl = new class ('role', \Yii::$app) extends RoleController {
            use CapturesParams;
        };
        $this->assertSame('rendered:' . $view, $name === null ? $ctrl->$action() : $ctrl->$action($name));

        return $this->withRealViews(
            $ctrl,
            "/role/{$view}",
            fn (): string => \Yii::$app->view->renderFile("@app/views/role/{$view}.php", $ctrl->capturedParams, $ctrl)
        );
    }

    private function checkbox(string $permission): string
    {
        return '//input[@type="checkbox"][@name="RoleForm[permissions][]"][@value="' . $permission . '"]';
    }

    private function xpath(string $html): \DOMXPath
    {
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING));

        return new \DOMXPath($doc);
    }

    private function auth(): DbManager
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(DbManager::class, $auth);

        return $auth;
    }

    /**
     * @param list<string> $permissions
     */
    private function roleWith(string $name, array $permissions): Role
    {
        $auth = $this->auth();
        $role = $auth->createRole($name);
        $auth->add($role);
        foreach ($permissions as $permissionName) {
            $permission = $auth->getPermission($permissionName);
            $this->assertNotNull($permission, $permissionName);
            $auth->addChild($role, $permission);
        }

        return $role;
    }

    /**
     * A user who holds only a role manager role: role.view, role.create,
     * role.update and role.delete, and the default role viewer.
     */
    private function roleManager(string $suffix): User
    {
        $role = $this->auth()->getRole('rpg-role-manager')
            ?? $this->roleWith('rpg-role-manager', ['role.view', 'role.create', 'role.update', 'role.delete']);

        return $this->createUserWithRole($suffix, $role->name);
    }
}
