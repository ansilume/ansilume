<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\TeamProject;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

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
            $tp = $this->makeTeamProject($role);
            $tp->validate(['role']);
            $this->assertFalse($tp->hasErrors('role'), "Role '{$role}' should be valid");
        }
    }

    public function testInvalidRoleFailsValidation(): void
    {
        $tp = $this->makeTeamProject('superadmin');
        $tp->validate(['role']);
        $this->assertTrue($tp->hasErrors('role'));
    }

    private function makeTeamProject(string $role): TeamProject
    {
        $tp = $this->getMockBuilder(TeamProject::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $tp->method('attributes')->willReturn(['team_id', 'project_id', 'role', 'created_at']);
        $tp->method('save')->willReturn(true);
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setValue($tp, ['team_id' => 1, 'project_id' => 1, 'role' => $role, 'created_at' => null]);
        return $tp;
    }
}
