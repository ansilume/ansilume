<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\AuditLog;
use app\models\User;
use app\models\UserForm;
use app\services\AssignedRoles;
use app\services\RoleGrantPolicy;
use app\services\UserChangePolicy;
use app\services\UserDeletionService;
use yii\data\ActiveDataProvider;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class UserController extends BaseController
{
    /**
     * The 403 of Delete for the only active superadmin, and of a save of the
     * Edit User form that would take the superadmin flag from the only active
     * superadmin or deactivate them (User::activeSuperadminExists()). Their
     * form opens and saves every other change; an inactive superadmin may be
     * demoted, deactivated or deleted.
     */
    public const ONLY_SUPERADMIN = 'Cannot remove, demote or deactivate the only active superadmin account.';

    /**
     * The note of the user page under the status of an inactive LDAP account
     * that ldap/sync deactivated (user.ldap_sync_disabled).
     */
    public const DEACTIVATED_BY_LDAP_SYNC = 'Deactivated by ldap/sync because the directory no longer had this user: an LDAP sign-in, or an ldap/sync that finds them in the directory, activates the account again.';

    /**
     * The note of the user page under the status of an inactive LDAP account
     * that ldap/sync did not deactivate (CLAUDE.md security rule 15).
     */
    public const DEACTIVATED_IN_ANSILUME = 'Deactivated in Ansilume: the directory does not activate the account again, neither an LDAP sign-in nor ldap/sync.';

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'], 'allow' => true, 'roles' => ['user.view']],
            ['actions' => ['create'], 'allow' => true, 'roles' => ['user.create']],
            ['actions' => ['update'], 'allow' => true, 'roles' => ['user.update']],
            ['actions' => ['delete', 'toggle-status'], 'allow' => true, 'roles' => ['user.delete']],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    protected function verbRules(): array
    {
        return ['delete' => ['POST'], 'toggle-status' => ['POST']];
    }

    /**
     * The users, with the roles assigned to the users of the page read in one
     * query: assigned roles only, never the default role every user holds.
     * Edit shows only for the users the signed-in user may change
     * (UserChangePolicy), decided once per role.
     */
    public function actionIndex(): string
    {
        $dataProvider = new ActiveDataProvider([
            'query' => User::find()->orderBy(['id' => SORT_ASC]),
            'pagination' => ['pageSize' => 30],
        ]);
        /** @var list<User> $users */
        $users = $dataProvider->getModels();
        $roles = AssignedRoles::fromApp()->ofUsers(array_map(static fn (User $user): int => (int)$user->id, $users));

        return $this->render('index', [
            'dataProvider' => $dataProvider,
            'roles' => $roles,
            'changeable' => UserChangePolicy::forCurrentUser()->mayChangeEach($users),
        ]);
    }

    /**
     * The user, with the roles assigned to them: never the default role every
     * user holds. Edit, the status toggle and Delete show only when the
     * signed-in user may change the user (UserChangePolicy). An inactive
     * LDAP account gets a note saying whether the directory activates it
     * again (statusNoteOf()).
     */
    public function actionView(int $id): string
    {
        $user = $this->findModel($id);

        return $this->render('view', [
            'user' => $user,
            'roles' => AssignedRoles::fromApp()->of($id),
            'mayChange' => UserChangePolicy::forCurrentUser()->mayChange($user),
            'statusNote' => $this->statusNoteOf($user),
        ]);
    }

    /**
     * The note under the status of an inactive LDAP account:
     * DEACTIVATED_BY_LDAP_SYNC when ldap/sync deactivated it, so an LDAP
     * sign-in or an ldap/sync that finds the user activates it again;
     * DEACTIVATED_IN_ANSILUME otherwise. Null for an active or a local
     * account.
     */
    private function statusNoteOf(User $user): ?string
    {
        if (!$user->isLdap() || (int)$user->status === User::STATUS_ACTIVE) {
            return null;
        }

        return $user->ldap_sync_disabled ? self::DEACTIVATED_BY_LDAP_SYNC : self::DEACTIVATED_IN_ANSILUME;
    }

    /**
     * Creates a user with what the signed-in user may give (RoleGrantPolicy):
     * a role they may assign, and the superadmin flag only from a superadmin.
     * user.created records the roles and the flag, from null.
     */
    public function actionCreate(): Response|string
    {
        $form = new UserForm(RoleGrantPolicy::forCurrentUser());

        if ($form->load((array)\Yii::$app->request->post()) && $form->save()) {
            /** @var \app\models\User $newUser Just saved successfully */
            $newUser = $form->getUser();
            $context = ['username' => $form->username] + $form->privilegeChanges();
            \Yii::$app->get('auditService')->log(AuditLog::ACTION_USER_CREATED, 'user', $newUser->id, null, $context);
            $this->session()->setFlash('success', "User \"{$form->username}\" created.");
            return $this->redirect(['view', 'id' => $newUser->id]);
        }

        return $this->render('form', ['form' => $form, 'user' => null]);
    }

    /**
     * Saves the user with what the signed-in user may give (RoleGrantPolicy).
     * Saved with the role the form shows, the user keeps every assigned role.
     * user.updated records the roles and the superadmin flag as {from, to}
     * when they change. A user the signed-in user may not change
     * (UserChangePolicy) answers 403, the form opened or saved. The only
     * active superadmin opens and saves their own form; a save that would
     * take their flag or deactivate them answers 403 (ONLY_SUPERADMIN).
     */
    public function actionUpdate(int $id): Response|string
    {
        $user = $this->findModel($id);
        $grants = RoleGrantPolicy::forCurrentUser();
        $this->guardMayChange($user, $grants);
        $form = UserForm::fromUser($user, $grants);

        if ($form->load((array)\Yii::$app->request->post())) {
            $this->guardKeepsTheOnlySuperadmin($user, $form);
            if ($form->save()) {
                $context = ['username' => $form->username] + $form->privilegeChanges();
                \Yii::$app->get('auditService')->log(AuditLog::ACTION_USER_UPDATED, 'user', $user->id, null, $context);
                $this->session()->setFlash('success', "User \"{$form->username}\" updated.");
                return $this->redirect(['view', 'id' => $user->id]);
            }
        }

        return $this->render('form', ['form' => $form, 'user' => $user]);
    }

    /**
     * Deletes the user and their roles. A user whom other records still
     * refer to stays, roles included; the flash says to deactivate them. A
     * user the signed-in user may not change (UserChangePolicy) answers 403,
     * as do one's own account and the only active superadmin
     * (ONLY_SUPERADMIN).
     */
    public function actionDelete(int $id): Response
    {
        $user = $this->findModel($id);
        $this->guardMayChange($user, RoleGrantPolicy::forCurrentUser());
        $this->guardSelf($user);
        $this->guardLastSuperadmin($user);

        $username = $user->username;
        /** @var UserDeletionService $deletion */
        $deletion = \Yii::$app->get('userDeletionService');
        if (!$deletion->delete($user)) {
            $this->session()->setFlash('danger', $deletion->refusalMessage($user));
            return $this->redirect(['view', 'id' => $id]);
        }

        \Yii::$app->get('auditService')->log(AuditLog::ACTION_USER_DELETED, 'user', $id, null, ['username' => $username]);
        $this->session()->setFlash('success', "User \"{$username}\" deleted.");
        return $this->redirect(['index']);
    }

    /**
     * Activates or deactivates the user. A user the signed-in user may not
     * change (UserChangePolicy) answers 403.
     */
    public function actionToggleStatus(int $id): Response
    {
        $user = $this->findModel($id);
        $this->guardMayChange($user, RoleGrantPolicy::forCurrentUser());
        $this->guardSelf($user);

        $user->status = ($user->status === User::STATUS_ACTIVE)
            ? User::STATUS_INACTIVE
            : User::STATUS_ACTIVE;
        $user->save(false);

        $label = $user->status === User::STATUS_ACTIVE ? 'activated' : 'deactivated';
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_USER_STATUS_CHANGED, 'user', $id, null, ['username' => $user->username, 'status' => $label]);
        $this->session()->setFlash('success', "User \"{$user->username}\" {$label}.");
        return $this->redirect(['view', 'id' => $id]);
    }

    /**
     * 403 when the signed-in user, who gives what $grants allows, may not
     * change $user, with why (UserChangePolicy::refusalOf()): after the 404
     * of a user that does not exist, before every other check.
     */
    private function guardMayChange(User $user, RoleGrantPolicy $grants): void
    {
        $refusal = UserChangePolicy::of($grants)->refusalOf($user);
        if ($refusal !== null) {
            throw new ForbiddenHttpException($refusal);
        }
    }

    /**
     * 403 (ONLY_SUPERADMIN) when saving $form, as loaded, would take the
     * superadmin flag from $user or deactivate them while they are the only
     * active superadmin. Nothing of the form is saved then.
     */
    private function guardKeepsTheOnlySuperadmin(User $user, UserForm $form): void
    {
        if (!$form->is_superadmin || $form->status !== User::STATUS_ACTIVE) {
            $this->guardLastSuperadmin($user);
        }
    }

    private function guardSelf(User $user): void
    {
        if ($user->id === (int)(\Yii::$app->user->id ?? 0)) {
            throw new ForbiddenHttpException('You cannot perform this action on your own account.');
        }
    }

    /**
     * 403 (ONLY_SUPERADMIN) when $user, as stored, is the only active
     * superadmin: a superadmin whose status is active while no other user is
     * one (User::activeSuperadminExists()). An inactive superadmin, who
     * cannot sign in, is never refused here.
     */
    private function guardLastSuperadmin(User $user): void
    {
        $activeSuperadmin = $user->is_superadmin && (int)$user->status === User::STATUS_ACTIVE;
        if ($activeSuperadmin && !User::activeSuperadminExists((int)$user->id)) {
            throw new ForbiddenHttpException(self::ONLY_SUPERADMIN);
        }
    }

    private function findModel(int $id): User
    {
        /** @var User|null $model */
        $model = User::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("User #{$id} not found.");
        }
        return $model;
    }
}
