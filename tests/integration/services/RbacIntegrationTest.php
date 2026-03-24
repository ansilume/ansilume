<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\tests\integration\DbTestCase;

/**
 * Integration tests for RBAC role hierarchy and permission assignment.
 *
 * Verifies the role/permission structure seeded by the RBAC migration:
 * - viewer: read-only access
 * - operator: viewer + create/update + job.launch + job.cancel
 * - admin: operator + user management + delete permissions
 *
 * Tests use the real DbManager so role inheritance is fully exercised.
 */
class RbacIntegrationTest extends DbTestCase
{
    private \yii\rbac\ManagerInterface $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = \Yii::$app->authManager;
    }

    // -------------------------------------------------------------------------
    // Role existence
    // -------------------------------------------------------------------------

    public function testRolesExist(): void
    {
        $this->assertNotNull($this->auth->getRole('viewer'));
        $this->assertNotNull($this->auth->getRole('operator'));
        $this->assertNotNull($this->auth->getRole('admin'));
    }

    // -------------------------------------------------------------------------
    // Viewer permissions
    // -------------------------------------------------------------------------

    /**
     * @dataProvider viewerAllowedProvider
     */
    public function testViewerCanAccessReadPermissions(string $permission): void
    {
        $user = $this->createUser('viewer');
        $this->auth->assign($this->auth->getRole('viewer'), $user->id);

        $this->assertTrue(
            $this->auth->checkAccess($user->id, $permission),
            "Viewer should have '{$permission}'."
        );
    }

    public static function viewerAllowedProvider(): array
    {
        return [
            ['project.view'],
            ['inventory.view'],
            ['credential.view'],
            ['job-template.view'],
            ['job.view'],
        ];
    }

    /**
     * @dataProvider viewerDeniedProvider
     */
    public function testViewerCannotAccessWritePermissions(string $permission): void
    {
        $user = $this->createUser('viewer');
        $this->auth->assign($this->auth->getRole('viewer'), $user->id);

        $this->assertFalse(
            $this->auth->checkAccess($user->id, $permission),
            "Viewer must NOT have '{$permission}'."
        );
    }

    public static function viewerDeniedProvider(): array
    {
        return [
            ['project.create'],
            ['project.update'],
            ['project.delete'],
            ['job.launch'],
            ['job.cancel'],
            ['user.view'],
            ['user.create'],
            ['credential.create'],
            ['credential.delete'],
        ];
    }

    // -------------------------------------------------------------------------
    // Operator permissions
    // -------------------------------------------------------------------------

    /**
     * @dataProvider operatorAllowedProvider
     */
    public function testOperatorHasExpectedPermissions(string $permission): void
    {
        $user = $this->createUser('operator');
        $this->auth->assign($this->auth->getRole('operator'), $user->id);

        $this->assertTrue(
            $this->auth->checkAccess($user->id, $permission),
            "Operator should have '{$permission}'."
        );
    }

    public static function operatorAllowedProvider(): array
    {
        return [
            // Inherited from viewer
            ['project.view'],
            ['inventory.view'],
            ['credential.view'],
            ['job-template.view'],
            ['job.view'],
            // Own permissions
            ['project.create'],
            ['project.update'],
            ['inventory.create'],
            ['inventory.update'],
            ['credential.create'],
            ['credential.update'],
            ['job-template.create'],
            ['job-template.update'],
            ['job.launch'],
            ['job.cancel'],
        ];
    }

    /**
     * @dataProvider operatorDeniedProvider
     */
    public function testOperatorCannotAccessAdminPermissions(string $permission): void
    {
        $user = $this->createUser('operator');
        $this->auth->assign($this->auth->getRole('operator'), $user->id);

        $this->assertFalse(
            $this->auth->checkAccess($user->id, $permission),
            "Operator must NOT have '{$permission}'."
        );
    }

    public static function operatorDeniedProvider(): array
    {
        return [
            ['user.view'],
            ['user.create'],
            ['user.update'],
            ['user.delete'],
            ['project.delete'],
            ['inventory.delete'],
            ['credential.delete'],
            ['job-template.delete'],
        ];
    }

    // -------------------------------------------------------------------------
    // Admin permissions
    // -------------------------------------------------------------------------

    /**
     * @dataProvider adminAllowedProvider
     */
    public function testAdminHasAllPermissions(string $permission): void
    {
        $user = $this->createUser('admin');
        $this->auth->assign($this->auth->getRole('admin'), $user->id);

        $this->assertTrue(
            $this->auth->checkAccess($user->id, $permission),
            "Admin should have '{$permission}'."
        );
    }

    public static function adminAllowedProvider(): array
    {
        return [
            // Inherited from operator → viewer
            ['project.view'],
            ['job.view'],
            ['job.launch'],
            ['job.cancel'],
            // Own permissions
            ['user.view'],
            ['user.create'],
            ['user.update'],
            ['user.delete'],
            ['project.delete'],
            ['inventory.delete'],
            ['credential.delete'],
            ['job-template.delete'],
        ];
    }

    // -------------------------------------------------------------------------
    // Role assignment
    // -------------------------------------------------------------------------

    public function testRoleCanBeAssignedAndRevoked(): void
    {
        $user = $this->createUser('assign');

        $this->auth->assign($this->auth->getRole('operator'), $user->id);
        $this->assertTrue($this->auth->checkAccess($user->id, 'job.launch'));

        $this->auth->revoke($this->auth->getRole('operator'), $user->id);
        $this->assertFalse($this->auth->checkAccess($user->id, 'job.launch'));
    }

    public function testUserWithNoRoleHasNoPermissions(): void
    {
        $user = $this->createUser('norole');

        $this->assertFalse($this->auth->checkAccess($user->id, 'project.view'));
        $this->assertFalse($this->auth->checkAccess($user->id, 'job.launch'));
    }

    public function testMultipleRolesStack(): void
    {
        $user = $this->createUser('multi');

        // Assign viewer first, then upgrade to admin
        $this->auth->assign($this->auth->getRole('viewer'), $user->id);
        $this->assertFalse($this->auth->checkAccess($user->id, 'job.launch'));

        $this->auth->assign($this->auth->getRole('admin'), $user->id);
        $this->assertTrue($this->auth->checkAccess($user->id, 'job.launch'));
        $this->assertTrue($this->auth->checkAccess($user->id, 'user.delete'));
    }
}
