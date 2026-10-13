<?php

declare(strict_types=1);

namespace app\tests\integration\migrations;

use app\tests\integration\DbTestCase;
use yii\helpers\FileHelper;
use yii\rbac\ManagerInterface;
use yii\rbac\Permission;
use yii\rbac\PhpManager;
use yii\rbac\Role;

/**
 * m000082_000000_restrict_approval_rule_management: the operator role loses
 * approval-rule.create and approval-rule.update; the admin role holds both
 * directly first, so admins never lose them. The RBAC rows change inside the
 * test's transaction and are rolled back with it.
 *
 * The test database is migrated already, so each test first puts back the
 * grants of m000052 that the migration removes.
 */
class RestrictApprovalRuleManagementMigrationTest extends DbTestCase
{
    private const PERMISSIONS = ['approval-rule.create', 'approval-rule.update'];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/m000082_000000_restrict_approval_rule_management.php';
    }

    /**
     * Regression: an operator could change any rule's approvers, and so
     * neutralise every gate that used the rule.
     */
    public function testUpTakesCreateAndUpdateFromOperators(): void
    {
        $this->grantAsBefore();

        $output = $this->up();

        foreach (self::PERMISSIONS as $name) {
            $this->assertFalse($this->auth()->hasChild($this->role('operator'), $this->permission($name)), "operator: {$name}");
            $this->assertTrue($this->auth()->hasChild($this->role('admin'), $this->permission($name)), "admin: {$name}");
        }
        $this->assertSame(
            "    > Removed approval-rule.create from the operator role.\n"
            . "    > Removed approval-rule.update from the operator role.\n"
            . "    > Operators no longer create or change approval rules. To let other users manage them, "
            . "grant approval-rule.create and approval-rule.update to a custom role.\n",
            $output
        );
    }

    public function testOperatorsKeepViewingRulesAndDecidingRequests(): void
    {
        $this->grantAsBefore();
        $operator = $this->createUser('rule_mgmt_operator');
        $this->auth()->assign($this->role('operator'), (string)$operator->id);

        $this->up();

        $userId = (int)$operator->id;
        $this->assertTrue($this->auth()->checkAccess($userId, 'approval-rule.view'));
        $this->assertTrue($this->auth()->checkAccess($userId, 'approval.decide'));
        $this->assertTrue($this->auth()->checkAccess($userId, 'approval.view'));
        $this->assertFalse($this->auth()->checkAccess($userId, 'approval-rule.create'));
        $this->assertFalse($this->auth()->checkAccess($userId, 'approval-rule.update'));
        $this->assertFalse($this->auth()->checkAccess($userId, 'approval-rule.delete'));
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
        $admin = $this->createUser('rule_mgmt_admin');
        $this->auth()->assign($this->role('admin'), (string)$admin->id);

        $output = $this->up();

        foreach (self::PERMISSIONS as $name) {
            $this->assertTrue($this->auth()->hasChild($this->role('admin'), $this->permission($name)), "admin holds {$name} directly");
            $this->assertTrue($this->auth()->checkAccess((int)$admin->id, $name), $name);
        }
        $this->assertStringStartsWith(
            "    > Granted approval-rule.create to the admin role directly.\n"
            . "    > Removed approval-rule.create from the operator role.\n"
            . "    > Granted approval-rule.update to the admin role directly.\n"
            . "    > Removed approval-rule.update from the operator role.\n",
            $output
        );
    }

    public function testCustomRolesKeepWhatTheyWereGranted(): void
    {
        $this->grantAsBefore();
        $custom = $this->auth()->createRole('rule-managers-' . uniqid());
        $this->auth()->add($custom);
        foreach (self::PERMISSIONS as $name) {
            $this->auth()->addChild($custom, $this->permission($name));
        }

        $this->up();

        foreach (self::PERMISSIONS as $name) {
            $this->assertTrue($this->auth()->hasChild($custom, $this->permission($name)), $name);
        }
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
     * An installation without one of the permissions, or without the admin
     * role: the rest is still done, nothing fails. Shown with a file-based
     * RBAC manager, so the shared roles of the test database stay as they
     * are.
     */
    public function testAMissingPermissionOrAdminRoleIsSkipped(): void
    {
        $this->withFileRbac(function (PhpManager $auth): void {
            $operator = $auth->createRole('operator');
            $auth->add($operator);
            $update = $auth->createPermission('approval-rule.update');
            $auth->add($update);
            $auth->addChild($operator, $update);

            $output = $this->up();

            $this->assertSame(
                "    > Permission approval-rule.create does not exist: nothing to change.\n"
                . "    > Removed approval-rule.update from the operator role.\n"
                . "    > Operators no longer create or change approval rules. To let other users manage them, "
                . "grant approval-rule.create and approval-rule.update to a custom role.\n",
                $output
            );
            $this->assertFalse($auth->hasChild($operator, $update));
            $this->assertNull($auth->getRole('admin'), 'up does not create roles');

            $this->migration()->safeDown();

            $this->assertTrue($auth->hasChild($operator, $update), 'down skips the missing permission and restores the other');
            $this->assertNull($auth->getPermission('approval-rule.create'));
        });
    }

    public function testWithoutAnOperatorRoleThereIsNothingToDo(): void
    {
        $this->withFileRbac(function (PhpManager $auth): void {
            $admin = $auth->createRole('admin');
            $auth->add($admin);
            foreach (self::PERMISSIONS as $name) {
                $permission = $auth->createPermission($name);
                $auth->add($permission);
                $auth->addChild($admin, $permission);
            }

            $this->assertSame('', $this->up());
            $this->migration()->safeDown();

            $this->assertNull($auth->getRole('operator'), 'down does not create roles');
            $this->assertCount(2, $auth->getChildren('admin'));
        });
    }

    public function testWithoutAnRbacManagerItFailsLoudly(): void
    {
        $original = \Yii::$app->getComponents(true)['authManager'] ?? null;
        \Yii::$app->set('authManager', null);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('The authManager component is not configured.');
            $this->migration()->safeUp();
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

    private function migration(): \m000082_000000_restrict_approval_rule_management
    {
        return new \m000082_000000_restrict_approval_rule_management(['db' => \Yii::$app->db, 'compact' => true]);
    }

    /**
     * Runs safeUp() and returns what it printed.
     */
    private function up(): string
    {
        ob_start();
        try {
            $this->migration()->safeUp();
        } finally {
            $output = (string)ob_get_clean();
        }

        return $output;
    }

    /**
     * Runs $test with a file-based RBAC manager in place of the database one;
     * its files live in a directory of their own, removed afterwards.
     *
     * @param callable(PhpManager): void $test
     */
    private function withFileRbac(callable $test): void
    {
        $dir = sys_get_temp_dir() . '/rbac-m000082-' . bin2hex(random_bytes(4));
        FileHelper::createDirectory($dir);
        $original = \Yii::$app->getComponents(true)['authManager'] ?? null;
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
     * The grants of m000052: operator and admin hold both permissions directly.
     */
    private function grantAsBefore(): void
    {
        foreach (['operator', 'admin'] as $roleName) {
            foreach (self::PERMISSIONS as $name) {
                if (!$this->auth()->hasChild($this->role($roleName), $this->permission($name))) {
                    $this->auth()->addChild($this->role($roleName), $this->permission($name));
                }
            }
        }
    }

    /**
     * @return array<string, list<string>> direct children of operator and admin
     */
    private function grants(): array
    {
        $grants = [];
        foreach (['operator', 'admin'] as $roleName) {
            $children = array_keys($this->auth()->getChildren($roleName));
            sort($children);
            $grants[$roleName] = $children;
        }

        return $grants;
    }

    private function role(string $name): Role
    {
        $role = $this->auth()->getRole($name);
        $this->assertNotNull($role, "role {$name}");

        return $role;
    }

    private function permission(string $name): Permission
    {
        $permission = $this->auth()->getPermission($name);
        $this->assertNotNull($permission, "permission {$name}");

        return $permission;
    }

    private function auth(): ManagerInterface
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);

        return $auth;
    }
}
