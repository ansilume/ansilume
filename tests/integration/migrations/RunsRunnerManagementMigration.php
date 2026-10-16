<?php

declare(strict_types=1);

namespace app\tests\integration\migrations;

use yii\rbac\BaseManager;
use yii\rbac\ManagerInterface;
use yii\rbac\Permission;
use yii\rbac\Role;

/**
 * Runs m000087_000000_restrict_runner_management on the roles of the test
 * database, inside the test's transaction, for the tests of the migration.
 * The class that uses it requires the migration in setUpBeforeClass().
 */
trait RunsRunnerManagementMigration
{
    private function migration(): \m000087_000000_restrict_runner_management
    {
        return new \m000087_000000_restrict_runner_management(['db' => \Yii::$app->db, 'compact' => true]);
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
     * Runs $test with $roles as the default roles of the RBAC manager, as
     * config/web.php and config/console.php give every signed-in user viewer;
     * the test configuration gives none. Puts the default roles back.
     *
     * @template T
     * @param list<string> $roles
     * @param callable(): T $test
     * @return T
     */
    private function withDefaultRoles(array $roles, callable $test): mixed
    {
        $auth = $this->auth();
        $this->assertInstanceOf(BaseManager::class, $auth);
        $before = $auth->getDefaultRoles();
        $auth->setDefaultRoles($roles);
        try {
            return $test();
        } finally {
            $auth->setDefaultRoles($before);
        }
    }

    /**
     * The grants of m000025: operator and admin hold both permissions directly.
     */
    private function grantAsBefore(): void
    {
        foreach (['operator', 'admin'] as $roleName) {
            foreach (\m000087_000000_restrict_runner_management::PERMISSIONS as $name) {
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
