<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\services\AssignedRoles;
use app\tests\integration\CountsQueries;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\InvalidConfigException;
use yii\rbac\DbManager;
use yii\rbac\ManagerInterface;
use yii\rbac\Role;

/**
 * AssignedRoles lists the roles assigned to users in the RBAC assignment
 * table, sorted by name, and never the manager's default roles: the web
 * application gives every user the default role viewer, which
 * DbManager::getRolesByUser() lists first for everyone.
 */
final class AssignedRolesTest extends DbTestCase
{
    use CountsQueries;
    use TeamScopeFixtures;

    /** The default role of the web and console applications (config/web.php). */
    private const PRODUCTION_DEFAULT_ROLES = ['viewer'];

    /** @var list<string> the default roles of the manager before the test */
    private array $savedDefaultRoles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $auth = $this->auth();
        $this->savedDefaultRoles = array_map('strval', $auth->defaultRoles);
        $auth->defaultRoles = self::PRODUCTION_DEFAULT_ROLES;
    }

    protected function tearDown(): void
    {
        $this->auth()->defaultRoles = $this->savedDefaultRoles;
        parent::tearDown();
    }

    public function testSeveralRolesAreListedSortedByNameWithoutThePermissionsAssignedDirectly(): void
    {
        $auth = $this->auth();
        $user = (int)$this->createUser('assigned-several')->id;
        $zulu = $this->newRole('zulu');
        $alpha = $this->newRole('alpha');
        $auth->assign($zulu, (string)$user);
        $auth->assign($this->role('operator'), (string)$user);
        $auth->assign($alpha, (string)$user);
        $permission = $auth->getPermission('job.launch');
        $this->assertNotNull($permission);
        $auth->assign($permission, (string)$user);

        $expected = [$alpha->name, 'operator', $zulu->name];
        $this->assertSame($expected, (new AssignedRoles($auth))->of($user));
        $this->assertSame([$user => $expected], (new AssignedRoles($auth))->ofUsers([$user]));
    }

    public function testAUserWithoutAnAssignedRoleHasNone(): void
    {
        $user = (int)$this->createUser('assigned-none')->id;

        $this->assertSame([], (new AssignedRoles($this->auth()))->of($user));
        $this->assertArrayHasKey('viewer', $this->auth()->getRolesByUser($user), 'the user holds the default role all the same');
    }

    /**
     * DbManager::getRolesByUser() lists the default role viewer first, for
     * an operator and an admin too; the assigned roles leave it out.
     */
    public function testTheDefaultRolesAreNeverListed(): void
    {
        $operator = (int)$this->createUserWithRole('assigned-operator', 'operator')->id;
        $admin = (int)$this->createUserWithRole('assigned-admin', 'admin')->id;
        $this->assertSame('viewer', array_key_first($this->auth()->getRolesByUser($operator)), 'precondition: the default role comes first');

        $this->assertSame(
            [$operator => ['operator'], $admin => ['admin']],
            (new AssignedRoles($this->auth()))->ofUsers([$operator, $admin])
        );
    }

    public function testEveryGivenIdIsAKeyInTheOrderGivenAndUnknownIdsHaveNoRoles(): void
    {
        $operator = (int)$this->createUserWithRole('assigned-order-operator', 'operator')->id;
        $viewer = (int)$this->createUserWithRole('assigned-order-viewer', 'viewer')->id;
        $unknown = (int)$this->createUser('assigned-gone')->id;
        $this->deleteUser($unknown);

        $this->assertSame(
            [$viewer => ['viewer'], $unknown => [], 0 => [], -1 => [], $operator => ['operator']],
            (new AssignedRoles($this->auth()))->ofUsers([$viewer, $unknown, 0, -1, $operator])
        );
        $this->assertSame([], (new AssignedRoles($this->auth()))->of($unknown));
    }

    public function testNoUsersCostNoQuery(): void
    {
        $roles = new AssignedRoles($this->auth());
        $this->queriesOf(fn () => $roles->ofUsers([]));

        $this->assertSame(0, $this->queriesOf(fn () => $roles->ofUsers([])));
        $this->assertSame([], $roles->ofUsers([]));
    }

    public function testTheRolesOfAnyNumberOfUsersCostOneQuery(): void
    {
        $roles = new AssignedRoles($this->auth());
        $few = $this->operators(2);
        $many = $this->operators(12);
        $this->queriesOf(fn () => $roles->ofUsers($few));

        $this->assertSame(1, $this->queriesOf(fn () => $roles->ofUsers($few)));
        $this->assertSame(1, $this->queriesOf(fn () => $roles->ofUsers($many)));
        $this->assertSame(array_fill_keys($many, ['operator']), $roles->ofUsers($many));
    }

    public function testFromAppReadsTheApplicationsManager(): void
    {
        $user = (int)$this->createUserWithRole('assigned-app', 'admin')->id;

        $this->assertSame(['admin'], AssignedRoles::fromApp()->of($user));
    }

    public function testFromAppRefusesAManagerWithoutAnAssignmentTable(): void
    {
        $original = $this->auth();
        $other = $this->createMock(ManagerInterface::class);
        \Yii::$app->set('authManager', $other);
        try {
            AssignedRoles::fromApp();
            $this->fail('expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(sprintf(AssignedRoles::NOT_A_DB_MANAGER, get_class($other)), $e->getMessage());
        } finally {
            \Yii::$app->set('authManager', $original);
        }
    }

    // -- Helpers --------------------------------------------------------------

    private function auth(): DbManager
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(DbManager::class, $auth);

        return $auth;
    }

    private function role(string $name): Role
    {
        $role = $this->auth()->getRole($name);
        $this->assertNotNull($role, $name);

        return $role;
    }

    private function newRole(string $prefix): Role
    {
        $role = $this->auth()->createRole($prefix . '-test-' . uniqid());
        $this->auth()->add($role);

        return $role;
    }

    /**
     * Deletes the user row and leaves its ID unused, inside the test's
     * transaction.
     */
    private function deleteUser(int $userId): void
    {
        \Yii::$app->db->createCommand()->delete('{{%user}}', ['id' => $userId])->execute();
    }

    /**
     * @return list<int>
     */
    private function operators(int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = (int)$this->createUserWithRole('assigned-cost', 'operator')->id;
        }

        return $ids;
    }
}
