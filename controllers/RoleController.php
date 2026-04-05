<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\RoleForm;
use app\services\RoleService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Custom role management UI. Admins can list, create, edit, and delete
 * RBAC roles. Built-in roles (viewer/operator/admin) can have their
 * permissions edited but cannot be renamed or deleted — see RoleService.
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

    public function actionView(string $name): string
    {
        $role = $this->roleService()->getRole($name);
        if ($role === null) {
            throw new NotFoundHttpException("Role \"{$name}\" not found.");
        }

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
        ]);
    }

    public function actionCreate(): Response|string
    {
        $form = new RoleForm();
        if ($form->load((array)\Yii::$app->request->post())) {
            if ($this->roleService()->createRole($form, (int)\Yii::$app->user->id)) {
                $this->session()->setFlash('success', "Role \"{$form->name}\" created.");
                return $this->redirect(['view', 'name' => $form->name]);
            }
        }
        return $this->render('create', ['form' => $form]);
    }

    public function actionUpdate(string $name): Response|string
    {
        $svc = $this->roleService();
        $data = $svc->getRole($name);
        if ($data === null) {
            throw new NotFoundHttpException("Role \"{$name}\" not found.");
        }

        $form = new RoleForm();
        $form->name = $data['name'];
        $form->description = $data['description'];
        $form->permissions = $data['directPermissions'];
        $form->isSystemRole = $data['isSystem'];
        $form->originalName = $data['name'];

        if ($form->load((array)\Yii::$app->request->post())) {
            if ($svc->updateRole($name, $form, (int)\Yii::$app->user->id)) {
                $this->session()->setFlash('success', "Role \"{$name}\" updated.");
                return $this->redirect(['view', 'name' => $name]);
            }
        }
        return $this->render('update', ['form' => $form, 'role' => $data]);
    }

    public function actionDelete(string $name): Response
    {
        $svc = $this->roleService();
        if ($svc->isSystemRole($name)) {
            $this->session()->setFlash('error', "Cannot delete system role \"{$name}\".");
            return $this->redirect(['view', 'name' => $name]);
        }

        if (!$svc->deleteRole($name, (int)\Yii::$app->user->id)) {
            throw new NotFoundHttpException("Role \"{$name}\" not found.");
        }

        $this->session()->setFlash('success', "Role \"{$name}\" deleted.");
        return $this->redirect(['index']);
    }

    private function roleService(): RoleService
    {
        /** @var RoleService $svc */
        $svc = \Yii::$app->get('roleService');
        return $svc;
    }
}
