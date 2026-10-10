<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Inventory;
use app\models\Project;
use app\models\TeamProject;
use app\services\ProjectAccessChecker;
use app\tests\integration\CountsQueries;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for ProjectAccessChecker using real DB records.
 * Exercises resolveRole(), canView(), canOperate(), the query filters
 * (buildProjectFilter(), buildChildResourceFilter(), buildChildOperateFilter(),
 * buildJobFilter(), templateIdSubquery()) and hasRestrictedProjects() against
 * actual team_project, team_member, and user rows.
 */
class ProjectAccessCheckerTest extends DbTestCase
{
    use CountsQueries;

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

    public function testSuperadminsAndRbacAdminsGetOperatorRoleOnARestrictedProjectWithoutTeamMembership(): void
    {
        $owner = $this->createUser('owner');
        $projectId = $this->teamProjectId((int)$owner->id, (int)$this->createTeam($owner->id)->id, TeamProject::ROLE_VIEWER);
        $superadmin = $this->createUser('superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $admin = $this->createUser('admin');
        $this->assignRole((int)$admin->id, 'admin');
        $operator = $this->createUser('operator');
        $this->assignRole((int)$operator->id, 'operator');

        $this->assertSame(TeamProject::ROLE_OPERATOR, $this->checker->resolveRole((int)$superadmin->id, $projectId));
        $this->assertSame(TeamProject::ROLE_OPERATOR, $this->checker->resolveRole((int)$admin->id, $projectId));
        $this->assertNull($this->checker->resolveRole((int)$operator->id, $projectId), 'control: the project is restricted');
    }

    /**
     * Regression: the RBAC admin lookup (several queries) came before the
     * check whether the project is restricted at all, although every user
     * gets the operator role on an open project.
     */
    public function testResolveRoleOnAnOpenProjectSkipsTheRbacLookup(): void
    {
        $owner = $this->createUser('owner');
        $operator = $this->createUser('operator');
        $this->assignRole((int)$operator->id, 'operator');
        $projectId = (int)$this->createProject($owner->id)->id;
        $this->assertSame(TeamProject::ROLE_OPERATOR, $this->checker->resolveRole((int)$operator->id, $projectId));

        $queries = $this->queriesOf(fn () => $this->checker->resolveRole((int)$operator->id, $projectId));

        $this->assertLessThanOrEqual(2, $queries, 'the user and the project restriction only');
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

    /**
     * Regression: the guest filter was ['0=1'], which Yii's query builder
     * rejects ("Operator '0=1' requires two operands"), so a query without
     * a user threw instead of returning nothing.
     */
    public function testBuildProjectFilterReturnsNoAccessForNullUserId(): void
    {
        $this->createProject($this->createUser()->id);

        $filter = $this->checker->buildProjectFilter(null);

        $this->assertSame(ProjectAccessChecker::DENY_ALL, $filter);
        $this->assertSame(0, (int)Project::find()->andWhere($filter)->count());
        $this->assertGreaterThan(0, (int)Project::find()->count());
    }

    public function testBuildProjectFilterReturnsNullForNonExistentUserWhenNoRestrictions(): void
    {
        // When no team_project rows exist, all users (even non-existent) see everything
        $filter = $this->checker->buildProjectFilter(999999);
        $this->assertNull($filter);
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
        $this->assertNotSame(ProjectAccessChecker::DENY_ALL, $filter);
    }

    // -------------------------------------------------------------------------
    // canViewChildResource() / canOperateChildResource()
    // -------------------------------------------------------------------------

    public function testCanViewChildResourceReturnsTrueForNullProjectId(): void
    {
        $user = $this->createUser();
        $this->assertTrue($this->checker->canViewChildResource($user->id, null));
    }

    public function testCanViewChildResourceReturnsTrueForTeamMember(): void
    {
        $owner = $this->createUser('owner');
        $member = $this->createUser('member');
        $project = $this->createProject($owner->id);
        $team = $this->createTeam($owner->id);
        $this->createTeamProject($team->id, $project->id, TeamProject::ROLE_VIEWER);
        $this->addTeamMember($team->id, $member->id);

        $this->assertTrue($this->checker->canViewChildResource($member->id, $project->id));
    }

    public function testCanViewChildResourceReturnsFalseForNonMember(): void
    {
        $owner = $this->createUser('owner');
        $other = $this->createUser('other');
        $project = $this->createProject($owner->id);
        $team = $this->createTeam($owner->id);
        $this->createTeamProject($team->id, $project->id, TeamProject::ROLE_OPERATOR);

        $this->assertFalse($this->checker->canViewChildResource($other->id, $project->id));
    }

    public function testCanOperateChildResourceReturnsTrueForNullProjectId(): void
    {
        $user = $this->createUser();
        $this->assertTrue($this->checker->canOperateChildResource($user->id, null));
    }

    public function testCanOperateChildResourceReturnsFalseForViewer(): void
    {
        $owner = $this->createUser('owner');
        $member = $this->createUser('member');
        $project = $this->createProject($owner->id);
        $team = $this->createTeam($owner->id);
        $this->createTeamProject($team->id, $project->id, TeamProject::ROLE_VIEWER);
        $this->addTeamMember($team->id, $member->id);

        $this->assertFalse($this->checker->canOperateChildResource($member->id, $project->id));
    }

    public function testCanOperateChildResourceReturnsTrueForOperator(): void
    {
        $owner = $this->createUser('owner');
        $member = $this->createUser('member');
        $project = $this->createProject($owner->id);
        $team = $this->createTeam($owner->id);
        $this->createTeamProject($team->id, $project->id, TeamProject::ROLE_OPERATOR);
        $this->addTeamMember($team->id, $member->id);

        $this->assertTrue($this->checker->canOperateChildResource($member->id, $project->id));
    }

    // -------------------------------------------------------------------------
    // buildChildResourceFilter()
    // -------------------------------------------------------------------------

    public function testBuildChildResourceFilterReturnsNullForAdmin(): void
    {
        $user = $this->createUser();
        \Yii::$app->db->createCommand()
            ->update('{{%user}}', ['is_superadmin' => 1], ['id' => $user->id])
            ->execute();

        $this->assertNull($this->checker->buildChildResourceFilter($user->id, 'project_id'));
    }

    /**
     * Regression: ['0=1'] made the query throw instead of matching nothing.
     */
    public function testBuildChildResourceFilterReturnsDenyForNullUser(): void
    {
        $this->createProject($this->createUser()->id);

        $filter = $this->checker->buildChildResourceFilter(null, 'project_id');

        $this->assertSame(ProjectAccessChecker::DENY_ALL, $filter);
        $this->assertSame(0, (int)Project::find()->andWhere($filter)->count());
    }

    public function testBuildChildResourceFilterReturnsNullWhenNoRestrictions(): void
    {
        $user = $this->createUser();
        $this->assertNull($this->checker->buildChildResourceFilter($user->id, 'project_id'));
    }

    public function testBuildChildResourceFilterReturnsOrConditionForTeamMember(): void
    {
        $owner = $this->createUser('owner');
        $member = $this->createUser('member');
        $project = $this->createProject($owner->id);
        $team = $this->createTeam($owner->id);
        $this->createTeamProject($team->id, $project->id, TeamProject::ROLE_OPERATOR);
        $this->addTeamMember($team->id, $member->id);

        $filter = $this->checker->buildChildResourceFilter($member->id, 'inventory.project_id');
        $this->assertIsArray($filter);
        $this->assertSame('or', $filter[0]);
    }

    // -------------------------------------------------------------------------
    // buildJobFilter()
    // -------------------------------------------------------------------------

    public function testBuildJobFilterReturnsNullForAdmin(): void
    {
        $user = $this->createUser();
        \Yii::$app->db->createCommand()
            ->update('{{%user}}', ['is_superadmin' => 1], ['id' => $user->id])
            ->execute();

        $this->assertNull($this->checker->buildJobFilter($user->id));
    }

    /**
     * Regression: ['0=1'] made the query throw instead of matching nothing.
     */
    public function testBuildJobFilterReturnsDenyForNullUser(): void
    {
        $filter = $this->checker->buildJobFilter(null);

        $this->assertSame(ProjectAccessChecker::DENY_ALL, $filter);
        $this->assertSame(0, (int)\app\models\Job::find()->andWhere($filter)->count());
    }

    public function testBuildJobFilterReturnsNullWhenNoRestrictions(): void
    {
        $user = $this->createUser();
        $this->assertNull($this->checker->buildJobFilter($user->id));
    }

    public function testBuildJobFilterReturnsInConditionForTeamMember(): void
    {
        $owner = $this->createUser('owner');
        $member = $this->createUser('member');
        $project = $this->createProject($owner->id);
        $team = $this->createTeam($owner->id);
        $this->createTeamProject($team->id, $project->id, TeamProject::ROLE_OPERATOR);
        $this->addTeamMember($team->id, $member->id);

        $filter = $this->checker->buildJobFilter($member->id);
        $this->assertIsArray($filter);
        $this->assertSame('in', $filter[0]);
        $this->assertSame('job_template_id', $filter[1]);
    }

    // -------------------------------------------------------------------------
    // buildChildOperateFilter()
    // -------------------------------------------------------------------------

    /**
     * Regression: ['0=1'] made the query throw instead of matching nothing.
     */
    public function testBuildChildOperateFilterDeniesEverythingForAGuest(): void
    {
        $this->createProject($this->createUser()->id);

        $filter = $this->checker->buildChildOperateFilter(null, 'project_id');

        $this->assertSame(ProjectAccessChecker::DENY_ALL, $filter);
        $this->assertSame(0, (int)Project::find()->andWhere($filter)->count());
    }

    public function testBuildChildOperateFilterReturnsNullForAnRbacAdmin(): void
    {
        $owner = $this->createUser('owner');
        $admin = $this->createUser('admin');
        $this->assignRole((int)$admin->id, 'admin');
        // A restricted project exists, so null is not the "nothing restricted" shortcut.
        $this->teamProjectId((int)$owner->id, (int)$this->createTeam($owner->id)->id, TeamProject::ROLE_VIEWER);

        $this->assertNull($this->checker->buildChildOperateFilter((int)$admin->id, 'project_id'));
    }

    public function testBuildChildOperateFilterReturnsNullForASuperadmin(): void
    {
        $owner = $this->createUser('owner');
        $superadmin = $this->createUser('superadmin');
        \Yii::$app->db->createCommand()
            ->update('{{%user}}', ['is_superadmin' => 1], ['id' => $superadmin->id])
            ->execute();
        $this->teamProjectId((int)$owner->id, (int)$this->createTeam($owner->id)->id, TeamProject::ROLE_VIEWER);

        $this->assertNull($this->checker->buildChildOperateFilter((int)$superadmin->id, 'project_id'));
    }

    public function testBuildChildOperateFilterReturnsNullWhenNoProjectIsRestricted(): void
    {
        $owner = $this->createUser('owner');
        $user = $this->createUser('regular');
        $this->assignRole((int)$user->id, 'operator');
        $this->createProject($owner->id);

        $this->assertNull($this->checker->buildChildOperateFilter((int)$user->id, 'project_id'));
    }

    /**
     * An operator-role team member may change rows of open projects, global
     * rows (NULL project_id) and restricted projects where one of their teams
     * holds the operator role, also when another of their teams only views it.
     */
    public function testBuildChildOperateFilterKeepsOpenGlobalAndOperatorRows(): void
    {
        $rows = $this->operateFilterFixture();

        $filter = $this->checker->buildChildOperateFilter($rows['member'], 'inventory.project_id');

        $this->assertIsArray($filter);
        $this->assertSame(
            [$rows['open'], $rows['global'], $rows['operator'], $rows['viewer_and_operator']],
            $this->filteredInventoryIds($rows, $filter)
        );
    }

    /**
     * Viewing is not operating: a project where the user's team only has
     * the viewer role passes the view filter but not the operate filter,
     * and another team's project passes neither.
     */
    public function testBuildChildOperateFilterExcludesViewerRoleAndForeignProjects(): void
    {
        $rows = $this->operateFilterFixture();

        $operate = $this->filteredInventoryIds($rows, $this->checker->buildChildOperateFilter($rows['member'], 'inventory.project_id'));
        $view = $this->filteredInventoryIds($rows, $this->checker->buildChildResourceFilter($rows['member'], 'inventory.project_id'));

        $this->assertNotContains($rows['viewer'], $operate);
        $this->assertContains($rows['viewer'], $view);
        $this->assertNotContains($rows['foreign'], $operate);
        $this->assertNotContains($rows['foreign'], $view);
    }

    // -------------------------------------------------------------------------
    // hasRestrictedProjects() and the cost of the filters
    // -------------------------------------------------------------------------

    public function testHasRestrictedProjectsOnceATeamIsGivenAProject(): void
    {
        $owner = $this->createUser('owner');
        $projectId = (int)$this->createProject($owner->id)->id;
        $teamId = (int)$this->createTeam($owner->id)->id;
        $this->assertFalse($this->checker->hasRestrictedProjects(), 'projects and teams alone restrict nothing');

        $this->createTeamProject($teamId, $projectId, TeamProject::ROLE_VIEWER);

        $this->assertTrue($this->checker->hasRestrictedProjects());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function filterProvider(): array
    {
        return [
            'project filter' => ['project'],
            'child resource filter' => ['child'],
            'child operate filter' => ['operate'],
            'job filter' => ['job'],
            'job template subquery' => ['templates'],
        ];
    }

    /**
     * Regression: the filters looked the user and their RBAC roles up before
     * the team restriction, several queries per call that changed nothing on
     * an installation without teams. Every approval vote paid them once per
     * approver.
     *
     * @dataProvider filterProvider
     */
    public function testWithoutRestrictedProjectsAFilterCostsOnlyTheRestrictionLookup(string $filter): void
    {
        $this->assertFalse($this->checker->hasRestrictedProjects(), 'precondition');
        $operator = $this->createUser('operator');
        $this->assignRole((int)$operator->id, 'operator');
        $operatorId = (int)$operator->id;
        $this->assertNull($this->filterFor($filter, $operatorId));

        $queries = $this->queriesOf(fn () => $this->filterFor($filter, $operatorId));

        $this->assertLessThanOrEqual(1, $queries, 'the restricted-project lookup only');
    }

    /**
     * @dataProvider filterProvider
     */
    public function testWithRestrictedProjectsAFilterIsNullForAnRbacAdminAndASuperadmin(string $filter): void
    {
        $owner = $this->createUser('owner');
        $this->teamProjectId((int)$owner->id, (int)$this->createTeam($owner->id)->id, TeamProject::ROLE_VIEWER);
        $admin = $this->createUser('admin');
        $this->assignRole((int)$admin->id, 'admin');
        $superadmin = $this->createUser('superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);

        $this->assertNull($this->filterFor($filter, (int)$admin->id));
        $this->assertNull($this->filterFor($filter, (int)$superadmin->id));
        $this->assertNotNull(
            $this->filterFor($filter, (int)$this->createUser('regular')->id),
            'control: a user without roles is restricted'
        );
    }

    /**
     * The ID of a user who no longer exists gets the restriction of a user
     * without teams.
     *
     * @dataProvider filterProvider
     */
    public function testWithRestrictedProjectsAFilterRestrictsAnUnknownUserLikeAUserWithoutTeams(string $filter): void
    {
        $owner = $this->createUser('owner');
        $this->teamProjectId((int)$owner->id, (int)$this->createTeam($owner->id)->id, TeamProject::ROLE_VIEWER);
        $gone = $this->createUser('gone');
        $goneId = (int)$gone->id;
        $gone->delete();

        $filterOfUnknown = $this->filterFor($filter, $goneId);

        $this->assertNotNull($filterOfUnknown);
        $this->assertEquals($this->filterFor($filter, (int)$this->createUser('regular')->id), $filterOfUnknown);
    }

    /**
     * Inventories (nullable project_id) in every access situation, and an
     * operator-role user who is member of two teams.
     *
     * @return array{member: int, open: int, global: int, operator: int, viewer: int, foreign: int, viewer_and_operator: int}
     */
    private function operateFilterFixture(): array
    {
        $owner = (int)$this->createUser('owner')->id;
        $member = (int)$this->createUser('member')->id;
        $this->assignRole($member, 'operator');
        $viewerTeam = (int)$this->createTeam($owner)->id;
        $operatorTeam = (int)$this->createTeam($owner)->id;
        $foreignTeam = (int)$this->createTeam($owner)->id;
        $this->addTeamMember($viewerTeam, $member);
        $this->addTeamMember($operatorTeam, $member);
        $mixedProject = $this->teamProjectId($owner, $viewerTeam, TeamProject::ROLE_VIEWER);
        $this->createTeamProject($operatorTeam, $mixedProject, TeamProject::ROLE_OPERATOR);

        return [
            'member' => $member,
            'open' => $this->inventoryIn($owner, (int)$this->createProject($owner)->id),
            'global' => $this->inventoryIn($owner, null),
            'operator' => $this->inventoryIn($owner, $this->teamProjectId($owner, $operatorTeam, TeamProject::ROLE_OPERATOR)),
            'viewer' => $this->inventoryIn($owner, $this->teamProjectId($owner, $viewerTeam, TeamProject::ROLE_VIEWER)),
            'foreign' => $this->inventoryIn($owner, $this->teamProjectId($owner, $foreignTeam, TeamProject::ROLE_OPERATOR)),
            'viewer_and_operator' => $this->inventoryIn($owner, $mixedProject),
        ];
    }

    /**
     * The fixture's inventory ids that pass the filter, ascending.
     *
     * @param array<string, int> $rows
     * @param array<int|string, mixed>|null $filter
     * @return list<int>
     */
    private function filteredInventoryIds(array $rows, ?array $filter): array
    {
        $query = Inventory::find()
            ->select('inventory.id')
            ->where(['inventory.id' => array_values(array_diff_key($rows, ['member' => true]))])
            ->orderBy(['inventory.id' => SORT_ASC]);
        if ($filter !== null) {
            $query->andWhere($filter);
        }

        return array_map('intval', $query->column());
    }

    private function inventoryIn(int $ownerId, ?int $projectId): int
    {
        $inventory = $this->createInventory($ownerId);
        $inventory->project_id = $projectId;
        $inventory->save(false);

        return (int)$inventory->id;
    }

    /**
     * The filter or subquery named in filterProvider() for the user.
     */
    private function filterFor(string $filter, int $userId): mixed
    {
        return match ($filter) {
            'project' => $this->checker->buildProjectFilter($userId),
            'child' => $this->checker->buildChildResourceFilter($userId, 'project_id'),
            'operate' => $this->checker->buildChildOperateFilter($userId, 'project_id'),
            'job' => $this->checker->buildJobFilter($userId),
            'templates' => $this->checker->templateIdSubquery($userId),
            default => $this->fail("Unknown filter '{$filter}'."),
        };
    }

    /**
     * A new project restricted to the team with the given role.
     */
    private function teamProjectId(int $ownerId, int $teamId, string $role): int
    {
        $projectId = (int)$this->createProject($ownerId)->id;
        $this->createTeamProject($teamId, $projectId, $role);

        return $projectId;
    }

    private function assignRole(int $userId, string $roleName): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->getRole($roleName);
        $this->assertNotNull($role, $roleName);
        $auth->assign($role, (string)$userId);
    }
}
