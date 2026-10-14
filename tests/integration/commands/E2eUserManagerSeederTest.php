<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eUserManagerSeeder;
use app\models\Project;
use app\models\Team;
use app\models\TeamMember;
use app\models\TeamProject;
use app\models\User;
use app\services\AssignedRoles;
use app\services\RoleGrantPolicy;
use app\services\UserChangePolicy;
use app\tests\integration\DbTestCase;
use yii\rbac\DbManager;

/**
 * The users of tests/e2e/tests/users/user-managers.spec.ts
 * (E2eUserManagerSeeder): a help desk that manages accounts but may change
 * only users all of whose roles it may assign and whose teams give them
 * nothing beyond its own, a user with two roles, and a viewer whose team has
 * a restricted project. The rollback removes the users, the roles, the team
 * and the project.
 */
class E2eUserManagerSeederTest extends DbTestCase
{
    /** The fixture password of tests/e2e/lib/session.ts; not a secret. */
    private const FIXTURE_PASSWORD = 'E2eHelpDeskPass1!';

    /** @var list<string> */
    private array $log = [];

    /** @var list<string> the default roles of the manager before the test */
    private array $savedDefaultRoles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = [];
        $auth = $this->auth();
        $this->savedDefaultRoles = array_map('strval', $auth->defaultRoles);
        // As config/web.php, which the specs run against.
        $auth->defaultRoles = ['viewer'];
    }

    protected function tearDown(): void
    {
        $this->auth()->defaultRoles = $this->savedDefaultRoles;
        parent::tearDown();
    }

    /**
     * The help desk may change a viewer, not e2e-admin's kind (a superadmin
     * with the admin role), an admin, an operator or the user with two
     * roles, whose roles include operator.
     */
    public function testTheHelpDeskMayChangeAViewerButNoMorePrivilegedUser(): void
    {
        $this->seed();

        $helpDesk = $this->user(E2eUserManagerSeeder::HELP_DESK);
        $auth = $this->auth();
        $this->assertSame([E2eUserManagerSeeder::HELP_DESK_ROLE], AssignedRoles::fromApp()->of((int)$helpDesk->id));
        $this->assertEqualsCanonicalizing(E2eUserManagerSeeder::HELP_DESK_PERMISSIONS, array_keys($auth->getChildren(E2eUserManagerSeeder::HELP_DESK_ROLE)));
        $policy = new UserChangePolicy(new RoleGrantPolicy($auth, $helpDesk), AssignedRoles::fromApp());
        $superadmin = $this->createUserWithRole('admin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);

        $this->assertTrue($policy->mayChange($this->createUserWithRole('viewer')));
        $this->assertFalse($policy->mayChange($superadmin));
        $this->assertFalse($policy->mayChange($this->createUserWithRole('admin')));
        $this->assertFalse($policy->mayChange($this->createUserWithRole('operator')));
        $this->assertFalse($policy->mayChange($this->user(E2eUserManagerSeeder::MULTI_ROLE)));
    }

    public function testTheUserWithTwoRolesHoldsOperatorAndTheSecondRole(): void
    {
        $this->seed();

        $multiRole = $this->user(E2eUserManagerSeeder::MULTI_ROLE);
        $this->assertSame([E2eUserManagerSeeder::SECOND_ROLE, 'operator'], AssignedRoles::fromApp()->of((int)$multiRole->id));
        $this->assertEqualsCanonicalizing(E2eUserManagerSeeder::SECOND_ROLE_PERMISSIONS, array_keys($this->auth()->getChildren(E2eUserManagerSeeder::SECOND_ROLE)));
    }

    public function testTheUsersAreActiveNonSuperadminsAndTheHelpDeskHasTheFixturePassword(): void
    {
        $this->seed();

        foreach ([E2eUserManagerSeeder::HELP_DESK, E2eUserManagerSeeder::MULTI_ROLE, E2eUserManagerSeeder::TEAM_MEMBER] as $name) {
            $user = $this->user($name);
            $this->assertSame(User::STATUS_ACTIVE, (int)$user->status, $name);
            $this->assertFalse((bool)$user->is_superadmin, $name);
        }
        $this->assertTrue($this->user(E2eUserManagerSeeder::HELP_DESK)->validatePassword(self::FIXTURE_PASSWORD));
        $this->assertFalse($this->user(E2eUserManagerSeeder::MULTI_ROLE)->validatePassword(self::FIXTURE_PASSWORD));
        $this->assertFalse($this->user(E2eUserManagerSeeder::TEAM_MEMBER)->validatePassword(self::FIXTURE_PASSWORD));
        $this->assertSame(
            [
                "  Restored user 'e2e-help-desk' (ID {$this->user(E2eUserManagerSeeder::HELP_DESK)->id}) with the roles 'e2e-user-manager'.\n",
                "  Restored user 'e2e-multi-role' (ID {$this->user(E2eUserManagerSeeder::MULTI_ROLE)->id}) with the roles 'operator', 'e2e-second-role'.\n",
                "  Restored user 'e2e-member-viewer' (ID {$this->user(E2eUserManagerSeeder::TEAM_MEMBER)->id}) with the roles 'viewer'.\n",
                "  Restored team 'e2e-member-team' (ID {$this->team()->id}) with the member 'e2e-member-viewer' and the project 'e2e-member-project' (viewer).\n",
            ],
            $this->log
        );
        $this->assertSame(
            sprintf(E2eUserManagerSeeder::RESTORED, E2eUserManagerSeeder::HELP_DESK, $this->user(E2eUserManagerSeeder::HELP_DESK)->id, E2eUserManagerSeeder::HELP_DESK_ROLE),
            $this->log[0]
        );
    }

    /**
     * A spec, or a person, may have changed the fixtures: a seed restores
     * the users, their roles and the roles' permissions, and keeps the IDs
     * and the password of the user with two roles.
     */
    public function testSeedingAgainRestoresTheUsersAndTheRoles(): void
    {
        $this->seed();
        $helpDesk = $this->user(E2eUserManagerSeeder::HELP_DESK);
        $multiRole = $this->user(E2eUserManagerSeeder::MULTI_ROLE);
        $passwordHash = $multiRole->password_hash;
        $helpDesk->status = User::STATUS_INACTIVE;
        $helpDesk->is_superadmin = true;
        $helpDesk->setPassword('Changed-by-a-spec-1!');
        $helpDesk->save(false);
        $auth = $this->auth();
        $auth->revokeAll((int)$multiRole->id);
        $admin = $auth->getRole('admin');
        $role = $auth->getRole(E2eUserManagerSeeder::HELP_DESK_ROLE);
        $launch = $auth->getPermission('job.launch');
        $this->assertNotNull($admin);
        $this->assertNotNull($role);
        $this->assertNotNull($launch);
        $auth->assign($admin, (int)$helpDesk->id);
        $auth->addChild($role, $launch);

        $this->seed();

        $restored = $this->user(E2eUserManagerSeeder::HELP_DESK);
        $this->assertSame((int)$helpDesk->id, (int)$restored->id);
        $this->assertSame(1, (int)User::find()->where(['username' => E2eUserManagerSeeder::HELP_DESK])->count());
        $this->assertSame(User::STATUS_ACTIVE, (int)$restored->status);
        $this->assertFalse((bool)$restored->is_superadmin);
        $this->assertTrue($restored->validatePassword(self::FIXTURE_PASSWORD));
        $this->assertSame([E2eUserManagerSeeder::HELP_DESK_ROLE], AssignedRoles::fromApp()->of((int)$restored->id));
        $this->assertFalse($auth->checkAccess((int)$restored->id, 'job.launch'));
        $this->assertSame([E2eUserManagerSeeder::SECOND_ROLE, 'operator'], AssignedRoles::fromApp()->of((int)$multiRole->id));
        $this->assertSame($passwordHash, $this->user(E2eUserManagerSeeder::MULTI_ROLE)->password_hash, 'the random password stays');
    }

    public function testAUserThatCannotBeSavedIsReportedAndGetsNoRole(): void
    {
        // The e-mail address is taken by another user.
        $other = $this->createUser('other');
        $other->email = E2eUserManagerSeeder::HELP_DESK . '@example.com';
        $other->save(false);

        $this->seed();

        $this->assertNull(User::findOne(['username' => E2eUserManagerSeeder::HELP_DESK]));
        $this->assertCount(4, $this->log);
        $this->assertSame(
            sprintf(E2eUserManagerSeeder::NOT_RESTORED, E2eUserManagerSeeder::HELP_DESK, '{"email":["Email \\"e2e-help-desk@example.com\\" has already been taken."]}'),
            $this->log[0]
        );
        $this->assertStringStartsWith("  Failed to restore user 'e2e-help-desk': ", $this->log[0]);
        $this->assertStringNotContainsString(self::FIXTURE_PASSWORD, $this->log[0]);
        $this->assertNotNull(User::findOne(['username' => E2eUserManagerSeeder::MULTI_ROLE]), 'the other user is restored');
    }

    /**
     * The team member holds only viewer, a role the help desk may assign,
     * and is the only member of a team that views one project: the help desk,
     * in no team, may not change them (TEAM_ACCESS_REFUSED); an admin may.
     */
    public function testTheTeamMemberIsBeyondTheHelpDeskButNotBeyondAnAdmin(): void
    {
        $this->seed();

        $member = $this->user(E2eUserManagerSeeder::TEAM_MEMBER);
        $team = $this->team();
        $project = Project::findOne(['name' => E2eUserManagerSeeder::TEAM_PROJECT]);
        $this->assertNotNull($project);
        $this->assertSame(['viewer'], AssignedRoles::fromApp()->of((int)$member->id));
        $this->assertSame([(int)$member->id], $this->membersOf($team));
        $this->assertSame([(int)$project->id => TeamProject::ROLE_VIEWER], $this->projectsOf($team));
        $helpDesk = new UserChangePolicy(new RoleGrantPolicy($this->auth(), $this->user(E2eUserManagerSeeder::HELP_DESK)), AssignedRoles::fromApp());
        $admin = new UserChangePolicy(new RoleGrantPolicy($this->auth(), $this->createUserWithRole('admin')), AssignedRoles::fromApp());

        $this->assertSame(UserChangePolicy::TEAM_ACCESS_REFUSED, $helpDesk->refusalOf($member));
        $this->assertNull($admin->refusalOf($member));
    }

    /**
     * A spec, or a person, may have changed the team: a seed restores
     * exactly its member and its project, with the viewer role, and keeps the
     * IDs and the member's password.
     */
    public function testSeedingAgainRestoresTheTeam(): void
    {
        $this->seed();
        $member = $this->user(E2eUserManagerSeeder::TEAM_MEMBER);
        $team = $this->team();
        $project = Project::findOne(['name' => E2eUserManagerSeeder::TEAM_PROJECT]);
        $this->assertNotNull($project);
        $passwordHash = $member->password_hash;
        TeamProject::updateAll(['role' => TeamProject::ROLE_OPERATOR], ['team_id' => $team->id]);
        $other = $this->createProject((int)$member->id);
        $this->createTeamProject((int)$team->id, (int)$other->id, TeamProject::ROLE_VIEWER);
        $this->addTeamMember((int)$team->id, (int)$this->user(E2eUserManagerSeeder::HELP_DESK)->id);
        TeamMember::deleteAll(['team_id' => $team->id, 'user_id' => $member->id]);
        $this->auth()->revokeAll((int)$member->id);

        $this->seed();

        $this->assertSame((int)$team->id, (int)$this->team()->id);
        $this->assertSame((int)$project->id, (int)Project::findOne(['name' => E2eUserManagerSeeder::TEAM_PROJECT])?->id);
        $this->assertSame([(int)$member->id], $this->membersOf($team));
        $this->assertSame([(int)$project->id => TeamProject::ROLE_VIEWER], $this->projectsOf($team));
        $this->assertSame(['viewer'], AssignedRoles::fromApp()->of((int)$member->id));
        $this->assertSame($passwordHash, $this->user(E2eUserManagerSeeder::TEAM_MEMBER)->password_hash, 'the random password stays');
    }

    /**
     * Without its member, the team is not seeded: the seed says why the
     * member was not restored.
     */
    public function testATeamMemberThatCannotBeSavedGetsNoTeam(): void
    {
        $other = $this->createUser('other');
        $other->email = E2eUserManagerSeeder::TEAM_MEMBER . '@example.com';
        $other->save(false);

        $this->seed();

        $this->assertNull(User::findOne(['username' => E2eUserManagerSeeder::TEAM_MEMBER]));
        $this->assertNull(Team::findOne(['name' => E2eUserManagerSeeder::TEAM]));
        $this->assertStringStartsWith("  Failed to restore user 'e2e-member-viewer': ", (string)end($this->log));
    }

    /**
     * teams/members.spec.ts and teams/projects.spec.ts open the first row of
     * the teams list, sorted by name, whose text contains "e2e-team", and
     * add a member or a project to that team: no text of this team's row
     * (name, description, creator) may contain it, or those specs would give
     * the help desk or the member more.
     */
    public function testNoTextOfTheTeamsRowInTheTeamsListContainsE2eTeam(): void
    {
        $this->seed();

        $team = $this->team();
        $creator = User::findOne($team->created_by);
        $this->assertNotNull($creator);
        foreach (['name' => $team->name, 'description' => (string)$team->description, 'creator' => $creator->username] as $column => $text) {
            $this->assertStringNotContainsString('e2e-team', $text, $column);
        }
    }

    /**
     * yii e2e/teardown removes what is named with the E2E prefix: the users,
     * the custom roles, the team and the project.
     */
    public function testEveryFixtureIsNamedWithTheE2ePrefix(): void
    {
        $names = [
            E2eUserManagerSeeder::HELP_DESK, E2eUserManagerSeeder::HELP_DESK_ROLE, E2eUserManagerSeeder::SECOND_ROLE,
            E2eUserManagerSeeder::MULTI_ROLE, E2eUserManagerSeeder::TEAM_MEMBER, E2eUserManagerSeeder::TEAM,
            E2eUserManagerSeeder::TEAM_PROJECT,
        ];
        foreach ($names as $name) {
            $this->assertStringStartsWith('e2e-', $name);
        }
    }

    private function team(): Team
    {
        $team = Team::findOne(['name' => E2eUserManagerSeeder::TEAM]);
        $this->assertInstanceOf(Team::class, $team);

        return $team;
    }

    /**
     * @return list<int> the IDs of the members of $team, ascending
     */
    private function membersOf(Team $team): array
    {
        return array_map('intval', TeamMember::find()->select('user_id')->where(['team_id' => $team->id])->orderBy(['user_id' => SORT_ASC])->column());
    }

    /**
     * @return array<int, string> project ID => the role of $team on it
     */
    private function projectsOf(Team $team): array
    {
        $projects = [];
        foreach (TeamProject::find()->where(['team_id' => $team->id])->all() as $grant) {
            $projects[(int)$grant->project_id] = $grant->role;
        }

        return $projects;
    }

    private function seed(): void
    {
        (new E2eUserManagerSeeder(function (string $msg): void {
            $this->log[] = $msg;
        }))->seed();
    }

    private function createUserWithRole(string $roleName): User
    {
        $user = $this->createUser('seeded-' . $roleName);
        $role = $this->auth()->getRole($roleName);
        $this->assertNotNull($role, $roleName);
        $this->auth()->assign($role, (int)$user->id);

        return $user;
    }

    private function user(string $name): User
    {
        $user = User::findOne(['username' => $name]);
        $this->assertInstanceOf(User::class, $user, $name);

        return $user;
    }

    private function auth(): DbManager
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(DbManager::class, $auth);

        return $auth;
    }
}
