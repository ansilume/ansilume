<?php

declare(strict_types=1);

namespace app\tests\integration\migrations;

use app\models\Runner;
use app\tests\integration\DbTestCase;
use yii\helpers\FileHelper;
use yii\rbac\Permission;
use yii\rbac\PhpManager;
use yii\rbac\Role;

/**
 * m000087_000000_restrict_runner_management: the operator role loses
 * runner-group.create and runner-group.update; the admin role holds both
 * directly first, so admins never lose them. What operator includes keeps
 * what it holds (RestrictRunnerManagementStillGrantedTest). The RBAC rows
 * change inside the test's transaction and are rolled back with it.
 *
 * The test database is migrated already, so each test first puts back the
 * grants of m000025 that the migration removes.
 */
class RestrictRunnerManagementMigrationTest extends DbTestCase
{
    use RunsRunnerManagementMigration;

    private const PERMISSIONS = ['runner-group.create', 'runner-group.update'];

    /** What the migration prints when it takes both permissions from operators. */
    private const TAKEN_FROM_OPERATORS = "    > Removed runner-group.create from the operator role.\n"
        . "    > Removed runner-group.update from the operator role.\n"
        . '    > Operators no longer register, move or delete runners, regenerate runner tokens, '
        . 'or create and change runner groups. To let other users do so, give them a custom role with '
        . "runner-group.create and runner-group.update; with it they can take over the jobs of every runner group.\n";

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/m000087_000000_restrict_runner_management.php';
    }

    /**
     * Regression: an operator could register a runner in any runner group, or
     * regenerate any runner's token and so take the runner over. A runner
     * claims every queued job of its group, whatever the team, and receives
     * its decrypted credentials, inventory and extra vars.
     */
    public function testUpTakesCreateAndUpdateFromOperators(): void
    {
        $this->grantAsBefore();

        $output = $this->up();

        foreach (self::PERMISSIONS as $name) {
            $this->assertFalse($this->auth()->hasChild($this->role('operator'), $this->permission($name)), "operator: {$name}");
            $this->assertTrue($this->auth()->hasChild($this->role('admin'), $this->permission($name)), "admin: {$name}");
        }
        $this->assertSame(self::TAKEN_FROM_OPERATORS, $output);
    }

    public function testThePrintedLinesAreTheMigrationsConstants(): void
    {
        $this->grantAsBefore();

        $output = $this->up();

        $this->assertSame(
            '    > ' . sprintf(\m000087_000000_restrict_runner_management::REMOVED_FROM_OPERATOR, 'runner-group.create') . "\n"
            . '    > ' . sprintf(\m000087_000000_restrict_runner_management::REMOVED_FROM_OPERATOR, 'runner-group.update') . "\n"
            . '    > ' . \m000087_000000_restrict_runner_management::RUNNERS_FOR_ADMINS . "\n",
            $output
        );
        $this->assertSame(self::PERMISSIONS, \m000087_000000_restrict_runner_management::PERMISSIONS);
    }

    public function testOperatorsKeepSeeingRunnerGroupsAndViewersAreUnchanged(): void
    {
        $this->grantAsBefore();
        $operator = $this->createUser('runner_mgmt_operator');
        $this->auth()->assign($this->role('operator'), (string)$operator->id);
        $viewer = $this->createUser('runner_mgmt_viewer');
        $this->auth()->assign($this->role('viewer'), (string)$viewer->id);

        $this->up();

        foreach ([(int)$operator->id, (int)$viewer->id] as $userId) {
            $this->assertTrue($this->auth()->checkAccess($userId, 'runner-group.view'));
            $this->assertFalse($this->auth()->checkAccess($userId, 'runner-group.create'));
            $this->assertFalse($this->auth()->checkAccess($userId, 'runner-group.update'));
            $this->assertFalse($this->auth()->checkAccess($userId, 'runner-group.delete'));
        }
        $this->assertTrue($this->auth()->checkAccess((int)$operator->id, 'job.launch'), 'operators keep the rest of their role');
    }

    /**
     * The admin role inherits operator. Where the role editor removed admin's
     * direct grants, admins held the permissions only through operator: the
     * migration grants them to admin directly before operator loses them.
     */
    public function testAdminsWhoHeldThemOnlyThroughOperatorKeepThem(): void
    {
        $this->grantAsBefore();
        foreach (self::PERMISSIONS as $name) {
            $this->auth()->removeChild($this->role('admin'), $this->permission($name));
        }
        $admin = $this->createUser('runner_mgmt_admin');
        $this->auth()->assign($this->role('admin'), (string)$admin->id);

        $output = $this->up();

        foreach (self::PERMISSIONS as $name) {
            $this->assertTrue($this->auth()->hasChild($this->role('admin'), $this->permission($name)), "admin holds {$name} directly");
            $this->assertTrue($this->auth()->checkAccess((int)$admin->id, $name), $name);
            $this->assertFalse($this->auth()->hasChild($this->role('operator'), $this->permission($name)), "operator: {$name}");
        }
        $this->assertStringStartsWith(
            "    > Granted runner-group.create to the admin role directly.\n"
            . "    > Removed runner-group.create from the operator role.\n"
            . "    > Granted runner-group.update to the admin role directly.\n"
            . "    > Removed runner-group.update from the operator role.\n",
            $output
        );
    }

    public function testCustomRolesKeepWhatTheyWereGranted(): void
    {
        $this->grantAsBefore();
        $custom = $this->auth()->createRole('runner-managers-' . uniqid());
        $this->auth()->add($custom);
        foreach (self::PERMISSIONS as $name) {
            $this->auth()->addChild($custom, $this->permission($name));
        }
        $manager = $this->createUser('runner_mgmt_custom');
        $this->auth()->assign($custom, (string)$manager->id);

        $this->up();

        foreach (self::PERMISSIONS as $name) {
            $this->assertTrue($this->auth()->hasChild($custom, $this->permission($name)), $name);
            $this->assertTrue($this->auth()->checkAccess((int)$manager->id, $name), "a custom role still lets its users: {$name}");
        }
    }

    /**
     * Existing runners keep running: the migration changes RBAC rows only,
     * so a runner's token still names it and it stays in its group.
     */
    public function testExistingRunnersKeepTheirTokensAndGroups(): void
    {
        $this->grantAsBefore();
        $owner = $this->createUser('runner_mgmt_owner');
        $group = $this->createRunnerGroup((int)$owner->id);
        $runner = $this->createRunner((int)$group->id, (int)$owner->id);
        $token = Runner::generateToken();
        $runner->token_hash = $token['hash'];
        $runner->save(false);

        $this->up();

        $found = Runner::findByToken($token['raw']);
        $this->assertNotNull($found, 'the runner token still works');
        $this->assertSame((int)$runner->id, (int)$found->id);
        $this->assertSame((int)$group->id, (int)$found->runner_group_id);
    }

    public function testRunningItAgainChangesNothing(): void
    {
        $this->grantAsBefore();
        $this->up();
        $before = $this->grants();

        $output = $this->up();

        $this->assertSame('', $output);
        $this->assertSame($before, $this->grants());
    }

    /**
     * An admin who took a permission from both operator and admin through
     * the role editor keeps it that way: the migration takes nothing and
     * grants nothing. Shown with a file-based RBAC manager, so the shared
     * roles of the test database stay as they are.
     */
    public function testAPermissionNeitherRoleHoldsIsLeftAlone(): void
    {
        $this->withFileRbac(function (PhpManager $auth): void {
            $operator = $this->addRole($auth, 'operator');
            $admin = $this->addRole($auth, 'admin');
            $auth->addChild($admin, $operator);
            $create = $this->addPermission($auth, 'runner-group.create');
            $update = $this->addPermission($auth, 'runner-group.update');
            $auth->addChild($operator, $update);

            $output = $this->up();

            $this->assertSame(
                "    > Granted runner-group.update to the admin role directly.\n"
                . "    > Removed runner-group.update from the operator role.\n"
                . '    > ' . \m000087_000000_restrict_runner_management::RUNNERS_FOR_ADMINS . "\n",
                $output
            );
            $this->assertFalse($auth->hasChild($admin, $create), 'admin did not hold runner-group.create and does not get it');
            $this->assertFalse($auth->hasChild($operator, $create));
            $this->assertTrue($auth->hasChild($admin, $update));
            $this->assertFalse($auth->hasChild($operator, $update));
        });
    }

    /**
     * An admin role that does not inherit operator held the permissions only
     * where it holds them directly: it gets nothing from operator's grants.
     */
    public function testAnAdminRoleThatDoesNotInheritOperatorGainsNothing(): void
    {
        $this->withFileRbac(function (PhpManager $auth): void {
            $operator = $this->addRole($auth, 'operator');
            $admin = $this->addRole($auth, 'admin');
            foreach (self::PERMISSIONS as $name) {
                $auth->addChild($operator, $this->addPermission($auth, $name));
            }

            $output = $this->up();

            $this->assertStringNotContainsString('Granted', $output);
            $this->assertSame([], $auth->getChildren($admin->name));
            $this->assertSame([], $auth->getChildren($operator->name));
        });
    }

    /**
     * An installation without one of the permissions, or without the admin
     * role: a line says so and the rest is still done, nothing fails.
     */
    public function testAMissingPermissionOrAdminRoleIsReportedAndSkipped(): void
    {
        $this->withFileRbac(function (PhpManager $auth): void {
            $operator = $this->addRole($auth, 'operator');
            $update = $this->addPermission($auth, 'runner-group.update');
            $auth->addChild($operator, $update);

            $output = $this->up();

            $this->assertSame(
                "    > Role admin does not exist: nothing to change for it.\n"
                . "    > Permission runner-group.create does not exist: nothing to change.\n"
                . "    > Removed runner-group.update from the operator role.\n"
                . '    > ' . \m000087_000000_restrict_runner_management::RUNNERS_FOR_ADMINS . "\n",
                $output
            );
            $this->assertFalse($auth->hasChild($operator, $update));
            $this->assertNull($auth->getRole('admin'), 'up does not create roles');

            $this->migration()->safeDown();

            $this->assertTrue($auth->hasChild($operator, $update), 'down skips the missing permission and restores the other');
            $this->assertNull($auth->getPermission('runner-group.create'));
        });
    }

    public function testWithoutAnOperatorRoleThereIsNothingToDo(): void
    {
        $this->withFileRbac(function (PhpManager $auth): void {
            $admin = $this->addRole($auth, 'admin');
            foreach (self::PERMISSIONS as $name) {
                $auth->addChild($admin, $this->addPermission($auth, $name));
            }

            $this->assertSame(
                '    > ' . sprintf(\m000087_000000_restrict_runner_management::ROLE_MISSING, 'operator') . "\n",
                $this->up()
            );
            $this->assertSame("    > Role operator does not exist: nothing to change for it.\n", $this->up());
            $this->migration()->safeDown();

            $this->assertNull($auth->getRole('operator'), 'down does not create roles');
            $this->assertCount(2, $auth->getChildren('admin'));
        });
    }

    public function testWithoutAnRbacManagerItFailsLoudly(): void
    {
        $original = $this->authManagerDefinition();
        \Yii::$app->set('authManager', null);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage(\m000087_000000_restrict_runner_management::NO_AUTH_MANAGER);
            $this->migration()->safeUp();
        } finally {
            \Yii::$app->set('authManager', $original);
        }
    }

    public function testWithoutAnRbacManagerDownFailsLoudlyToo(): void
    {
        $original = $this->authManagerDefinition();
        \Yii::$app->set('authManager', null);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('The authManager component is not configured.');
            $this->migration()->safeDown();
        } finally {
            \Yii::$app->set('authManager', $original);
        }
    }

    public function testDownGivesOperatorsBothPermissionsBack(): void
    {
        $this->grantAsBefore();
        $this->up();

        $this->migration()->safeDown();

        foreach (self::PERMISSIONS as $name) {
            $this->assertTrue($this->auth()->hasChild($this->role('operator'), $this->permission($name)), "operator: {$name}");
            $this->assertTrue($this->auth()->hasChild($this->role('admin'), $this->permission($name)), "admin: {$name}");
        }
        $before = $this->grants();
        $this->migration()->safeDown();
        $this->assertSame($before, $this->grants(), 'down twice changes nothing');
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * Runs $test with a file-based RBAC manager in place of the database one;
     * its files live in a directory of their own, removed afterwards.
     *
     * @param callable(PhpManager): void $test
     */
    private function withFileRbac(callable $test): void
    {
        $dir = sys_get_temp_dir() . '/rbac-m000087-' . bin2hex(random_bytes(4));
        FileHelper::createDirectory($dir);
        $original = $this->authManagerDefinition();
        $auth = new PhpManager([
            'itemFile' => $dir . '/items.php',
            'assignmentFile' => $dir . '/assignments.php',
            'ruleFile' => $dir . '/rules.php',
        ]);
        \Yii::$app->set('authManager', $auth);
        try {
            $test($auth);
        } finally {
            \Yii::$app->set('authManager', $original);
            FileHelper::removeDirectory($dir);
        }
    }

    /**
     * The definition of the authManager component, to put back afterwards.
     */
    private function authManagerDefinition(): mixed
    {
        $components = \Yii::$app->getComponents(true);

        return $components['authManager'] ?? null;
    }

    private function addRole(PhpManager $auth, string $name): Role
    {
        $role = $auth->createRole($name);
        $auth->add($role);

        return $role;
    }

    private function addPermission(PhpManager $auth, string $name): Permission
    {
        $permission = $auth->createPermission($name);
        $auth->add($permission);

        return $permission;
    }
}
