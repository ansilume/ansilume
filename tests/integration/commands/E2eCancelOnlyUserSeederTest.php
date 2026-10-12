<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eCancelOnlyUserSeeder;
use app\models\User;
use app\tests\integration\DbTestCase;
use yii\rbac\ManagerInterface;

/**
 * The user of the re-launch permission spec (E2eCancelOnlyUserSeeder): it
 * may view and cancel jobs but not launch them. The rollback removes the
 * user and the role.
 */
class E2eCancelOnlyUserSeederTest extends DbTestCase
{
    /** The fixture password of tests/e2e/lib/session.ts; not a secret. */
    private const FIXTURE_PASSWORD = 'E2eCancelerPass1!';

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = [];
    }

    public function testTheUserMayViewAndCancelJobsButNotLaunchThem(): void
    {
        $this->seed();

        $user = $this->user();
        $auth = $this->auth();
        $this->assertTrue($auth->checkAccess((int)$user->id, 'job.view'));
        $this->assertTrue($auth->checkAccess((int)$user->id, 'job.cancel'));
        $this->assertFalse($auth->checkAccess((int)$user->id, 'job.launch'));
        $this->assertSame([E2eCancelOnlyUserSeeder::ROLE], array_keys($auth->getRolesByUser((int)$user->id)));
        $this->assertEqualsCanonicalizing(
            E2eCancelOnlyUserSeeder::PERMISSIONS,
            array_keys($auth->getChildren(E2eCancelOnlyUserSeeder::ROLE))
        );
    }

    public function testTheUserIsAnActiveNonSuperadminWithTheFixturePassword(): void
    {
        $this->seed();

        $user = $this->user();
        $this->assertSame(User::STATUS_ACTIVE, (int)$user->status);
        $this->assertFalse((bool)$user->is_superadmin);
        $this->assertTrue($user->validatePassword(self::FIXTURE_PASSWORD));
        $this->assertSame(
            ["  Restored user '" . E2eCancelOnlyUserSeeder::USERNAME . "' (ID {$user->id}) with the only role '" . E2eCancelOnlyUserSeeder::ROLE . "'.\n"],
            $this->log
        );
    }

    public function testSeedingAgainRestoresTheUserAndTheRole(): void
    {
        $this->seed();
        $user = $this->user();
        $user->status = User::STATUS_INACTIVE;
        $user->setPassword('Changed-by-a-spec-1!');
        $user->save(false);
        $auth = $this->auth();
        $viewer = $auth->getRole('viewer');
        $this->assertNotNull($viewer);
        $auth->assign($viewer, (int)$user->id);
        $role = $auth->getRole(E2eCancelOnlyUserSeeder::ROLE);
        $launch = $auth->getPermission('job.launch');
        $this->assertNotNull($role);
        $this->assertNotNull($launch);
        $auth->addChild($role, $launch);

        $this->seed();

        $restored = $this->user();
        $this->assertSame((int)$user->id, (int)$restored->id);
        $this->assertSame(1, (int)User::find()->where(['username' => E2eCancelOnlyUserSeeder::USERNAME])->count());
        $this->assertSame(User::STATUS_ACTIVE, (int)$restored->status);
        $this->assertTrue($restored->validatePassword(self::FIXTURE_PASSWORD));
        $this->assertSame([E2eCancelOnlyUserSeeder::ROLE], array_keys($auth->getRolesByUser((int)$restored->id)));
        $this->assertFalse($auth->checkAccess((int)$restored->id, 'job.launch'));
    }

    public function testAUserThatCannotBeSavedIsReportedAndGetsNoRole(): void
    {
        // The e-mail address is taken by another user.
        $other = $this->createUser('other');
        $other->email = E2eCancelOnlyUserSeeder::USERNAME . '@example.com';
        $other->save(false);

        $this->seed();

        $this->assertNull(User::findOne(['username' => E2eCancelOnlyUserSeeder::USERNAME]));
        $this->assertCount(1, $this->log);
        $this->assertStringStartsWith("  Failed to restore user '" . E2eCancelOnlyUserSeeder::USERNAME . "': ", $this->log[0]);
        $this->assertStringNotContainsString(self::FIXTURE_PASSWORD, $this->log[0]);
    }

    private function seed(): void
    {
        (new E2eCancelOnlyUserSeeder(function (string $msg): void {
            $this->log[] = $msg;
        }))->seed();
    }

    private function user(): User
    {
        $user = User::findOne(['username' => E2eCancelOnlyUserSeeder::USERNAME]);
        $this->assertInstanceOf(User::class, $user);

        return $user;
    }

    private function auth(): ManagerInterface
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(ManagerInterface::class, $auth);

        return $auth;
    }
}
