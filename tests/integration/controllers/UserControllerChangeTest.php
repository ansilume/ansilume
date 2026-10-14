<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\UserController;
use app\models\AuditLog;
use app\models\TeamMember;
use app\models\TeamProject;
use app\models\User;
use app\models\UserForm;
use app\services\RoleGrantPolicy;
use app\services\UserChangePolicy;
use app\tests\integration\CountsQueries;
use app\tests\integration\TeamScopeFixtures;
use yii\rbac\DbManager;
use yii\web\AssetManager;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\View;

/**
 * Who may change or delete which user on the Users pages
 * (UserChangePolicy): a superadmin everyone; anyone else a user who is no
 * superadmin and all of whose assigned roles they may assign. Whatever writes
 * another user's account answers 403 otherwise: the Edit User form, opened or
 * saved, the status toggle and Delete, after the 404 of a user that does not
 * exist and before every other check. The users list and the user page offer
 * Edit, the toggle and Delete only for the users the signed-in user may
 * change.
 *
 * Up to 2.10.1 whoever held user.update set the password and the email of
 * any user, a superadmin included, and could then sign in as them. The help
 * desk of these tests holds a custom role with user.view and user.update, and
 * user.delete where a test says so, as an admin can build in the role editor.
 * Neither a superadmin nor an admin, it changes a team member only when its
 * own teams give it every project the member's teams give the member, with
 * at least the same role (UserChangePolicy::TEAM_ACCESS_REFUSED otherwise).
 * Run with the default role viewer of the web application (config/web.php).
 */
final class UserControllerChangeTest extends WebControllerTestCase
{
    use CountsQueries;
    use TeamScopeFixtures;

    /** The help desk's permissions: managing accounts, but neither deactivating nor deleting them. */
    private const HELP_DESK = ['user.view', 'user.update'];

    /** A help desk that may also deactivate and delete accounts. */
    private const HELP_DESK_WITH_DELETE = ['user.view', 'user.update', 'user.delete'];

    /** The password a take-over would set; a test value, not a secret. */
    private const TAKEN_OVER_PASSWORD = 'TakenOverPass1!';

    /** @var list<string> the default roles of the manager before the test */
    private array $savedDefaultRoles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $auth = $this->auth();
        $this->savedDefaultRoles = array_map('strval', $auth->defaultRoles);
        $auth->defaultRoles = ['viewer'];
    }

    protected function tearDown(): void
    {
        $this->auth()->defaultRoles = $this->savedDefaultRoles;
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function privilegedUserProvider(): array
    {
        return ['a superadmin with the admin role' => [true], 'an admin who is no superadmin' => [false]];
    }

    /**
     * regression: the help desk opened the Edit User form of a superadmin or
     * of an admin and set their password and email: it could then sign in
     * as them, or have their password reset sent to an address of its own.
     * Opening and saving the form answer 403; nothing is saved or audited.
     *
     * @dataProvider privilegedUserProvider
     */
    public function testRegressionAHelpDeskCannotSetThePasswordOrTheEmailOfAPrivilegedUser(bool $superadmin): void
    {
        $this->loginAs($this->helpDesk('web_change_help_desk', self::HELP_DESK));
        $target = $this->privilegedUser($superadmin);
        $before = $this->stateOf($target);

        $this->assertRefused(fn () => $this->makeController()->actionUpdate($target->id), 'opening the form');
        $this->setPost(['UserForm' => $this->formOf($target, [
            'password' => self::TAKEN_OVER_PASSWORD,
            'email' => 'taken-over-' . uniqid('', true) . '@example.com',
        ])]);
        $this->assertRefused(fn () => $this->makeController()->actionUpdate($target->id), 'saving the form');

        $this->assertSame($before, $this->stateOf($target), 'nothing is written');
        $this->assertFalse($this->stored($target)->validatePassword(self::TAKEN_OVER_PASSWORD));
        $this->assertNull($this->audit(AuditLog::ACTION_USER_UPDATED, $target->id));
    }

    /**
     * regression: a help desk that may deactivate accounts (user.delete)
     * deactivated a superadmin or an admin.
     *
     * @dataProvider privilegedUserProvider
     */
    public function testRegressionAHelpDeskCannotDeactivateAPrivilegedUser(bool $superadmin): void
    {
        $this->loginAs($this->helpDesk('web_toggle_help_desk', self::HELP_DESK_WITH_DELETE));
        $target = $this->privilegedUser($superadmin);
        $before = $this->stateOf($target);
        $this->setPost([]);

        $this->assertRefused(fn () => $this->makeController()->actionToggleStatus($target->id), 'the status toggle');

        $this->assertSame($before, $this->stateOf($target), 'still active');
        $this->assertNull($this->audit(AuditLog::ACTION_USER_STATUS_CHANGED, $target->id));
    }

    /**
     * regression: a help desk that may delete accounts deleted a superadmin
     * (while another remained) or an admin.
     *
     * @dataProvider privilegedUserProvider
     */
    public function testRegressionAHelpDeskCannotDeleteAPrivilegedUser(bool $superadmin): void
    {
        $this->loginAs($this->helpDesk('web_delete_help_desk', self::HELP_DESK_WITH_DELETE));
        $target = $this->privilegedUser($superadmin);
        $before = $this->stateOf($target);
        $this->setPost([]);

        $this->assertRefused(fn () => $this->makeController()->actionDelete($target->id), 'Delete');

        $this->assertSame($before, $this->stateOf($target), 'not deleted, roles kept');
        $this->assertNull($this->audit(AuditLog::ACTION_USER_DELETED, $target->id));
    }

    /**
     * regression: an admin who is no superadmin holds every permission but
     * not the flag, and set a superadmin's password and email, deactivated
     * or deleted them.
     */
    public function testRegressionAnAdminWhoIsNoSuperadminCannotChangeASuperadmin(): void
    {
        $this->loginAs($this->userWithRoles('web_change_admin', ['admin']));
        $target = $this->privilegedUser(true);
        $before = $this->stateOf($target);

        $this->assertRefused(fn () => $this->makeController()->actionUpdate($target->id), 'opening the form');
        $this->setPost(['UserForm' => $this->formOf($target, [
            'password' => self::TAKEN_OVER_PASSWORD,
            'email' => 'taken-over-' . uniqid('', true) . '@example.com',
        ])]);
        $this->assertRefused(fn () => $this->makeController()->actionUpdate($target->id), 'saving the form');
        $this->setPost([]);
        $this->assertRefused(fn () => $this->makeController()->actionToggleStatus($target->id), 'the status toggle');
        $this->assertRefused(fn () => $this->makeController()->actionDelete($target->id), 'Delete');

        $this->assertSame($before, $this->stateOf($target), 'nothing is written');
        $this->assertNull($this->audit(AuditLog::ACTION_USER_UPDATED, $target->id));
        $this->assertNull($this->audit(AuditLog::ACTION_USER_STATUS_CHANGED, $target->id));
        $this->assertNull($this->audit(AuditLog::ACTION_USER_DELETED, $target->id));
    }

    /**
     * The help desk may still change a viewer: set the password and the
     * email, deactivate the account and delete it, each audited.
     */
    public function testAHelpDeskMayStillChangeAViewer(): void
    {
        $this->loginAs($this->helpDesk('web_viewer_help_desk', self::HELP_DESK_WITH_DELETE));
        $viewer = $this->userWithRoles('web_change_viewer', ['viewer']);
        $email = 'changed-' . uniqid('', true) . '@example.com';
        $this->setPost(['UserForm' => $this->formOf($viewer, ['role' => 'viewer', 'password' => self::TAKEN_OVER_PASSWORD, 'email' => $email])]);

        $saved = $this->makeController();
        $saved->actionUpdate($viewer->id);

        $this->assertSame(['view', 'id' => $viewer->id], $saved->capturedRedirect, 'the form was saved');
        $this->assertSame($email, $this->stored($viewer)->email);
        $this->assertTrue($this->stored($viewer)->validatePassword(self::TAKEN_OVER_PASSWORD));
        $this->assertNotNull($this->audit(AuditLog::ACTION_USER_UPDATED, $viewer->id));

        $this->setPost([]);
        $this->makeController()->actionToggleStatus($viewer->id);
        $this->assertSame(User::STATUS_INACTIVE, $this->stored($viewer)->status);
        $this->assertNotNull($this->audit(AuditLog::ACTION_USER_STATUS_CHANGED, $viewer->id));

        $deleted = $this->makeController();
        $deleted->actionDelete($viewer->id);
        $this->assertSame(['index'], $deleted->capturedRedirect);
        $this->assertNull(User::findOne($viewer->id));
        $this->assertNotNull($this->audit(AuditLog::ACTION_USER_DELETED, $viewer->id));
    }

    /**
     * A superadmin may change everyone: another superadmin's password and
     * email, an admin's status, an operator's account.
     */
    public function testASuperadminMayChangeEveryone(): void
    {
        $actor = $this->userWithRoles('web_change_superadmin', ['admin']);
        $other = $this->userWithRoles('web_change_other_superadmin', ['admin']);
        $this->makeTheOnlySuperadmins($actor, $other);
        $this->loginAs($actor);
        $email = 'changed-' . uniqid('', true) . '@example.com';
        $this->setPost(['UserForm' => $this->formOf($other, ['role' => 'admin', 'password' => self::TAKEN_OVER_PASSWORD, 'email' => $email])]);

        $saved = $this->makeController();
        $saved->actionUpdate($other->id);

        $this->assertSame(['view', 'id' => $other->id], $saved->capturedRedirect, 'the form was saved');
        $this->assertSame($email, $this->stored($other)->email);
        $this->assertTrue($this->stored($other)->validatePassword(self::TAKEN_OVER_PASSWORD));

        $admin = $this->userWithRoles('web_change_plain_admin', ['admin']);
        $this->setPost([]);
        $this->makeController()->actionToggleStatus($admin->id);
        $this->assertSame(User::STATUS_INACTIVE, $this->stored($admin)->status);

        $operator = $this->userWithRoles('web_change_operator', ['operator']);
        $this->makeController()->actionDelete($operator->id);
        $this->assertNull(User::findOne($operator->id));
    }

    /**
     * The 404 of a user that does not exist comes first.
     */
    public function testAUserThatDoesNotExistIsNotFoundFirst(): void
    {
        $this->loginAs($this->helpDesk('web_missing_help_desk', self::HELP_DESK_WITH_DELETE));
        $missing = (int)User::find()->max('id') + 1000;
        $actions = [
            'update' => fn () => $this->makeController()->actionUpdate($missing),
            'toggle-status' => fn () => $this->makeController()->actionToggleStatus($missing),
            'delete' => fn () => $this->makeController()->actionDelete($missing),
        ];

        foreach ($actions as $action => $call) {
            try {
                $call();
                $this->fail("{$action}: expected NotFoundHttpException");
            } catch (NotFoundHttpException $e) {
                $this->assertSame("User #{$missing} not found.", $e->getMessage(), $action);
            }
        }
    }

    /**
     * The refusal comes before every other check: an admin who is no
     * superadmin hears it for the only superadmin, whose own check says
     * "Cannot remove, demote or deactivate the only active superadmin
     * account.".
     */
    public function testTheRefusalComesBeforeTheOtherChecks(): void
    {
        $this->loginAs($this->userWithRoles('web_order_admin', ['admin']));
        $target = $this->userWithRoles('web_order_only_superadmin', ['admin']);
        $this->makeTheOnlySuperadmins($target);
        $this->setPost(['UserForm' => $this->formOf($target, ['is_superadmin' => '0'])]);

        $this->assertRefused(fn () => $this->makeController()->actionUpdate($target->id), 'saving the form');
        $this->assertRefused(fn () => $this->makeController()->actionDelete($target->id), 'Delete');
        $this->assertTrue((bool)$this->stored($target)->is_superadmin);
    }

    /**
     * A user manager may change their own account, whose roles they hold: the
     * other checks answer as before.
     */
    public function testOnesOwnAccountIsLeftToTheOtherChecks(): void
    {
        $helpDesk = $this->helpDesk('web_self_help_desk', self::HELP_DESK_WITH_DELETE);
        $this->loginAs($helpDesk);

        $this->assertSame('form', $this->openedForm($helpDesk), 'the own Edit User form opens');
        $this->setPost([]);
        foreach (['toggle-status' => fn () => $this->makeController()->actionToggleStatus($helpDesk->id), 'delete' => fn () => $this->makeController()->actionDelete($helpDesk->id)] as $action => $call) {
            try {
                $call();
                $this->fail("{$action}: expected ForbiddenHttpException");
            } catch (ForbiddenHttpException $e) {
                $this->assertSame('You cannot perform this action on your own account.', $e->getMessage(), $action);
            }
        }
    }

    // -- Team access ----------------------------------------------------------

    /**
     * regression: a help desk in no team set the password and the email of
     * a viewer whose team has a restricted project, deactivated or deleted
     * them: it could then sign in as them and read what only the team may.
     * Opening and saving the form, the status toggle and Delete answer 403
     * TEAM_ACCESS_REFUSED; nothing is written or audited.
     */
    public function testRegressionAHelpDeskInNoTeamCannotChangeATeamMember(): void
    {
        $this->loginAs($this->helpDesk('web_team_help_desk', self::HELP_DESK_WITH_DELETE));
        $member = $this->teamMember('web_team_member', TeamProject::ROLE_VIEWER);
        $before = $this->stateOf($member);

        $refusals = ['opening the form' => $this->refusalOf(fn () => $this->makeController()->actionUpdate($member->id))];
        $this->setPost(['UserForm' => $this->formOf($member, [
            'role' => 'viewer',
            'password' => self::TAKEN_OVER_PASSWORD,
            'email' => 'taken-over-' . uniqid('', true) . '@example.com',
        ])]);
        $refusals['saving the form'] = $this->refusalOf(fn () => $this->makeController()->actionUpdate($member->id));
        $this->setPost([]);
        $refusals['the status toggle'] = $this->refusalOf(fn () => $this->makeController()->actionToggleStatus($member->id));
        $refusals['Delete'] = $this->refusalOf(fn () => $this->makeController()->actionDelete($member->id));

        $this->assertSame(array_fill_keys(array_keys($refusals), true), array_map(static fn (?string $refusal): bool => $refusal !== null, $refusals), 'each answers 403');
        $this->assertSame(array_fill_keys(array_keys($refusals), UserChangePolicy::TEAM_ACCESS_REFUSED), $refusals);
        $this->assertSame($before, $this->stateOf($member), 'nothing is written');
        $this->assertFalse($this->stored($member)->validatePassword(self::TAKEN_OVER_PASSWORD));
        $this->assertNull($this->audit(AuditLog::ACTION_USER_UPDATED, $member->id));
        $this->assertNull($this->audit(AuditLog::ACTION_USER_STATUS_CHANGED, $member->id));
        $this->assertNull($this->audit(AuditLog::ACTION_USER_DELETED, $member->id));
    }

    /**
     * regression: the users list and the user page offered Edit, the status
     * toggle and Delete for such a team member. They offer them for a viewer
     * in no team only.
     */
    public function testRegressionThePagesOfferNothingThatChangesATeamMember(): void
    {
        $this->loginAs($this->helpDesk('web_team_page_help_desk', self::HELP_DESK_WITH_DELETE));
        $expected = [
            'a viewer whose team has a restricted project' => [$this->teamMember('web_team_page_member', TeamProject::ROLE_VIEWER), false],
            'a viewer in no team' => [$this->userWithRoles('web_team_page_viewer', ['viewer']), true],
        ];

        foreach ($expected as $kind => [$user, $offered]) {
            $this->assertSame($offered ? 1 : 0, $this->nodeCount($this->listPageOf($user), $this->rowLink($user, 'Edit')), "{$kind}: Edit in the list");
            $xpath = $this->xpathOf($this->userPage($user));
            $this->assertSame($offered ? 1 : 0, $this->nodeCount($xpath, '//a[normalize-space()="Edit"]'), "{$kind}: Edit");
            $this->assertSame($offered ? 1 : 0, $this->nodeCount($xpath, '//form[contains(@action, "toggle-status")]'), "{$kind}: the status toggle");
            $this->assertSame($offered ? 1 : 0, $this->nodeCount($xpath, '//form[contains(@action, "/user/delete")]'), "{$kind}: Delete");
        }
    }

    /**
     * A help desk in the member's team has what the team gives the member:
     * it may still change them, audited.
     */
    public function testAHelpDeskInTheMembersTeamMayChangeThem(): void
    {
        $helpDesk = $this->helpDesk('web_team_mate_help_desk', self::HELP_DESK);
        $member = $this->teamMember('web_team_mate_member', TeamProject::ROLE_OPERATOR);
        $teamId = (int)TeamMember::find()->select('team_id')->where(['user_id' => $member->id])->scalar();
        $this->addTeamMember($teamId, (int)$helpDesk->id);
        $this->loginAs($helpDesk);
        $email = 'changed-' . uniqid('', true) . '@example.com';
        $this->setPost(['UserForm' => $this->formOf($member, ['role' => 'viewer', 'email' => $email])]);

        $saved = $this->makeController();
        $saved->actionUpdate($member->id);

        $this->assertSame(['view', 'id' => $member->id], $saved->capturedRedirect, 'the form was saved');
        $this->assertSame($email, $this->stored($member)->email);
        $this->assertNotNull($this->audit(AuditLog::ACTION_USER_UPDATED, $member->id));
    }

    /**
     * regression: a user who held every permission of the admin role
     * through a custom role, without the admin role, changed admins and gave
     * the admin role, which grants every team-restricted project. Changing
     * an admin, the form opened or saved, the status toggle and Delete,
     * answers 403 UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED, which names
     * the admin role (regression of wave 5f: CHANGE_REFUSED said they lacked
     * permissions they hold), and writes nothing; the form refuses the role
     * with RoleGrantPolicy::ADMIN_ROLE_REFUSED and does not offer it.
     */
    public function testRegressionEveryPermissionOfTheAdminRoleWithoutTheRoleNeitherChangesNorGivesIt(): void
    {
        $this->loginAs($this->userWithRoles('web_all_of_admin', [$this->allOfAdminRole()]));
        $admin = $this->userWithRoles('web_all_of_admin_target', ['admin']);
        $viewer = $this->userWithRoles('web_all_of_admin_viewer', ['viewer']);
        $before = $this->stateOf($admin);

        $adminRoleRefused = UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED;
        $this->assertRefused(fn () => $this->makeController()->actionUpdate($admin->id), 'opening the form of an admin', $adminRoleRefused);
        $this->setPost(['UserForm' => $this->formOf($admin, ['password' => self::TAKEN_OVER_PASSWORD])]);
        $this->assertRefused(fn () => $this->makeController()->actionUpdate($admin->id), 'saving the form of an admin', $adminRoleRefused);
        $this->setPost([]);
        $this->assertRefused(fn () => $this->makeController()->actionToggleStatus($admin->id), 'the status toggle of an admin', $adminRoleRefused);
        $this->assertRefused(fn () => $this->makeController()->actionDelete($admin->id), 'Delete of an admin', $adminRoleRefused);
        $this->assertSame($before, $this->stateOf($admin), 'nothing of the admin is written');
        $this->assertSame([], $this->nodeListOf($this->xpathOf($this->editPage($viewer)), '//select[@id="userform-role"]/option[@value="admin"]'), 'admin is not offered');
        $this->setPost(['UserForm' => $this->formOf($viewer, ['role' => 'admin'])]);
        $refused = $this->makeController();
        $refused->actionUpdate($viewer->id);

        $this->assertSame('form', $refused->capturedView, 'the form is shown again');
        $form = $refused->capturedParams['form'] ?? null;
        $this->assertInstanceOf(UserForm::class, $form);
        $this->assertSame([sprintf(RoleGrantPolicy::ADMIN_ROLE_REFUSED, 'admin')], $form->getErrors('role'));
        $this->assertSame(['viewer'], $this->stateOf($viewer)['roles']);
    }

    // -- What the pages offer -------------------------------------------------

    /**
     * regression: the users list offered Edit for every user to whoever held
     * user.update. It offers it for the users the signed-in user may change.
     */
    public function testRegressionTheUsersListOffersEditOnlyForUsersTheSignedInUserMayChange(): void
    {
        $this->loginAs($this->helpDesk('web_list_help_desk', self::HELP_DESK));
        $expected = [
            'a superadmin' => [$this->privilegedUser(true), false],
            'an admin' => [$this->privilegedUser(false), false],
            'an operator' => [$this->userWithRoles('web_list_operator', ['operator']), false],
            'a viewer' => [$this->userWithRoles('web_list_viewer', ['viewer']), true],
            'a user without a role' => [$this->createUser('web_list_none'), true],
        ];

        foreach ($expected as $kind => [$user, $offered]) {
            $xpath = $this->listPageOf($user);
            $this->assertSame(1, $this->nodeCount($xpath, sprintf('//table[@id="user-table"]/tbody/tr[td[1]="%d"]', $user->id)), "{$kind}: listed");
            $this->assertSame($offered ? 1 : 0, $this->nodeCount($xpath, $this->rowLink($user, 'Edit')), "{$kind}: Edit");
        }
    }

    /**
     * regression: the user page offered Edit, the status toggle and Delete
     * for every user to whoever held user.update and user.delete. It offers
     * them for the users the signed-in user may change.
     */
    public function testRegressionTheUserPageOffersEditTheToggleAndDeleteOnlyForUsersTheSignedInUserMayChange(): void
    {
        $this->loginAs($this->helpDesk('web_page_help_desk', self::HELP_DESK_WITH_DELETE));
        $expected = [
            'a superadmin' => [$this->privilegedUser(true), false],
            'an admin' => [$this->privilegedUser(false), false],
            'a viewer' => [$this->userWithRoles('web_page_viewer', ['viewer']), true],
        ];

        foreach ($expected as $kind => [$user, $offered]) {
            $xpath = $this->xpathOf($this->userPage($user));
            $this->assertSame($offered ? 1 : 0, $this->nodeCount($xpath, '//a[normalize-space()="Edit"]'), "{$kind}: Edit");
            $this->assertSame($offered ? 1 : 0, $this->nodeCount($xpath, '//form[contains(@action, "toggle-status")]//button[normalize-space()="Deactivate"]'), "{$kind}: Deactivate");
            $this->assertSame($offered ? 1 : 0, $this->nodeCount($xpath, '//form[contains(@action, "/user/delete")]//button[normalize-space()="Delete"]'), "{$kind}: Delete");
            $this->assertSame(1, $this->nodeCount($xpath, sprintf('//h2[normalize-space()="%s"]', $user->username)), "{$kind}: the page is shown");
        }
    }

    // -- Cost ------------------------------------------------------------------

    /**
     * regression: the Edit User page of an admin who is no superadmin checked
     * every permission of every role one by one, a few queries each. A role
     * the admin holds is decided at once (RoleGrantPolicy), so the page costs
     * the same whether the roles grant 20 permissions more or not.
     */
    public function testRegressionTheEditPageOfAnAdminCostsTheSameHoweverManyPermissionsTheRolesGrant(): void
    {
        $this->loginAs($this->userWithRoles('web_cost_edit_admin', ['admin']));
        $viewer = $this->userWithRoles('web_cost_edit_viewer', ['viewer']);
        $this->editPage($viewer);
        $before = $this->queriesOf(fn () => $this->editPage($viewer));

        $viewerRole = $this->auth()->getRole('viewer');
        $this->assertNotNull($viewerRole);
        for ($i = 0; $i < 20; $i++) {
            $permission = $this->auth()->createPermission('test.cost-' . substr(md5(uniqid('', true)), 0, 8));
            $this->auth()->add($permission);
            $this->auth()->addChild($viewerRole, $permission);
        }
        // Changing the RBAC items clears the manager's memo of the admin's
        // own roles, which a request reads once: measure warm, as before.
        $this->editPage($viewer);

        $this->assertSame($before, $this->queriesOf(fn () => $this->editPage($viewer)));
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * Fails unless $action answers 403 with $message, by default
     * UserChangePolicy::CHANGE_REFUSED.
     */
    private function assertRefused(\Closure $action, string $what, string $message = UserChangePolicy::CHANGE_REFUSED): void
    {
        $this->assertSame($message, $this->refusalOf($action) ?? "{$what}: no 403", $what);
    }

    /**
     * The message of the 403 $action answers; null when it answers none.
     */
    private function refusalOf(\Closure $action): ?string
    {
        try {
            $action();
        } catch (ForbiddenHttpException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * A viewer who is the only member of a new team that has a new project
     * with $teamRole: a restricted project.
     */
    private function teamMember(string $suffix, string $teamRole): User
    {
        $admin = $this->userWithRoles($suffix . '_creator', ['admin']);
        $project = $this->createProject((int)$admin->id);
        $team = $this->createTeam((int)$admin->id);
        $this->createTeamProject((int)$team->id, (int)$project->id, $teamRole);
        $member = $this->userWithRoles($suffix, ['viewer']);
        $this->addTeamMember((int)$team->id, (int)$member->id);

        return $member;
    }

    /**
     * A custom role holding every permission of the admin role, but not the
     * admin role itself.
     */
    private function allOfAdminRole(): string
    {
        $auth = $this->auth();
        $role = $auth->createRole('all-of-admin-' . substr(md5(uniqid('', true)), 0, 8));
        $auth->add($role);
        foreach ($auth->getPermissionsByRole('admin') as $permission) {
            $auth->addChild($role, $permission);
        }

        return $role->name;
    }

    /**
     * @return list<\DOMNode> the nodes $query finds
     */
    private function nodeListOf(\DOMXPath $xpath, string $query): array
    {
        $nodes = $xpath->query($query);
        $this->assertNotFalse($nodes, $query);

        return iterator_to_array($nodes, false);
    }

    /**
     * A superadmin with the admin role, with another superadmin besides, or
     * an admin who is no superadmin.
     */
    private function privilegedUser(bool $superadmin): User
    {
        $user = $this->userWithRoles('web_change_privileged', ['admin']);
        if ($superadmin) {
            $user->is_superadmin = true;
            $user->save(false);
            $other = $this->createUser('web_change_other');
            $other->is_superadmin = true;
            $other->save(false);
        }

        return $user;
    }

    /**
     * A user holding a custom help desk role with $permissions.
     *
     * @param list<string> $permissions
     */
    private function helpDesk(string $suffix, array $permissions): User
    {
        $auth = $this->auth();
        $role = $auth->createRole('help-desk-' . substr(md5(uniqid('', true)), 0, 8));
        $auth->add($role);
        foreach ($permissions as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }

        return $this->userWithRoles($suffix, [$role->name]);
    }

    /**
     * A user assigned $roles, read back as the session reads them.
     *
     * @param list<string> $roles
     */
    private function userWithRoles(string $suffix, array $roles): User
    {
        $user = $this->createUser($suffix);
        foreach ($roles as $role) {
            $this->assignRole($user->id, $role);
        }
        $user->refresh();

        return $user;
    }

    /**
     * Makes $users the only superadmins, inside the test's transaction.
     */
    private function makeTheOnlySuperadmins(User ...$users): void
    {
        User::updateAll(['is_superadmin' => 0], ['is_superadmin' => 1]);
        foreach ($users as $user) {
            $user->is_superadmin = true;
            $user->save(false);
        }
    }

    /**
     * Every column of the user as stored, and their roles: null when the
     * user is gone.
     *
     * @return array{row: array<string, mixed>|null, roles: list<string>}
     */
    private function stateOf(User $user): array
    {
        /** @var array<string, mixed>|null $row */
        $row = User::find()->where(['id' => $user->id])->asArray()->one();
        $roles = array_map('strval', array_keys($this->auth()->getAssignments((string)$user->id)));
        sort($roles);

        return ['row' => $row, 'roles' => $roles];
    }

    private function stored(User $user): User
    {
        $stored = User::findOne($user->id);
        $this->assertNotNull($stored);

        return $stored;
    }

    private function audit(string $action, int $userId): ?AuditLog
    {
        return AuditLog::findOne(['action' => $action, 'object_type' => 'user', 'object_id' => $userId]);
    }

    /**
     * The edit form of $user as the browser posts it, with $changes applied.
     *
     * @param array<string, string> $changes
     * @return array<string, string>
     */
    private function formOf(User $user, array $changes): array
    {
        return array_merge([
            'username' => $user->username,
            'email' => $user->email,
            'password' => '',
            'role' => 'admin',
            'status' => (string)$user->status,
        ], $changes);
    }

    /**
     * The view the Edit User form of $user renders when opened.
     */
    private function openedForm(User $user): ?string
    {
        $ctrl = $this->makeController();
        $ctrl->actionUpdate($user->id);

        return $ctrl->capturedView;
    }

    private function auth(): DbManager
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(DbManager::class, $auth);

        return $auth;
    }

    private function nodeCount(\DOMXPath $xpath, string $query): int
    {
        $nodes = $xpath->query($query);
        $this->assertNotFalse($nodes, $query);

        return $nodes->length;
    }

    /**
     * The links named $text in $user's row of the users list.
     */
    private function rowLink(User $user, string $text): string
    {
        return sprintf('//table[@id="user-table"]/tbody/tr[td[1]="%d"]//a[normalize-space()="%s"]', $user->id, $text);
    }

    /**
     * The page of the users list that shows $user: the list is ordered by
     * ID, 30 users a page, and the database of the tests keeps the users of
     * earlier runs.
     */
    private function listPageOf(User $user): \DOMXPath
    {
        $page = (string)(intdiv((int)User::find()->where(['<', 'id', $user->id])->count(), 30) + 1);
        $this->setQueryParams(['page' => $page]);
        $ctrl = $this->makeController();

        return $this->xpathOf($this->page($ctrl, '/user/index?page=' . $page, fn () => $ctrl->actionIndex()));
    }

    private function userPage(User $user): string
    {
        $ctrl = $this->makeController();

        return $this->page($ctrl, '/user/view?id=' . $user->id, fn () => $ctrl->actionView($user->id));
    }

    private function editPage(User $user): string
    {
        $ctrl = $this->makeController();

        return $this->page($ctrl, '/user/update?id=' . $user->id, fn () => $ctrl->actionUpdate($user->id));
    }

    /**
     * What $action renders with the real views of the user pages, without
     * the layout. The console application of the tests has no web root, so
     * asset bundles are dummies; view, asset manager and controller are
     * restored afterwards.
     *
     * @param \Closure(): (string|Response) $action
     */
    private function page(UserController $ctrl, string $url, \Closure $action): string
    {
        $components = \Yii::$app->getComponents(true);
        $originals = ['view' => $components['view'] ?? null, 'assetManager' => $components['assetManager'] ?? null];
        $previousController = \Yii::$app->controller;
        \Yii::$app->set('assetManager', new AssetManager(['bundles' => false, 'basePath' => sys_get_temp_dir(), 'baseUrl' => '/assets']));
        \Yii::$app->set('view', new View());
        \Yii::$app->controller = $ctrl;
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setUrl($url);
        $ctrl->rendersViews = true;
        try {
            $html = $action();
            $this->assertIsString($html, 'the page was rendered, not redirected');

            return $html;
        } finally {
            \Yii::$app->controller = $previousController;
            foreach ($originals as $id => $definition) {
                \Yii::$app->set($id, $definition);
            }
        }
    }

    private function xpathOf(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($document);
    }

    private function makeController(): UserController
    {
        return new class ('user', \Yii::$app) extends UserController {
            /** @var mixed the route passed to redirect() */
            public mixed $capturedRedirect = null;

            /** @var string|null the view render() was asked for */
            public ?string $capturedView = null;

            /** @var array<string, mixed> the parameters render() was given */
            public array $capturedParams = [];

            /** Whether render() renders the real view, without the layout. */
            public bool $rendersViews = false;

            public function render($view, $params = []): string
            {
                $this->capturedView = $view;
                $this->capturedParams = $params;
                return $this->rendersViews ? $this->renderPartial($view, $params) : 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): Response
            {
                $this->capturedRedirect = $url;
                $response = new Response();
                $response->content = 'redirected';
                return $response;
            }
        };
    }
}
