<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\UsersController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\User;
use app\services\RoleGrantPolicy;
use app\services\UserDeletionService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\CountsQueries;
use yii\rbac\DbManager;

/**
 * Integration tests for the Users API controller.
 *
 * Exercises authentication, authorization, CRUD operations, password
 * handling, sensitive field redaction, and delete guards against a real
 * database with transactions rolled back after each test. Run with the
 * default role viewer of the web application (config/web.php), which every
 * user holds and DbManager::getRolesByUser() lists first: a user's role is
 * the role assigned to them, never the default role.
 *
 * A "role" in the body names one of the instance's roles, or the request
 * answers 422 (UsersController::INVALID_ROLE) and writes nothing; the
 * OpenAPI spec quotes that answer. A role the user already has keeps every
 * role assigned to them; another replaces them all.
 *
 * What the caller may give (RoleGrantPolicy): a role only when they hold
 * every permission it grants, a change of is_superadmin only as a
 * superadmin; else 403, and nothing is written. The help desk of these tests
 * holds a custom role with user.view and user.update, as an admin can build
 * in the role editor; authenticateWithAdmin() signs in an admin, who holds
 * every permission but is no superadmin.
 */
class UsersControllerTest extends WebControllerTestCase
{
    use CountsQueries;
    use OpenApiSchemaAssertions;

    /** A page number beyond the last page: the list answers its last page. */
    private const LAST_PAGE = '1000000';

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

    // -- Authorization (403) --------------------------------------------------

    public function testIndexRejects403WithoutPermission(): void
    {
        $this->authenticateAs('no-perm');
        $this->ctrl->actionIndex();
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    // -- Index ----------------------------------------------------------------

    public function testIndexReturnsPaginatedList(): void
    {
        $this->authenticateWithAdmin();

        $result = $this->ctrl->actionIndex();
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('meta', $result);

        /** @var array{total: int, page: int, per_page: int, pages: int} $meta */
        $meta = $result['meta'];
        $this->assertGreaterThanOrEqual(1, $meta['total']);
        $this->assertSame(25, $meta['per_page']);

        /** @var array<int, array<string, mixed>> $data */
        $data = $result['data'];
        $first = $data[0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('username', $first);
        $this->assertArrayHasKey('email', $first);
        $this->assertArrayHasKey('status', $first);
    }

    // -- View -----------------------------------------------------------------

    public function testViewReturnsUser(): void
    {
        $admin = $this->authenticateWithAdmin();

        $data = $this->callSuccess($this->ctrl->actionView((int)$admin->id));
        $this->assertSame(200, \Yii::$app->response->statusCode);

        /** @var array<string, mixed> $user */
        $user = $data;
        $this->assertSame((int)$admin->id, $user['id']);
        $this->assertArrayHasKey('username', $user);
        $this->assertArrayHasKey('email', $user);
        $this->assertArrayHasKey('role', $user);
    }

    public function testViewReturns404(): void
    {
        $this->authenticateWithAdmin();
        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionView(999999);
    }

    public function testViewNeverExposesPasswordHash(): void
    {
        $admin = $this->authenticateWithAdmin();

        $data = $this->callSuccess($this->ctrl->actionView((int)$admin->id));

        /** @var array<string, mixed> $user */
        $user = $data;
        $this->assertArrayNotHasKey('password_hash', $user);
        $this->assertArrayNotHasKey('auth_key', $user);
        $this->assertArrayNotHasKey('totp_secret', $user);
        $this->assertArrayNotHasKey('recovery_codes', $user);
    }

    // -- Create ---------------------------------------------------------------

    public function testCreateWithValidData(): void
    {
        $this->authenticateWithAdmin();
        $this->setBody([
            'username' => 'api-new-user',
            'email' => 'api-new@example.com',
            'password' => 'securepassword',
            'status' => User::STATUS_ACTIVE,
            'role' => 'viewer',
        ]);

        $data = $this->callSuccess($this->ctrl->actionCreate());
        $this->assertSame(201, \Yii::$app->response->statusCode);

        /** @var array<string, mixed> $user */
        $user = $data;
        $this->assertSame('api-new-user', $user['username']);
        $this->assertSame('api-new@example.com', $user['email']);
        $this->assertSame('viewer', $user['role']);
    }

    public function testCreateRejects422WithoutPassword(): void
    {
        $this->authenticateWithAdmin();
        $this->setBody([
            'username' => 'no-pass-user',
            'email' => 'nopass@example.com',
        ]);

        $this->ctrl->actionCreate();
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    public function testCreateRejects422WithShortPassword(): void
    {
        $this->authenticateWithAdmin();
        $this->setBody([
            'username' => 'short-pass-user',
            'email' => 'shortpass@example.com',
            'password' => 'ab',
        ]);

        $this->ctrl->actionCreate();
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    // -- Update ---------------------------------------------------------------

    public function testUpdateWithValidData(): void
    {
        $admin = $this->authenticateWithAdmin();
        $target = $this->createUser('update-target');

        $this->setBody([
            'username' => 'updated-username',
            'email' => 'updated@example.com',
        ]);
        $data = $this->callSuccess($this->ctrl->actionUpdate((int)$target->id));
        $this->assertSame(200, \Yii::$app->response->statusCode);

        /** @var array<string, mixed> $user */
        $user = $data;
        $this->assertSame('updated-username', $user['username']);
        $this->assertSame('updated@example.com', $user['email']);
    }

    public function testUpdateWithPasswordChange(): void
    {
        $admin = $this->authenticateWithAdmin();
        $target = $this->createUser('pw-change');

        $oldHash = $target->password_hash;

        $this->setBody([
            'password' => 'newpassword123',
        ]);
        $this->ctrl->actionUpdate((int)$target->id);
        $this->assertSame(200, \Yii::$app->response->statusCode);

        $target->refresh();
        $this->assertNotSame($oldHash, $target->password_hash);
    }

    public function testUpdateRejects403WithoutPermission(): void
    {
        $target = $this->createUser('update-forbidden');
        $email = $target->email;
        $this->authenticateAs('no-update-perm');
        $this->setBody(['email' => 'forbidden-' . uniqid('', true) . '@example.com']);

        $result = $this->ctrl->actionUpdate((int)$target->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $result);
        $target->refresh();
        $this->assertSame($email, $target->email);
    }

    public function testAnUpdateWithoutARoleKeepsTheRolesAndOneWithARoleReplacesThem(): void
    {
        $this->authenticateWithAdmin();
        $target = $this->createUser('role-update');
        $this->assignRole($target, 'operator');

        $this->setBody(['email' => 'role-update-' . uniqid('', true) . '@example.com']);
        $this->callSuccess($this->ctrl->actionUpdate((int)$target->id));
        $this->assertSame(['operator'], $this->roleNames((int)$target->id));

        $this->setBody(['role' => 'viewer']);
        /** @var array<string, mixed> $user */
        $user = $this->callSuccess($this->ctrl->actionUpdate((int)$target->id));
        $this->assertSame('viewer', $user['role']);
        $this->assertSame(['viewer'], $this->roleNames((int)$target->id));
    }

    // -- Role: assigned, never the default role -------------------------------

    /**
     * regression: GET /api/v1/users/{id} answered role viewer for every user,
     * the default role that DbManager::getRolesByUser() lists first.
     */
    public function testRegressionViewAnswersTheAssignedRole(): void
    {
        $this->authenticateWithAdmin();
        $operator = $this->createUser('view-operator');
        $this->assignRole($operator, 'operator');
        $none = $this->createUser('view-none');

        /** @var array<string, mixed> $user */
        $user = $this->callSuccess($this->ctrl->actionView((int)$operator->id));
        $this->assertSame('operator', $user['role']);
        /** @var array<string, mixed> $user */
        $user = $this->callSuccess($this->ctrl->actionView((int)$none->id));
        $this->assertNull($user['role'], 'a user without an assigned role');
    }

    /**
     * regression: GET /api/v1/users answered role viewer for every user.
     */
    public function testRegressionIndexAnswersTheAssignedRoles(): void
    {
        $admin = $this->authenticateWithAdmin();
        $operator = $this->createUser('index-operator');
        $this->assignRole($operator, 'operator');
        $several = $this->createUser('index-several');
        $this->assignRole($several, 'viewer');
        $this->assignRole($several, 'admin');
        $none = $this->createUser('index-none');

        $roles = [];
        foreach ($this->listPage('1') as $user) {
            $this->assertIsArray($user);
            $roles[$user['id']] = $user['role'];
        }

        $expected = [(int)$none->id => null, (int)$several->id => 'admin', (int)$operator->id => 'operator', (int)$admin->id => 'admin'];
        $this->assertSame($expected, array_intersect_key($roles, $expected), 'newest first; of several roles the first by name');
    }

    /**
     * regression: a client that sent the role it read back unchanged, for
     * example to change the email, made the user a viewer.
     */
    public function testRegressionSendingTheRoleBackUnchangedKeepsIt(): void
    {
        $this->authenticateWithAdmin();
        $operator = $this->createUser('round-trip');
        $this->assignRole($operator, 'operator');

        /** @var array<string, mixed> $read */
        $read = $this->callSuccess($this->ctrl->actionView((int)$operator->id));
        $this->setBody(['email' => 'round-trip-' . uniqid('', true) . '@example.com', 'role' => $read['role']]);
        /** @var array<string, mixed> $written */
        $written = $this->callSuccess($this->ctrl->actionUpdate((int)$operator->id));

        $this->assertSame('operator', $written['role']);
        $this->assertSame(['operator'], $this->roleNames((int)$operator->id));
        $audit = $this->updateAudit((int)$operator->id);
        $this->assertNotNull($audit);
        // Nothing changed, so the entry names no role: user.updated records roles and is_superadmin only when they change.
        $this->assertSame(['username' => $operator->username, 'source' => 'api'], json_decode((string)$audit->metadata, true));
    }

    /**
     * regression: the list read the roles of each user with a query of its
     * own. The last page holds a different number of users after 2 and
     * after 12 new ones; the roles of all of them cost one query.
     */
    public function testRegressionTheListCostsTheSameQueriesFor2And12Users(): void
    {
        $this->authenticateWithAdmin();
        $this->createOperators(2);
        $this->listPage(self::LAST_PAGE);
        $fewQueries = $this->queriesOf(fn () => $this->listPage(self::LAST_PAGE));
        $fewUsers = count($this->listPage(self::LAST_PAGE));
        $this->createOperators(10);

        $this->assertSame($fewQueries, $this->queriesOf(fn () => $this->listPage(self::LAST_PAGE)), 'a page after 2 new users and one after 12');
        $this->assertNotSame($fewUsers, count($this->listPage(self::LAST_PAGE)), 'precondition: the two pages hold a different number of users');
    }

    // -- Role: one of the instance's roles, or 422 ------------------------------

    /**
     * regression: POST /api/v1/users with a role that names no role saved
     * the user without any role and answered 201. Now it answers 422 with
     * the roles the instance has, and creates nothing.
     */
    public function testRegressionCreatingAUserWithAnUnknownRoleIs422AndCreatesNothing(): void
    {
        $this->authenticateWithAdmin();
        $username = 'unknown-role-' . uniqid();
        $this->setBody(['username' => $username, 'email' => $username . '@example.com', 'password' => 'securepassword', 'role' => 'superuser']);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::stockRolesError()]], $result);
        $this->assertFalse(User::find()->where(['username' => $username])->exists(), 'no user is created');
        $this->assertFalse(
            AuditLog::find()->where(['action' => AuditLog::ACTION_USER_CREATED])->andWhere(['like', 'metadata', $username])->exists(),
            'nothing is audited'
        );
    }

    /**
     * regression: PUT /api/v1/users/{id} with a role that names no role
     * saved the user, removed every role it had and answered 200. Now it
     * answers 422 and changes nothing: neither the user's fields nor their
     * roles.
     */
    public function testRegressionUpdatingAUserWithAnUnknownRoleIs422AndChangesNothing(): void
    {
        $this->authenticateWithAdmin();
        $target = $this->createUser('unknown-role-update');
        $this->assignRole($target, 'operator');
        $before = $this->userRow((int)$target->id);

        $this->setBody(['email' => 'unknown-role-' . uniqid('', true) . '@example.com', 'status' => User::STATUS_INACTIVE, 'role' => 'superuser']);
        $result = $this->ctrl->actionUpdate((int)$target->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::stockRolesError()]], $result);
        $this->assertSame($before, $this->userRow((int)$target->id), 'the user is unchanged');
        $this->assertSame(['operator'], $this->roleNames((int)$target->id), 'the roles are unchanged');
        $this->assertNull($this->updateAudit((int)$target->id), 'nothing is audited');
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function roleThatIsNotAStringProvider(): array
    {
        return [
            'a number' => [5],
            'zero' => [0],
            'a float' => [1.5],
            'true' => [true],
            'false' => [false],
            'a list' => [['admin']],
            'an empty list' => [[]],
            'an object' => [['name' => 'admin']],
        ];
    }

    /**
     * regression: POST /api/v1/users ignored a role that is not a string: it
     * created the user without a role and answered 201, as if the role had
     * been taken. Now it answers 422 and creates nothing.
     *
     * @dataProvider roleThatIsNotAStringProvider
     */
    public function testRegressionCreatingAUserWithARoleThatIsNotAStringIs422AndCreatesNothing(mixed $role): void
    {
        $this->authenticateWithAdmin();
        $username = 'role-not-a-string-' . uniqid();
        $this->setBody(['username' => $username, 'email' => $username . '@example.com', 'password' => 'securepassword', 'role' => $role]);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::stockRolesError()]], $result);
        $this->assertFalse(User::find()->where(['username' => $username])->exists(), 'no user is created');
    }

    /**
     * regression: PUT /api/v1/users/{id} ignored a role that is not a
     * string: it saved the rest of the request, kept the roles and answered
     * 200, as if the role had been taken. Now it answers 422 and changes
     * nothing.
     *
     * @dataProvider roleThatIsNotAStringProvider
     */
    public function testRegressionUpdatingAUserWithARoleThatIsNotAStringIs422AndChangesNothing(mixed $role): void
    {
        $this->authenticateWithAdmin();
        $target = $this->createUser('role-not-a-string');
        $this->assignRole($target, 'operator');
        $before = $this->userRow((int)$target->id);
        $this->setBody(['email' => 'role-not-a-string-' . uniqid('', true) . '@example.com', 'role' => $role]);

        $result = $this->ctrl->actionUpdate((int)$target->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::stockRolesError()]], $result);
        $this->assertSame($before, $this->userRow((int)$target->id), 'the user is unchanged');
        $this->assertSame(['operator'], $this->roleNames((int)$target->id), 'the roles are unchanged');
        $this->assertNull($this->updateAudit((int)$target->id), 'nothing is audited');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function roleNamedInexactlyProvider(): array
    {
        return [
            'another case' => ['Admin'],
            'capitals' => ['ADMIN'],
            'a trailing space' => ['admin '],
            'a leading space' => [' admin'],
            'a blank' => [' '],
        ];
    }

    /**
     * Role names are compared exactly, as the user form compares them
     * (UserForm::roleOptions()): a name in another case or with spaces names
     * no role, although the database, which compares names without regard
     * to case or trailing spaces, finds admin for some of them.
     *
     * @dataProvider roleNamedInexactlyProvider
     */
    public function testARoleNameIsComparedExactly(string $role): void
    {
        $this->authenticateWithAdmin();
        $target = $this->createUser('inexact-role');
        $this->assignRole($target, 'operator');
        $before = $this->userRow((int)$target->id);

        $this->setBody(['role' => $role]);
        $result = $this->ctrl->actionUpdate((int)$target->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::stockRolesError()]], $result);
        $this->assertSame($before, $this->userRow((int)$target->id));
        $this->assertSame(['operator'], $this->roleNames((int)$target->id));
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function noRoleProvider(): array
    {
        return ['null' => [null], 'an empty string' => ['']];
    }

    /**
     * null and '' leave the roles alone, as no "role" does: POST assigns
     * none, PUT keeps the user's roles and saves the rest of the request.
     *
     * @dataProvider noRoleProvider
     */
    public function testANullOrEmptyRoleLeavesTheRolesAlone(?string $role): void
    {
        $this->authenticateWithAdmin();
        $username = 'no-role-' . uniqid();
        $this->setBody(['username' => $username, 'email' => $username . '@example.com', 'password' => 'securepassword', 'role' => $role]);
        /** @var array<string, mixed> $created */
        $created = $this->callSuccess($this->ctrl->actionCreate());
        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertNull($created['role']);
        $this->assertIsInt($created['id']);
        $this->assertSame([], $this->roleNames($created['id']), 'POST assigns no role');

        $target = $this->createUser('no-role-update');
        $this->assignRole($target, 'operator');
        $email = 'no-role-' . uniqid('', true) . '@example.com';
        $this->setBody(['email' => $email, 'role' => $role]);
        /** @var array<string, mixed> $updated */
        $updated = $this->callSuccess($this->ctrl->actionUpdate((int)$target->id));
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame($email, $updated['email'], 'the rest of the request is saved');
        $this->assertSame('operator', $updated['role']);
        $this->assertSame(['operator'], $this->roleNames((int)$target->id), 'PUT keeps the roles');
    }

    /**
     * Every role of the instance can be assigned, also one added after the
     * stock roles, and the 422 names all of them, sorted by name: not in the
     * order of the user form, which lists viewer, operator and admin first.
     */
    public function testEveryRoleOfTheInstanceIsAssignableAndTheRefusalNamesThemAll(): void
    {
        $this->authenticateWithAdmin();
        $auth = $this->auth();
        $custom = 'auditor-' . substr(md5(uniqid('', true)), 0, 8);
        $auth->add($auth->createRole($custom));

        $username = 'custom-role-' . uniqid();
        $this->setBody(['username' => $username, 'email' => $username . '@example.com', 'password' => 'securepassword', 'role' => $custom]);
        /** @var array<string, mixed> $created */
        $created = $this->callSuccess($this->ctrl->actionCreate());
        $this->assertSame($custom, $created['role']);
        $this->assertIsInt($created['id']);
        $id = $created['id'];
        $this->assertSame([$custom], $this->roleNames($id));

        $this->setBody(['role' => 'admin']);
        /** @var array<string, mixed> $updated */
        $updated = $this->callSuccess($this->ctrl->actionUpdate($id));
        $this->assertSame('admin', $updated['role']);
        $this->assertSame(['admin'], $this->roleNames($id), 'the role replaces every role');

        $this->setBody(['role' => 'superuser']);
        $refused = $this->ctrl->actionUpdate($id);
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => sprintf(UsersController::INVALID_ROLE, "admin, {$custom}, operator, viewer")]], $refused);
        $this->assertSame(['admin'], $this->roleNames($id));
    }

    /**
     * web/openapi.yaml quotes the 422 of POST and PUT for a role that names
     * no role as the API answers it on an instance with the stock roles.
     */
    public function testTheSpecQuotesTheRoleRefusal(): void
    {
        $this->authenticateWithAdmin();
        $target = $this->createUser('spec-role');
        $operations = [
            'POST /api/v1/users' => ['/api/v1/users', 'post', fn (): array => $this->ctrl->actionCreate()],
            'PUT /api/v1/users/{id}' => ['/api/v1/users/{id}', 'put', fn (): array => $this->ctrl->actionUpdate((int)$target->id)],
        ];
        foreach ($operations as $operation => [$path, $method, $call]) {
            $response = $this->dig($this->spec(), ['paths', $path, $method, 'responses', '422'], "web/openapi.yaml documents the 422 of {$operation}.");
            $this->assertSame(
                '#/components/schemas/ErrorResponse',
                $this->dig($response, ['content', 'application/json', 'schema', '$ref'], $operation),
                "{$operation}: the 422 is an ErrorResponse."
            );
            $quoted = $this->dig($response, ['content', 'application/json', 'examples', 'role', 'value', 'error', 'message'], "{$operation} quotes the 422 of a role.");
            $this->assertSame(self::stockRolesError(), $quoted, "{$operation} quotes UsersController::INVALID_ROLE.");

            $username = 'spec-role-' . uniqid();
            $this->setBody(['username' => $username, 'email' => $username . '@example.com', 'password' => 'securepassword', 'role' => 'superuser']);
            $this->assertSame(['error' => ['message' => $quoted]], $call(), "{$operation} answers what the spec quotes.");
            $this->assertSame(422, \Yii::$app->response->statusCode, $operation);
        }
    }

    // -- Delete ---------------------------------------------------------------

    public function testDeleteReturnsSuccess(): void
    {
        $this->authenticateWithAdmin();
        $target = $this->createUser('delete-target');

        $data = $this->callSuccess($this->ctrl->actionDelete((int)$target->id));

        /** @var array<string, mixed> $payload */
        $payload = $data;
        $this->assertTrue($payload['deleted']);

        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionView((int)$target->id);
    }

    public function testDeleteRejects403WithoutPermission(): void
    {
        $target = $this->createUser('delete-forbidden');
        $this->authenticateAs('no-delete-perm');

        $result = $this->ctrl->actionDelete((int)$target->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $result);
        $this->assertNotNull(User::findOne($target->id));
    }

    public function testDeleteRejectsSelfDeletion(): void
    {
        $admin = $this->authenticateWithAdmin();

        $this->ctrl->actionDelete((int)$admin->id);
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    /**
     * Regression: a user whom other records still refer to (here: a job
     * they launched) failed on the foreign key with a server error, after
     * all their roles had been revoked, so their schedules and triggers
     * stopped launching.
     */
    public function testDeletingAUserOtherRecordsReferToIs409AndKeepsTheirRoles(): void
    {
        $this->authenticateWithAdmin();
        $target = $this->createUser('referenced');
        $this->assignRole($target, 'operator');
        $owner = (int)$this->createUser('owner')->id;
        $template = $this->createJobTemplate(
            $this->createProject($owner)->id,
            $this->createInventory($owner)->id,
            $this->createRunnerGroup($owner)->id,
            $owner
        );
        $this->createJob($template->id, (int)$target->id);

        $result = $this->ctrl->actionDelete((int)$target->id);

        $this->assertSame(409, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => (new UserDeletionService())->refusalMessage($target)]], $result);
        $this->assertNotNull(User::findOne($target->id));
        $this->assertSame(['operator'], $this->roleNames((int)$target->id));
        $this->assertNull($this->deletionAudit((int)$target->id), 'only deletes are audited');
    }

    /**
     * Regression: as above, for a user whose only reference is a trigger
     * token they generated.
     */
    public function testDeletingTheGeneratorOfATriggerTokenIs409(): void
    {
        $this->authenticateWithAdmin();
        $target = $this->createUser('token-generator');
        $this->assignRole($target, 'operator');
        $workflow = $this->createWorkflowTemplate((int)$this->createUser('owner')->id);
        $workflow->generateTriggerToken((int)$target->id);

        $this->ctrl->actionDelete((int)$target->id);

        $this->assertSame(409, \Yii::$app->response->statusCode);
        $this->assertNotNull(User::findOne($target->id));
        $this->assertSame(['operator'], $this->roleNames((int)$target->id));
    }

    public function testDeletingAnUnreferencedUserRemovesTheirRolesAndIsAudited(): void
    {
        $this->authenticateWithAdmin();
        $target = $this->createUser('free');
        $this->assignRole($target, 'operator');

        $result = $this->ctrl->actionDelete((int)$target->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(['data' => ['deleted' => true]], $result);
        $this->assertNull(User::findOne($target->id));
        $this->assertSame([], $this->roleNames((int)$target->id));
        $audit = $this->deletionAudit((int)$target->id);
        $this->assertNotNull($audit);
        $this->assertSame(['username' => $target->username, 'source' => 'api'], json_decode((string)$audit->metadata, true));
    }

    // -- The only superadmin --------------------------------------------------

    /**
     * Only a superadmin deletes a superadmin (UserChangePolicy), so the only
     * superadmin is one whom a superadmin tries to delete after losing the
     * flag meanwhile: the token's user was read as a superadmin, the stored
     * flags count one.
     */
    public function testDeletingTheOnlySuperadminIs422AndChangesNothing(): void
    {
        $actor = $this->createUser('demoted-meanwhile');
        $actor->is_superadmin = true;
        $actor->save(false);
        $this->authenticate($actor);
        $target = $this->createUser('only-superadmin');
        $this->assignRole($target, 'admin');
        $this->makeTheOnlySuperadmins($target);

        $result = $this->ctrl->actionDelete((int)$target->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Cannot delete the only active superadmin.']], $result);
        $this->assertTrue($this->isSuperadmin((int)$target->id));
        $this->assertSame(['admin'], $this->roleNames((int)$target->id));
        $this->assertNull($this->deletionAudit((int)$target->id));
    }

    public function testASuperadminCanBeDeletedWhileAnotherRemains(): void
    {
        $target = $this->createUser('superadmin');
        $this->assignRole($target, 'admin');
        $actor = $this->createUser('other-superadmin');
        $this->makeTheOnlySuperadmins($target, $actor);
        $this->authenticate($actor);

        $result = $this->ctrl->actionDelete((int)$target->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(['data' => ['deleted' => true]], $result);
        $this->assertNull(User::findOne($target->id));
        $this->assertSame([], $this->roleNames((int)$target->id));
    }

    /**
     * Regression: PUT /api/v1/users/{id} with is_superadmin false took the
     * flag from the only superadmin, which the web UI and DELETE refuse.
     * Only a superadmin changes the flag, so the only superadmin is the one
     * who can try.
     */
    public function testDemotingTheOnlySuperadminIs422AndChangesNothing(): void
    {
        $target = $this->createUser('only-superadmin');
        $this->makeTheOnlySuperadmins($target);
        $this->authenticate($target);
        $email = $target->email;

        $this->setBody(['is_superadmin' => false, 'email' => 'demoted-' . uniqid('', true) . '@example.com']);
        $result = $this->ctrl->actionUpdate((int)$target->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Cannot demote the only active superadmin.']], $result);
        $this->assertTrue($this->isSuperadmin((int)$target->id));
        $target->refresh();
        $this->assertSame($email, $target->email, 'nothing of the request is saved');
        $this->assertNull($this->updateAudit((int)$target->id));
    }

    public function testASuperadminCanBeDemotedWhileAnotherRemains(): void
    {
        $target = $this->createUser('superadmin');
        $actor = $this->createUser('other-superadmin');
        $this->makeTheOnlySuperadmins($target, $actor);
        $this->authenticate($actor);

        $this->setBody(['is_superadmin' => false]);
        $result = $this->ctrl->actionUpdate((int)$target->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        /** @var array<string, mixed> $user */
        $user = $this->callSuccess($result);
        $this->assertFalse($user['is_superadmin']);
        $this->assertFalse($this->isSuperadmin((int)$target->id));
        $this->assertSame(
            ['username' => $target->username, 'source' => 'api', 'is_superadmin' => ['from' => true, 'to' => false]],
            $this->auditContext($this->updateAudit((int)$target->id))
        );
    }

    /**
     * Only a superadmin changes the flag: an admin who is none gets 403 for
     * a body that would promote, and nothing is written. (A superadmin, whom
     * a body could demote, they may not change at all:
     * UsersControllerChangeTest.)
     */
    public function testAnAdminWhoIsNoSuperadminCannotChangeTheFlag(): void
    {
        $this->authenticateWithAdmin();
        $operator = $this->createUser('kept-operator');
        $this->assignRole($operator, 'operator');
        $before = $this->userRow((int)$operator->id);

        $this->setBody(['is_superadmin' => true, 'email' => 'flag-' . uniqid('', true) . '@example.com']);
        $result = $this->ctrl->actionUpdate((int)$operator->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => RoleGrantPolicy::SUPERADMIN_REFUSED]], $result);
        $this->assertSame($before, $this->userRow((int)$operator->id), 'nothing is written');
        $this->assertNull($this->updateAudit((int)$operator->id));
    }

    /**
     * regression: the only superadmin deactivated their own account with
     * PUT {status: 0} and got 200: nobody was left to sign in as a
     * superadmin, and admins do not change superadmins. 422
     * UsersController::ONLY_SUPERADMIN_DEACTIVATED now, as the web form
     * refuses it; nothing is written.
     */
    public function testRegressionTheOnlySuperadminCannotDeactivateThemselves(): void
    {
        $target = $this->createUser('only-superadmin');
        $this->makeTheOnlySuperadmins($target);
        $this->authenticate($target);
        $before = $this->userRow((int)$target->id);

        $this->setBody(['status' => User::STATUS_INACTIVE, 'email' => 'deactivated-' . uniqid('', true) . '@example.com']);
        $result = $this->ctrl->actionUpdate((int)$target->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Cannot deactivate the only active superadmin.']], $result);
        $this->assertSame(UsersController::ONLY_SUPERADMIN_DEACTIVATED, $result['error']['message']);
        $this->assertSame($before, $this->userRow((int)$target->id), 'nothing is written');
        $this->assertNull($this->updateAudit((int)$target->id));
    }

    /**
     * Demoting comes first where a body would do both; with another
     * superadmin besides, a superadmin is deactivated.
     */
    public function testDemotingIsNamedFirstAndASuperadminCanBeDeactivatedWhileAnotherRemains(): void
    {
        $target = $this->createUser('superadmin');
        $actor = $this->createUser('other-superadmin');
        $this->makeTheOnlySuperadmins($target);
        $this->authenticate($target);
        $this->setBody(['status' => User::STATUS_INACTIVE, 'is_superadmin' => false]);
        $this->assertSame(['error' => ['message' => UsersController::ONLY_SUPERADMIN_DEMOTED]], $this->ctrl->actionUpdate((int)$target->id));

        $target->refresh();
        $this->makeTheOnlySuperadmins($target, $actor);
        $this->authenticate($actor);
        $this->setBody(['status' => User::STATUS_INACTIVE]);
        $result = $this->ctrl->actionUpdate((int)$target->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        /** @var array<string, mixed> $user */
        $user = $this->callSuccess($result);
        $this->assertSame(User::STATUS_INACTIVE, $user['status']);
        $this->assertTrue($this->isSuperadmin((int)$target->id));
    }

    /**
     * The only superadmin, who alone may change their account, can still
     * update it: a body that repeats the flag changes nothing of it.
     */
    public function testTheOnlySuperadminCanStillBeUpdatedOtherwise(): void
    {
        $target = $this->createUser('only-superadmin');
        $this->makeTheOnlySuperadmins($target);
        $this->authenticate($target);
        $email = 'kept-' . uniqid('', true) . '@example.com';

        $this->setBody(['is_superadmin' => true, 'email' => $email]);
        $result = $this->ctrl->actionUpdate((int)$target->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        /** @var array<string, mixed> $user */
        $user = $this->callSuccess($result);
        $this->assertTrue($user['is_superadmin']);
        $this->assertSame($email, $user['email']);
        $this->assertTrue($this->isSuperadmin((int)$target->id));
    }

    // -- What the caller may give ---------------------------------------------

    /**
     * regression: a help desk role (user.view and user.update) made its own
     * account a superadmin, and an admin, with PUT /api/v1/users/{self}.
     * Each answers 403 now, and nothing is written.
     */
    public function testRegressionAHelpDeskCannotRaiseItselfThroughTheApi(): void
    {
        $helpDesk = $this->helpDesk('api-help-desk-self');
        $this->authenticate($helpDesk);
        $before = $this->userRow((int)$helpDesk->id);
        $roles = $this->roleNames((int)$helpDesk->id);

        $this->setBody(['is_superadmin' => true]);
        $this->assertSame(['error' => ['message' => RoleGrantPolicy::SUPERADMIN_REFUSED]], $this->ctrl->actionUpdate((int)$helpDesk->id));
        $this->assertSame(403, \Yii::$app->response->statusCode);

        $this->setBody(['role' => 'admin', 'email' => 'raised-' . uniqid('', true) . '@example.com']);
        $this->assertSame(['error' => ['message' => sprintf(RoleGrantPolicy::ROLE_REFUSED, 'admin')]], $this->ctrl->actionUpdate((int)$helpDesk->id));
        $this->assertSame(403, \Yii::$app->response->statusCode);

        $this->assertSame($before, $this->userRow((int)$helpDesk->id), 'nothing is written');
        $this->assertSame($roles, $this->roleNames((int)$helpDesk->id));
        $this->assertFalse($this->auth()->checkAccess((string)$helpDesk->id, 'admin'));
        $this->assertNull($this->updateAudit((int)$helpDesk->id));
    }

    /**
     * regression: as above, for another user: the help desk made a viewer
     * an admin or a superadmin. A body that repeats the flag changes nothing
     * and is fine. (An operator it may not change at all:
     * UsersControllerChangeTest.)
     */
    public function testRegressionAHelpDeskCannotRaiseAnotherUserThroughTheApi(): void
    {
        $this->authenticate($this->helpDesk('api-help-desk'));
        $viewer = $this->createUser('api-help-desk-target');
        $this->assignRole($viewer, 'viewer');
        $before = $this->userRow((int)$viewer->id);

        $this->setBody(['role' => 'admin']);
        $this->assertSame(['error' => ['message' => sprintf(RoleGrantPolicy::ROLE_REFUSED, 'admin')]], $this->ctrl->actionUpdate((int)$viewer->id));
        $this->setBody(['is_superadmin' => true]);
        $this->assertSame(['error' => ['message' => RoleGrantPolicy::SUPERADMIN_REFUSED]], $this->ctrl->actionUpdate((int)$viewer->id));
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame($before, $this->userRow((int)$viewer->id));
        $this->assertSame(['viewer'], $this->roleNames((int)$viewer->id));

        $email = 'repeated-' . uniqid('', true) . '@example.com';
        $this->setBody(['is_superadmin' => false, 'role' => 'viewer', 'email' => $email]);
        /** @var array<string, mixed> $user */
        $user = $this->callSuccess($this->ctrl->actionUpdate((int)$viewer->id));
        $this->assertSame(200, \Yii::$app->response->statusCode, 'the flag and the role as they are');
        $this->assertSame($email, $user['email']);
        $this->assertSame(['viewer'], $this->roleNames((int)$viewer->id), 'a role the user has needs no grant');
    }

    /**
     * regression: POST /api/v1/users with is_superadmin true, or a role the
     * caller does not hold, needed only user.create. Each answers 403 now
     * and creates nothing; a role the caller may assign is fine.
     */
    public function testRegressionUserCreateAloneCannotCreateASuperadminOrAnAdmin(): void
    {
        $creator = $this->createUser('api-creator');
        $this->assignRole($creator, $this->customRole(['user.create']));
        $this->authenticate($creator);

        $refusals = [
            [['is_superadmin' => true, 'role' => 'viewer'], RoleGrantPolicy::SUPERADMIN_REFUSED],
            [['role' => 'admin'], sprintf(RoleGrantPolicy::ROLE_REFUSED, 'admin')],
        ];
        foreach ($refusals as [$fields, $message]) {
            $username = 'api-created-' . uniqid();
            $this->setBody(['username' => $username, 'email' => $username . '@example.com', 'password' => 'securepassword'] + $fields);

            $this->assertSame(['error' => ['message' => $message]], $this->ctrl->actionCreate());
            $this->assertSame(403, \Yii::$app->response->statusCode);
            $this->assertFalse(User::find()->where(['username' => $username])->exists(), 'no user is created');
        }

        $username = 'api-created-viewer-' . uniqid();
        $this->setBody(['username' => $username, 'email' => $username . '@example.com', 'password' => 'securepassword', 'role' => 'viewer', 'is_superadmin' => false]);
        /** @var array<string, mixed> $created */
        $created = $this->callSuccess($this->ctrl->actionCreate());
        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertFalse($created['is_superadmin']);
        $this->assertSame('viewer', $created['role']);
    }

    /**
     * A role that names no role answers 422 before the grant is looked at.
     */
    public function testAnUnknownRoleIs422AlsoForAHelpDesk(): void
    {
        $helpDesk = $this->helpDesk('api-help-desk-unknown');
        $this->authenticate($helpDesk);

        $names = array_map('strval', array_keys($this->auth()->getRoles()));
        sort($names, SORT_STRING);

        $this->setBody(['role' => 'superuser']);
        $this->assertSame(
            ['error' => ['message' => sprintf(UsersController::INVALID_ROLE, implode(', ', $names))]],
            $this->ctrl->actionUpdate((int)$helpDesk->id)
        );
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    public function testASuperadminSetsTheFlagOnCreateAndUpdate(): void
    {
        $superadmin = $this->createUser('api-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $this->authenticate($superadmin);

        $username = 'api-new-superadmin-' . uniqid();
        $this->setBody(['username' => $username, 'email' => $username . '@example.com', 'password' => 'securepassword', 'role' => 'admin', 'is_superadmin' => true]);
        /** @var array<string, mixed> $created */
        $created = $this->callSuccess($this->ctrl->actionCreate());
        $this->assertTrue($created['is_superadmin']);
        $this->assertIsInt($created['id']);
        $this->assertTrue($this->isSuperadmin($created['id']));

        $target = $this->createUser('api-promoted');
        $this->setBody(['is_superadmin' => true]);
        $this->callSuccess($this->ctrl->actionUpdate((int)$target->id));
        $this->assertTrue($this->isSuperadmin((int)$target->id));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function secondRoleProvider(): array
    {
        return ['a custom role sorted before operator' => ['a-approvers'], 'one sorted after it' => ['z-approvers']];
    }

    /**
     * regression: PUT /api/v1/users/{id} with role operator for a user with
     * the roles operator and an approver role, as an LDAP group mapping
     * gives them, removed the approver role; a client that sent back the
     * role it read kept only the first by name. A role the user has keeps
     * every role assigned to them.
     *
     * @dataProvider secondRoleProvider
     */
    public function testRegressionARoleTheUserHasKeepsEveryAssignedRole(string $prefix): void
    {
        $this->authenticateWithAdmin();
        $target = $this->createUser('api-multi');
        $this->assignRole($target, 'operator');
        $custom = $this->customRole([], $prefix);
        $this->assignRole($target, $custom);
        $both = $this->roleNames((int)$target->id);
        $this->assertCount(2, $both);

        $this->setBody(['role' => 'operator', 'email' => 'multi-' . uniqid('', true) . '@example.com']);
        $this->callSuccess($this->ctrl->actionUpdate((int)$target->id));
        $this->assertSame($both, $this->roleNames((int)$target->id), 'role operator');

        /** @var array<string, mixed> $read */
        $read = $this->callSuccess($this->ctrl->actionView((int)$target->id));
        $this->assertSame($both[0], $read['role'], 'the first by name');
        $this->setBody(['role' => $read['role'], 'email' => 'round-trip-' . uniqid('', true) . '@example.com']);
        $this->callSuccess($this->ctrl->actionUpdate((int)$target->id));
        $this->assertSame($both, $this->roleNames((int)$target->id), 'a GET-PUT round trip');

        $this->setBody(['role' => $custom]);
        $this->callSuccess($this->ctrl->actionUpdate((int)$target->id));
        $this->assertSame($both, $this->roleNames((int)$target->id), 'the other role');
        $this->assertSame(['username' => $target->username, 'source' => 'api'], $this->auditContext($this->updateAudit((int)$target->id)));
    }

    public function testARoleTheUserDoesNotHaveReplacesEveryAssignedRole(): void
    {
        $this->authenticateWithAdmin();
        $target = $this->createUser('api-multi-replaced');
        $this->assignRole($target, 'operator');
        $custom = $this->customRole([], 'a-replaced');
        $this->assignRole($target, $custom);

        $this->setBody(['role' => 'viewer']);
        /** @var array<string, mixed> $user */
        $user = $this->callSuccess($this->ctrl->actionUpdate((int)$target->id));

        $this->assertSame('viewer', $user['role']);
        $this->assertSame(['viewer'], $this->roleNames((int)$target->id));
        $this->assertSame(
            ['username' => $target->username, 'source' => 'api', 'roles' => ['from' => [$custom, 'operator'], 'to' => ['viewer']]],
            $this->auditContext($this->updateAudit((int)$target->id))
        );
    }

    /**
     * regression: user.updated and user.created named neither the roles nor
     * the superadmin flag, so the audit log could not tell who gave whom a
     * role or the flag. They record both as {from, to} when they change,
     * from null for a created user.
     */
    public function testRegressionTheAuditRecordsTheRolesAndTheFlag(): void
    {
        $superadmin = $this->createUser('api-audit-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $this->authenticate($superadmin);
        $target = $this->createUser('api-audit-target');
        $this->assignRole($target, 'operator');

        $this->setBody(['role' => 'admin', 'is_superadmin' => true]);
        $this->callSuccess($this->ctrl->actionUpdate((int)$target->id));
        $this->assertSame(
            [
                'username' => $target->username,
                'source' => 'api',
                'roles' => ['from' => ['operator'], 'to' => ['admin']],
                'is_superadmin' => ['from' => false, 'to' => true],
            ],
            $this->auditContext($this->updateAudit((int)$target->id))
        );

        $username = 'api-audit-created-' . uniqid();
        $this->setBody(['username' => $username, 'email' => $username . '@example.com', 'password' => 'securepassword', 'role' => 'operator']);
        /** @var array<string, mixed> $created */
        $created = $this->callSuccess($this->ctrl->actionCreate());
        $this->assertIsInt($created['id']);
        $this->assertSame(
            [
                'username' => $username,
                'source' => 'api',
                'roles' => ['from' => null, 'to' => ['operator']],
                'is_superadmin' => ['from' => null, 'to' => false],
            ],
            $this->auditContext(AuditLog::findOne(['action' => AuditLog::ACTION_USER_CREATED, 'object_type' => 'user', 'object_id' => $created['id']]))
        );
    }

    // -- Helpers --------------------------------------------------------------

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
     * A user holding a help desk role: user.view and user.update, nothing
     * else.
     */
    private function helpDesk(string $label): User
    {
        $user = $this->createUser($label);
        $this->assignRole($user, $this->customRole(['user.view', 'user.update'], 'help-desk'));

        return $user;
    }

    /**
     * A custom role named $prefix-<random> holding $permissions.
     *
     * @param list<string> $permissions
     */
    private function customRole(array $permissions, string $prefix = 'custom'): string
    {
        $auth = $this->auth();
        $role = $auth->createRole($prefix . '-' . substr(md5(uniqid('', true)), 0, 8));
        $auth->add($role);
        foreach ($permissions as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }

        return $role->name;
    }

    /**
     * The context of an audit entry, decoded.
     *
     * @return array<string, mixed>
     */
    private function auditContext(?AuditLog $audit): array
    {
        $this->assertNotNull($audit, 'the entry');
        $context = json_decode((string)$audit->metadata, true);
        $this->assertIsArray($context);

        return $context;
    }

    /**
     * Extract the data payload from a success response.
     *
     * @param array<string, mixed> $result
     */
    private function callSuccess(array $result): mixed
    {
        $this->assertArrayHasKey('data', $result);
        return $result['data'];
    }

    /**
     * Create a user with no RBAC role — will fail all permission checks.
     */
    private function authenticateAs(string $label): void
    {
        $user = $this->createUser($label);
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }

    /**
     * Create an admin user with full permissions and authenticate.
     */
    private function authenticateWithAdmin(): User
    {
        $user = $this->createUser('api-admin');
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $adminRole = $auth->getRole('admin');
        $this->assertNotNull($adminRole);
        $auth->assign($adminRole, (string)$user->id);

        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'admin-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);

        return $user;
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

    private function assignRole(User $user, string $roleName): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->getRole($roleName);
        $this->assertNotNull($role, $roleName);
        $auth->assign($role, (string)$user->id);
    }

    /**
     * The roles assigned to $userId, as the assignment table holds them.
     *
     * @return list<string>
     */
    private function roleNames(int $userId): array
    {
        $names = array_map('strval', array_keys($this->auth()->getAssignments((string)$userId)));
        sort($names);
        return $names;
    }

    private function auth(): DbManager
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(DbManager::class, $auth);

        return $auth;
    }

    /**
     * The users of page $page of GET /api/v1/users, newest first.
     *
     * @return array<int, mixed>
     */
    private function listPage(string $page): array
    {
        $this->setQueryParams(['page' => $page]);
        $result = $this->ctrl->actionIndex();
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertArrayHasKey('data', $result);

        return $result['data'];
    }

    private function createOperators(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->assignRole($this->createUser('cost-operator'), 'operator');
        }
    }

    private function deletionAudit(int $userId): ?AuditLog
    {
        return AuditLog::findOne(['action' => AuditLog::ACTION_USER_DELETED, 'object_type' => 'user', 'object_id' => $userId]);
    }

    private function updateAudit(int $userId): ?AuditLog
    {
        return AuditLog::findOne(['action' => AuditLog::ACTION_USER_UPDATED, 'object_type' => 'user', 'object_id' => $userId]);
    }

    /**
     * Makes $users the only superadmins, inside the test's transaction.
     */
    private function makeTheOnlySuperadmins(User ...$users): void
    {
        User::updateAll(['is_superadmin' => 0], ['is_superadmin' => 1]);
        foreach ($users as $user) {
            $user->is_superadmin = true;
            $user->save(false);
        }
        $this->assertSame(count($users), (int)User::find()->where(['is_superadmin' => 1])->count());
    }

    private function isSuperadmin(int $userId): bool
    {
        return (bool)User::find()->select('is_superadmin')->where(['id' => $userId])->scalar();
    }

    /**
     * The 422 of a role that names no role on an instance with the stock
     * roles, as the test database has them: the text that the spec and
     * docs/updating.md quote.
     */
    private static function stockRolesError(): string
    {
        return sprintf(UsersController::INVALID_ROLE, 'admin, operator, viewer');
    }

    /**
     * Every column of the user as stored, to show that nothing was written.
     *
     * @return array<string, mixed>
     */
    private function userRow(int $userId): array
    {
        $row = User::find()->where(['id' => $userId])->asArray()->one();
        $this->assertIsArray($row, "User #{$userId}");

        return $row;
    }
}
