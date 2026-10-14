<?php

declare(strict_types=1);

namespace app\commands;

use app\models\Team;
use app\models\TeamMember;
use app\models\TeamProject;
use app\models\User;
use yii\rbac\ManagerInterface;
use yii\rbac\Role;

/**
 * The users of tests/e2e/tests/users/user-managers.spec.ts:
 *
 * - HELP_DESK holds only HELP_DESK_ROLE: it views, edits, deactivates and
 *   deletes accounts, but may change only users all of whose roles it may
 *   assign (app\services\UserChangePolicy): viewers, not e2e-admin (a
 *   superadmin and an admin) or an operator.
 * - MULTI_ROLE holds operator and SECOND_ROLE, as an LDAP group mapping gives
 *   them: its Edit User form says that saving with the role shown keeps both
 *   (app\models\UserForm::SEVERAL_ROLES_HINT). It never signs in, so its
 *   password is random.
 * - TEAM_MEMBER holds only viewer, a role the help desk may assign, and is
 *   the only member of TEAM, which views TEAM_PROJECT and nothing else: a
 *   restricted project the help desk, in no team, does not reach. So the help
 *   desk may not change TEAM_MEMBER either
 *   (UserChangePolicy::TEAM_ACCESS_REFUSED); admins may. It never signs in,
 *   so its password is random.
 *
 * Restored on every seed: the roles' permissions, the users (active, no
 * superadmins), the help desk's fixture password and exactly their roles;
 * the team with exactly its member and its project. Teardown removes the
 * users, the roles, the team and the project (users, custom roles, teams and
 * projects named e2e-*).
 */
final class E2eUserManagerSeeder
{
    public const HELP_DESK_ROLE = 'e2e-user-manager';

    /** What the help desk may do: manage accounts, including deactivating and deleting them. */
    public const HELP_DESK_PERMISSIONS = ['user.view', 'user.update', 'user.delete'];

    public const HELP_DESK = 'e2e-help-desk';

    public const SECOND_ROLE = 'e2e-second-role';

    /** The second role of MULTI_ROLE: an approver's permissions, as an LDAP group mapping may give them. */
    public const SECOND_ROLE_PERMISSIONS = ['approval.view', 'approval.decide'];

    public const MULTI_ROLE = 'e2e-multi-role';

    /** A user who holds only viewer and is the only member of TEAM. */
    public const TEAM_MEMBER = 'e2e-member-viewer';

    /** The team of TEAM_MEMBER: it views TEAM_PROJECT and has no other project. */
    public const TEAM = 'e2e-member-team';

    /** The project of TEAM: restricted, since a team has it. */
    public const TEAM_PROJECT = 'e2e-member-project';

    /** What a seed says of a restored user: their name, ID and roles, joined with "', '". */
    public const RESTORED = "  Restored user '%s' (ID %d) with the roles '%s'.\n";

    /** What a seed says of a user it could not save: their name and the validation errors as JSON. */
    public const NOT_RESTORED = "  Failed to restore user '%s': %s\n";

    /** What a seed says of TEAM: its name and ID, its member, its project and the team's role on it. */
    public const TEAM_RESTORED = "  Restored team '%s' (ID %d) with the member '%s' and the project '%s' (%s).\n";

    /** The fixture password of HELP_DESK in tests/e2e/lib/session.ts; not a secret. */
    private const HELP_DESK_PASSWORD = 'E2eHelpDeskPass1!';

    /** @var callable(string): void */
    private $logger;

    /**
     * @param callable(string): void $logger
     */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    public function seed(): void
    {
        /** @var ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        $helpDeskRole = $this->role($auth, self::HELP_DESK_ROLE, 'E2E: manages the accounts of users whose roles it may assign', self::HELP_DESK_PERMISSIONS);
        $secondRole = $this->role($auth, self::SECOND_ROLE, 'E2E: the second role of ' . self::MULTI_ROLE, self::SECOND_ROLE_PERMISSIONS);

        $helpDesk = $this->restored(self::HELP_DESK);
        $helpDesk->setPassword(self::HELP_DESK_PASSWORD);
        $this->save($auth, $helpDesk, [$helpDeskRole]);

        $multiRole = $this->restoredWithoutSignIn(self::MULTI_ROLE);
        $this->save($auth, $multiRole, array_values(array_filter([$auth->getRole('operator'), $secondRole])));

        $teamMember = $this->restoredWithoutSignIn(self::TEAM_MEMBER);
        if ($this->save($auth, $teamMember, array_values(array_filter([$auth->getRole('viewer')])))) {
            $this->seedTeam($teamMember);
        }
    }

    /**
     * TEAM with exactly TEAM_MEMBER as its member and TEAM_PROJECT, which it
     * views, as its project. The member created both, so they need no other
     * fixture.
     */
    private function seedTeam(User $member): void
    {
        $project = E2eFixtureHelper::project(self::TEAM_PROJECT, (int)$member->id);
        $team = Team::findOne(['name' => self::TEAM]) ?? new Team();
        $team->name = self::TEAM;
        // No text of the team's row in the teams list may contain "e2e-team":
        // teams/members.spec.ts and teams/projects.spec.ts open the first row
        // that does, and add a member or a project to that team.
        $team->description = 'E2E: ' . self::TEAM_MEMBER . ' is its only member; it only views ' . self::TEAM_PROJECT;
        $team->created_by = (int)$member->id;
        $team->save(false);

        TeamMember::deleteAll(['team_id' => $team->id]);
        $membership = new TeamMember();
        $membership->team_id = (int)$team->id;
        $membership->user_id = (int)$member->id;
        $membership->created_at = time();
        $membership->save(false);

        TeamProject::deleteAll(['team_id' => $team->id]);
        $grant = new TeamProject();
        $grant->team_id = (int)$team->id;
        $grant->project_id = (int)$project->id;
        $grant->role = TeamProject::ROLE_VIEWER;
        $grant->created_at = time();
        $grant->save(false);

        ($this->logger)(sprintf(self::TEAM_RESTORED, self::TEAM, (int)$team->id, self::TEAM_MEMBER, self::TEAM_PROJECT, TeamProject::ROLE_VIEWER));
    }

    /**
     * The user $name as restored(), who never signs in: a new one gets a
     * random password, a stored one keeps theirs.
     */
    private function restoredWithoutSignIn(string $name): User
    {
        $user = $this->restored($name);
        if ($user->isNewRecord) {
            $user->setPassword(\Yii::$app->security->generateRandomString(24));
        }

        return $user;
    }

    /**
     * The user $name, stored or new, as the specs expect it: active, no
     * superadmin; not saved yet.
     */
    private function restored(string $name): User
    {
        $user = User::findOne(['username' => $name]) ?? new User();
        $user->username = $name;
        $user->email = $name . '@example.com';
        $user->status = User::STATUS_ACTIVE;
        $user->is_superadmin = false;
        if ($user->isNewRecord) {
            $user->generateAuthKey();
        }

        return $user;
    }

    /**
     * Saves $user and gives them exactly $roles; false, and said, when the
     * user cannot be saved.
     *
     * @param list<Role> $roles
     */
    private function save(ManagerInterface $auth, User $user, array $roles): bool
    {
        if (!$user->save()) {
            ($this->logger)(sprintf(self::NOT_RESTORED, $user->username, (string)json_encode($user->errors)));
            return false;
        }
        $auth->revokeAll((int)$user->id);
        foreach ($roles as $role) {
            $auth->assign($role, (int)$user->id);
        }
        $names = implode("', '", array_map(static fn (Role $role): string => $role->name, $roles));

        ($this->logger)(sprintf(self::RESTORED, $user->username, (int)$user->id, $names));

        return true;
    }

    /**
     * The role $name with exactly $permissions.
     *
     * @param list<string> $permissions
     */
    private function role(ManagerInterface $auth, string $name, string $description, array $permissions): Role
    {
        $role = $auth->getRole($name);
        if ($role === null) {
            $role = $auth->createRole($name);
            $role->description = $description;
            $auth->add($role);
        }
        $auth->removeChildren($role);
        foreach ($permissions as $permissionName) {
            $permission = $auth->getPermission($permissionName);
            if ($permission !== null) {
                $auth->addChild($role, $permission);
            }
        }

        return $role;
    }
}
