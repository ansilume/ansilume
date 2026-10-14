<?php

declare(strict_types=1);

namespace app\tests\integration\components;

use app\components\AuthManager;
use app\models\User;
use app\tests\integration\DbTestCase;

/**
 * app\components\AuthManager answers as yii\rbac\DbManager, with the default
 * role viewer of the web and console applications, except that a user ID
 * that names no user holds nothing.
 *
 * regression: signed-out visitors held the default role viewer.
 * DbManager applied the default roles to the user ID null that
 * yii\web\User::getId() answers for a visitor who has not signed in, so every
 * access rule that names a viewer permission let them in (credential/index,
 * project/index, runner-group/index and more). Every no-user case below
 * holds the viewer's permissions with DbManager.
 */
final class AuthManagerTest extends DbTestCase
{
    /** The default role of the web and console applications (config/web.php). */
    private const DEFAULT_ROLES = ['viewer'];

    /**
     * Values that name no user: what yii\web\User::getId() answers for a
     * signed-out visitor (null), what code makes of it ('' and 0), and every
     * other value that is not an int from 1 up or its canonical decimal
     * string.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function noUserProvider(): array
    {
        return [
            'null, a signed-out visitor' => [null],
            'an empty string' => [''],
            'int 0' => [0],
            "'0'" => ['0'],
            "'07'" => ['07'],
            "'+7'" => ['+7'],
            "' 7'" => [' 7'],
            "'7 '" => ['7 '],
            "'7' and a line feed" => ["7\n"],
            'int -1' => [-1],
            "'-1'" => ['-1'],
            "'abc'" => ['abc'],
            "'1e3'" => ['1e3'],
            'a decimal string beyond PHP_INT_MAX' => ['9223372036854775808'],
            'float 1.0' => [1.0],
            'true' => [true],
            'false' => [false],
            'an array' => [[7]],
        ];
    }

    /**
     * Every role and every permission is refused to a value that names no
     * user, the default role viewer and its permissions included.
     *
     * @dataProvider noUserProvider
     */
    public function testAValueThatNamesNoUserHoldsNothing(mixed $userId): void
    {
        $auth = $this->authManager();
        $items = $this->everyItem($auth);

        $held = array_values(array_filter(
            $items,
            static fn (string $item): bool => $auth->checkAccess($userId, $item)
        ));

        $this->assertSame([], $held, 'items held by a value that names no user');
    }

    /**
     * A user without a role holds the default role viewer and its
     * permissions, named by its ID or by its ID as a string, and nothing the
     * operator role adds.
     */
    public function testARolelessUserHoldsTheViewersPermissionsAndNotTheOperators(): void
    {
        $auth = $this->authManager();
        $user = $this->createUser('authmanager_roleless');
        $this->assertSame([], $auth->getAssignments((string)$user->id), 'precondition: no role is assigned');
        $viewer = $this->permissionsOf($auth, 'viewer');
        $operatorOnly = array_values(array_diff($this->permissionsOf($auth, 'operator'), $viewer));
        $this->assertContains('credential.view', $viewer, 'precondition: the viewer role');
        $this->assertContains('job.launch', $operatorOnly, 'precondition: the operator role');

        foreach (['the ID' => (int)$user->id, 'the ID as a string' => (string)$user->id] as $named => $userId) {
            $this->assertTrue($auth->checkAccess($userId, 'viewer'), "the default role, by {$named}");
            $this->assertFalse($auth->checkAccess($userId, 'operator'), "the operator role, by {$named}");
            foreach ($viewer as $permission) {
                $this->assertTrue($auth->checkAccess($userId, $permission), "{$permission} by {$named}");
            }
            foreach ($operatorOnly as $permission) {
                $this->assertFalse($auth->checkAccess($userId, $permission), "{$permission} by {$named}");
            }
        }
    }

    /**
     * An assigned role grants its permissions, and nothing above it.
     */
    public function testAnAssignedRoleGrantsItsPermissions(): void
    {
        $auth = $this->authManager();
        $user = $this->createUser('authmanager_operator');
        $role = $auth->getRole('operator');
        $this->assertNotNull($role);
        $auth->assign($role, (string)$user->id);
        $operator = $this->permissionsOf($auth, 'operator');
        $adminOnly = array_values(array_diff($this->permissionsOf($auth, 'admin'), $operator));
        $this->assertContains('user.view', $adminOnly, 'precondition: the admin role');

        $this->assertTrue($auth->checkAccess((int)$user->id, 'operator'));
        $this->assertFalse($auth->checkAccess((int)$user->id, 'admin'));
        foreach ($operator as $permission) {
            $this->assertTrue($auth->checkAccess((int)$user->id, $permission), $permission);
        }
        foreach ($adminOnly as $permission) {
            $this->assertFalse($auth->checkAccess((int)$user->id, $permission), $permission);
        }
    }

    /**
     * Through yii\web\User::can(), as the access filters ask: a signed-in
     * user without a role holds the default role, a signed-out visitor does
     * not.
     */
    public function testTheDefaultRoleAppliesToSignedInUsersOnly(): void
    {
        $auth = $this->authManager();
        $signedIn = $this->webUser($auth);
        $signedIn->setIdentity($this->createUser('authmanager_signed_in'));
        $signedOut = $this->webUser($auth);

        $this->assertFalse($signedIn->getIsGuest());
        $this->assertTrue($signedIn->can('credential.view'));
        $this->assertTrue($signedOut->getIsGuest());
        $this->assertNull($signedOut->getId());
        $this->assertFalse($signedOut->can('credential.view'));
    }

    private function authManager(): AuthManager
    {
        return new AuthManager(['defaultRoles' => self::DEFAULT_ROLES]);
    }

    /**
     * @return \yii\web\User<\yii\web\IdentityInterface>
     */
    private function webUser(AuthManager $auth): \yii\web\User
    {
        return new \yii\web\User([
            'identityClass' => User::class,
            'enableSession' => false,
            'enableAutoLogin' => false,
            'accessChecker' => $auth,
        ]);
    }

    /**
     * The names of every role and every permission.
     *
     * @return list<string>
     */
    private function everyItem(AuthManager $auth): array
    {
        $items = array_map('strval', array_merge(array_keys($auth->getRoles()), array_keys($auth->getPermissions())));
        $this->assertContains('viewer', $items, 'precondition: the roles are seeded');
        $this->assertContains('credential.view', $items, 'precondition: the permissions are seeded');

        return $items;
    }

    /**
     * @return list<string> the permissions of $role, those of the roles below it included
     */
    private function permissionsOf(AuthManager $auth, string $role): array
    {
        return array_map('strval', array_keys($auth->getPermissionsByRole($role)));
    }
}
