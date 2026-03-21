<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\TeamProject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TeamProject role constants and validation.
 * No database required.
 */
class TeamProjectTest extends TestCase
{
    public function testViewerRoleConstant(): void
    {
        $this->assertSame('viewer', TeamProject::ROLE_VIEWER);
    }

    public function testOperatorRoleConstant(): void
    {
        $this->assertSame('operator', TeamProject::ROLE_OPERATOR);
    }

    public function testValidRolePassesValidation(): void
    {
        foreach ([TeamProject::ROLE_VIEWER, TeamProject::ROLE_OPERATOR] as $role) {
            $tp       = new TeamProject();
            $tp->role = $role;
            $tp->validate(['role']);
            $this->assertFalse($tp->hasErrors('role'), "Role '{$role}' should be valid");
        }
    }

    public function testInvalidRoleFailsValidation(): void
    {
        $tp       = new TeamProject();
        $tp->role = 'superadmin';
        $tp->validate(['role']);
        $this->assertTrue($tp->hasErrors('role'));
    }
}
