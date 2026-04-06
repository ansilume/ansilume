<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\Project;
use app\models\Team;
use app\models\TeamProject;
use app\tests\integration\DbTestCase;

class TeamProjectTest extends DbTestCase
{
    public function testTableName(): void
    {
        $this->assertSame('{{%team_project}}', TeamProject::tableName());
    }

    public function testPersistAndRetrieve(): void
    {
        $user = $this->createUser();
        $team = $this->createTeam($user->id);
        $project = $this->createProject($user->id);
        $tp = $this->createTeamProject((int)$team->id, (int)$project->id);

        $this->assertNotNull($tp->id);
        $reloaded = TeamProject::findOne($tp->id);
        $this->assertNotNull($reloaded);
        $this->assertSame($team->id, $reloaded->team_id);
        $this->assertSame($project->id, $reloaded->project_id);
        $this->assertSame(TeamProject::ROLE_OPERATOR, $reloaded->role);
    }

    public function testValidationRequiresFields(): void
    {
        $tp = new TeamProject();
        $this->assertFalse($tp->validate());
        $this->assertArrayHasKey('team_id', $tp->errors);
        $this->assertArrayHasKey('project_id', $tp->errors);
        $this->assertArrayHasKey('role', $tp->errors);
    }

    public function testRoleValidation(): void
    {
        $user = $this->createUser();
        $team = $this->createTeam($user->id);
        $project = $this->createProject($user->id);

        $tp = new TeamProject();
        $tp->team_id = $team->id;
        $tp->project_id = $project->id;
        $tp->role = 'admin';
        $this->assertFalse($tp->validate());
        $this->assertArrayHasKey('role', $tp->errors);
    }

    public function testUniqueConstraint(): void
    {
        $user = $this->createUser();
        $team = $this->createTeam($user->id);
        $project = $this->createProject($user->id);
        $this->createTeamProject((int)$team->id, (int)$project->id);

        $dup = new TeamProject();
        $dup->team_id = $team->id;
        $dup->project_id = $project->id;
        $dup->role = TeamProject::ROLE_VIEWER;
        $this->assertFalse($dup->validate());
    }

    public function testTeamRelation(): void
    {
        $user = $this->createUser();
        $team = $this->createTeam($user->id);
        $project = $this->createProject($user->id);
        $tp = $this->createTeamProject((int)$team->id, (int)$project->id);

        $this->assertInstanceOf(Team::class, $tp->team);
        $this->assertSame($team->id, $tp->team->id);
    }

    public function testProjectRelation(): void
    {
        $user = $this->createUser();
        $team = $this->createTeam($user->id);
        $project = $this->createProject($user->id);
        $tp = $this->createTeamProject((int)$team->id, (int)$project->id);

        $this->assertInstanceOf(Project::class, $tp->project);
        $this->assertSame($project->id, $tp->project->id);
    }
}
