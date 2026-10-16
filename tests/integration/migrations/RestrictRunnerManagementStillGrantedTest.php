<?php

declare(strict_types=1);

namespace app\tests\integration\migrations;

use app\tests\integration\DbTestCase;
use yii\rbac\Role;

/**
 * m000087_000000_restrict_runner_management takes the permissions from the
 * operator role only: what operator includes keeps what it holds, as an
 * admin decides that. The migration names each role or permission below
 * operator that still grants it runner-group.create or runner-group.update,
 * says that every signed-in user holds it where a default role is or
 * includes it, and leaves out the line that operators no longer manage
 * runners. The RBAC rows change inside the test's transaction and are
 * rolled back with it.
 *
 * The test database is migrated already, so each test first puts back the
 * grants of m000025 that the migration removes.
 */
class RestrictRunnerManagementStillGrantedTest extends DbTestCase
{
    use RunsRunnerManagementMigration;

    private const PERMISSIONS = ['runner-group.create', 'runner-group.update'];

    /** The lines that take both permissions from the operator role, without the closing line. */
    private const REMOVED_BOTH = "    > Removed runner-group.create from the operator role.\n"
        . "    > Removed runner-group.update from the operator role.\n";

    /** What the migration prints when it takes both permissions from operators. */
    private const TAKEN_FROM_OPERATORS = self::REMOVED_BOTH
        . '    > Operators no longer register, move or delete runners, regenerate runner tokens, '
        . 'or create and change runner groups. To let other users do so, give them a custom role with '
        . "runner-group.create and runner-group.update; with it they can take over the jobs of every runner group.\n";

    /** The default role of the web and console applications (config/web.php, config/console.php). */
    private const DEFAULT_ROLES = ['viewer'];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/m000087_000000_restrict_runner_management.php';
    }

    /**
     * With viewer as the default role, as the console application runs the
     * migration, a default installation prints the same three lines, and a
     * second run nothing: viewer holds neither permission.
     */
    public function testWithViewerAsTheDefaultRoleADefaultInstallationPrintsTheSame(): void
    {
        $this->grantAsBefore();

        $first = $this->withDefaultRoles(self::DEFAULT_ROLES, fn (): string => $this->up());
        $second = $this->withDefaultRoles(self::DEFAULT_ROLES, fn (): string => $this->up());

        $this->assertSame(self::TAKEN_FROM_OPERATORS, $first);
        $this->assertSame('', $second);
    }

    /**
     * Regression (review of the runner step, finding 2): the migration took
     * the permissions only from the operator role and still said that
     * operators no longer manage runners where an admin had given viewer
     * runner-group.update in the role editor. Operator includes viewer, the
     * default role of every signed-in user, so operators and every signed-in
     * user kept managing runners. The migration still takes nothing from
     * viewer, but names it in place of the closing line.
     */
    public function testRegressionViewerWasGivenRunnerGroupUpdate(): void
    {
        $this->grantAsBefore();
        $this->auth()->addChild($this->role('viewer'), $this->permission('runner-group.update'));
        $operator = $this->createUser('runner_mgmt_viewer_op');
        $this->auth()->assign($this->role('operator'), (string)$operator->id);
        $anyone = $this->createUser('runner_mgmt_anyone');

        $output = $this->withDefaultRoles(self::DEFAULT_ROLES, fn (): string => $this->up());

        $this->assertSame(
            self::REMOVED_BOTH
            . "    > Every signed-in user still holds runner-group.update through the viewer role: take it from that role to leave runner management to admins.\n",
            $output
        );
        $this->assertStringNotContainsString(\m000087_000000_restrict_runner_management::RUNNERS_FOR_ADMINS, $output, 'Operators still manage runners.');
        $this->assertSame(
            self::REMOVED_BOTH . '    > ' . sprintf(\m000087_000000_restrict_runner_management::EVERY_USER_STILL_HOLDS, 'runner-group.update', 'viewer', 'role') . "\n",
            $output,
            'The line is the constant the docs quote.'
        );
        foreach (self::PERMISSIONS as $name) {
            $this->assertFalse($this->auth()->hasChild($this->role('operator'), $this->permission($name)), "operator: {$name}");
            $this->assertTrue($this->auth()->hasChild($this->role('admin'), $this->permission($name)), "admin keeps {$name}");
        }
        $this->assertTrue($this->auth()->hasChild($this->role('viewer'), $this->permission('runner-group.update')), 'The migration takes nothing from viewer.');
        $this->assertTrue($this->auth()->checkAccess((int)$operator->id, 'runner-group.update'), 'Operators keep it through viewer.');
        $this->assertFalse($this->auth()->checkAccess((int)$operator->id, 'runner-group.create'));
        $this->assertTrue(
            $this->withDefaultRoles(self::DEFAULT_ROLES, fn (): bool => $this->auth()->checkAccess((int)$anyone->id, 'runner-group.update')),
            'So does every signed-in user.'
        );
    }

    /**
     * Where viewer is no default role, only those who hold operator, or a
     * role that includes it, hold what viewer grants through operator: the
     * line names operators.
     */
    public function testWhereViewerIsNoDefaultRoleTheLineNamesOperators(): void
    {
        $this->grantAsBefore();
        $this->auth()->addChild($this->role('viewer'), $this->permission('runner-group.update'));

        $this->assertSame(
            self::REMOVED_BOTH
            . "    > Operators still hold runner-group.update through the viewer role: take it from that role to leave runner management to admins.\n",
            $this->withDefaultRoles([], fn (): string => $this->up())
        );
    }

    /**
     * A role that viewer includes, which only a change outside the role
     * editor sets up (custom roles are flat there): the line names that role,
     * where the permission is to be taken from, and says that every
     * signed-in user holds it. A second run takes nothing and names it
     * again.
     */
    public function testARoleThatViewerIncludesIsNamed(): void
    {
        $this->grantAsBefore();
        $chain = $this->addRoleBelow($this->role('viewer'), 'runner-chain-', 'runner-group.create');

        $first = $this->withDefaultRoles(self::DEFAULT_ROLES, fn (): string => $this->up());
        $before = $this->grants();
        $second = $this->withDefaultRoles([], fn (): string => $this->up());

        $this->assertSame(
            self::REMOVED_BOTH
            . "    > Every signed-in user still holds runner-group.create through the {$chain->name} role: take it from that role to leave runner management to admins.\n",
            $first
        );
        $this->assertSame(
            "    > Operators still hold runner-group.create through the {$chain->name} role: take it from that role to leave runner management to admins.\n",
            $second,
            'Without default roles the line names operators.'
        );
        $this->assertSame($before, $this->grants(), 'The second run changes nothing.');
        $this->assertTrue($this->auth()->hasChild($chain, $this->permission('runner-group.create')), 'The role keeps it.');
        $this->assertArrayHasKey('runner-group.create', $this->auth()->getPermissionsByRole('operator'), 'Operator holds it through viewer.');
    }

    /**
     * A permission that includes another, which only a change outside the
     * role editor sets up: the line names that permission. Operator includes
     * it directly, the default role viewer does not, so the line names
     * operators.
     */
    public function testAPermissionThatIncludesItIsNamed(): void
    {
        $this->grantAsBefore();
        $bundle = $this->auth()->createPermission('runner-bundle-' . uniqid());
        $this->auth()->add($bundle);
        $this->auth()->addChild($bundle, $this->permission('runner-group.update'));
        $this->auth()->addChild($this->role('operator'), $bundle);

        $output = $this->withDefaultRoles(self::DEFAULT_ROLES, fn (): string => $this->up());

        $this->assertSame(
            self::REMOVED_BOTH
            . "    > Operators still hold runner-group.update through the {$bundle->name} permission: take it from that permission to leave runner management to admins.\n",
            $output
        );
        $this->assertTrue($this->auth()->hasChild($bundle, $this->permission('runner-group.update')), 'The permission keeps it.');
    }

    /**
     * One line for each role or permission that grants a permission directly,
     * by permission and then by name; operator itself is never named.
     */
    public function testEveryRoleThatStillGrantsOneIsNamedInOrder(): void
    {
        $this->grantAsBefore();
        foreach (self::PERMISSIONS as $name) {
            $this->auth()->addChild($this->role('viewer'), $this->permission($name));
        }
        $operatorsOnly = $this->addRoleBelow($this->role('operator'), 'runner-chain-', 'runner-group.update');

        $output = $this->withDefaultRoles(self::DEFAULT_ROLES, fn (): string => $this->up());

        $this->assertSame(
            self::REMOVED_BOTH
            . '    > ' . sprintf(\m000087_000000_restrict_runner_management::EVERY_USER_STILL_HOLDS, 'runner-group.create', 'viewer', 'role') . "\n"
            . '    > ' . sprintf(\m000087_000000_restrict_runner_management::STILL_HELD, 'runner-group.update', $operatorsOnly->name, 'role') . "\n"
            . '    > ' . sprintf(\m000087_000000_restrict_runner_management::EVERY_USER_STILL_HOLDS, 'runner-group.update', 'viewer', 'role') . "\n",
            $output
        );
        $this->assertStringNotContainsString('through the operator role', $output);
    }

    /**
     * Where the migration takes nothing, as when it runs again, what still
     * grants operators a permission is named all the same; the closing line
     * needs a permission taken.
     */
    public function testWhatStillGrantsOperatorsIsNamedAlsoWhenNothingIsTaken(): void
    {
        $this->grantAsBefore();
        $this->up();
        $this->auth()->addChild($this->role('viewer'), $this->permission('runner-group.create'));

        $this->assertSame(
            "    > Operators still hold runner-group.create through the viewer role: take it from that role to leave runner management to admins.\n",
            $this->up()
        );
    }

    /**
     * A new role named $prefix plus a unique part, a child of $parent, that
     * grants $permission directly.
     */
    private function addRoleBelow(Role $parent, string $prefix, string $permission): Role
    {
        $role = $this->auth()->createRole($prefix . uniqid());
        $this->auth()->add($role);
        $this->auth()->addChild($parent, $role);
        $this->auth()->addChild($role, $this->permission($permission));

        return $role;
    }
}
