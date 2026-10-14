<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\commands\SetupController;
use app\controllers\api\v1\TeamsController;
use app\controllers\api\v1\UsersController;
use app\controllers\TeamController;
use app\controllers\UserController;
use app\models\AuditLog;
use app\models\RoleForm;
use app\models\User;
use app\services\RoleChangePolicy;
use app\services\RoleGrantPolicy;
use app\services\RoleService;
use app\services\UserChangePolicy;
use PHPUnit\Framework\TestCase;

/**
 * What docs/updating.md (the section for the update from 2.10.1),
 * troubleshooting.md, README.md and CLAUDE.md say about who may change
 * users, roles and teams is what the code does, in its words: the admin
 * role (RoleGrantPolicy::ADMIN_ROLE_REFUSED), the team rule of user changes
 * (UserChangePolicy::TEAM_ACCESS_REFUSED), the only superadmin's own
 * account (UserController::ONLY_SUPERADMIN, UsersController), role editing
 * (RoleChangePolicy, RoleForm::NOT_HELD_HINT) and team management
 * (TeamController, TeamsController). The queries the section gives run in
 * RoleChangesQueryDocsContractTest and TeamMemberChangesQueryDocsContractTest;
 * what an open project is, in TeamManagementDocsContractTest.
 *
 * Regressions (wave 5d of 2.11.0), each failing on the docs from before:
 * the intro said users who are no superadmins no longer change the accounts
 * of users "who hold more than they do", though the rule weighed roles
 * only, so a help desk could still change a team member; nothing said that
 * whoever held role.update or role.create could give any role, their own
 * and viewer included, any permission; and nothing named the team rule,
 * the admin role, the only superadmin's own account or what team
 * management decides.
 *
 * Regressions (wave 5e of 2.11.0), each failing on the docs from before:
 * the docs quoted the refusals of "the only superadmin", which counted
 * inactive superadmins, and said nothing of setup/admin as the way back in
 * (SuperadminRecoveryDocsContractTest pins its troubleshooting entry); and
 * README.md said a user who is no superadmin changes only users whose teams
 * give them no project beyond their own, leaving out the role on a project
 * that the team rule weighs too.
 *
 * Regression (wave 5f of 2.11.0), failing on the docs from before: nothing
 * named the refusal of a user with the admin role to a user who holds every
 * permission of their roles but not the admin role
 * (UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED), which said they lack
 * permissions (UserChangePolicy::CHANGE_REFUSED) up to wave 5e.
 *
 * Regression (wave 5g of 2.11.0), failing on the docs from before: the docs
 * said a user whose teams refuse the change as well gets
 * UserChangePolicy::CHANGE_REFUSED, as UserChangePolicy answered; it answers
 * UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED wherever only the admin role
 * refuses now, whatever the teams, as the admin role would lift the team
 * rule too. The team refusal (UserChangePolicy::TEAM_ACCESS_REFUSED) comes
 * only where the roles allow the change, and the docs say so.
 */
class DelegatedAdministrationDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;
    use ReadsTheUpdateSection;

    private const TROUBLESHOOTING = 'docs/troubleshooting.md';

    /**
     * regression: the intro overstated the rule of user changes, which
     * weighed roles only, and named no rule for roles.
     */
    public function testRegressionTheIntroSaysExactlyWhoChangesAccountsAndRoles(): void
    {
        $intro = $this->intro();
        $expected = [
            'Only a superadmin gives the superadmin flag, and only superadmins and admins give the admin role (see **Security: who may give roles and the superadmin flag**).',
            'A user who is no superadmin no longer changes the account of a superadmin, nor that of a user whose roles or teams give that user more than they have themselves (see **Security: who may change other users\' accounts**), nor gives a role a permission they do not hold, nor changes or deletes a role that grants more than they hold (see **Security: who may change roles**).',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $intro, 'The intro of the section of updating.md');
        }
        $this->assertStringNotContainsString('who hold more than they do', $intro, 'The intro of wave 5c overstated the rule of the roles.');
    }

    /**
     * regression: nothing said who may change roles. The item says what
     * role.update, role.create and role.delete allowed up to 2.10.1, what
     * RoleChangePolicy allows now, in its words and those of the role form,
     * gives the query of the role changes to check
     * (RoleChangesQueryDocsContractTest runs it), and step 10 of the
     * checklist points to it. RoleService takes a RoleChangePolicy for
     * every write.
     */
    public function testRegressionTheRolesItemSaysWhoMayChangeRolesAndWhatToCheck(): void
    {
        $item = $this->item('**Security: who may change roles.**');
        $expected = [
            'Up to 2.10.1, whoever held `role.update` could give any role, their own and the default role viewer included, any permission, and so take `user.update` or `approval-rule.update`; whoever held `role.create` could create a role with any permission, and whoever held `role.delete` could delete any custom role.',
            'From 2.11.0 on, a user who is no superadmin creates a role only with permissions they hold, through their roles or the default role, and changes or deletes a role only when they may assign it (see **Security: who may give roles and the superadmin flag**), adding to it only permissions they hold; removing permissions needs no more.',
            'The New Role and Edit Role forms disable the permissions they do not hold and say `' . RoleForm::NOT_HELD_HINT . '`; one sent anyway is refused with `'
                . sprintf(RoleChangePolicy::PERMISSION_REFUSED, 'user.update') . '`, as an error of the Permissions field and as a `403` of `POST /api/v1/roles` and `PUT /api/v1/roles/{name}`.',
            'The Edit Role form of a role they may not assign, opened or saved, **Delete**, and `PUT` and `DELETE /api/v1/roles/{name}` answer `403` `' . RoleChangePolicy::CHANGE_REFUSED
                . '`, after the `404` of a role that does not exist; the role page no longer offers **Edit** and **Delete** for such a role. Nothing is written.',
            'Superadmins change every role, and so do admins, who hold every permission and the admin role.',
            '-- Roles created, changed or deleted by users who are no superadmin',
            'The `metadata` of `' . AuditLog::ACTION_ROLE_CREATED . '` lists the permissions of the role, and that of `' . AuditLog::ACTION_ROLE_UPDATED . '` the permissions it `added` and `removed`.',
            'and on the Roles page the permissions of your custom roles and of viewer, which every signed-in user holds; take from them what their holders should not hold (step 10 above).',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $item, 'The roles item of updating.md');
        }
        $this->assertStringNotContainsString('curl', $item, 'The item shows no way to take a permission.');
        $this->assertMentions(
            '10. Check the roles that users who are no superadmin created, changed or deleted (the `' . AuditLog::ACTION_ROLE_CREATED . '`, `' . AuditLog::ACTION_ROLE_UPDATED . '` and `'
                . AuditLog::ACTION_ROLE_DELETED . '` entries of the audit log) with the read-only query under **Security: who may change roles**, and on the Roles page the permissions of your custom roles and of viewer, which every signed-in user holds.',
            $this->checklist(10),
            'Step 10 of the checklist'
        );
        foreach (['createRole', 'updateRole', 'deleteRole'] as $write) {
            $types = array_map(
                static fn (\ReflectionParameter $parameter): string => (string)$parameter->getType(),
                (new \ReflectionMethod(RoleService::class, $write))->getParameters()
            );
            $this->assertContains(RoleChangePolicy::class, $types, "RoleService::{$write}() takes a RoleChangePolicy, as the docs say.");
        }
    }

    /**
     * regression: nothing named the admin role, the team rule of user
     * changes or the only superadmin's own account. The roles item quotes
     * RoleGrantPolicy::ADMIN_ROLE_REFUSED; the accounts item the team rule
     * (UserChangePolicy::TEAM_ACCESS_REFUSED, with the query that
     * TeamMemberChangesQueryDocsContractTest runs) and what the only
     * active superadmin may do with their own account (UserController,
     * UsersController); step 9 names both queries of the item.
     *
     * regression (wave 5e): the item quoted the refusals of "the only
     * superadmin", which counted inactive superadmins, and named neither
     * Delete nor setup/admin (SetupController::ACTIVE_SUPERADMIN_EXISTS).
     * The text of 2.10.1 stays a literal: the constant holds the new one.
     */
    public function testRegressionTheAccountsItemNamesTheAdminRoleTheTeamRuleAndTheOnlySuperadmin(): void
    {
        $this->assertSame('admin', RoleGrantPolicy::ADMIN_ROLE, 'The role the docs call the admin role.');
        $this->assertMentions(
            'The `admin` role gives every team-restricted project on top of its permissions, so it, and a role that contains it, is assigned only by a superadmin or by a user who holds the `admin` role; a user who holds every permission of it through another role gets `'
                . sprintf(RoleGrantPolicy::ADMIN_ROLE_REFUSED, 'admin') . '`, as an error of the field and as a `403` of the API, and nothing is written.',
            $this->item('**Security: who may give roles and the superadmin flag.**'),
            'The roles and superadmin item of updating.md'
        );
        $item = $this->item('**Security: who may change other users\' accounts.**');
        $expected = [
            'Teams give access to team-restricted projects beyond any role, so a user who is neither a superadmin nor holds the `admin` role changes a user only when, for every project the user\'s teams give the user, their own teams give them that project with at least the same role (operator over viewer; the highest role of their teams counts)',
            'Such a change, where the roles allow it, answers `403` `' . UserChangePolicy::TEAM_ACCESS_REFUSED . '` in the same places, and the pages offer no **Edit**, **Deactivate**, **Activate** or **Delete** for that user.',
            'The only active superadmin now opens and saves their own Edit User form; up to 2.10.1 opening it answered `403` `Cannot remove or demote the only superadmin account.`',
            'A save that would clear their superadmin flag or deactivate them answers `403` `' . UserController::ONLY_SUPERADMIN . '`, and `PUT /api/v1/users/{id}` answers `422` `'
                . UsersController::ONLY_SUPERADMIN_DEMOTED . '` or, new, `' . UsersController::ONLY_SUPERADMIN_DEACTIVATED
                . '`: up to 2.10.1 the API let anyone who held `user.update` deactivate the only superadmin, that superadmin included.',
            'Only active superadmins count: up to 2.10.1 an inactive superadmin counted as another superadmin, so the last active superadmin could deactivate or demote themselves while the only other superadmin was inactive, and then nobody could sign in as a superadmin.',
            '**Delete** of the only active superadmin answers the same `403`, and `DELETE /api/v1/users/{id}` answers `422` `' . UsersController::ONLY_SUPERADMIN_DELETED
                . '`; an inactive superadmin may be demoted, deactivated or deleted.',
            '`php yii setup/admin` creates a superadmin whenever no active superadmin exists, the way back in when no superadmin can sign in (see [troubleshooting.md](troubleshooting.md#no-superadmin-can-sign-in-any-more)); while one exists it answers `'
                . SetupController::ACTIVE_SUPERADMIN_EXISTS . '` (up to 2.10.1: `A superadmin already exists. Aborting.`, for an inactive superadmin too).',
            '-- Changes to the accounts of team members by users without the same access to the team\'s projects',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $item, 'The accounts item of updating.md');
        }
        $this->assertStringNotContainsString('still answers that `403`', $item, 'The save of 2.10.1 answered another text.');
    }

    /**
     * regression (wave 5f): a user who holds every permission of the admin
     * role but not the role was told that they lack permissions when they
     * changed a user with the admin role. The accounts item, the API
     * contract change and the troubleshooting entry name
     * UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED, which UserChangePolicy
     * gives wherever only the admin role refuses, whatever the teams
     * (regression of wave 5g: the docs said a user whose teams refuse as
     * well gets CHANGE_REFUSED).
     */
    public function testRegressionTheDocsNameTheRefusalOfAUserWithTheAdminRole(): void
    {
        $this->assertSame(
            'You cannot change or delete this user: a role assigned to them is or contains the admin role, which only a superadmin or an admin may assign.',
            UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED
        );
        $item = $this->item('**Security: who may change other users\' accounts.**');
        $this->assertMentions(
            'A user who holds every permission that the user\'s roles grant, but is neither a superadmin nor holds the `admin` role, gets `403` `' . UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED
                . '` instead, in the same places, for a user who is no superadmin and holds the `admin` role or a role that contains it, whatever the user\'s teams, as the `admin` role also gives every team-restricted project.',
            $item,
            'The accounts item of updating.md'
        );
        $this->assertStringNotContainsString('unless the user\'s teams refuse the change as well', $item, 'The teams do not decide the refusal.');
        $api = $this->between($this->flat('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION), '**API contract change:**', '**Database and deployment.**');
        $this->assertMentions(
            '- `PUT` and `DELETE /api/v1/users/{id}` answer `403` `' . UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED
                . '` in place of that answer when the caller holds every permission of the user\'s roles but neither is a superadmin nor holds the `admin` role, and the user is no superadmin and holds the `admin` role or a role that contains it, whatever the user\'s teams; nothing is written.',
            $api,
            'The API contract change of updating.md'
        );
        $this->assertStringNotContainsString('and the user\'s teams do not refuse the change', $api, 'The teams do not decide the refusal.');
        $troubleshooting = $this->flat(self::TROUBLESHOOTING, '## Changing a user is refused');
        $this->assertMentions(
            'A user who holds every permission of the user\'s roles, but neither is a superadmin nor holds the `admin` role, gets the second refusal for a user who is no superadmin and holds the `admin` role or a role that contains it, whatever the user\'s teams: the `admin` role also gives every team-restricted project.',
            $troubleshooting,
            'troubleshooting.md, on refused user changes'
        );
        $this->assertStringNotContainsString('where the user\'s teams refuse as well, the first', $troubleshooting, 'The teams do not decide the refusal.');
        $policy = (string)file_get_contents(dirname(__DIR__, 3) . '/services/UserChangePolicy.php');
        $this->assertStringContainsString(
            "return \$this->onlyTheAdminRoleRefuses(\$user, \$roles)\n            ? self::ADMIN_ROLE_CHANGE_REFUSED\n            : self::CHANGE_REFUSED;",
            $policy,
            'Wherever only the admin role refuses, whatever the teams.'
        );
        $this->assertStringNotContainsString(
            "&& \$this->teamAccessOf([\$userId])[\$userId]\n            ? self::ADMIN_ROLE_CHANGE_REFUSED",
            $policy,
            'The teams are not asked on the refusal path.'
        );
    }

    /**
     * The API contract change names every new answer of the users and
     * roles endpoints, in the words of the code; OpenApiUsersContractTest
     * and OpenApiRolesContractTest check that the API answers them.
     */
    public function testTheApiContractChangeNamesTheNewRefusalsOfUsersAndRoles(): void
    {
        $api = $this->between($this->flat('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION), '**API contract change:**', '**Database and deployment.**');
        $expected = [
            '`PUT` and `DELETE /api/v1/users/{id}` answer `403` `' . UserChangePolicy::TEAM_ACCESS_REFUSED
                . '` where neither of those answers comes, when the caller is neither a superadmin nor holds the `admin` role and the user\'s teams give the user a project that the caller\'s teams do not give the caller, or only with a lower role.',
            '`POST /api/v1/users` and `PUT /api/v1/users/{id}` answer `403` `' . sprintf(RoleGrantPolicy::ADMIN_ROLE_REFUSED, 'admin')
                . '` for the `admin` role, or a role that contains it, from a caller who holds every permission it grants but not the `admin` role; nothing is written.',
            '`PUT /api/v1/users/{id}` answers `422` `' . UsersController::ONLY_SUPERADMIN_DEACTIVATED
                . '` when the only active superadmin sends a `status` other than 10 for their own account, and nothing is written; anyone else gets the `403` of a user they may not change. Up to 2.10.1 anyone who held `user.update` could deactivate the only superadmin.',
            'The other two answers about the only superadmin name the only active superadmin: `PUT /api/v1/users/{id}` with `is_superadmin: false` answers `422` `' . UsersController::ONLY_SUPERADMIN_DEMOTED
                . '` (was `Cannot demote the only superadmin.`), and `DELETE /api/v1/users/{id}` answers `422` `' . UsersController::ONLY_SUPERADMIN_DELETED
                . '` (was `Cannot delete the only superadmin.`). An inactive superadmin no longer counts as another superadmin for these three answers, and may be demoted, deactivated or deleted.',
            '`POST /api/v1/roles` and `PUT /api/v1/roles/{name}` answer `403` `' . sprintf(RoleChangePolicy::PERMISSION_REFUSED, 'user.update')
                . '` for a permission the caller does not hold, which `PUT` checks for the permissions it adds, after the `422` of a body that does not validate;',
            '`PUT` and `DELETE /api/v1/roles/{name}` answer `403` `' . RoleChangePolicy::CHANGE_REFUSED
                . '` for a role the caller may not assign, after the `404` of an unknown role and before every other answer. Nothing is written.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $api, 'The API contract change of updating.md');
        }
        $this->assertMentions(
            'and that the users and roles endpoints answer `403` for a change the caller may not make (see **API contract change**).',
            $this->checklist(4),
            'Step 4 of the checklist'
        );
    }

    /**
     * The known limits say that whoever may change a role changes what
     * every holder may do, viewer included (the default role of every
     * signed-in user), and that team management is administrative: it
     * takes the admin role (TeamController, TeamsController), and a project
     * without a team is open (TeamManagementDocsContractTest).
     */
    public function testTheKnownLimitsNameRoleEditingAndTeamManagement(): void
    {
        $limits = $this->item('**Known limits.**');
        $expected = [
            'Whoever may change a role changes what every user who holds it may do. Every signed-in user holds viewer, the default role, so whoever holds `role.update` may change viewer and give every user the permissions they hold themselves. Grant `role.update` only to users who may give their own permissions to everyone.',
            'Team management is administrative: whoever may change a team\'s members or its projects, which takes the admin role or the superadmin flag, decides who reaches which team-restricted project, and reaches every project themselves.',
            'The rule of **Security: who may change other users\' accounts** takes team memberships as they stand.',
            'A project that no team has is open: every signed-in user reaches it, as far as their roles allow, so removing a project from its last team, or deleting that team, opens the project to everyone.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $limits, 'The known limits of updating.md');
        }
        $config = require dirname(__DIR__, 3) . '/config/web.php';
        $this->assertIsArray($config);
        $this->assertSame(['viewer'], $config['components']['authManager']['defaultRoles'] ?? null, 'viewer is the default role the limit names.');

        $web = new \ReflectionClass(TeamController::class);
        $rules = $web->getMethod('accessRules')->invoke($web->newInstanceWithoutConstructor());
        $this->assertIsArray($rules);
        $this->assertNotEmpty($rules);
        foreach ($rules as $rule) {
            $this->assertSame([RoleGrantPolicy::ADMIN_ROLE], $rule['roles'] ?? null, 'Every team page takes the admin role.');
        }
        $api = new \ReflectionClass(TeamsController::class);
        $permissions = $api->getMethod('apiAccessRules')->invoke($api->newInstanceWithoutConstructor());
        $this->assertIsArray($permissions);
        $this->assertSame([RoleGrantPolicy::ADMIN_ROLE], array_values(array_unique($permissions)), 'Every teams endpoint takes the admin role.');
    }

    /**
     * The fixes name the permission listed twice (RoleService::createRole()
     * grants it once) and the preview of a text artifact, whose failure
     * text the job page's script writes; artifacts.md says the same.
     */
    public function testTheFixesNameTheDuplicatePermissionAndTheArtifactPreview(): void
    {
        $fixes = $this->between($this->flat('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION), '**Fixes that change behaviour:**', '**API contract change:**');
        $failed = 'Failed to load preview.';
        $expected = [
            '`POST /api/v1/roles`, and a New Role form sent by hand, with a permission listed twice answered `500` and left the role half created, without an audit entry; the permission is granted once now.',
            'The preview of a text artifact on a job\'s page says `' . $failed . '` when the server refuses it (signed out, or no access any more) or cannot find the artifact or its file; it showed an empty preview for such an answer. Opened again, the preview is fetched again.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $fixes, 'The fixes of updating.md');
        }
        $this->assertStringContainsString('array_values(array_unique($form->permissions))', (string)file_get_contents(dirname(__DIR__, 3) . '/services/RoleService.php'));

        $script = $this->previewScript();
        $this->assertStringContainsString("fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })", $script);
        $this->assertStringContainsString("if (!r.ok) {", $script, 'An answer that is not OK fails the preview.');
        $this->assertStringContainsString("content.textContent = '" . $failed . "';", $script);

        $artifacts = $this->flat('docs/artifacts.md');
        $this->assertMentions(
            'A text preview that the server refuses (signed out, or no access any more) or cannot find shows `' . $failed . '`; open it again to fetch it again.',
            $artifacts,
            'artifacts.md'
        );
        $this->assertMentions(
            '`GET /api/v1/jobs/{id}/artifacts/{aid}/content` answers `415` `Artifact type is not previewable.` for a MIME type outside the previewable allowlist',
            $artifacts,
            'artifacts.md'
        );
        $this->assertStringNotContainsString('Preview returns 415', $artifacts, 'The job page offers no Preview that answers 415.');
        $this->assertStringContainsString("'Artifact type is not previewable.', 415", (string)file_get_contents(dirname(__DIR__, 3) . '/controllers/api/v1/JobsController.php'));
    }

    /**
     * The troubleshooting entries on refused changes of users and roles
     * quote every refusal as the code words it.
     */
    public function testTheTroubleshootingEntriesQuoteTheRefusalsOfUsersAndRoles(): void
    {
        $users = $this->flat(self::TROUBLESHOOTING, '## Changing a user is refused');
        foreach (
            [
                UserChangePolicy::CHANGE_REFUSED,
                UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED,
                UserChangePolicy::TEAM_ACCESS_REFUSED,
                sprintf(RoleGrantPolicy::ROLE_REFUSED, 'admin'),
                sprintf(RoleGrantPolicy::ADMIN_ROLE_REFUSED, 'admin'),
                RoleGrantPolicy::SUPERADMIN_REFUSED,
                UserController::ONLY_SUPERADMIN,
                UsersController::ONLY_SUPERADMIN_DEMOTED,
                UsersController::ONLY_SUPERADMIN_DEACTIVATED,
                UsersController::ONLY_SUPERADMIN_DELETED,
            ] as $quote
        ) {
            $this->assertMentions('`' . $quote . '`', $users, 'troubleshooting.md, on refused user changes');
        }
        $this->assertMentions('Unless they hold the `admin` role, their own teams must also give them every team-restricted project that the user\'s teams give the user, with at least the same role (operator over viewer).', $users, 'troubleshooting.md');
        $this->assertMentions(
            'An inactive superadmin does not count, as they cannot sign in, and may be demoted, deactivated or deleted. Make another user a superadmin, or reactivate one, first; when no superadmin can sign in at all, see [No superadmin can sign in any more](#no-superadmin-can-sign-in-any-more).',
            $users,
            'troubleshooting.md, on refused user changes'
        );

        $roles = $this->flat(self::TROUBLESHOOTING, '## Changing a role is refused');
        foreach ([sprintf(RoleChangePolicy::PERMISSION_REFUSED, 'user.update'), RoleChangePolicy::CHANGE_REFUSED, RoleForm::NOT_HELD_HINT] as $quote) {
            $this->assertMentions('`' . $quote . '`', $roles, 'troubleshooting.md, on refused role changes');
        }
        $this->assertMentions('Removing permissions from a role they may change is never refused.', $roles, 'troubleshooting.md');
    }

    /**
     * CLAUDE.md's rule 14 names the admin role, the team rule, the only
     * active superadmin's own account and setup/admin, role editing and team
     * management, with the classes that decide them; README.md's security
     * list says the same.
     *
     * regression (wave 5e): README.md's list left out the role on a project
     * that the team rule weighs (UserChangePolicy::TEAM_ACCESS_REFUSED also
     * answers a user whom a team gives a project as operator, where the
     * changer's teams give it as viewer).
     */
    public function testClaudeMdAndReadmeNameTheRulesOfUsersRolesAndTeams(): void
    {
        $claude = $this->flat('CLAUDE.md');
        $expected = [
            'the admin role (`RoleGrantPolicy::ADMIN_ROLE`), which gives every team-restricted project on top of its permissions, and a role that contains it, only by a superadmin or a user who holds the admin role (`RoleGrantPolicy::ADMIN_ROLE_REFUSED`)',
            'The only active superadmin edits their own account; only a save that would clear their flag or deactivate them, and their deletion, are refused (`UserController::ONLY_SUPERADMIN`, `UsersController::ONLY_SUPERADMIN_DEMOTED`, `ONLY_SUPERADMIN_DEACTIVATED`, `ONLY_SUPERADMIN_DELETED`); only active superadmins count (`User::activeSuperadminExists()`), an inactive one may be demoted, deactivated or deleted, and `php yii setup/admin` creates a superadmin while no active superadmin exists (`SetupController::ACTIVE_SUPERADMIN_EXISTS` otherwise).',
            'A user who is no superadmin creates a role only with permissions they hold, and changes or deletes a role (the Edit Role form, opened or saved, Delete, `PUT` and `DELETE /api/v1/roles/{name}`) only when they may assign it, adding to it only permissions they hold (`'
                . RoleChangePolicy::class . '`, which `RoleService` takes for every write)',
            'otherwise the answer is 403 (`RoleChangePolicy::CHANGE_REFUSED`) after the 404 and before every other check, or `RoleChangePolicy::PERMISSION_REFUSED` for a permission they do not hold',
            'New code that writes a role (its permissions, description or existence) goes through `RoleService` with a `RoleChangePolicy`.',
            'Team management is administrative: whoever may change a team\'s members or projects (the admin role or the superadmin flag: `TeamController`, `TeamsController`) decides who reaches which team-restricted project, and a project that no team has is open to every signed-in user (`app\services\ProjectAccessChecker`)',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $claude, 'Rule 14 of CLAUDE.md');
        }
        $this->assertTrue(defined(UsersController::class . '::ONLY_SUPERADMIN_DELETED'), 'CLAUDE.md names UsersController::ONLY_SUPERADMIN_DELETED.');
        $this->assertTrue(defined(SetupController::class . '::ACTIVE_SUPERADMIN_EXISTS'), 'CLAUDE.md names SetupController::ACTIVE_SUPERADMIN_EXISTS.');
        $this->assertTrue(method_exists(User::class, 'activeSuperadminExists'), 'CLAUDE.md names User::activeSuperadminExists().');
        $readme = $this->flat('README.md');
        $readmeSays = [
            'the `admin` role, and a role that contains it, only by a superadmin or an admin; a user who is no superadmin changes or deletes only users who are no superadmins, whose roles they may assign and, unless they are admins, whose teams give them no project beyond their own, nor a higher role on one, and gives a role only permissions they hold and changes or deletes only roles they may assign',
            'only admins and superadmins manage teams, and a project that no team has is open to every signed-in user',
        ];
        foreach ($readmeSays as $phrase) {
            $this->assertMentions($phrase, $readme, 'The security list of README.md');
        }
        $this->assertStringNotContainsString('whose teams give them no project beyond their own, and gives', $readme, 'The team rule weighs the role on a project too.');
    }

    /**
     * The intro of the section: its first paragraph, which ends with "What
     * to do:".
     */
    private function intro(): string
    {
        foreach ($this->units('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION) as $unit) {
            if (str_ends_with($unit, 'What to do:')) {
                return $unit;
            }
        }
        $this->fail('The section of updating.md has no intro.');
    }

    /**
     * The script of the job page that previews artifacts, as
     * views/job/view.php (the repository's) holds it.
     */
    private function previewScript(): string
    {
        $view = (string)file_get_contents(dirname(__DIR__, 3) . '/views/job/view.php');
        $start = strpos($view, "<script>\ndocument.querySelectorAll('.artifact-preview-btn')");
        $this->assertNotFalse($start, 'views/job/view.php has the preview script.');
        $end = strpos($view, '</script>', $start);
        $this->assertNotFalse($end);

        return substr($view, $start, $end - $start);
    }
}
