<?php

declare(strict_types=1);

namespace app\tests\unit\helpers;

use app\helpers\PermissionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Guardrail tests for the permission catalog: every catalog entry must
 * correspond to a real permission in auth_item, and every permission in
 * auth_item must be listed in the catalog. This keeps the role management
 * UI in sync with the migrations.
 */
class PermissionCatalogTest extends TestCase
{
    public function testGroupsReturnsNonEmpty(): void
    {
        $groups = PermissionCatalog::groups();
        $this->assertNotEmpty($groups);
        foreach ($groups as $group) {
            $this->assertArrayHasKey('domain', $group);
            $this->assertArrayHasKey('label', $group);
            $this->assertArrayHasKey('permissions', $group);
            $this->assertNotEmpty($group['permissions']);
        }
    }

    public function testPermissionNamesAreFlattened(): void
    {
        $all = PermissionCatalog::allPermissionNames();
        $this->assertNotEmpty($all);
        foreach ($all as $name) {
            $this->assertIsString($name);
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9-]*\.[a-z][a-z0-9_-]*$/', $name);
        }
    }

    public function testLabelForReturnsLabelWhenKnown(): void
    {
        $this->assertSame('View projects', PermissionCatalog::labelFor('project.view'));
        $this->assertSame('Delete users', PermissionCatalog::labelFor('user.delete'));
    }

    public function testLabelForFallsBackToRawName(): void
    {
        $this->assertSame('unknown.permission', PermissionCatalog::labelFor('unknown.permission'));
    }

    public function testEveryAuthItemPermissionIsInCatalog(): void
    {
        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        $dbNames = array_keys($auth->getPermissions());
        $catalogNames = PermissionCatalog::allPermissionNames();

        $missing = array_diff($dbNames, $catalogNames);
        $this->assertEmpty(
            $missing,
            'Permissions present in auth_item but not in PermissionCatalog: ' . implode(', ', $missing)
        );
    }

    public function testEveryCatalogEntryExistsInAuthItem(): void
    {
        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        $dbNames = array_keys($auth->getPermissions());
        $catalogNames = PermissionCatalog::allPermissionNames();

        $missing = array_diff($catalogNames, $dbNames);
        $this->assertEmpty(
            $missing,
            'Permissions declared in PermissionCatalog but not in auth_item: ' . implode(', ', $missing)
        );
    }

    public function testNoDuplicatePermissionNames(): void
    {
        $names = PermissionCatalog::allPermissionNames();
        $this->assertSame(
            count($names),
            count(array_unique($names)),
            'Duplicate permission name found in catalog'
        );
    }
}
