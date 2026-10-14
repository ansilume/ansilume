<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\UserController;
use app\models\AuditLog;
use app\models\User;
use app\models\UserForm;
use app\services\RoleGrantPolicy;
use app\services\UserDeletionService;
use app\tests\integration\CountsQueries;
use app\tests\integration\TeamScopeFixtures;
use yii\data\ActiveDataProvider;
use yii\rbac\DbManager;
use yii\web\AssetManager;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\View;

/**
 * Deleting, demoting and editing users from the web UI, and the roles the
 * user pages show. Run with the default role viewer of the web application
 * (config/web.php), which every user holds and DbManager::getRolesByUser()
 * lists first: the pages show and the form keeps the roles assigned to a
 * user, never the default role.
 *
 * What the signed-in user may give (RoleGrantPolicy): a role only when they
 * hold every permission it grants, the superadmin flag only as a superadmin.
 * The help desk of these tests holds a custom role with user.view and
 * user.update, as an admin can build in the role editor; the admins hold
 * every permission but are no superadmins.
 */
class UserControllerActionTest extends WebControllerTestCase
{
    use CountsQueries;
    use TeamScopeFixtures;

    /** A page number beyond the last page: the list shows its last page, where the newest users are. */
    private const LAST_PAGE = '1000000';

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
     * Regression: a user whom other records still refer to (here: a project
     * they created) failed on the foreign key with a server error.
     */
    public function testDeletingAUserOtherRecordsReferToKeepsThemAndExplainsWhy(): void
    {
        $this->loginAs($this->createUserWithRole('web_del_admin', 'admin'));
        $target = $this->createUserWithRole('web_del_referenced', 'operator');
        $this->createProject($target->id);

        $ctrl = $this->makeController();
        $result = $ctrl->actionDelete($target->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(['view', 'id' => $target->id], $ctrl->capturedRedirect);
        $this->assertSame(
            ['danger' => (new UserDeletionService())->refusalMessage($target)],
            \Yii::$app->session->getAllFlashes()
        );
        $this->assertNotNull(User::findOne($target->id));
        $this->assertSame(['operator'], $this->roleNames($target->id));
        $this->assertNull($this->deletionAudit($target->id), 'only deletes are audited');
    }

    /**
     * Regression: the web UI deleted the user but left their role
     * assignments behind (auth_assignment has no foreign key to user).
     */
    public function testDeletingAnUnreferencedUserRemovesThemAndTheirRoles(): void
    {
        $this->loginAs($this->createUserWithRole('web_del_admin', 'admin'));
        $target = $this->createUserWithRole('web_del_free', 'operator');

        $ctrl = $this->makeController();
        $result = $ctrl->actionDelete($target->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(['index'], $ctrl->capturedRedirect);
        $this->assertSame(
            ['success' => 'User "' . $target->username . '" deleted.'],
            \Yii::$app->session->getAllFlashes()
        );
        $this->assertNull(User::findOne($target->id));
        $this->assertSame([], $this->roleNames($target->id));
        $audit = $this->deletionAudit($target->id);
        $this->assertNotNull($audit);
        $this->assertSame(['username' => $target->username], json_decode((string)$audit->metadata, true));
    }

    public function testAUserCannotDeleteThemselves(): void
    {
        $admin = $this->createUserWithRole('web_del_self', 'admin');
        $this->loginAs($admin);

        try {
            $this->makeController()->actionDelete($admin->id);
            $this->fail('expected ForbiddenHttpException');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame('You cannot perform this action on your own account.', $e->getMessage());
        }
        $this->assertNotNull(User::findOne($admin->id));
        $this->assertSame(['admin'], $this->roleNames($admin->id));
    }

    // -- The only superadmin --------------------------------------------------

    /**
     * Only a superadmin deletes a superadmin (UserChangePolicy), so the only
     * superadmin is one whom a superadmin tries to delete after losing the
     * flag meanwhile: the signed-in user's session still says superadmin,
     * the stored flags count one.
     */
    public function testTheOnlySuperadminCannotBeDeleted(): void
    {
        $actor = $this->createUserWithRole('web_su_admin', 'admin');
        $actor->is_superadmin = true;
        $this->loginAs($actor);
        $target = $this->createUserWithRole('web_su_only', 'admin');
        $this->makeTheOnlySuperadmins($target);
        $this->assertTrue((bool)$actor->is_superadmin, 'precondition: the session says superadmin');

        try {
            $this->makeController()->actionDelete($target->id);
            $this->fail('expected ForbiddenHttpException');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame('Cannot remove, demote or deactivate the only active superadmin account.', $e->getMessage());
        }
        $this->assertTrue($this->isSuperadmin($target->id));
        $this->assertSame(['admin'], $this->roleNames($target->id));
        $this->assertNull($this->deletionAudit($target->id));
    }

    public function testASuperadminCanBeDeletedWhileAnotherRemains(): void
    {
        $actor = $this->createUserWithRole('web_su_admin', 'admin');
        $target = $this->createUserWithRole('web_su_target', 'admin');
        $this->makeTheOnlySuperadmins($target, $actor);
        $this->loginAs($actor);

        $ctrl = $this->makeController();
        $ctrl->actionDelete($target->id);

        $this->assertSame(['index'], $ctrl->capturedRedirect);
        $this->assertNull(User::findOne($target->id));
        $this->assertSame([], $this->roleNames($target->id));
        $this->assertNotNull($this->deletionAudit($target->id));
    }

    /**
     * Only a superadmin changes a superadmin (UserChangePolicy): the only
     * superadmin, editing their own account, cannot demote it; a save that
     * would saves nothing of the form (UserController::ONLY_SUPERADMIN).
     */
    public function testTheOnlySuperadminCannotBeDemoted(): void
    {
        $target = $this->createUserWithRole('web_su_only', 'admin');
        $this->makeTheOnlySuperadmins($target);
        $this->loginAs($target);
        $email = $target->email;
        $this->setPost(['UserForm' => $this->formOf($target, [
            'is_superadmin' => '0',
            'email' => 'demoted-' . uniqid('', true) . '@example.com',
        ])]);

        try {
            $this->makeController()->actionUpdate($target->id);
            $this->fail('expected ForbiddenHttpException');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame('Cannot remove, demote or deactivate the only active superadmin account.', $e->getMessage());
        }
        $this->assertTrue($this->isSuperadmin($target->id));
        $target->refresh();
        $this->assertSame($email, $target->email, 'nothing of the form is saved');
        $this->assertSame(['admin'], $this->roleNames($target->id));
        $this->assertNull($this->updateAudit($target->id));
    }

    /**
     * regression: the only superadmin could not open their own Edit User
     * form: opening it answered 403 "Cannot remove or demote the only
     * superadmin account.", so they changed their email, username or role
     * nowhere in the web UI. They open it and save every change but the two
     * that would leave no superadmin.
     */
    public function testRegressionTheOnlySuperadminOpensAndSavesTheirOwnEditUserForm(): void
    {
        $target = $this->createUserWithRole('web_su_self', 'admin');
        $this->makeTheOnlySuperadmins($target);
        $this->loginAs($target);

        try {
            $html = $this->editPage($target);
        } catch (ForbiddenHttpException $e) {
            $this->fail('opening the form answered 403: ' . $e->getMessage());
        }
        $this->assertSame(['admin'], $this->selectedRoles($html), 'the form is shown');
        $this->assertSame(1, $this->nodeCount($html, '//input[@type="checkbox"][@name="UserForm[is_superadmin]"][@checked]'));

        $username = 'web_su_renamed_' . uniqid();
        $email = 'renamed-' . uniqid('', true) . '@example.com';
        $this->setPost(['UserForm' => $this->formOf($target, ['username' => $username, 'email' => $email, 'password' => 'NewSuperPass1!', 'role' => 'operator'])]);
        $ctrl = $this->makeController();
        $ctrl->actionUpdate($target->id);

        $this->assertSame(['view', 'id' => $target->id], $ctrl->capturedRedirect, 'the form was saved');
        $target->refresh();
        $this->assertSame([$username, $email], [$target->username, $target->email]);
        $this->assertTrue($target->validatePassword('NewSuperPass1!'));
        $this->assertTrue($this->isSuperadmin($target->id));
        $this->assertSame(['operator'], $this->roleNames($target->id));
        $this->assertSame(
            ['username' => $username, 'roles' => ['from' => ['admin'], 'to' => ['operator']]],
            $this->auditContext($this->updateAudit($target->id))
        );
    }

    /**
     * The only superadmin cannot deactivate their account through the form
     * either: 403 UserController::ONLY_SUPERADMIN, nothing of it saved.
     */
    public function testTheOnlySuperadminCannotDeactivateThemselvesThroughTheForm(): void
    {
        $target = $this->createUserWithRole('web_su_inactive', 'admin');
        $this->makeTheOnlySuperadmins($target);
        $this->loginAs($target);
        $email = $target->email;
        $this->setPost(['UserForm' => $this->formOf($target, [
            'status' => (string)User::STATUS_INACTIVE,
            'email' => 'deactivated-' . uniqid('', true) . '@example.com',
        ])]);

        try {
            $this->makeController()->actionUpdate($target->id);
            $this->fail('expected ForbiddenHttpException');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame(UserController::ONLY_SUPERADMIN, $e->getMessage());
        }
        $target->refresh();
        $this->assertSame(User::STATUS_ACTIVE, $target->status);
        $this->assertSame($email, $target->email, 'nothing of the form is saved');
        $this->assertNull($this->updateAudit($target->id));
    }

    /**
     * With another superadmin besides, a superadmin's form deactivates them.
     */
    public function testASuperadminCanBeDeactivatedThroughTheFormWhileAnotherRemains(): void
    {
        $actor = $this->createUserWithRole('web_su_deactivating', 'admin');
        $target = $this->createUserWithRole('web_su_deactivated', 'admin');
        $this->makeTheOnlySuperadmins($target, $actor);
        $this->loginAs($actor);
        $this->setPost(['UserForm' => $this->formOf($target, ['status' => (string)User::STATUS_INACTIVE])]);

        $ctrl = $this->makeController();
        $ctrl->actionUpdate($target->id);

        $this->assertSame(['view', 'id' => $target->id], $ctrl->capturedRedirect);
        $target->refresh();
        $this->assertSame(User::STATUS_INACTIVE, $target->status);
        $this->assertTrue($this->isSuperadmin($target->id));
    }

    public function testASuperadminCanBeDemotedWhileAnotherRemains(): void
    {
        $actor = $this->createUserWithRole('web_su_admin', 'admin');
        $target = $this->createUserWithRole('web_su_target', 'admin');
        $this->makeTheOnlySuperadmins($target, $actor);
        $this->loginAs($actor);
        $this->setPost(['UserForm' => $this->formOf($target, ['is_superadmin' => '0'])]);

        $ctrl = $this->makeController();
        $ctrl->actionUpdate($target->id);

        $this->assertNull($ctrl->capturedView, 'the form was saved, not shown again');
        $this->assertSame(['view', 'id' => $target->id], $ctrl->capturedRedirect);
        $this->assertFalse($this->isSuperadmin($target->id));
        $this->assertSame(['admin'], $this->roleNames($target->id));
        $this->assertSame(
            ['username' => $target->username, 'is_superadmin' => ['from' => true, 'to' => false]],
            $this->auditContext($this->updateAudit($target->id))
        );
    }

    /**
     * Only a superadmin sets or clears the flag: an admin's form ignores the
     * posted field and saves the rest. (An admin who is no superadmin may
     * not change a superadmin at all: UserControllerChangeTest.)
     */
    public function testAnAdminWhoIsNoSuperadminDoesNotPromote(): void
    {
        $this->loginAs($this->createUserWithRole('web_su_plain_admin', 'admin'));
        $operator = $this->createUserWithRole('web_su_promoted', 'operator');
        $email = 'kept-' . uniqid('', true) . '@example.com';
        $this->setPost(['UserForm' => $this->formOf($operator, ['role' => 'operator', 'is_superadmin' => '1', 'email' => $email])]);

        $ctrl = $this->makeController();
        $ctrl->actionUpdate($operator->id);

        $this->assertSame(['view', 'id' => $operator->id], $ctrl->capturedRedirect, 'the rest is saved');
        $this->assertFalse($this->isSuperadmin($operator->id));
        $operator->refresh();
        $this->assertSame($email, $operator->email);
        $this->assertSame(['username' => $operator->username], $this->auditContext($this->updateAudit($operator->id)));
    }

    // -- What the signed-in user may give -------------------------------------

    /**
     * regression: a help desk role (user.view and user.update) made its own
     * account an admin and a superadmin through the Edit User form, which
     * hid the Superadmin field but took it, and gave any role. The role is
     * refused on the form, the flag ignored, nothing saved or audited.
     */
    public function testRegressionAHelpDeskCannotRaiseItselfThroughTheForm(): void
    {
        $helpDesk = $this->createUserWithRole('web_help_desk_self', $this->helpDeskRole());
        $this->loginAs($helpDesk);
        $email = $helpDesk->email;
        $this->setPost(['UserForm' => $this->formOf($helpDesk, [
            'role' => 'admin',
            'is_superadmin' => '1',
            'email' => 'raised-' . uniqid('', true) . '@example.com',
        ])]);

        $ctrl = $this->makeController();
        $ctrl->actionUpdate($helpDesk->id);

        $this->assertFalse($this->isSuperadmin($helpDesk->id), 'no superadmin');
        $this->assertSame([$this->helpDeskRoleName], $this->roleNames($helpDesk->id), 'no admin');
        $this->assertFalse($this->auth()->checkAccess((string)$helpDesk->id, 'admin'));
        $this->assertSame('form', $ctrl->capturedView, 'the form is shown again');
        $this->assertNull($ctrl->capturedRedirect);
        $form = $ctrl->capturedParams['form'] ?? null;
        $this->assertInstanceOf(UserForm::class, $form);
        $this->assertSame([sprintf(RoleGrantPolicy::ROLE_REFUSED, 'admin')], $form->getErrors('role'));
        $helpDesk->refresh();
        $this->assertSame($email, $helpDesk->email, 'nothing of the form is saved');
        $this->assertNull($this->updateAudit($helpDesk->id));
    }

    /**
     * regression: as above, for another user's account: the help desk made
     * a viewer an admin, and a superadmin with the role unchanged. (It may
     * change no operator at all: UserControllerChangeTest.)
     */
    public function testRegressionAHelpDeskCannotRaiseAnotherUserThroughTheForm(): void
    {
        $this->loginAs($this->createUserWithRole('web_help_desk', $this->helpDeskRole()));
        $viewer = $this->createUserWithRole('web_help_desk_target', 'viewer');

        $this->setPost(['UserForm' => $this->formOf($viewer, ['role' => 'admin'])]);
        $refused = $this->makeController();
        $refused->actionUpdate($viewer->id);
        $this->assertSame(['viewer'], $this->roleNames($viewer->id), 'no admin');
        $this->assertSame('form', $refused->capturedView);
        $form = $refused->capturedParams['form'] ?? null;
        $this->assertInstanceOf(UserForm::class, $form);
        $this->assertSame([sprintf(RoleGrantPolicy::ROLE_REFUSED, 'admin')], $form->getErrors('role'));

        $this->setPost(['UserForm' => $this->formOf($viewer, ['role' => 'viewer', 'is_superadmin' => '1'])]);
        $saved = $this->makeController();
        $saved->actionUpdate($viewer->id);
        $this->assertSame(['view', 'id' => $viewer->id], $saved->capturedRedirect, 'saved, without the flag');
        $this->assertFalse($this->isSuperadmin($viewer->id));
        $this->assertSame(['viewer'], $this->roleNames($viewer->id));
    }

    /**
     * regression: whoever held user.create made the users they created
     * superadmins, or admins. The flag is ignored, and a role the creator
     * may not assign is refused.
     */
    public function testRegressionUserCreateAloneCannotCreateASuperadminOrAnAdmin(): void
    {
        $this->loginAs($this->createUserWithRole('web_creator', $this->roleOf(['user.create'])));
        $username = 'web_created_' . uniqid();
        $this->setPost(['UserForm' => $this->newUserForm($username, ['role' => 'viewer', 'is_superadmin' => '1'])]);

        $ctrl = $this->makeController();
        $ctrl->actionCreate();

        $created = User::findOne(['username' => $username]);
        $this->assertNotNull($created, 'created, without the flag');
        $this->assertSame(['view', 'id' => $created->id], $ctrl->capturedRedirect);
        $this->assertFalse((bool)$created->is_superadmin);
        $this->assertSame(['viewer'], $this->roleNames($created->id));

        $admin = 'web_created_admin_' . uniqid();
        $this->setPost(['UserForm' => $this->newUserForm($admin, ['role' => 'admin'])]);
        $refused = $this->makeController();
        $refused->actionCreate();
        $this->assertSame('form', $refused->capturedView);
        $form = $refused->capturedParams['form'] ?? null;
        $this->assertInstanceOf(UserForm::class, $form);
        $this->assertSame([sprintf(RoleGrantPolicy::ROLE_REFUSED, 'admin')], $form->getErrors('role'));
        $this->assertNull(User::findOne(['username' => $admin]), 'no user is created');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function secondRoleProvider(): array
    {
        return ['a custom role sorted before operator' => ['a-approvers'], 'one sorted after it' => ['z-approvers']];
    }

    /**
     * regression: saving the Edit User form of a user with several assigned
     * roles (operator and an approver role, as an LDAP group mapping gives
     * them) unchanged kept only the role the form showed, the first by name.
     *
     * @dataProvider secondRoleProvider
     */
    public function testRegressionAnUnchangedSaveKeepsEveryAssignedRole(string $prefix): void
    {
        $this->loginAs($this->createUserWithRole('web_multi_admin', 'admin'));
        $target = $this->createUserWithRole('web_multi_target', 'operator');
        $custom = $this->roleOf([], $prefix);
        $this->assignRole($target->id, $custom);
        $both = $this->roleNames($target->id);
        $this->assertCount(2, $both);
        $target->refresh();
        $shown = UserForm::fromUser($target)->role;
        $this->assertSame($both[0], $shown, 'precondition: the form shows the first by name');
        $this->setPost(['UserForm' => $this->formOf($target, [
            'role' => $shown,
            'email' => 'multi-' . uniqid('', true) . '@example.com',
        ])]);

        $ctrl = $this->makeController();
        $ctrl->actionUpdate($target->id);

        $this->assertSame(['view', 'id' => $target->id], $ctrl->capturedRedirect, 'the form was saved');
        $this->assertSame($both, $this->roleNames($target->id));
        $this->assertSame(['username' => $target->username], $this->auditContext($this->updateAudit($target->id)));
    }

    /**
     * regression: user.updated named no role and no superadmin flag, so the
     * audit log could not tell who gave whom a role or the flag. It records
     * both as {from, to} when they change.
     */
    public function testRegressionTheUpdateAuditRecordsTheRolesAndTheFlag(): void
    {
        $superadmin = $this->createUser('web_audit_superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $this->loginAs($superadmin);
        $target = $this->createUserWithRole('web_audit_target', 'operator');
        $this->setPost(['UserForm' => $this->formOf($target, ['role' => 'admin', 'is_superadmin' => '1'])]);

        $this->makeController()->actionUpdate($target->id);

        $this->assertSame(
            [
                'username' => $target->username,
                'roles' => ['from' => ['operator'], 'to' => ['admin']],
                'is_superadmin' => ['from' => false, 'to' => true],
            ],
            $this->auditContext($this->updateAudit($target->id))
        );
    }

    /**
     * regression: user.created named neither the role nor the flag of the
     * new user. It records both, from null.
     */
    public function testRegressionTheCreateAuditRecordsTheRolesAndTheFlag(): void
    {
        $this->loginAs($this->createUserWithRole('web_audit_creator', 'admin'));
        $username = 'web_audit_created_' . uniqid();
        $this->setPost(['UserForm' => $this->newUserForm($username, ['role' => 'operator'])]);

        $this->makeController()->actionCreate();

        $created = User::findOne(['username' => $username]);
        $this->assertNotNull($created);
        $audit = AuditLog::findOne(['action' => AuditLog::ACTION_USER_CREATED, 'object_type' => 'user', 'object_id' => $created->id]);
        $this->assertSame(
            [
                'username' => $username,
                'roles' => ['from' => null, 'to' => ['operator']],
                'is_superadmin' => ['from' => null, 'to' => false],
            ],
            $this->auditContext($audit)
        );
    }

    /**
     * The edit page of the help desk offers the roles it may assign, and no
     * Superadmin field: viewer and its own role, not operator or admin.
     */
    public function testTheEditPageOffersTheRolesTheSignedInUserMayAssign(): void
    {
        $helpDeskRole = $this->helpDeskRole();
        $this->loginAs($this->createUserWithRole('web_choices_help_desk', $helpDeskRole));
        $viewer = $this->createUserWithRole('web_choices_viewer', 'viewer');

        $html = $this->editPage($viewer);

        $this->assertSame(['viewer', $helpDeskRole], $this->roleOptionsOf($html));
        $this->assertSame(['viewer'], $this->selectedRoles($html));
        $this->assertSame(0, $this->nodeCount($html, '//input[@name="UserForm[is_superadmin]"]'), 'no Superadmin field');
    }

    /**
     * Without the default role, the help desk may not assign viewer: the
     * edit page of a user without an assigned role still shows Viewer, the
     * form's choice for them, in its place, so the form can be shown as it
     * is (saving that choice needs the grant, UserFormAssignedRoleTest).
     */
    public function testTheEditPageKeepsTheViewerChoiceOfAUserWithoutARole(): void
    {
        $this->auth()->defaultRoles = [];
        $helpDeskRole = $this->helpDeskRole();
        $this->loginAs($this->createUserWithRole('web_choices_help_desk_none', $helpDeskRole));
        $none = $this->createUser('web_choices_none');

        $html = $this->editPage($none);

        $this->assertSame(['viewer', $helpDeskRole], $this->roleOptionsOf($html));
        $this->assertSame(['viewer'], $this->selectedRoles($html));
    }

    public function testTheEditPageOfASuperadminOffersEveryRoleAndTheFlag(): void
    {
        $superadmin = $this->createUser('web_choices_superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $this->loginAs($superadmin);
        $operator = $this->createUserWithRole('web_choices_su_operator', 'operator');

        $html = $this->editPage($operator);

        $this->assertSame(array_keys(UserForm::roleOptions()), $this->roleOptionsOf($html));
        $this->assertSame(1, $this->nodeCount($html, '//input[@type="checkbox"][@name="UserForm[is_superadmin]"]'));
    }

    /**
     * The edit page of a user with several roles says that saving with the
     * role shown keeps them all.
     */
    public function testTheEditPageOfAUserWithSeveralRolesSaysTheyStay(): void
    {
        $this->loginAs($this->createUserWithRole('web_hint_admin', 'admin'));
        $target = $this->createUserWithRole('web_hint_target', 'operator');
        $custom = $this->roleOf([], 'z-hinted');
        $this->assignRole($target->id, $custom);

        $hint = $this->only($this->xpathOf($this->editPage($target)), '//div[contains(@class, "field-userform-role")]//*[contains(@class, "hint-block")]');

        $this->assertSame(sprintf(UserForm::SEVERAL_ROLES_HINT, 'operator, ' . $custom), trim($hint->textContent));
    }

    // -- Roles: assigned, never the default role ------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function assignedRoleProvider(): array
    {
        return ['an operator' => ['operator'], 'an admin' => ['admin']];
    }

    /**
     * regression: the Edit User form preselected Viewer for every user, the
     * default role that DbManager::getRolesByUser() lists first.
     *
     * @dataProvider assignedRoleProvider
     */
    public function testRegressionTheEditFormPreselectsTheAssignedRole(string $role): void
    {
        $this->loginAs($this->createUserWithRole('web_roles_admin', 'admin'));
        $target = $this->createUserWithRole('web_roles_target', $role);

        $this->assertSame([$role], $this->selectedRoles($this->editPage($target)));
    }

    /**
     * regression: saving the Edit User form of an operator or an admin
     * unchanged, for example after changing only the email, made them a
     * viewer: the form preselected Viewer and saving gives the role shown.
     * The form is posted back as the browser posts it.
     *
     * @dataProvider assignedRoleProvider
     */
    public function testRegressionAnUnchangedSaveKeepsTheRole(string $role): void
    {
        $this->loginAs($this->createUserWithRole('web_unchanged_admin', 'admin'));
        $target = $this->createUserWithRole('web_unchanged_target', $role);
        $this->setPost($this->postedForm($this->editPage($target)));

        $ctrl = $this->makeController();
        $ctrl->actionUpdate($target->id);

        $this->assertSame(['view', 'id' => $target->id], $ctrl->capturedRedirect, 'the form was saved');
        $this->assertSame([$role], $this->roleNames($target->id));
        $audit = $this->updateAudit($target->id);
        $this->assertNotNull($audit);
        // Nothing changed, so the entry names no role: user.updated records roles and is_superadmin only when they change.
        $this->assertSame(['username' => $target->username], json_decode((string)$audit->metadata, true));
    }

    /**
     * regression: the users list showed the badge of the default role viewer
     * beside every user's role.
     */
    public function testRegressionTheUsersListShowsOnlyTheAssignedRoles(): void
    {
        $this->loginAs($this->createUserWithRole('web_list_admin', 'admin'));
        $operator = $this->createUserWithRole('web_list_operator', 'operator');
        $several = $this->createUserWithRole('web_list_several', 'viewer');
        $this->assignRole($several->id, 'admin');
        $none = $this->createUser('web_list_none');

        $this->assertSame(['operator'], $this->listRoles($operator));
        $this->assertSame(['admin', 'viewer'], $this->listRoles($several), 'sorted by name');
        $this->assertSame([], $this->listRoles($none));
        $this->assertSame('—', trim($this->roleCell($this->listPageOf($none), $none)->textContent), 'a user without an assigned role');
    }

    /**
     * regression: the user page listed the default role viewer among every
     * user's roles.
     */
    public function testRegressionTheUserPageShowsOnlyTheAssignedRoles(): void
    {
        $this->loginAs($this->createUserWithRole('web_page_admin', 'admin'));
        $operator = $this->createUserWithRole('web_page_operator', 'operator');
        $none = $this->createUser('web_page_none');

        $this->assertSame(['operator'], $this->pageRoles($operator));
        $this->assertSame([], $this->pageRoles($none));
        $xpath = $this->xpathOf($this->userPage($none));
        $this->assertSame('None', trim($this->rolesOfPage($xpath)->textContent), 'a user without an assigned role');
    }

    /**
     * regression: the users list read the roles of each user on the page
     * with a query of its own. The last page shows the newest users, so it
     * holds a different number of users after 2 and after 12 new ones; the
     * roles of all of them cost one query, and whether the signed-in admin
     * may change them is decided once per role (UserChangePolicy). The new
     * users hold the admin's role, so both pages hold the same roles whether
     * or not the admin's own row is among them.
     */
    public function testRegressionTheUsersListCostsTheSameQueriesFor2And12Users(): void
    {
        $this->loginAs($this->createUserWithRole('web_cost_admin', 'admin'));
        $this->createAdmins(2);
        $this->usersList();
        $fewQueries = $this->queriesOf(fn () => $this->usersList());
        $fewRows = $this->rowsOf($this->usersList());
        $this->createAdmins(10);
        // Assigning a role clears the RBAC manager's memo of the admin's own
        // roles, which a request reads once: measure warm, as before.
        $this->usersList();

        $this->assertSame($fewQueries, $this->queriesOf(fn () => $this->usersList()), 'a page of 2 new users and a page of 12');
        $this->assertNotSame($fewRows, $this->rowsOf($this->usersList()), 'precondition: the two pages hold a different number of users');
    }

    // -- Helpers --------------------------------------------------------------

    /** The name of the help desk role helpDeskRole() created last. */
    private string $helpDeskRoleName = '';

    /**
     * A help desk role: user.view and user.update, nothing else.
     */
    private function helpDeskRole(): string
    {
        $this->helpDeskRoleName = $this->roleOf(['user.view', 'user.update'], 'help-desk');

        return $this->helpDeskRoleName;
    }

    /**
     * A custom role named $prefix-<random> holding $permissions.
     *
     * @param list<string> $permissions
     */
    private function roleOf(array $permissions, string $prefix = 'custom'): string
    {
        $auth = $this->auth();
        $role = $auth->createRole($prefix . '-' . substr(md5(uniqid('', true)), 0, 8));
        $auth->add($role);
        foreach ($permissions as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }

        return $role->name;
    }

    /**
     * The create form of a local user named $username, as posted.
     *
     * @param array<string, string> $changes
     * @return array<string, string>
     */
    private function newUserForm(string $username, array $changes): array
    {
        return array_merge([
            'username' => $username,
            'email' => $username . '@example.com',
            'password' => 'WebUserPass1!',
            'status' => (string)User::STATUS_ACTIVE,
        ], $changes);
    }

    /**
     * The context of an audit entry, decoded.
     *
     * @return array<string, mixed>
     */
    private function auditContext(?AuditLog $audit): array
    {
        $this->assertNotNull($audit, 'the entry');
        $context = json_decode((string)$audit->metadata, true);
        $this->assertIsArray($context);

        return $context;
    }

    /**
     * @return list<string> the values of the options of the Role field of the form $html shows
     */
    private function roleOptionsOf(string $html): array
    {
        $values = [];
        foreach ($this->xpathOf($html)->query('//select[@id="userform-role"]/option') ?: [] as $option) {
            $this->assertInstanceOf(\DOMElement::class, $option);
            $values[] = $option->getAttribute('value');
        }

        return $values;
    }

    private function nodeCount(string $html, string $query): int
    {
        $nodes = $this->xpathOf($html)->query($query);
        $this->assertNotFalse($nodes);

        return $nodes->length;
    }

    /**
     * The roles assigned to $userId, as the assignment table holds them.
     *
     * @return list<string>
     */
    private function roleNames(int $userId): array
    {
        $names = array_map('strval', array_keys($this->auth()->getAssignments((string)$userId)));
        sort($names);
        return $names;
    }

    private function auth(): DbManager
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(DbManager::class, $auth);

        return $auth;
    }

    private function deletionAudit(int $userId): ?AuditLog
    {
        return AuditLog::findOne(['action' => AuditLog::ACTION_USER_DELETED, 'object_type' => 'user', 'object_id' => $userId]);
    }

    private function updateAudit(int $userId): ?AuditLog
    {
        return AuditLog::findOne(['action' => AuditLog::ACTION_USER_UPDATED, 'object_type' => 'user', 'object_id' => $userId]);
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
        $this->assertSame(count($users), (int)User::find()->where(['is_superadmin' => 1])->count());
    }

    private function isSuperadmin(int $userId): bool
    {
        return (bool)User::find()->select('is_superadmin')->where(['id' => $userId])->scalar();
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
            'is_superadmin' => $user->is_superadmin ? '1' : '0',
        ], $changes);
    }

    private function createAdmins(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->createUserWithRole('web_cost_admin_listed', 'admin');
        }
    }

    /**
     * Page $page of the users list, its last page by default, as GET
     * /user/index renders it.
     */
    private function usersList(string $page = self::LAST_PAGE): string
    {
        $this->setQueryParams(['page' => $page]);
        $ctrl = $this->makeController();

        return $this->page($ctrl, '/user/index?page=' . $page, fn () => $ctrl->actionIndex());
    }

    /**
     * The page of the users list that shows $user: the list is ordered by
     * ID, and the database of the tests keeps the users of earlier runs.
     */
    private function listPageOf(User $user): \DOMXPath
    {
        $before = (int)User::find()->where(['<', 'id', $user->id])->count();

        return $this->xpathOf($this->usersList((string)(intdiv($before, $this->listPageSize()) + 1)));
    }

    /**
     * The number of users the users list shows per page.
     */
    private function listPageSize(): int
    {
        $ctrl = $this->makeController();
        $ctrl->actionIndex();
        $dataProvider = $ctrl->capturedParams['dataProvider'] ?? null;
        $this->assertInstanceOf(ActiveDataProvider::class, $dataProvider);
        $pagination = $dataProvider->getPagination();
        $this->assertNotFalse($pagination);

        return $pagination->getPageSize();
    }

    /**
     * @return list<string> the role badges of $user's row in the users list
     */
    private function listRoles(User $user): array
    {
        $xpath = $this->listPageOf($user);

        return $this->badges($xpath, $this->roleCell($xpath, $user));
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
        // ActiveForm posts back to the current URL.
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

    /**
     * The one node $query finds.
     */
    private function only(\DOMXPath $xpath, string $query): \DOMElement
    {
        $nodes = $xpath->query($query);
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, $query);
        $node = $nodes->item(0);
        $this->assertInstanceOf(\DOMElement::class, $node);

        return $node;
    }

    /**
     * The Role cell of $user's row in the users list $xpath shows.
     */
    private function roleCell(\DOMXPath $xpath, User $user): \DOMElement
    {
        return $this->only($xpath, sprintf('//table[@id="user-table"]/tbody/tr[td[1]="%d"]/td[5]', $user->id));
    }

    /**
     * The Roles of the user page.
     */
    private function rolesOfPage(\DOMXPath $xpath): \DOMElement
    {
        return $this->only($xpath, '//dt[normalize-space()="Roles"]/following-sibling::dd[1]');
    }

    /**
     * @return list<string> the role badges of $user's page
     */
    private function pageRoles(User $user): array
    {
        $xpath = $this->xpathOf($this->userPage($user));

        return $this->badges($xpath, $this->rolesOfPage($xpath));
    }

    /**
     * @return list<string> the texts of the badges in $node
     */
    private function badges(\DOMXPath $xpath, \DOMElement $node): array
    {
        $texts = [];
        foreach ($xpath->query('.//span[contains(concat(" ", @class, " "), " badge ")]', $node) ?: [] as $badge) {
            $texts[] = trim($badge->textContent);
        }

        return $texts;
    }

    /**
     * The number of users the users list shows.
     */
    private function rowsOf(string $html): int
    {
        $rows = $this->xpathOf($html)->query('//table[@id="user-table"]/tbody/tr');
        $this->assertNotFalse($rows);

        return $rows->length;
    }

    /**
     * @return list<string> the selected options of the Role field of the edit form
     */
    private function selectedRoles(string $html): array
    {
        $selected = [];
        foreach ($this->xpathOf($html)->query('//select[@id="userform-role"]/option[@selected]') ?: [] as $option) {
            $this->assertInstanceOf(\DOMElement::class, $option);
            $selected[] = $option->getAttribute('value');
        }

        return $selected;
    }

    /**
     * The fields of the form #user-form as a browser posts it unchanged: the
     * value of every named input, the checked checkboxes and the selected
     * option of every select (its first option when none is selected).
     *
     * @return array<string, mixed>
     */
    private function postedForm(string $html): array
    {
        $xpath = $this->xpathOf($html);
        $pairs = [];
        foreach ($xpath->query('//form[@id="user-form"]//*[@name]') ?: [] as $field) {
            $this->assertInstanceOf(\DOMElement::class, $field);
            $value = $this->postedValue($xpath, $field);
            if ($value !== null) {
                $pairs[] = rawurlencode($field->getAttribute('name')) . '=' . rawurlencode($value);
            }
        }
        parse_str(implode('&', $pairs), $posted);
        $this->assertArrayHasKey('UserForm', $posted);

        return $posted;
    }

    /**
     * What a browser posts for $field; null when it posts nothing.
     */
    private function postedValue(\DOMXPath $xpath, \DOMElement $field): ?string
    {
        if ($field->tagName === 'select') {
            $selected = $xpath->query('option[@selected]', $field);
            $options = $xpath->query('option', $field);
            $this->assertNotFalse($selected);
            $this->assertNotFalse($options);
            $option = $selected->item(0) ?? $options->item(0);
            $this->assertInstanceOf(\DOMElement::class, $option);

            return $option->getAttribute('value');
        }
        $type = $field->getAttribute('type');
        if (in_array($type, ['checkbox', 'radio'], true) && !$field->hasAttribute('checked')) {
            return null;
        }

        return in_array($type, ['submit', 'button'], true) ? null : $field->getAttribute('value');
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
