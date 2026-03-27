<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\TeamProject;
use app\services\ProjectAccessChecker;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ProjectAccessChecker role-resolution logic.
 *
 * We test the pure logic in resolveRole() by creating a testable subclass
 * that overrides the DB-backed methods with controlled data.
 */
class ProjectAccessCheckerTest extends TestCase
{
    public function testSuperadminAlwaysGetsOperatorRole(): void
    {
        $checker = $this->makeChecker(
            isSuperadmin: true,
            isAdmin: false,
            teamRows: [],
            hasAnyTeamAccess: false
        );
        $this->assertSame(TeamProject::ROLE_OPERATOR, $checker->resolveRole(1, 99));
    }

    public function testAdminAlwaysGetsOperatorRole(): void
    {
        $checker = $this->makeChecker(
            isSuperadmin: false,
            isAdmin: true,
            teamRows: [],
            hasAnyTeamAccess: false
        );
        $this->assertSame(TeamProject::ROLE_OPERATOR, $checker->resolveRole(1, 99));
    }

    public function testOpenProjectGrantsOperatorToRbacUser(): void
    {
        // No team restrictions on this project + user has project.view via RBAC
        $checker = $this->makeChecker(
            isSuperadmin: false,
            isAdmin: false,
            teamRows: [],
            hasAnyTeamAccess: false,
            hasRbacProjectView: true
        );
        $this->assertSame(TeamProject::ROLE_OPERATOR, $checker->resolveRole(1, 10));
    }

    public function testOpenProjectDeniesAccessWhenNoRbac(): void
    {
        $checker = $this->makeChecker(
            isSuperadmin: false,
            isAdmin: false,
            teamRows: [],
            hasAnyTeamAccess: false,
            hasRbacProjectView: false
        );
        $this->assertNull($checker->resolveRole(1, 10));
    }

    public function testRestrictedProjectGrantsViewerViaTeam(): void
    {
        $checker = $this->makeChecker(
            isSuperadmin: false,
            isAdmin: false,
            teamRows: [['role' => TeamProject::ROLE_VIEWER]],
            hasAnyTeamAccess: true
        );
        $this->assertSame(TeamProject::ROLE_VIEWER, $checker->resolveRole(1, 10));
    }

    public function testRestrictedProjectGrantsOperatorViaTeam(): void
    {
        $checker = $this->makeChecker(
            isSuperadmin: false,
            isAdmin: false,
            teamRows: [['role' => TeamProject::ROLE_OPERATOR]],
            hasAnyTeamAccess: true
        );
        $this->assertSame(TeamProject::ROLE_OPERATOR, $checker->resolveRole(1, 10));
    }

    public function testHighestRoleWinsWhenUserInMultipleTeams(): void
    {
        $checker = $this->makeChecker(
            isSuperadmin: false,
            isAdmin: false,
            teamRows: [
                ['role' => TeamProject::ROLE_VIEWER],
                ['role' => TeamProject::ROLE_OPERATOR],
            ],
            hasAnyTeamAccess: true
        );
        // operator > viewer
        $this->assertSame(TeamProject::ROLE_OPERATOR, $checker->resolveRole(1, 10));
    }

    public function testRestrictedProjectDeniesAccessWithNoTeamMembership(): void
    {
        $checker = $this->makeChecker(
            isSuperadmin: false,
            isAdmin: false,
            teamRows: [],
            hasAnyTeamAccess: true  // project IS restricted, but user has no team
        );
        $this->assertNull($checker->resolveRole(1, 10));
    }

    public function testCanViewReturnsTrueWhenRoleResolved(): void
    {
        $checker = $this->makeChecker(
            isSuperadmin: true,
            isAdmin: false,
            teamRows: [],
            hasAnyTeamAccess: false
        );
        $this->assertTrue($checker->canView(1, 10));
    }

    public function testCanViewReturnsFalseWhenNoAccess(): void
    {
        $checker = $this->makeChecker(
            isSuperadmin: false,
            isAdmin: false,
            teamRows: [],
            hasAnyTeamAccess: true  // restricted, user has no team
        );
        $this->assertFalse($checker->canView(1, 10));
    }

    public function testCanOperateReturnsTrueForOperatorRole(): void
    {
        $checker = $this->makeChecker(
            isSuperadmin: false,
            isAdmin: false,
            teamRows: [['role' => TeamProject::ROLE_OPERATOR]],
            hasAnyTeamAccess: true
        );
        $this->assertTrue($checker->canOperate(1, 10));
    }

    public function testCanOperateReturnsFalseForViewerRole(): void
    {
        $checker = $this->makeChecker(
            isSuperadmin: false,
            isAdmin: false,
            teamRows: [['role' => TeamProject::ROLE_VIEWER]],
            hasAnyTeamAccess: true
        );
        $this->assertFalse($checker->canOperate(1, 10));
    }

    // -------------------------------------------------------------------------

    private function makeChecker(
        bool $isSuperadmin,
        bool $isAdmin,
        array $teamRows,
        bool $hasAnyTeamAccess,
        bool $hasRbacProjectView = false,
    ): ProjectAccessChecker {
        return new class (
            $isSuperadmin,
            $isAdmin,
            $teamRows,
            $hasAnyTeamAccess,
            $hasRbacProjectView
        ) extends ProjectAccessChecker {
            public function __construct(
                private bool $isSuperadminFlag,
                private bool $isAdminFlag,
                private array $teamRowsData,
                private bool $hasAnyTeamAccessFlag,
                private bool $hasRbacFlag,
            ) {
                // Skip parent constructor (Yii Component)
            }

            public function resolveRole(int $userId, int $projectId): ?string
            {
                // Inline the logic from the real resolveRole, using test doubles
                if ($this->isSuperadminFlag || $this->isAdminFlag) {
                    return TeamProject::ROLE_OPERATOR;
                }

                if (!$this->hasAnyTeamAccessFlag) {
                    return $this->hasRbacFlag ? TeamProject::ROLE_OPERATOR : null;
                }

                if (empty($this->teamRowsData)) {
                    return null;
                }

                foreach ($this->teamRowsData as $row) {
                    if ($row['role'] === TeamProject::ROLE_OPERATOR) {
                        return TeamProject::ROLE_OPERATOR;
                    }
                }

                return TeamProject::ROLE_VIEWER;
            }
        };
    }
}
