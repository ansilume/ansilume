<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\SetupController;
use app\models\AuditLog;
use app\models\User;
use app\services\AuditService;
use app\tests\integration\DbTestCase;
use yii\console\ExitCode;

/**
 * Integration tests for SetupController::actionAdmin().
 *
 * Verifies that:
 *   - the command creates an admin user and writes ACTION_USER_CREATED to the audit log
 *   - the audit metadata includes the console source so the entry is distinguishable
 *     from browser-initiated user creation
 *   - the command refuses to run while an active superadmin exists
 *     (SetupController::ACTIVE_SUPERADMIN_EXISTS) and produces no audit entry
 *   - while no active superadmin exists, every superadmin being inactive or none
 *     left, it creates one: the recovery when no superadmin can sign in
 *   - only the first setup, run while no user exists, runs the deferred seed
 *     migrations
 *
 * The database of the tests keeps the users of earlier runs and has no
 * superadmin, so most runs here are runs while users exist.
 *
 * Regression guard: if someone removes the auditService->log() call from actionAdmin(),
 * testAdminCommandCreatesAuditLog() will fail immediately.
 */
class SetupControllerTest extends DbTestCase
{
    /** The seed migrations that setup/admin runs again on the first setup. */
    private const SEED_MIGRATIONS = [
        'm000020_000000_seed_selftest_template',
        'm000035_000000_seed_demo_project',
        'm000036_000000_assign_default_runner_group_to_seeded_templates',
    ];

    private SetupController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep the console output for the assertions, out of the test output.
        $this->ctrl = new class ('setup', \Yii::$app) extends SetupController {
            /** What the command wrote to stdout. */
            public string $out = '';

            /** What the command wrote to stderr. */
            public string $err = '';

            public function stdout($string): int
            {
                $this->out .= $string;
                return strlen($string);
            }
            public function stderr($string): int
            {
                $this->err .= $string;
                return strlen($string);
            }
        };
    }

    // -------------------------------------------------------------------------
    // Audit log is written on success
    // -------------------------------------------------------------------------

    public function testAdminCommandCreatesAuditLog(): void
    {
        $username = 'setup_admin_' . uniqid('', true);
        $email    = $username . '@example.com';

        $result = $this->ctrl->actionAdmin($username, $email, 'S3cur3P@ss!');

        $this->assertSame(ExitCode::OK, $result);

        $user = User::find()->where(['username' => $username])->one();
        $this->assertNotNull($user, 'Admin user should be persisted');

        $entry = AuditLog::find()
            ->where([
                'action'      => AuditService::ACTION_USER_CREATED,
                'object_type' => 'user',
                'object_id'   => $user->id,
            ])
            ->one();

        $this->assertNotNull($entry, 'AuditLog entry for ACTION_USER_CREATED must be written');
        $this->assertSame($user->id, $entry->user_id, 'Audit entry user_id should be the new admin user');

        $meta = json_decode((string)$entry->metadata, true);
        $this->assertSame($username, $meta['username']);
        $this->assertSame(
            'console:setup/admin',
            $meta['source'],
            'Audit metadata must identify this as a console-originated action'
        );
    }

    public function testAuditMetadataContainsEmail(): void
    {
        $username = 'setup_email_' . uniqid('', true);
        $email    = $username . '@example.com';

        $this->ctrl->actionAdmin($username, $email, 'S3cur3P@ss!');

        $user  = User::find()->where(['username' => $username])->one();
        $entry = AuditLog::find()
            ->where(['action' => AuditService::ACTION_USER_CREATED, 'object_id' => $user->id])
            ->one();

        $meta = json_decode((string)$entry->metadata, true);
        $this->assertSame($email, $meta['email']);
    }

    // -------------------------------------------------------------------------
    // No audit entry is written when the command aborts
    // -------------------------------------------------------------------------

    public function testAdminUserGetsRbacRoleAssigned(): void
    {
        $username = 'setup_rbac_' . uniqid('', true);
        $email = $username . '@example.com';

        $result = $this->ctrl->actionAdmin($username, $email, 'S3cur3P@ss!');

        $this->assertSame(ExitCode::OK, $result);

        $user = User::find()->where(['username' => $username])->one();
        $this->assertNotNull($user);

        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        $roles = $auth->getRolesByUser($user->id);
        $this->assertArrayHasKey('admin', $roles, 'Admin user must have the admin RBAC role');
    }

    public function testNoAuditLogWhenSuperadminAlreadyExists(): void
    {
        // Seed an active superadmin so the command aborts.
        $existing               = new User();
        $existing->username     = 'existing_' . uniqid('', true);
        $existing->email        = $existing->username . '@example.com';
        $existing->password_hash = \Yii::$app->security->generatePasswordHash('test');
        $existing->auth_key     = \Yii::$app->security->generateRandomString();
        $existing->status       = User::STATUS_ACTIVE;
        $existing->is_superadmin = true;
        $existing->save(false);

        $countBefore = (int)AuditLog::find()
            ->where(['action' => AuditService::ACTION_USER_CREATED])
            ->count();

        $result = $this->ctrl->actionAdmin('second_admin', 'second@example.com', 'P@ssw0rd!');

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $result);

        $countAfter = (int)AuditLog::find()
            ->where(['action' => AuditService::ACTION_USER_CREATED])
            ->count();

        $this->assertSame(
            $countBefore,
            $countAfter,
            'No audit entry should be written when the command aborts early'
        );
    }

    // -------------------------------------------------------------------------
    // While no active superadmin exists: the recovery
    // -------------------------------------------------------------------------

    /**
     * regression: setup/admin aborted while any superadmin existed, an
     * inactive one too ("A superadmin already exists. Aborting."), so once
     * every superadmin was inactive only a database edit let a superadmin
     * sign in again. It creates an active superadmin with the admin role
     * now, audited as before, and leaves the inactive one as it is.
     */
    public function testRegressionSetupAdminCreatesASuperadminWhileEverySuperadminIsInactive(): void
    {
        $inactive = $this->createUser('setup_inactive_superadmin');
        $this->makeTheOnlySuperadmins([], [$inactive]);
        $applied = $this->seedApplyTimes();
        $username = 'setup_recovery_' . uniqid('', true);

        $result = $this->ctrl->actionAdmin($username, $username . '@example.com', 'S3cur3P@ss!');

        $this->assertSame(ExitCode::OK, $result, $this->ctrl->out . $this->ctrl->err);
        $user = User::findOne(['username' => $username]);
        $this->assertNotNull($user);
        $this->assertSame([User::STATUS_ACTIVE, true], [(int)$user->status, (bool)$user->is_superadmin]);
        $this->assertTrue($user->validatePassword('S3cur3P@ss!'));
        $this->assertArrayHasKey('admin', $this->auth()->getAssignments((string)$user->id), 'the admin role');
        $this->assertStringContainsString("Admin user '{$username}' created with ID {$user->id}.\n", $this->ctrl->out);
        $entry = AuditLog::findOne(['action' => AuditService::ACTION_USER_CREATED, 'object_type' => 'user', 'object_id' => $user->id]);
        $this->assertNotNull($entry);
        $this->assertSame('console:setup/admin', json_decode((string)$entry->metadata, true)['source'] ?? null);
        $this->assertSame($applied, $this->seedApplyTimes(), 'the deferred seed migrations did not run');

        $kept = User::findOne($inactive->id);
        $this->assertNotNull($kept);
        $this->assertSame([User::STATUS_INACTIVE, true], [(int)$kept->status, (bool)$kept->is_superadmin], 'the inactive superadmin stays as they are');
    }

    /**
     * regression: a run while users existed reset the deferred seed
     * migrations (m000020, m000035, m000036) and ran them again: they create
     * the Selftest and Demo content an operator removed, queue a clone of
     * the demo repository, and give the default runner group to seeded
     * templates whose runner group is gone. Only the first setup runs them
     * now. Here no superadmin is left at all, which made the run create one
     * before as well; the seed migrations keep the time they were applied.
     */
    public function testRegressionARunWhileUsersExistLeavesTheDeferredSeedsAlone(): void
    {
        $this->createUser('setup_existing');
        $this->makeTheOnlySuperadmins([], []);
        $applied = $this->seedApplyTimes();
        $this->assertCount(count(self::SEED_MIGRATIONS), $applied, 'precondition: the seed migrations are applied');
        $username = 'setup_no_seeds_' . uniqid('', true);

        $result = $this->ctrl->actionAdmin($username, $username . '@example.com', 'S3cur3P@ss!');

        $this->assertSame(ExitCode::OK, $result, $this->ctrl->out . $this->ctrl->err);
        $this->assertNotNull(User::findOne(['username' => $username, 'is_superadmin' => true, 'status' => User::STATUS_ACTIVE]));
        $this->assertSame($applied, $this->seedApplyTimes(), 'the deferred seed migrations did not run again');
    }

    /**
     * The first setup, run while no user exists, still runs the deferred
     * seed migrations: they are reset and applied again. The test deletes
     * every user inside its transaction and swaps in a queue that keeps its
     * jobs in memory, so that the sync job of the demo project reaches no
     * worker.
     */
    public function testTheFirstSetupRunsTheDeferredSeeds(): void
    {
        $this->assertCount(count(self::SEED_MIGRATIONS), $this->seedApplyTimes(), 'precondition: the seed migrations are applied');
        $startedAt = time();
        $db = \Yii::$app->db;
        $db->createCommand('SET FOREIGN_KEY_CHECKS = 0')->execute();
        try {
            $db->createCommand()->delete(User::tableName())->execute();
        } finally {
            $db->createCommand('SET FOREIGN_KEY_CHECKS = 1')->execute();
        }
        $this->assertFalse(User::find()->exists(), 'precondition: no user exists');
        $components = \Yii::$app->getComponents(true);
        $queue = $components['queue'] ?? null;
        \Yii::$app->set('queue', new \yii\queue\sync\Queue());
        $username = 'setup_first_' . uniqid('', true);

        try {
            $result = $this->ctrl->actionAdmin($username, $username . '@example.com', 'S3cur3P@ss!');
        } finally {
            \Yii::$app->set('queue', $queue);
        }

        $this->assertSame(ExitCode::OK, $result, $this->ctrl->out . $this->ctrl->err);
        $this->assertNotNull(User::findOne(['username' => $username, 'is_superadmin' => true]));
        $applied = $this->seedApplyTimes();
        $this->assertSame(self::SEED_MIGRATIONS, array_keys($applied), 'every seed migration is applied');
        foreach ($applied as $version => $appliedAt) {
            $this->assertGreaterThanOrEqual($startedAt, $appliedAt, "{$version} was applied again");
        }
    }

    /**
     * An active superadmin makes setup/admin abort with
     * ACTIVE_SUPERADMIN_EXISTS, though an inactive superadmin exists beside
     * them: nothing is created or audited.
     */
    public function testAnActiveSuperadminMakesSetupAdminAbort(): void
    {
        $this->makeTheOnlySuperadmins([$this->createUser('setup_active_superadmin')], [$this->createUser('setup_inactive_superadmin')]);
        $users = (int)User::find()->count();
        $created = (int)AuditLog::find()->where(['action' => AuditService::ACTION_USER_CREATED])->count();

        $result = $this->ctrl->actionAdmin('setup_refused_' . uniqid(), 'setup-refused-' . uniqid() . '@example.com', 'S3cur3P@ss!');

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $result);
        $this->assertSame("An active superadmin already exists. Aborting.\n", $this->ctrl->out);
        $this->assertSame(SetupController::ACTIVE_SUPERADMIN_EXISTS . "\n", $this->ctrl->out);
        $this->assertSame($users, (int)User::find()->count(), 'nothing is created');
        $this->assertSame($created, (int)AuditLog::find()->where(['action' => AuditService::ACTION_USER_CREATED])->count());
    }

    /**
     * A username that is taken, such as that of the inactive superadmin a
     * new one replaces, fails as before: nothing is created or audited.
     */
    public function testATakenUsernameFailsWhileEverySuperadminIsInactive(): void
    {
        $inactive = $this->createUser('setup_taken');
        $this->makeTheOnlySuperadmins([], [$inactive]);
        $users = (int)User::find()->count();
        $created = (int)AuditLog::find()->where(['action' => AuditService::ACTION_USER_CREATED])->count();

        $result = $this->ctrl->actionAdmin($inactive->username, 'setup-taken-' . uniqid() . '@example.com', 'S3cur3P@ss!');

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $result);
        $this->assertStringStartsWith('Failed to create user: ', $this->ctrl->err);
        $this->assertStringContainsString('has already been taken', $this->ctrl->err);
        $this->assertSame($users, (int)User::find()->count(), 'nothing is created');
        $this->assertSame($created, (int)AuditLog::find()->where(['action' => AuditService::ACTION_USER_CREATED])->count());
    }

    /**
     * bin/quickstart reads the output of setup/admin: "already exists" as an
     * admin who is there already, "created with ID" as a new one. The abort
     * text keeps the words it looks for.
     */
    public function testTheAbortTextKeepsWhatQuickstartLooksFor(): void
    {
        $quickstart = (string)file_get_contents(dirname(__DIR__, 3) . '/bin/quickstart');

        $this->assertStringContainsString('*"already exists"*)', $quickstart);
        $this->assertStringContainsString('already exists', SetupController::ACTIVE_SUPERADMIN_EXISTS);
        $this->assertStringContainsString('*"created with ID"*)', $quickstart);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Makes the users of $active and $inactive the only superadmins, inside
     * the test's transaction, those of $inactive deactivated.
     *
     * @param list<User> $active
     * @param list<User> $inactive
     */
    private function makeTheOnlySuperadmins(array $active, array $inactive): void
    {
        User::updateAll(['is_superadmin' => 0], ['is_superadmin' => 1]);
        foreach ($active as $user) {
            $user->refresh();
            $user->is_superadmin = true;
            $user->status = User::STATUS_ACTIVE;
            $user->save(false);
        }
        foreach ($inactive as $user) {
            $user->refresh();
            $user->is_superadmin = true;
            $user->status = User::STATUS_INACTIVE;
            $user->save(false);
        }
        $this->assertSame(count($active), (int)User::find()->where(['is_superadmin' => 1, 'status' => User::STATUS_ACTIVE])->count());
        $this->assertSame(count($active) + count($inactive), (int)User::find()->where(['is_superadmin' => 1])->count());
    }

    /**
     * The time each deferred seed migration was applied at, by version,
     * sorted by version.
     *
     * @return array<string, int>
     */
    private function seedApplyTimes(): array
    {
        $times = (new \yii\db\Query())
            ->select('apply_time')
            ->from('{{%migration}}')
            ->where(['version' => self::SEED_MIGRATIONS])
            ->indexBy('version')
            ->orderBy(['version' => SORT_ASC])
            ->column();

        return array_map('intval', $times);
    }

    private function auth(): \yii\rbac\ManagerInterface
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);

        return $auth;
    }
}
