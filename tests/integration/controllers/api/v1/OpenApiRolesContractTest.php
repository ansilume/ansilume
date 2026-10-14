<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\RolesController;
use app\models\ApiToken;
use app\models\User;
use app\services\RoleChangePolicy;
use app\tests\integration\controllers\WebControllerTestCase;
use yii\rbac\DbManager;
use yii\web\Request;

/**
 * What web/openapi.yaml says about who may create, change and delete roles
 * through POST /api/v1/roles and PUT and DELETE /api/v1/roles/{name} is
 * what RolesController answers (RoleChangePolicy): the 403 of POST is
 * RoleCreateForbidden, that of PUT RoleUpdateForbidden and that of DELETE
 * RoleDeleteForbidden, whose examples are the answers of a caller without
 * the permission, of a role manager who lists a permission they do not hold
 * (RoleChangePolicy::PERMISSION_REFUSED) and of one who changes a role they
 * may not assign (RoleChangePolicy::CHANGE_REFUSED); the operations name
 * the answers in the controller's order (RolesControllerChangeRuleTest pins
 * the order). Run with the default role viewer of the web application.
 *
 * Regression (wave 5d): the spec documented the generic 403 only, and the
 * API let anyone with role.create, role.update or role.delete give any role
 * any permission, their own and viewer included, and delete any custom
 * role. Fails on the spec from before.
 */
class OpenApiRolesContractTest extends WebControllerTestCase
{
    use OpenApiSchemaAssertions;
    use OpenApiWorkflowAssertions;

    private const ROLES = '/api/v1/roles';

    private const ROLE = '/api/v1/roles/{name}';

    /** @var list<string> the default roles before the test */
    private array $savedDefaultRoles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedDefaultRoles = array_map('strval', $this->auth()->defaultRoles);
        $this->auth()->defaultRoles = ['viewer'];
    }

    protected function tearDown(): void
    {
        $this->auth()->defaultRoles = $this->savedDefaultRoles;
        parent::tearDown();
    }

    /**
     * regression: POST, PUT and DELETE documented the generic 403 only.
     * Their 403s are named responses whose examples the API answers to a
     * caller without the permission and to a role manager.
     */
    public function testRegressionTheRoleRefusalsAreDocumentedAsTheApiAnswersThem(): void
    {
        $grant = sprintf(RoleChangePolicy::PERMISSION_REFUSED, 'user.update');
        $this->assertSame('#/components/responses/RoleCreateForbidden', $this->specAt(['paths', self::ROLES, 'post', 'responses', '403', '$ref']));
        $this->assertSame('#/components/responses/RoleUpdateForbidden', $this->specAt(['paths', self::ROLE, 'put', 'responses', '403', '$ref']));
        $this->assertSame('#/components/responses/RoleDeleteForbidden', $this->specAt(['paths', self::ROLE, 'delete', 'responses', '403', '$ref']));
        $this->assertSame(['Forbidden.', $grant], $this->exampleMessages(self::ROLES, 'post', '403'));
        $this->assertSame(['Forbidden.', RoleChangePolicy::CHANGE_REFUSED, $grant], $this->exampleMessages(self::ROLE, 'put', '403'));
        $this->assertSame(['Forbidden.', RoleChangePolicy::CHANGE_REFUSED], $this->exampleMessages(self::ROLE, 'delete', '403'));

        $own = $this->roleWith(['role.view', 'role.create', 'role.update', 'role.delete']);
        $manager = $this->userWith($own);
        $this->authenticate($manager);
        $name = 'spec-roles-' . substr(md5(uniqid('', true)), 0, 8);
        $this->setBody(['name' => $name, 'permissions' => ['role.view', 'user.update']]);
        $this->assertError(403, $grant, $this->controller()->actionCreate(), 'POST with a permission the caller does not hold');
        $this->assertNull($this->auth()->getRole($name), 'Nothing is created.');

        $this->setBody(['permissions' => ['role.view', 'role.create', 'role.update', 'role.delete', 'user.update']]);
        $this->assertError(403, $grant, $this->controller()->actionUpdate($own), 'PUT that adds a permission the caller does not hold');
        $this->assertArrayNotHasKey('user.update', $this->auth()->getPermissionsByRole($own), 'Nothing is written.');

        $this->setBody(['permissions' => ['job.view']]);
        $this->assertError(403, RoleChangePolicy::CHANGE_REFUSED, $this->controller()->actionUpdate('admin'), 'PUT of a role the caller may not assign');
        $this->assertError(403, RoleChangePolicy::CHANGE_REFUSED, $this->controller()->actionDelete('admin'), 'DELETE of a role the caller may not assign');

        $this->authenticate($this->userWith($this->roleWith(['role.view'])));
        $this->assertError(403, 'Forbidden.', $this->controller()->actionCreate(), 'POST without role.create');
        $this->assertError(403, 'Forbidden.', $this->controller()->actionUpdate($own), 'PUT without role.update');
        $this->assertError(403, 'Forbidden.', $this->controller()->actionDelete($own), 'DELETE without role.delete');
    }

    /**
     * The operations say who may create, change and delete a role, and in
     * which order the answers come; each 403 says what it refuses.
     */
    public function testTheOperationsSayWhoMayChangeARole(): void
    {
        $grant = sprintf(RoleChangePolicy::PERMISSION_REFUSED, 'user.update');
        $post = $this->specText(['paths', self::ROLES, 'post', 'description']);
        $this->assertStringContainsString(
            'Requires `role.create`. A caller who is no superadmin may list only permissions they hold, through their roles or the default role viewer; a permission they do not hold answers 403 `'
                . $grant . '`, naming the first such permission of the request, and nothing is written. A permission listed twice is granted once.',
            $post
        );
        $this->assertStringContainsString(
            'The answers come in this order: 403 `Forbidden.` without `role.create`; 422 for a body that does not validate (the name, an unknown permission); 403 for a permission the caller does not hold.',
            $post
        );
        $put = $this->specText(['paths', self::ROLE, 'put', 'description']);
        $this->assertStringContainsString(
            'Requires `role.update`. A caller who is no superadmin changes only a role they may assign (they hold every permission it grants, and for the admin role, or a role that contains it, they hold the admin role), and adds to it only permissions they hold; removing permissions needs no more. A role they may not change answers 403 `'
                . RoleChangePolicy::CHANGE_REFUSED . '`; an added permission they do not hold answers 403 `' . $grant . '`. Nothing is written.',
            $put
        );
        $this->assertStringContainsString(
            'The answers come in this order: 403 `Forbidden.` without `role.update`; 404 for an unknown role; 403 for a role the caller may not change; 422 for a body that does not validate; 403 for an added permission the caller does not hold.',
            $put
        );
        $delete = $this->specText(['paths', self::ROLE, 'delete', 'description']);
        $this->assertStringContainsString(
            'Requires `role.delete`. A caller who is no superadmin deletes only a role they may assign; anyone else gets 403 `' . RoleChangePolicy::CHANGE_REFUSED . '` and nothing is deleted.',
            $delete
        );
        $this->assertStringContainsString(
            'The answers come in this order: 403 `Forbidden.` without `role.delete`; 404 for an unknown role; 403 for a role the caller may not change; 422 `Cannot delete system role.`; 422 `Role could not be deleted.`.',
            $delete
        );
        $this->assertSame(
            'The caller lacks `role.create`, or lists a permission they do not hold. Nothing was written.',
            $this->specText(['components', 'responses', 'RoleCreateForbidden', 'description'])
        );
        $this->assertSame(
            'The caller lacks `role.update`, may not change the role (it grants more than they hold), or adds a permission they do not hold. Nothing was written.',
            $this->specText(['components', 'responses', 'RoleUpdateForbidden', 'description'])
        );
        $this->assertSame(
            'The caller lacks `role.delete`, or may not change the role. Nothing was deleted.',
            $this->specText(['components', 'responses', 'RoleDeleteForbidden', 'description'])
        );
    }

    private function controller(): RolesController
    {
        \Yii::$app->response->statusCode = 200;

        return new RolesController('api/v1/roles', $this->app());
    }

    /**
     * @param array<string, mixed> $result
     */
    private function assertError(int $status, string $message, array $result, string $what): void
    {
        $this->assertSame(['error' => ['message' => $message]], $result, $what);
        $this->assertSame($status, \Yii::$app->response->statusCode, $what);
    }

    /**
     * Signs $user in with an API token of their own.
     */
    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'spec');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->setBodyParams($body);
    }

    /**
     * A user whose only role is $role.
     */
    private function userWith(string $role): User
    {
        $user = $this->createUser('spec-roles');
        $item = $this->auth()->getRole($role);
        $this->assertNotNull($item, $role);
        $this->auth()->assign($item, (string)$user->id);

        return $user;
    }

    /**
     * A custom role holding $permissions only, as an admin builds one in
     * the role editor; its name.
     *
     * @param list<string> $permissions
     */
    private function roleWith(array $permissions): string
    {
        $role = $this->auth()->createRole('spec-roles-' . substr(md5(uniqid('', true)), 0, 8));
        $this->auth()->add($role);
        foreach ($permissions as $name) {
            $permission = $this->auth()->getPermission($name);
            $this->assertNotNull($permission, $name);
            $this->auth()->addChild($role, $permission);
        }

        return $role->name;
    }

    private function auth(): DbManager
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(DbManager::class, $auth);

        return $auth;
    }
}
