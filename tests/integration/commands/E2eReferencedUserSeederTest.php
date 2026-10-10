<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eReferencedUserSeeder;
use app\models\Team;
use app\models\TeamMember;
use app\models\TeamProject;
use app\models\User;
use app\services\UserDeletionService;
use app\tests\integration\DbTestCase;

/**
 * The fixture of the users spec that deletes a user whom another record
 * still refers to (tests/e2e/tests/users/crud.spec.ts). The test database
 * holds no "e2e-" rows outside a test, and the rollback removes them.
 */
class E2eReferencedUserSeederTest extends DbTestCase
{
    /** @var list<string> */
    private array $log = [];

    public function testSeedsAnActiveUserWhomTheirTeamKeepsFromBeingDeleted(): void
    {
        $this->seed();

        $user = $this->seededUser();
        $this->assertSame(User::STATUS_ACTIVE, (int)$user->status);
        $team = $this->seededTeam();
        $this->assertSame((int)$user->id, (int)$team->created_by);
        $this->assertFalse((new UserDeletionService())->delete($user), 'the team still refers to its creator');
        $this->assertNotNull(User::findOne($user->id));
        // A team without members and projects restricts nobody.
        $this->assertFalse(TeamMember::find()->where(['team_id' => $team->id])->exists());
        $this->assertFalse(TeamProject::find()->where(['team_id' => $team->id])->exists());
        $this->assertSame(
            ["  Restored user e2e-referenced-user (ID {$user->id}), creator of team e2e-referenced-user-team.\n"],
            $this->log
        );
    }

    public function testSeedingAgainRestoresTheUserAndTheTeamInsteadOfAddingThem(): void
    {
        $this->seed();
        $first = $this->seededUser();
        $first->status = User::STATUS_INACTIVE;
        $first->save(false);

        $this->seed();

        $this->assertSame(1, (int)User::find()->where(['username' => E2eReferencedUserSeeder::USERNAME])->count());
        $this->assertSame(1, (int)Team::find()->where(['name' => E2eReferencedUserSeeder::TEAM])->count());
        $again = $this->seededUser();
        $this->assertSame((int)$first->id, (int)$again->id);
        $this->assertSame(User::STATUS_ACTIVE, (int)$again->status, 'a deactivated fixture is active again');
        $this->assertSame($first->password_hash, $again->password_hash, 'the password is set once');
        $this->assertSame((int)$again->id, (int)$this->seededTeam()->created_by);
    }

    private function seed(): void
    {
        (new E2eReferencedUserSeeder(function (string $msg): void {
            $this->log[] = $msg;
        }))->seed();
    }

    private function seededUser(): User
    {
        $user = User::findOne(['username' => E2eReferencedUserSeeder::USERNAME]);
        $this->assertNotNull($user);

        return $user;
    }

    private function seededTeam(): Team
    {
        $team = Team::findOne(['name' => E2eReferencedUserSeeder::TEAM]);
        $this->assertNotNull($team);

        return $team;
    }
}
