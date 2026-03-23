<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\TeamProject;
use app\services\ProjectAccessChecker;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for ProjectAccessChecker using real DB records.
 * Exercises resolveRole(), canView(), canOperate(), and buildProjectFilter()
 * against actual team_project, team_member, and user rows.
 */
class ProjectAccessCheckerTest extends DbTestCase
{
    private ProjectAccessChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checker = \Yii::$app->get('projectAccessChecker');
    }

    // -------------------------------------------------------------------------
    // resolveRole()
    // -------------------------------------------------------------------------

    public function testResolveRoleReturnsNullForNonExistentUser(): void
    {
        $this->assertNull($this->checker->resolveRole(999999, 1));
    }

    public function testSuperadminGetsOperatorRoleWithoutTeamMembership(): void
    {
        $user = $this->createUser();
        \Yii::$app->db->createCommand()
            ->update('{{%user}}', ['is_superadmin' => 1], ['id' => $user->id])
            ->execute();

        $project = $this->createProject($user->id);

        $this->assertSame(TeamProject::ROLE_OPERATOR, $this->checker->resolveRole($user->id, $project->id));
    }

    public function testOpenProjectReturnsNullWhenUserHasNoRbacAndNoTeam(): void
    {
        $owner   = $this->createUser('owner');
        $user    = $this->createUser('regular');
        $project = $this->createProject($owner->id);

        // Restrict the project via a team (so it's not "open")
        $team = $this->createTeam($owner->id);
        $this->createTeamProject($team->id, $project->id, TeamProject::ROLE_VIEWER);
        // User has no team membership → no access
        $this->assertNull($this->checker->resolveRole($user->id, $project->id));
    }

    public function testUserWithViewerTeamRoleGetsViewerRole(): void
    {
        $owner   = $this->createUser('owner');
        $member  = $this->createUser('member');
        $project = $this->createProject($owner->id);
        $team    = $this->createTeam($owner->id);

        $this->createTeamProject($team->id, $project->id, TeamProject::ROLE_VIEWER);
        $this->addTeamMember($team->id, $member->id);

        $this->assertSame(TeamProject::ROLE_VIEWER, $this->checker->resolveRole($member->id, $project->id));
    }

    public function testUserWithOperatorTeamRoleGetsOperatorRole(): void
    {
        $owner   = $this->createUser('owner');
        $member  = $this->createUser('member');
        $project = $this->createProject($owner->id);
        $team    = $this->createTeam($owner->id);

        $this->createTeamProject($team->id, $project->id, TeamProject::ROLE_OPERATOR);
        $this->addTeamMember($team->id, $member->id);

        $this->assertSame(TeamProject::ROLE_OPERATOR, $this->checker->resolveRole($member->id, $project->id));
    }

    public function testHighestRoleWinsAcrossMultipleTeams(): void
    {
        $owner   = $this->createUser('owner');
        $member  = $this->createUser('member');
        $project = $this->createProject($owner->id);

        $teamA = $this->createTeam($owner->id);
        $teamB = $this->createTeam($owner->id);

        $this->createTeamProject($teamA->id, $project->id, TeamProject::ROLE_VIEWER);
        $this->createTeamProject($teamB->id, $project->id, TeamProject::ROLE_OPERATOR);
        $this->addTeamMember($teamA->id, $member->id);
        $this->addTeamMember($teamB->id, $member->id);

        $this->assertSame(TeamProject::ROLE_OPERATOR, $this->checker->resolveRole($member->id, $project->id));
    }

    public function testRestrictedProjectDeniesUserWithNoTeamMembership(): void
    {
        $owner   = $this->createUser('owner');
        $other   = $this->createUser('other');
        $project = $this->createProject($owner->id);
        $team    = $this->createTeam($owner->id);
        $this->createTeamProject($team->id, $project->id, TeamProject::ROLE_OPERATOR);
        // 'other' is NOT in the team

        $this->assertNull($this->checker->resolveRole($other->id, $project->id));
    }

    // -------------------------------------------------------------------------
    // canView() / canOperate()
    // -------------------------------------------------------------------------

    public function testCanViewReturnsTrueForTeamMember(): void
    {
        $owner   = $this->createUser('owner');
        $member  = $this->createUser('member');
        $project = $this->createProject($owner->id);
        $team    = $this->createTeam($owner->id);
        $this->createTeamProject($team->id, $project->id, TeamProject::ROLE_VIEWER);
        $this->addTeamMember($team->id, $member->id);

        $this->assertTrue($this->checker->canView($member->id, $project->id));
    }

    public function testCanViewReturnsFalseForNonMember(): void
    {
        $owner   = $this->createUser('owner');
        $other   = $this->createUser('other');
        $project = $this->createProject($owner->id);
        $team    = $this->createTeam($owner->id);
        $this->createTeamProject($team->id, $project->id, TeamProject::ROLE_OPERATOR);

        $this->assertFalse($this->checker->canView($other->id, $project->id));
    }

    public function testCanOperateReturnsTrueForOperatorTeamMember(): void
    {
        $owner   = $this->createUser('owner');
        $member  = $this->createUser('member');
        $project = $this->createProject($owner->id);
        $team    = $this->createTeam($owner->id);
        $this->createTeamProject($team->id, $project->id, TeamProject::ROLE_OPERATOR);
        $this->addTeamMember($team->id, $member->id);

        $this->assertTrue($this->checker->canOperate($member->id, $project->id));
    }

    public function testCanOperateReturnsFalseForViewerTeamMember(): void
    {
        $owner   = $this->createUser('owner');
        $member  = $this->createUser('member');
        $project = $this->createProject($owner->id);
        $team    = $this->createTeam($owner->id);
        $this->createTeamProject($team->id, $project->id, TeamProject::ROLE_VIEWER);
        $this->addTeamMember($team->id, $member->id);

        $this->assertFalse($this->checker->canOperate($member->id, $project->id));
    }

    // -------------------------------------------------------------------------
    // buildProjectFilter()
    // -------------------------------------------------------------------------

    public function testBuildProjectFilterReturnsNoAccessForNullUserId(): void
    {
        $filter = $this->checker->buildProjectFilter(null);
        $this->assertSame(['0=1'], $filter);
    }

    public function testBuildProjectFilterReturnsNoAccessForNonExistentUser(): void
    {
        $filter = $this->checker->buildProjectFilter(999999);
        $this->assertSame(['0=1'], $filter);
    }

    public function testBuildProjectFilterReturnsNullWhenNoRestrictionsExist(): void
    {
        $user = $this->createUser();
        // No team_project rows in DB at all (within this transaction)
        $filter = $this->checker->buildProjectFilter($user->id);
        $this->assertNull($filter);
    }

    public function testBuildProjectFilterReturnsSuperadminNull(): void
    {
        $user = $this->createUser();
        \Yii::$app->db->createCommand()
            ->update('{{%user}}', ['is_superadmin' => 1], ['id' => $user->id])
            ->execute();

        $filter = $this->checker->buildProjectFilter($user->id);
        $this->assertNull($filter);
    }

    public function testBuildProjectFilterContainsAccessibleProjectId(): void
    {
        $owner   = $this->createUser('owner');
        $member  = $this->createUser('member');
        $project = $this->createProject($owner->id);
        $team    = $this->createTeam($owner->id);
        $this->createTeamProject($team->id, $project->id, TeamProject::ROLE_OPERATOR);
        $this->addTeamMember($team->id, $member->id);

        $filter = $this->checker->buildProjectFilter($member->id);

        // Filter should be an 'or' condition array (not null, not deny-all)
        $this->assertIsArray($filter);
        $this->assertNotSame(['0=1'], $filter);
    }

}
