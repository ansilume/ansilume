<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\Team;
use app\tests\integration\DbTestCase;

class TeamIntegrationTest extends DbTestCase
{
    public function testHasMemberReturnsFalseForNonMember(): void
    {
        $user = $this->createUser();
        $team = $this->createTeam($user->id);

        $other = $this->createUser('other');

        $this->assertFalse($team->hasMember($other->id));
    }

    public function testHasMemberReturnsTrueAfterAddingMember(): void
    {
        $user   = $this->createUser();
        $team   = $this->createTeam($user->id);
        $member = $this->createUser('member');

        $this->addMember($team->id, $member->id);

        $this->assertTrue($team->hasMember($member->id));
    }

    public function testHasMemberReturnsFalseAfterMemberRemoved(): void
    {
        $user   = $this->createUser();
        $team   = $this->createTeam($user->id);
        $member = $this->createUser('ex');

        $this->addMember($team->id, $member->id);
        $this->assertTrue($team->hasMember($member->id));

        \app\models\TeamMember::deleteAll(['team_id' => $team->id, 'user_id' => $member->id]);

        $this->assertFalse($team->hasMember($member->id));
    }

    public function testHasMemberChecksCorrectTeam(): void
    {
        $user  = $this->createUser();
        $teamA = $this->createTeam($user->id);
        $teamB = $this->createTeam($user->id);

        $member = $this->createUser('member');
        $this->addMember($teamA->id, $member->id);

        $this->assertTrue($teamA->hasMember($member->id));
        $this->assertFalse($teamB->hasMember($member->id));
    }

    public function testTeamNameRequired(): void
    {
        $team = new Team();
        $team->validate();
        $this->assertArrayHasKey('name', $team->errors);
    }

    public function testTeamTableName(): void
    {
        $this->assertSame('{{%team}}', Team::tableName());
    }

    public function testTeamCreatorRelation(): void
    {
        $user = $this->createUser();
        $team = $this->createTeam($user->id);

        $this->assertNotNull($team->creator);
        $this->assertSame($user->id, $team->creator->id);
    }

    public function testTeamMembersRelation(): void
    {
        $user = $this->createUser();
        $team = $this->createTeam($user->id);
        $m1 = $this->createUser('m1');
        $m2 = $this->createUser('m2');
        $this->addMember($team->id, $m1->id);
        $this->addMember($team->id, $m2->id);

        $this->assertCount(2, $team->teamMembers);
        $this->assertCount(2, $team->members);
    }

    public function testTeamProjectsRelation(): void
    {
        $user = $this->createUser();
        $team = $this->createTeam($user->id);
        $proj = $this->createProject($user->id);
        $this->createTeamProject($team->id, $proj->id);

        $this->assertCount(1, $team->teamProjects);
        $this->assertCount(1, $team->projects);
        $this->assertSame($proj->id, $team->projects[0]->id);
    }

    // -------------------------------------------------------------------------

    private function addMember(int $teamId, int $userId): void
    {
        $this->addTeamMember($teamId, $userId);
    }
}
