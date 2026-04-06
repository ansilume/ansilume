<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\Team;
use app\models\TeamMember;
use app\models\User;
use app\tests\integration\DbTestCase;

class TeamMemberTest extends DbTestCase
{
    public function testTableName(): void
    {
        $this->assertSame('{{%team_member}}', TeamMember::tableName());
    }

    public function testPersistsMembership(): void
    {
        $user = $this->createUser();
        $team = $this->createTeam($user->id);
        $member = $this->addTeamMember((int)$team->id, (int)$user->id);

        $this->assertNotNull($member->id);
        $reloaded = TeamMember::findOne($member->id);
        $this->assertNotNull($reloaded);
        $this->assertSame($team->id, $reloaded->team_id);
        $this->assertSame($user->id, $reloaded->user_id);
    }

    public function testRequiredFieldValidation(): void
    {
        $member = new TeamMember();
        $this->assertFalse($member->validate());
        $this->assertArrayHasKey('team_id', $member->errors);
        $this->assertArrayHasKey('user_id', $member->errors);
    }

    public function testUniqueConstraintIsValidated(): void
    {
        $user = $this->createUser();
        $team = $this->createTeam($user->id);
        $this->addTeamMember((int)$team->id, (int)$user->id);

        $dup = new TeamMember();
        $dup->team_id = $team->id;
        $dup->user_id = $user->id;
        $this->assertFalse($dup->validate());
    }

    public function testTeamRelationResolvesBack(): void
    {
        $user = $this->createUser();
        $team = $this->createTeam($user->id);
        $member = $this->addTeamMember((int)$team->id, (int)$user->id);

        $this->assertInstanceOf(Team::class, $member->team);
        $this->assertSame($team->id, $member->team->id);
    }

    public function testUserRelationResolvesBack(): void
    {
        $user = $this->createUser();
        $team = $this->createTeam($user->id);
        $member = $this->addTeamMember((int)$team->id, (int)$user->id);

        $this->assertInstanceOf(User::class, $member->user);
        $this->assertSame($user->id, $member->user->id);
    }
}
