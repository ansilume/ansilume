<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\services\UserPrivileges;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\rbac\DbManager;

/**
 * UserPrivileges: the roles assigned to a user and their superadmin flag,
 * and the {from, to} of their change that user.created and user.updated
 * record.
 */
final class UserPrivilegesTest extends DbTestCase
{
    use TeamScopeFixtures;

    public function testOfReadsTheAssignedRolesSortedByNameAndTheFlag(): void
    {
        $auth = $this->auth();
        $saved = array_map('strval', $auth->defaultRoles);
        $auth->defaultRoles = ['viewer'];
        try {
            $user = $this->createUserWithRole('privileges-of', 'operator');
            $this->assignRole($user->id, 'admin');
            $user->is_superadmin = true;
            $user->save(false);

            $privileges = UserPrivileges::of($user);

            $this->assertSame(['admin', 'operator'], $privileges->roles, 'assigned roles only, never the default role');
            $this->assertTrue($privileges->superadmin);
            $this->assertSame([], UserPrivileges::of($this->createUser('privileges-none'))->roles);
            $this->assertFalse(UserPrivileges::of($this->createUser('privileges-flag'))->superadmin);
        } finally {
            $auth->defaultRoles = $saved;
        }
    }

    public function testACreatedUserRecordsBothFromNull(): void
    {
        $this->assertSame(
            ['roles' => ['from' => null, 'to' => ['operator']], 'is_superadmin' => ['from' => null, 'to' => false]],
            (new UserPrivileges(['operator'], false))->changesSince(null)
        );
        $this->assertSame(
            ['roles' => ['from' => null, 'to' => []], 'is_superadmin' => ['from' => null, 'to' => true]],
            (new UserPrivileges([], true))->changesSince(null),
            'a user created without a role'
        );
    }

    public function testAnUpdateRecordsOnlyWhatChanged(): void
    {
        $before = new UserPrivileges(['approvers', 'operator'], false);

        $this->assertSame([], (new UserPrivileges(['approvers', 'operator'], false))->changesSince($before), 'nothing changed');
        $this->assertSame(
            ['roles' => ['from' => ['approvers', 'operator'], 'to' => ['viewer']]],
            (new UserPrivileges(['viewer'], false))->changesSince($before),
            'the roles: every role that went, not only the first'
        );
        $this->assertSame(
            ['is_superadmin' => ['from' => false, 'to' => true]],
            (new UserPrivileges(['approvers', 'operator'], true))->changesSince($before)
        );
        $this->assertSame(
            ['roles' => ['from' => ['approvers', 'operator'], 'to' => ['admin']], 'is_superadmin' => ['from' => false, 'to' => true]],
            (new UserPrivileges(['admin'], true))->changesSince($before)
        );
    }

    private function auth(): DbManager
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(DbManager::class, $auth);

        return $auth;
    }
}
