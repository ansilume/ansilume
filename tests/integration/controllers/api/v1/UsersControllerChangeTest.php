<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\UsersController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\TeamMember;
use app\models\TeamProject;
use app\models\User;
use app\services\RoleGrantPolicy;
use app\services\UserChangePolicy;
use app\tests\integration\controllers\WebControllerTestCase;
use yii\rbac\DbManager;
use yii\web\NotFoundHttpException;

/**
 * Whom a caller may change or delete through the users API
 * (UserChangePolicy): a superadmin everyone; anyone else a user who is no
 * superadmin and all of whose assigned roles they may assign. PUT and DELETE
 * /api/v1/users/{id} answer 403 (UserChangePolicy::CHANGE_REFUSED)
 * otherwise, after the 403 of a caller without the permission and the 404 of
 * a user that does not exist, before every other check; nothing is written
 * or audited.
 *
 * Up to 2.10.1 whoever held user.update set the password and the email of
 * any user, a superadmin included, and could then sign in as them: a help
 * desk {user.view, user.update} that sent PUT {password, email} to a
 * superadmin with the admin role got 200. The help desk of these tests holds
 * such a custom role, with user.delete where a test says so. Neither a
 * superadmin nor an admin, it changes a team member only when its own teams
 * give it every project the member's teams give the member, with at least
 * the same role (403 UserChangePolicy::TEAM_ACCESS_REFUSED otherwise). Run
 * with the default role viewer of the web application (config/web.php).
 */
final class UsersControllerChangeTest extends WebControllerTestCase
{
    /** The help desk's permissions: managing accounts, but neither deactivating nor deleting them. */
    private const HELP_DESK = ['user.view', 'user.update'];

    /** A help desk that may also delete accounts. */
    private const HELP_DESK_WITH_DELETE = ['user.view', 'user.update', 'user.delete'];

    /** The password a take-over would set; a test value, not a secret. */
    private const TAKEN_OVER_PASSWORD = 'TakenOverPass1!';

    private UsersController $ctrl;

    /** @var list<string> the default roles of the manager before the test */
    private array $savedDefaultRoles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new UsersController('api/v1/users', \Yii::$app);
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
     * @return array<string, array{0: bool}>
     */
    public static function privilegedUserProvider(): array
    {
        return ['a superadmin with the admin role' => [true], 'an admin who is no superadmin' => [false]];
    }

    /**
     * regression: the help desk sent PUT {password, email} to a superadmin
     * or an admin and got 200: the new password signed it in as them, and a
     * password reset went to its own address. 403 now; nothing is written.
     *
     * @dataProvider privilegedUserProvider
     */
    public function testRegressionAHelpDeskCannotSetThePasswordOrTheEmailOfAPrivilegedUser(bool $superadmin): void
    {
        $this->authenticate($this->helpDesk('api-change-help-desk', self::HELP_DESK));
        $target = $this->privilegedUser($superadmin);
        $before = $this->stateOf($target);

        $this->setBody(['password' => self::TAKEN_OVER_PASSWORD, 'email' => 'taken-over-' . uniqid('', true) . '@example.com']);
        $this->assertRefused($this->ctrl->actionUpdate($target->id));

        $this->assertSame($before, $this->stateOf($target), 'nothing is written');
        $this->assertFalse($this->stored($target)->validatePassword(self::TAKEN_OVER_PASSWORD));
        $this->assertNull($this->audit(AuditLog::ACTION_USER_UPDATED, $target->id));
    }

    /**
     * regression: the help desk deactivated a superadmin or an admin with
     * PUT {status: 0}.
     *
     * @dataProvider privilegedUserProvider
     */
    public function testRegressionAHelpDeskCannotDeactivateAPrivilegedUser(bool $superadmin): void
    {
        $this->authenticate($this->helpDesk('api-status-help-desk', self::HELP_DESK));
        $target = $this->privilegedUser($superadmin);
        $before = $this->stateOf($target);

        $this->setBody(['status' => User::STATUS_INACTIVE]);
        $this->assertRefused($this->ctrl->actionUpdate($target->id));

        $this->assertSame($before, $this->stateOf($target), 'still active');
        $this->assertNull($this->audit(AuditLog::ACTION_USER_UPDATED, $target->id));
    }

    /**
     * regression: a help desk that may delete accounts deleted a superadmin
     * (while another remained) or an admin.
     *
     * @dataProvider privilegedUserProvider
     */
    public function testRegressionAHelpDeskCannotDeleteAPrivilegedUser(bool $superadmin): void
    {
        $this->authenticate($this->helpDesk('api-delete-help-desk', self::HELP_DESK_WITH_DELETE));
        $target = $this->privilegedUser($superadmin);
        $before = $this->stateOf($target);

        $this->assertRefused($this->ctrl->actionDelete($target->id));

        $this->assertSame($before, $this->stateOf($target), 'not deleted, roles kept');
        $this->assertNull($this->audit(AuditLog::ACTION_USER_DELETED, $target->id));
    }

    /**
     * regression: an admin who is no superadmin holds every permission but
     * not the flag, and set a superadmin's password and email, or deleted
     * them.
     */
    public function testRegressionAnAdminWhoIsNoSuperadminCannotChangeOrDeleteASuperadmin(): void
    {
        $this->authenticate($this->userWithRoles('api-change-admin', ['admin']));
        $target = $this->privilegedUser(true);
        $before = $this->stateOf($target);

        $this->setBody(['password' => self::TAKEN_OVER_PASSWORD, 'email' => 'taken-over-' . uniqid('', true) . '@example.com']);
        $this->assertRefused($this->ctrl->actionUpdate($target->id));
        $this->assertRefused($this->ctrl->actionDelete($target->id));

        $this->assertSame($before, $this->stateOf($target), 'nothing is written');
        $this->assertNull($this->audit(AuditLog::ACTION_USER_UPDATED, $target->id));
        $this->assertNull($this->audit(AuditLog::ACTION_USER_DELETED, $target->id));
    }

    /**
     * The help desk may still change a viewer and a user without a role: the
     * password, the email and the status, and delete them.
     */
    public function testAHelpDeskMayStillChangeAViewerOrAUserWithoutARole(): void
    {
        $this->authenticate($this->helpDesk('api-viewer-help-desk', self::HELP_DESK_WITH_DELETE));
        $targets = ['a viewer' => $this->userWithRoles('api-change-viewer', ['viewer']), 'a user without a role' => $this->createUser('api-change-none')];

        foreach ($targets as $kind => $target) {
            $email = 'changed-' . uniqid('', true) . '@example.com';
            $this->setBody(['password' => self::TAKEN_OVER_PASSWORD, 'email' => $email, 'status' => User::STATUS_INACTIVE]);
            $result = $this->ctrl->actionUpdate($target->id);

            $this->assertSame(200, \Yii::$app->response->statusCode, $kind);
            $this->assertArrayHasKey('data', $result, $kind);
            $stored = $this->stored($target);
            $this->assertSame($email, $stored->email, $kind);
            $this->assertSame(User::STATUS_INACTIVE, $stored->status, $kind);
            $this->assertTrue($stored->validatePassword(self::TAKEN_OVER_PASSWORD), $kind);
            $this->assertNotNull($this->audit(AuditLog::ACTION_USER_UPDATED, $target->id), $kind);

            $this->assertSame(['data' => ['deleted' => true]], $this->ctrl->actionDelete($target->id), $kind);
            $this->assertNull(User::findOne($target->id), $kind);
        }
    }

    /**
     * A superadmin may change everyone: another superadmin's password and
     * email, and delete an admin.
     */
    public function testASuperadminMayChangeEveryone(): void
    {
        $actor = $this->userWithRoles('api-change-superadmin', ['admin']);
        $actor->is_superadmin = true;
        $actor->save(false);
        $this->authenticate($actor);
        $other = $this->privilegedUser(true);
        $email = 'changed-' . uniqid('', true) . '@example.com';

        $this->setBody(['password' => self::TAKEN_OVER_PASSWORD, 'email' => $email]);
        $result = $this->ctrl->actionUpdate($other->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertArrayHasKey('data', $result);
        $this->assertSame($email, $this->stored($other)->email);
        $this->assertTrue($this->stored($other)->validatePassword(self::TAKEN_OVER_PASSWORD));

        $admin = $this->privilegedUser(false);
        $this->assertSame(['data' => ['deleted' => true]], $this->ctrl->actionDelete($admin->id));
        $this->assertNull(User::findOne($admin->id));
    }

    /**
     * The 403 of a caller without the permission comes first, then the 404
     * of a user that does not exist, then the refusal.
     */
    public function testTheRefusalComesAfterThePermissionAndTheNotFound(): void
    {
        $target = $this->privilegedUser(true);
        $this->authenticate($this->helpDesk('api-order-nobody', ['user.view']));
        $this->setBody(['email' => 'nobody-' . uniqid('', true) . '@example.com']);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $this->ctrl->actionUpdate($target->id));
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $this->ctrl->actionDelete($target->id));
        $this->assertSame(403, \Yii::$app->response->statusCode);

        $this->authenticate($this->helpDesk('api-order-help-desk', self::HELP_DESK_WITH_DELETE));
        $missing = (int)User::find()->max('id') + 1000;
        foreach (['PUT' => fn () => $this->ctrl->actionUpdate($missing), 'DELETE' => fn () => $this->ctrl->actionDelete($missing)] as $method => $call) {
            try {
                $call();
                $this->fail("{$method}: expected NotFoundHttpException");
            } catch (NotFoundHttpException $e) {
                $this->assertSame("User #{$missing} not found.", $e->getMessage(), $method);
            }
        }
    }

    /**
     * The refusal comes before every other check, whose answers the body
     * would otherwise get: a change of auth_source and an unknown role
     * (422), a role the caller may not assign and a change of the flag
     * (403), and, for DELETE, the only superadmin (422).
     */
    public function testTheRefusalComesBeforeTheOtherChecks(): void
    {
        $this->authenticate($this->helpDesk('api-order-before', self::HELP_DESK_WITH_DELETE));
        $target = $this->privilegedUser(false);
        $before = $this->stateOf($target);
        $bodies = [
            'auth_source' => ['auth_source' => User::AUTH_SOURCE_LDAP],
            'unknown role' => ['role' => 'superuser'],
            'role' => ['role' => 'viewer'],
            'is_superadmin' => ['is_superadmin' => true],
        ];

        foreach ($bodies as $check => $body) {
            $this->setBody($body);
            $this->assertRefused($this->ctrl->actionUpdate($target->id), $check);
        }
        $this->assertSame($before, $this->stateOf($target));

        $this->authenticate($this->userWithRoles('api-order-admin', ['admin']));
        $only = $this->userWithRoles('api-order-only-superadmin', ['admin']);
        User::updateAll(['is_superadmin' => 0], ['is_superadmin' => 1]);
        $only->is_superadmin = true;
        $only->save(false);
        $this->assertRefused($this->ctrl->actionDelete($only->id), 'the only superadmin');
        $this->assertNotNull(User::findOne($only->id));
    }

    /**
     * A caller may change their own account, whose roles they hold: the
     * other checks answer as before.
     */
    public function testOnesOwnAccountIsLeftToTheOtherChecks(): void
    {
        $helpDesk = $this->helpDesk('api-self-help-desk', self::HELP_DESK_WITH_DELETE);
        $this->authenticate($helpDesk);

        $this->setBody(['is_superadmin' => true]);
        $this->assertSame(['error' => ['message' => RoleGrantPolicy::SUPERADMIN_REFUSED]], $this->ctrl->actionUpdate($helpDesk->id));
        $this->assertSame(['error' => ['message' => 'Cannot delete your own account.']], $this->ctrl->actionDelete($helpDesk->id));
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    // -- Team access ----------------------------------------------------------

    /**
     * regression: a help desk in no team sent PUT {password, email} to a
     * viewer whose team has a restricted project and got 200: it could sign
     * in as them and read what only the team may; PUT {status: 0} and DELETE
     * deactivated or deleted them. 403 TEAM_ACCESS_REFUSED now; nothing is
     * written or audited.
     */
    public function testRegressionAHelpDeskInNoTeamCannotChangeOrDeleteATeamMember(): void
    {
        $this->authenticate($this->helpDesk('api-team-help-desk', self::HELP_DESK_WITH_DELETE));
        $member = $this->teamMember('api-team-member', TeamProject::ROLE_VIEWER);
        $before = $this->stateOf($member);
        $bodies = [
            'password and email' => ['password' => self::TAKEN_OVER_PASSWORD, 'email' => 'taken-over-' . uniqid('', true) . '@example.com'],
            'status' => ['status' => User::STATUS_INACTIVE],
        ];

        $answers = [];
        foreach ($bodies as $what => $body) {
            $this->setBody($body);
            $answers['PUT ' . $what] = $this->answerOf(fn (): array => $this->ctrl->actionUpdate($member->id));
        }
        $answers['DELETE'] = $this->answerOf(fn (): array => $this->ctrl->actionDelete($member->id));

        $this->assertSame(array_fill_keys(array_keys($answers), 403), array_map(static fn (array $answer): int => $answer['status'], $answers), 'each answers 403');
        $this->assertSame(
            array_fill_keys(array_keys($answers), ['error' => ['message' => UserChangePolicy::TEAM_ACCESS_REFUSED]]),
            array_map(static fn (array $answer): array => $answer['body'], $answers)
        );
        $this->assertSame($before, $this->stateOf($member), 'nothing is written');
        $this->assertFalse($this->stored($member)->validatePassword(self::TAKEN_OVER_PASSWORD));
        $this->assertNull($this->audit(AuditLog::ACTION_USER_UPDATED, $member->id));
        $this->assertNull($this->audit(AuditLog::ACTION_USER_DELETED, $member->id));
    }

    /**
     * A help desk in the member's team has what the team gives the member,
     * and an admin in no team every project: both may change the member.
     */
    public function testAHelpDeskInTheMembersTeamAndAnAdminMayChangeThem(): void
    {
        $helpDesk = $this->helpDesk('api-team-mate-help-desk', self::HELP_DESK);
        $member = $this->teamMember('api-team-mate-member', TeamProject::ROLE_OPERATOR);
        $teamId = (int)TeamMember::find()->select('team_id')->where(['user_id' => $member->id])->scalar();
        $this->addTeamMember($teamId, (int)$helpDesk->id);

        foreach (['a help desk in the team' => $helpDesk, 'an admin in no team' => $this->userWithRoles('api-team-admin', ['admin'])] as $who => $actor) {
            $this->authenticate($actor);
            $email = 'changed-' . uniqid('', true) . '@example.com';
            $this->setBody(['email' => $email]);
            $result = $this->ctrl->actionUpdate($member->id);

            $this->assertSame(200, \Yii::$app->response->statusCode, $who);
            $this->assertArrayHasKey('data', $result, $who);
            $this->assertSame($email, $this->stored($member)->email, $who);
        }
    }

    /**
     * regression: a user who held every permission of the admin role
     * through a custom role, without the admin role, gave the admin role,
     * which grants every team-restricted project, with PUT and POST, and
     * changed or deleted admins. 403 RoleGrantPolicy::ADMIN_ROLE_REFUSED for
     * the role, UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED for an admin
     * (regression of wave 5f: CHANGE_REFUSED said they lacked permissions
     * they hold); nothing is written.
     */
    public function testRegressionEveryPermissionOfTheAdminRoleWithoutTheRoleNeitherGivesNorChangesIt(): void
    {
        $this->authenticate($this->userWithRoles('api-all-of-admin', [$this->allOfAdminRole()]));
        $viewer = $this->userWithRoles('api-all-of-admin-viewer', ['viewer']);
        $admin = $this->userWithRoles('api-all-of-admin-target', ['admin']);
        $before = [$this->stateOf($viewer), $this->stateOf($admin)];

        $this->setBody(['role' => 'admin']);
        $answers = ['PUT a viewer the admin role' => $this->answerOf(fn (): array => $this->ctrl->actionUpdate($viewer->id))];
        $username = 'api-all-of-admin-new-' . uniqid();
        $this->setBody(['username' => $username, 'email' => $username . '@example.com', 'password' => 'securepassword', 'role' => 'admin']);
        $answers['POST a user with the admin role'] = $this->answerOf(fn (): array => $this->ctrl->actionCreate());
        $this->setBody(['email' => 'taken-over-' . uniqid('', true) . '@example.com']);
        $answers['PUT an admin'] = $this->answerOf(fn (): array => $this->ctrl->actionUpdate($admin->id));
        $answers['DELETE an admin'] = $this->answerOf(fn (): array => $this->ctrl->actionDelete($admin->id));

        $this->assertSame(array_fill_keys(array_keys($answers), 403), array_map(static fn (array $answer): int => $answer['status'], $answers), 'each answers 403');
        $adminRefused = ['error' => ['message' => sprintf(RoleGrantPolicy::ADMIN_ROLE_REFUSED, 'admin')]];
        $changeRefused = ['error' => ['message' => UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED]];
        $this->assertSame(
            [
                'PUT a viewer the admin role' => $adminRefused,
                'POST a user with the admin role' => $adminRefused,
                'PUT an admin' => $changeRefused,
                'DELETE an admin' => $changeRefused,
            ],
            array_map(static fn (array $answer): array => $answer['body'], $answers)
        );
        $this->assertSame($before, [$this->stateOf($viewer), $this->stateOf($admin)], 'nothing is written');
        $this->assertNull(User::findOne(['username' => $username]), 'nobody is created');
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * Fails unless $result is the 403 of UserChangePolicy::CHANGE_REFUSED.
     *
     * @param array<string, mixed> $result
     */
    private function assertRefused(array $result, string $what = ''): void
    {
        $this->assertSame(['error' => ['message' => UserChangePolicy::CHANGE_REFUSED]], $result, $what);
        $this->assertSame(403, \Yii::$app->response->statusCode, $what);
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
     * A viewer who is the only member of a new team that has a new project
     * with $teamRole: a restricted project.
     */
    private function teamMember(string $label, string $teamRole): User
    {
        $admin = $this->userWithRoles($label . '-creator', ['admin']);
        $project = $this->createProject((int)$admin->id);
        $team = $this->createTeam((int)$admin->id);
        $this->createTeamProject((int)$team->id, (int)$project->id, $teamRole);
        $member = $this->userWithRoles($label, ['viewer']);
        $this->addTeamMember((int)$team->id, (int)$member->id);

        return $member;
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
     * A superadmin with the admin role, with another superadmin besides, or
     * an admin who is no superadmin.
     */
    private function privilegedUser(bool $superadmin): User
    {
        $user = $this->userWithRoles('api-change-privileged', ['admin']);
        if ($superadmin) {
            $user->is_superadmin = true;
            $user->save(false);
            $other = $this->createUser('api-change-other');
            $other->is_superadmin = true;
            $other->save(false);
        }

        return $user;
    }

    /**
     * A user holding a custom help desk role with $permissions.
     *
     * @param list<string> $permissions
     */
    private function helpDesk(string $label, array $permissions): User
    {
        $auth = $this->auth();
        $role = $auth->createRole('help-desk-' . substr(md5(uniqid('', true)), 0, 8));
        $auth->add($role);
        foreach ($permissions as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }

        return $this->userWithRoles($label, [$role->name]);
    }

    /**
     * @param list<string> $roles
     */
    private function userWithRoles(string $label, array $roles): User
    {
        $user = $this->createUser($label);
        foreach ($roles as $roleName) {
            $role = $this->auth()->getRole($roleName);
            $this->assertNotNull($role, $roleName);
            $this->auth()->assign($role, (string)$user->id);
        }

        return $user;
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

    private function stored(User $user): User
    {
        $stored = User::findOne($user->id);
        $this->assertNotNull($stored);

        return $stored;
    }

    private function audit(string $action, int $userId): ?AuditLog
    {
        return AuditLog::findOne(['action' => $action, 'object_type' => 'user', 'object_id' => $userId]);
    }

    private function auth(): DbManager
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(DbManager::class, $auth);

        return $auth;
    }
}
