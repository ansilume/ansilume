<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eRoleManagerSeeder;
use app\models\User;
use app\services\AssignedRoles;
use app\services\RoleChangePolicy;
use app\tests\integration\DbTestCase;
use yii\rbac\DbManager;

/**
 * The user of tests/e2e/tests/roles/role-managers.spec.ts
 * (E2eRoleManagerSeeder): a role manager who may change only roles they may
 * assign and grant only what they hold. The rollback removes the user and
 * the role.
 */
class E2eRoleManagerSeederTest extends DbTestCase
{
    /** The fixture password of tests/e2e/lib/session.ts; not a secret. */
    private const FIXTURE_PASSWORD = 'E2eRoleEditorPass1!';

    /** @var list<string> */
    private array $log = [];

    /** @var list<string> the default roles of the manager before the test */
    private array $savedDefaultRoles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = [];
        $auth = $this->auth();
        $this->savedDefaultRoles = array_map('strval', $auth->defaultRoles);
        // As config/web.php, which the spec runs against.
        $auth->defaultRoles = ['viewer'];
    }

    protected function tearDown(): void
    {
        $this->auth()->defaultRoles = $this->savedDefaultRoles;
        parent::tearDown();
    }

    /**
     * What the spec relies on: the admin role is not theirs to change, the
     * permissions they lack are not theirs to grant, and the ones they hold
     * are.
     */
    public function testTheRoleManagerMayChangeOnlyRolesTheyMayAssignAndGrantOnlyWhatTheyHold(): void
    {
        $this->seed();

        $editor = $this->user();
        $auth = $this->auth();
        $this->assertSame([E2eRoleManagerSeeder::ROLE], AssignedRoles::fromApp()->of((int)$editor->id));
        $this->assertEqualsCanonicalizing(E2eRoleManagerSeeder::PERMISSIONS, array_keys($auth->getChildren(E2eRoleManagerSeeder::ROLE)));
        $policy = new RoleChangePolicy($auth, $editor);
        $this->assertFalse($policy->mayChange('admin'));
        $this->assertFalse($policy->mayChange('operator'));
        $this->assertTrue($policy->mayChange(E2eRoleManagerSeeder::ROLE));
        $this->assertSame('user.update', $policy->firstRefused(['role.view', 'project.view', 'user.update']));
    }

    public function testTheUserIsAnActiveNonSuperadminWithTheFixturePassword(): void
    {
        $this->seed();

        $editor = $this->user();
        $this->assertSame(User::STATUS_ACTIVE, (int)$editor->status);
        $this->assertFalse((bool)$editor->is_superadmin);
        $this->assertSame(E2eRoleManagerSeeder::USERNAME . '@example.com', $editor->email);
        $this->assertTrue($editor->validatePassword(self::FIXTURE_PASSWORD));
        $this->assertSame(
            ["  Restored user 'e2e-role-editor' (ID {$editor->id}) with the only role 'e2e-role-manager'.\n"],
            $this->log
        );
    }

    /**
     * A spec, or a person, may have changed the fixtures: a seed restores
     * the user, their role and its permissions, and keeps the ID.
     */
    public function testSeedingAgainRestoresTheUserAndTheRole(): void
    {
        $this->seed();
        $editor = $this->user();
        $editor->status = User::STATUS_INACTIVE;
        $editor->is_superadmin = true;
        $editor->setPassword('Changed-by-a-spec-1!');
        $editor->save(false);
        $auth = $this->auth();
        $admin = $auth->getRole('admin');
        $role = $auth->getRole(E2eRoleManagerSeeder::ROLE);
        $grant = $auth->getPermission('user.update');
        $this->assertNotNull($admin);
        $this->assertNotNull($role);
        $this->assertNotNull($grant);
        $auth->assign($admin, (int)$editor->id);
        $auth->addChild($role, $grant);

        $this->seed();

        $restored = $this->user();
        $this->assertSame((int)$editor->id, (int)$restored->id);
        $this->assertSame(1, (int)User::find()->where(['username' => E2eRoleManagerSeeder::USERNAME])->count());
        $this->assertSame(User::STATUS_ACTIVE, (int)$restored->status);
        $this->assertFalse((bool)$restored->is_superadmin);
        $this->assertTrue($restored->validatePassword(self::FIXTURE_PASSWORD));
        $this->assertSame([E2eRoleManagerSeeder::ROLE], AssignedRoles::fromApp()->of((int)$restored->id));
        $this->assertEqualsCanonicalizing(E2eRoleManagerSeeder::PERMISSIONS, array_keys($auth->getChildren(E2eRoleManagerSeeder::ROLE)));
        $this->assertFalse($auth->checkAccess((int)$restored->id, 'user.update'));
    }

    public function testAUserThatCannotBeSavedIsReportedAndGetsNoRole(): void
    {
        // The e-mail address is taken by another user.
        $other = $this->createUser('other');
        $other->email = E2eRoleManagerSeeder::USERNAME . '@example.com';
        $other->save(false);

        $this->seed();

        $this->assertNull(User::findOne(['username' => E2eRoleManagerSeeder::USERNAME]));
        $this->assertCount(1, $this->log);
        $this->assertStringStartsWith("  Failed to restore user 'e2e-role-editor': {", $this->log[0]);
        $this->assertStringContainsString('email', $this->log[0]);
        $this->assertNotNull($this->auth()->getRole(E2eRoleManagerSeeder::ROLE), 'the role is restored first');
    }

    private function seed(): void
    {
        (new E2eRoleManagerSeeder(function (string $message): void {
            $this->log[] = $message;
        }))->seed();
    }

    private function user(): User
    {
        $user = User::findOne(['username' => E2eRoleManagerSeeder::USERNAME]);
        $this->assertInstanceOf(User::class, $user);

        return $user;
    }

    private function auth(): DbManager
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(DbManager::class, $auth);

        return $auth;
    }
}
