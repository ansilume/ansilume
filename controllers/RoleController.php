<?php

declare(strict_types=1);

namespace app\controllers;

use app\helpers\PermissionCatalog;
use app\models\RoleForm;
use app\services\RoleChangePolicy;
use app\services\RoleChangeRefusedException;
use app\services\RoleService;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Custom role management UI. Admins can list, create, edit, and delete
 * RBAC roles. Built-in roles (viewer/operator/admin) can have their
 * permissions edited but cannot be renamed or deleted — see RoleService.
 *
 * What the signed-in user may change (RoleChangePolicy): a user who is no
 * superadmin creates roles only with permissions they hold, and opens or
 * saves the Edit Role form of a role, or deletes it, only when they may
 * assign the role; anything else answers 403
 * (RoleChangePolicy::CHANGE_REFUSED), after the 404 of a role that does not
 * exist. The form disables the permissions they do not hold, and a saved
 * permission they do not hold is an error of the Permissions field
 * (RoleChangePolicy::PERMISSION_REFUSED).
 */
class RoleController extends BaseController
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'], 'allow' => true, 'roles' => ['role.view']],
            ['actions' => ['create'], 'allow' => true, 'roles' => ['role.create']],
            ['actions' => ['update'], 'allow' => true, 'roles' => ['role.update']],
            ['actions' => ['delete'], 'allow' => true, 'roles' => ['role.delete']],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    protected function verbRules(): array
    {
        return ['delete' => ['POST']];
    }

    public function actionIndex(): string
    {
        return $this->render('index', [
            'roles' => $this->roleService()->listRoles(),
        ]);
    }

    /**
     * The role's page; it offers Edit and Delete only when the signed-in
     * user may change the role (RoleChangePolicy).
     */
    public function actionView(string $name): string
    {
        $role = $this->findRole($name);

        $userIds = $role['userIds'];
        $users = [];
        if (!empty($userIds)) {
            $users = \app\models\User::find()
                ->select(['id', 'username'])
                ->where(['id' => $userIds])
                ->orderBy(['username' => SORT_ASC])
                ->all();
        }

        return $this->render('view', [
            'role' => $role,
            'users' => $users,
            'mayChange' => RoleChangePolicy::forCurrentUser()->mayChange($name),
        ]);
    }

    /**
     * Creates a role with the permissions the signed-in user may grant
     * (RoleChangePolicy).
     */
    public function actionCreate(): Response|string
    {
        $form = new RoleForm();
        $policy = RoleChangePolicy::forCurrentUser();
        if (
            $form->load((array)\Yii::$app->request->post())
            && $this->saved($form, fn (): bool => $this->roleService()->createRole($form, $policy))
        ) {
            $this->session()->setFlash('success', "Role \"{$form->name}\" created.");
            return $this->redirect(['view', 'name' => $form->name]);
        }
        return $this->render('create', ['form' => $form, 'grantable' => $this->grantable($policy)]);
    }

    /**
     * Edits a role the signed-in user may change, adding only permissions
     * they may grant (RoleChangePolicy); any other role answers 403, the form
     * opened or saved.
     */
    public function actionUpdate(string $name): Response|string
    {
        $svc = $this->roleService();
        $data = $this->findRole($name);
        $policy = RoleChangePolicy::forCurrentUser();
        $this->guardMayChange($policy, $name);

        $form = new RoleForm();
        $form->name = $data['name'];
        $form->description = $data['description'];
        $form->permissions = $data['directPermissions'];
        $form->isSystemRole = $data['isSystem'];
        $form->originalName = $data['name'];

        if (
            $form->load((array)\Yii::$app->request->post())
            && $this->saved($form, fn (): bool => $svc->updateRole($name, $form, $policy))
        ) {
            $this->session()->setFlash('success', "Role \"{$name}\" updated.");
            return $this->redirect(['view', 'name' => $name]);
        }
        return $this->render('update', ['form' => $form, 'role' => $data, 'grantable' => $this->grantable($policy)]);
    }

    /**
     * Deletes a custom role the signed-in user may change (RoleChangePolicy):
     * 404 for a role that does not exist, then 403 for one they may not
     * change, then the refusal of a system role.
     */
    public function actionDelete(string $name): Response
    {
        $svc = $this->roleService();
        $this->findRole($name);
        $policy = RoleChangePolicy::forCurrentUser();
        $this->guardMayChange($policy, $name);

        if ($svc->isSystemRole($name)) {
            $this->session()->setFlash('danger', "Cannot delete system role \"{$name}\".");
            return $this->redirect(['view', 'name' => $name]);
        }

        if (!$svc->deleteRole($name, $policy)) {
            throw new NotFoundHttpException("Role \"{$name}\" not found.");
        }

        $this->session()->setFlash('success', "Role \"{$name}\" deleted.");
        return $this->redirect(['index']);
    }

    /**
     * @return array{name: string, description: string, isSystem: bool, directPermissions: string[], effectivePermissions: string[], userIds: int[]}
     * @throws NotFoundHttpException when the role does not exist
     */
    private function findRole(string $name): array
    {
        $role = $this->roleService()->getRole($name);
        if ($role === null) {
            throw new NotFoundHttpException("Role \"{$name}\" not found.");
        }

        return $role;
    }

    /**
     * 403 (RoleChangePolicy::CHANGE_REFUSED) when the signed-in user may not
     * change the role $name.
     *
     * @throws ForbiddenHttpException
     */
    private function guardMayChange(RoleChangePolicy $policy, string $name): void
    {
        if (!$policy->mayChange($name)) {
            throw new ForbiddenHttpException(RoleChangePolicy::CHANGE_REFUSED);
        }
    }

    /**
     * Whether $save saved the role. A permission the signed-in user may not
     * grant is an error of the Permissions field, and nothing is saved.
     *
     * @param callable(): bool $save
     */
    private function saved(RoleForm $form, callable $save): bool
    {
        try {
            return $save();
        } catch (RoleChangeRefusedException $e) {
            $form->addError('permissions', $e->getMessage());
            return false;
        }
    }

    /**
     * @return array<string, bool> every permission of the catalog => whether the signed-in user may grant it
     */
    private function grantable(RoleChangePolicy $policy): array
    {
        return $policy->grantable(PermissionCatalog::allPermissionNames());
    }

    private function roleService(): RoleService
    {
        /** @var RoleService $svc */
        $svc = \Yii::$app->get('roleService');
        return $svc;
    }
}
