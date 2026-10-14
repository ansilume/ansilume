<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\AuthManager;
use app\services\PermissionHolders;
use app\tests\integration\CountsQueries;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\rbac\DbManager;
use yii\rbac\Item;
use yii\rbac\Permission;
use yii\rbac\Role;

/**
 * PermissionHolders reads DbManager's tables in bulk and must find exactly
 * the users DbManager::checkAccess() finds one by one: through roles at any
 * depth, a permission assigned directly, default roles, and business rules
 * (which only checkAccess() can evaluate). IDs below 1 name no user and hold
 * nothing, as app\components\AuthManager answers.
 */
class PermissionHoldersDbManagerTest extends DbTestCase
{
    use CountsQueries;
    use TeamScopeFixtures;

    private const DECIDE = 'approval.decide';

    public function testEveryKindOfHolderIsFoundAsCheckAccessFindsThemOneByOne(): void
    {
        $deciders = $this->newRole('deciders', $this->permission(self::DECIDE));
        $nested = $this->newRole('nested', $deciders);
        $bystanders = $this->newRole('bystanders');
        $aboveAdmin = $this->newRole('above-admin', $this->role('admin'));

        $viewer = (int)$this->createUserWithRole('holder-viewer', 'viewer')->id;
        $admin = (int)$this->createUserWithRole('holder-admin', 'admin')->id;
        $plain = (int)$this->createUser('holder-plain')->id;
        $custom = $this->userWith($deciders);
        $operator = (int)$this->createUserWithRole('holder-operator', 'operator')->id;
        $nestedUser = $this->userWith($nested);
        $direct = $this->userWith($this->permission(self::DECIDE));
        $bystander = $this->userWith($bystanders);
        $aboveAdminUser = $this->userWith($aboveAdmin);
        $ids = [$viewer, $admin, $plain, $custom, $operator, $nestedUser, $direct, $bystander, $aboveAdminUser];

        $expected = [$admin, $custom, $operator, $nestedUser, $direct, $aboveAdminUser];
        $this->assertSame($expected, $this->holders()->among(self::DECIDE, $ids), 'in the order given');
        $this->assertSame($expected, $this->checkedOneByOne(self::DECIDE, $ids));

        $admins = [$admin, $aboveAdminUser];
        $this->assertSame($admins, $this->holders()->among('admin', $ids));
        $this->assertSame($admins, $this->checkedOneByOne('admin', $ids));
    }

    /**
     * The web application gives every user the viewer role by default. Once
     * the viewer role grants an item, every user holds it, assigned or not.
     */
    public function testADefaultRoleThatGrantsTheItemGivesItToEveryUser(): void
    {
        $auth = $this->auth();
        $auth->addChild($this->role('viewer'), $this->permission(self::DECIDE));
        $plain = (int)$this->createUser('default-plain')->id;
        $viewer = (int)$this->createUserWithRole('default-viewer', 'viewer')->id;
        $bystander = $this->userWith($this->newRole('default-bystanders'));
        $ids = [$plain, $viewer, $bystander];

        $saved = $auth->defaultRoles;
        try {
            $auth->defaultRoles = ['viewer'];
            $this->assertSame($ids, $this->holders()->among(self::DECIDE, $ids));
            $this->assertSame($ids, $this->checkedOneByOne(self::DECIDE, $ids));

            $auth->defaultRoles = [];
            $this->assertSame([$viewer], $this->holders()->among(self::DECIDE, $ids));
            $this->assertSame([$viewer], $this->checkedOneByOne(self::DECIDE, $ids));
        } finally {
            $auth->defaultRoles = $saved;
        }
    }

    /**
     * regression: a default role that grants the item answered every given
     * ID, also 0 and -1, which name no user: app\components\AuthManager
     * answers false for them, and among() promises its answer.
     */
    public function testRegressionIdsBelowOneHoldNothingUnderTheDefaultRole(): void
    {
        $auth = $this->auth();
        $this->assertInstanceOf(AuthManager::class, $auth, 'the manager of every application');
        $auth->addChild($this->role('viewer'), $this->permission(self::DECIDE));
        $plain = (int)$this->createUser('below-one-plain')->id;
        $admin = (int)$this->createUserWithRole('below-one-admin', 'admin')->id;
        $ids = [0, $plain, -1, $admin];

        $saved = $auth->defaultRoles;
        try {
            $auth->defaultRoles = ['viewer'];
            $this->assertSame([$plain, $admin], $this->checkedOneByOne(self::DECIDE, $ids), 'AuthManager');
            $this->assertSame([$plain, $admin], $this->holders()->among(self::DECIDE, $ids), 'through the default role');
            $this->assertSame([$admin], $this->holders()->among('admin', $ids), 'through an assignment');
            $this->assertSame([], $this->holders()->among(self::DECIDE, [0, -1]));
        } finally {
            $auth->defaultRoles = $saved;
        }
    }

    public function testAnItemBehindABusinessRuleIsCheckedUserByUser(): void
    {
        $auth = $this->auth();
        $passes = (int)$this->createUser('rule-passes')->id;
        $fails = (int)$this->createUser('rule-fails')->id;
        $rule = new ListedUsersRbacRule();
        $rule->name = 'test-listed-users-' . uniqid();
        $rule->userIds = [$passes];
        $auth->add($rule);
        $role = $auth->createRole('test-ruled-' . uniqid());
        $role->ruleName = $rule->name;
        $auth->add($role);
        $auth->addChild($role, $this->permission(self::DECIDE));
        $auth->assign($role, (string)$passes);
        $auth->assign($role, (string)$fails);

        $this->assertSame([$passes], $this->checkedOneByOne(self::DECIDE, [$passes, $fails]));
        $this->assertSame(
            [$passes],
            $this->holders()->among(self::DECIDE, [$passes, $fails]),
            'the rule is evaluated, not taken for granted'
        );
    }

    public function testNobodyHoldsAnItemThatDoesNotExist(): void
    {
        $ids = [(int)$this->createUserWithRole('missing-admin', 'admin')->id];
        $name = 'test-no-such-item-' . uniqid();

        $this->assertSame([], $this->holders()->among($name, $ids));
        $this->assertSame([], $this->checkedOneByOne($name, $ids));
        $this->assertSame([], $this->holders()->among(self::DECIDE, []));
    }

    public function testTheCostDoesNotGrowWithTheNumberOfUsers(): void
    {
        $few = $this->operators(3);
        $many = $this->operators(30);
        $this->queriesOf(fn () => $this->holders()->among(self::DECIDE, $few));

        $this->assertSame(
            $this->queriesOf(fn () => $this->holders()->among(self::DECIDE, $few)),
            $this->queriesOf(fn () => $this->holders()->among(self::DECIDE, $many))
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function holders(): PermissionHolders
    {
        return new PermissionHolders($this->auth());
    }

    private function auth(): DbManager
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(DbManager::class, $auth);

        return $auth;
    }

    /**
     * @param list<int> $userIds
     * @return list<int>
     */
    private function checkedOneByOne(string $itemName, array $userIds): array
    {
        $auth = $this->auth();

        return array_values(array_filter(
            $userIds,
            static fn (int $userId): bool => $auth->checkAccess($userId, $itemName)
        ));
    }

    private function newRole(string $suffix, Item ...$children): Role
    {
        $auth = $this->auth();
        $role = $auth->createRole('test-' . $suffix . '-' . uniqid());
        $auth->add($role);
        foreach ($children as $child) {
            $auth->addChild($role, $child);
        }

        return $role;
    }

    private function role(string $name): Role
    {
        $role = $this->auth()->getRole($name);
        $this->assertNotNull($role, $name);

        return $role;
    }

    private function permission(string $name): Permission
    {
        $permission = $this->auth()->getPermission($name);
        $this->assertNotNull($permission, $name);

        return $permission;
    }

    private function userWith(Role|Permission $item): int
    {
        $userId = (int)$this->createUser('holder')->id;
        $this->auth()->assign($item, (string)$userId);

        return $userId;
    }

    /**
     * @return list<int>
     */
    private function operators(int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = (int)$this->createUserWithRole('cost-operator', 'operator')->id;
        }

        return $ids;
    }
}
