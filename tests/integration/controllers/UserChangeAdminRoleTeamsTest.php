<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\api\v1\UsersController;
use app\controllers\UserController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\TeamProject;
use app\models\User;
use app\services\ProjectAccessChecker;
use app\services\UserChangePolicy;
use app\tests\integration\TeamScopeFixtures;
use yii\rbac\DbManager;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * regression (wave 5g): a user who holds every permission of the admin role
 * through a role of their own, but not the admin role, and is in no team,
 * was refused an admin whose team has a team-restricted project with
 * UserChangePolicy::CHANGE_REFUSED ("they are a superadmin, or a role
 * assigned to them grants permissions you do not hold"), untrue for them,
 * on the Users pages and through the users API. The admin role is all they
 * lack, as it would lift the team rule too. The Edit User form, opened or
 * saved, the status toggle and Delete, and PUT and DELETE
 * /api/v1/users/{id} answer 403 UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED
 * where the user's teams refuse the change as well, as they do where the
 * teams do not (UserControllerChangeTest, UsersControllerChangeTest), and
 * nothing is written or audited. Each test fails on the policy from
 * before, which answered CHANGE_REFUSED here.
 *
 * Run with the default role viewer of the web application
 * (config/web.php). UserChangePolicyAdminRoleTeamsTest holds the policy's
 * answers for the whole class of the case.
 */
final class UserChangeAdminRoleTeamsTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /** The password a take-over would set; a test value, not a secret. */
    private const TAKEN_OVER_PASSWORD = 'TakenOverPass1!';

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
     * regression: opening and saving the Edit User form of such an admin,
     * their status toggle and Delete answered 403 CHANGE_REFUSED.
     */
    public function testRegressionThePagesNameTheAdminRoleWhereTheTeamsRefuseAsWell(): void
    {
        ['holder' => $holder, 'admin' => $admin] = $this->holderAndAdminInATeam('web_admin_teams');
        $this->loginAs($holder);
        $before = $this->stateOf($admin);

        $refusals = ['opening the form' => $this->refusalOf(fn () => $this->webController()->actionUpdate($admin->id))];
        $this->setPost(['UserForm' => [
            'username' => $admin->username,
            'email' => 'taken-over-' . uniqid('', true) . '@example.com',
            'password' => self::TAKEN_OVER_PASSWORD,
            'role' => 'admin',
            'status' => (string)$admin->status,
        ]]);
        $refusals['saving the form'] = $this->refusalOf(fn () => $this->webController()->actionUpdate($admin->id));
        $this->setPost([]);
        $refusals['the status toggle'] = $this->refusalOf(fn () => $this->webController()->actionToggleStatus($admin->id));
        $refusals['Delete'] = $this->refusalOf(fn () => $this->webController()->actionDelete($admin->id));

        $this->assertSame(array_fill_keys(array_keys($refusals), UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED), $refusals);
        $this->assertNothingIsWritten($admin, $before);
    }

    /**
     * regression: PUT {password, email}, PUT {status: 0} and DELETE of such
     * an admin answered 403 CHANGE_REFUSED.
     */
    public function testRegressionTheApiNamesTheAdminRoleWhereTheTeamsRefuseAsWell(): void
    {
        ['holder' => $holder, 'admin' => $admin] = $this->holderAndAdminInATeam('api-admin-teams');
        $this->authenticate($holder);
        $before = $this->stateOf($admin);
        $api = new UsersController('api/v1/users', $this->app());
        $bodies = [
            'PUT password and email' => ['password' => self::TAKEN_OVER_PASSWORD, 'email' => 'taken-over-' . uniqid('', true) . '@example.com'],
            'PUT status' => ['status' => User::STATUS_INACTIVE],
        ];

        $answers = [];
        foreach ($bodies as $what => $body) {
            $this->setBody($body);
            $answers[$what] = $this->answerOf(fn (): array => $api->actionUpdate($admin->id));
        }
        $this->setBody([]);
        $answers['DELETE'] = $this->answerOf(fn (): array => $api->actionDelete($admin->id));

        $refused = ['status' => 403, 'body' => ['error' => ['message' => UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED]]];
        $this->assertSame(array_fill_keys(array_keys($answers), $refused), $answers);
        $this->assertNothingIsWritten($admin, $before);
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * The holder, a user who holds a custom role with every permission of
     * the admin role, but not the admin role, and is in no team, and an
     * admin who is no superadmin and the only member of a team that views a
     * new project: a team-restricted project the holder cannot see, so the
     * team rule refuses the holder that admin as well.
     *
     * @return array{holder: User, admin: User}
     */
    private function holderAndAdminInATeam(string $label): array
    {
        $creator = $this->userWithRoles($label . '_creator', ['admin']);
        $project = $this->createProject((int)$creator->id);
        $team = $this->createTeam((int)$creator->id);
        $this->createTeamProject((int)$team->id, (int)$project->id, TeamProject::ROLE_VIEWER);
        $admin = $this->userWithRoles($label . '_target', ['admin']);
        $this->addTeamMember((int)$team->id, (int)$admin->id);
        $holder = $this->userWithRoles($label . '_holder', [$this->allOfAdminRole()]);
        $access = \Yii::$app->get('projectAccessChecker');
        $this->assertInstanceOf(ProjectAccessChecker::class, $access);
        $this->assertFalse($access->canView((int)$holder->id, (int)$project->id), 'precondition: the holder cannot see the project');
        $this->assertTrue($access->canView((int)$admin->id, (int)$project->id), 'precondition: the admin can');

        return ['holder' => $holder, 'admin' => $admin];
    }

    /**
     * Fails unless $admin is stored as $before, with the same roles, and no
     * update, status change or deletion of them is audited.
     *
     * @param array{row: array<string, mixed>|null, roles: list<string>} $before
     */
    private function assertNothingIsWritten(User $admin, array $before): void
    {
        $this->assertSame($before, $this->stateOf($admin), 'nothing is written');
        $stored = User::findOne($admin->id);
        $this->assertNotNull($stored, 'the admin is not deleted');
        $this->assertFalse($stored->validatePassword(self::TAKEN_OVER_PASSWORD), 'the password is not set');
        foreach ([AuditLog::ACTION_USER_UPDATED, AuditLog::ACTION_USER_STATUS_CHANGED, AuditLog::ACTION_USER_DELETED] as $action) {
            $this->assertNull(AuditLog::findOne(['action' => $action, 'object_type' => 'user', 'object_id' => $admin->id]), "no {$action}");
        }
    }

    /**
     * The message of the 403 $action answers; null when it answers none.
     */
    private function refusalOf(\Closure $action): ?string
    {
        try {
            $action();
        } catch (ForbiddenHttpException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * What $request answers: its status code and its body.
     *
     * @param \Closure(): array<string, mixed> $request
     * @return array{status: int, body: array<string, mixed>}
     */
    private function answerOf(\Closure $request): array
    {
        $body = $request();

        return ['status' => \Yii::$app->response->statusCode, 'body' => $body];
    }

    /**
     * A custom role holding every permission of the admin role, but not the
     * admin role itself.
     */
    private function allOfAdminRole(): string
    {
        $auth = $this->auth();
        $role = $auth->createRole('all-of-admin-' . substr(md5(uniqid('', true)), 0, 8));
        $auth->add($role);
        foreach ($auth->getPermissionsByRole('admin') as $permission) {
            $auth->addChild($role, $permission);
        }

        return $role->name;
    }

    /**
     * A user assigned $roles, read back as the session reads them.
     *
     * @param list<string> $roles
     */
    private function userWithRoles(string $suffix, array $roles): User
    {
        $user = $this->createUser($suffix);
        foreach ($roles as $role) {
            $this->assignRole($user->id, $role);
        }
        $user->refresh();

        return $user;
    }

    /**
     * Every column of the user as stored, and their roles: null when the
     * user is gone.
     *
     * @return array{row: array<string, mixed>|null, roles: list<string>}
     */
    private function stateOf(User $user): array
    {
        /** @var array<string, mixed>|null $row */
        $row = User::find()->where(['id' => $user->id])->asArray()->one();
        $roles = array_map('strval', array_keys($this->auth()->getAssignments((string)$user->id)));
        sort($roles);

        return ['row' => $row, 'roles' => $roles];
    }

    /**
     * Signs $user in with an API token of their own.
     */
    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'test');
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
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
    }

    private function auth(): DbManager
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(DbManager::class, $auth);

        return $auth;
    }

    /**
     * The web controller of the Users pages, which renders and redirects
     * nowhere.
     */
    private function webController(): UserController
    {
        return new class ('user', $this->app()) extends UserController {
            public function render($view, $params = []): string
            {
                return 'rendered:' . $view;
            }

            /**
             * @param array<int|string, mixed>|string $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                $response = new Response();
                $response->content = 'redirected';
                return $response;
            }
        };
    }

    private function app(): \yii\base\Module
    {
        $app = \Yii::$app;
        $this->assertNotNull($app);

        return $app;
    }
}
