<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\AuditLog;
use app\models\User;
use app\models\UserForm;
use yii\data\ActiveDataProvider;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class UserController extends BaseController
{
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'],             'allow' => true, 'roles' => ['user.view']],
            ['actions' => ['create'],                    'allow' => true, 'roles' => ['user.create']],
            ['actions' => ['update'],                    'allow' => true, 'roles' => ['user.update']],
            ['actions' => ['delete', 'toggle-status'],   'allow' => true, 'roles' => ['user.delete']],
        ];
    }

    protected function verbRules(): array
    {
        return ['delete' => ['POST'], 'toggle-status' => ['POST']];
    }

    public function actionIndex(): string
    {
        $dataProvider = new ActiveDataProvider([
            'query' => User::find()->orderBy(['id' => SORT_ASC]),
            'pagination' => ['pageSize' => 30],
        ]);
        return $this->render('index', ['dataProvider' => $dataProvider]);
    }

    public function actionView(int $id): string
    {
        $user  = $this->findModel($id);
        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        $roles = $auth->getRolesByUser($id);
        return $this->render('view', ['user' => $user, 'roles' => $roles]);
    }

    public function actionCreate(): Response|string
    {
        $form = new UserForm();

        if ($form->load(\Yii::$app->request->post()) && $form->save()) {
            $newUser = $form->getUser();
            \Yii::$app->get('auditService')->log(AuditLog::ACTION_USER_CREATED, 'user', $newUser->id, null, ['username' => $form->username]);
            \Yii::$app->session->setFlash('success', "User \"{$form->username}\" created.");
            return $this->redirect(['view', 'id' => $newUser->id]);
        }

        return $this->render('form', ['form' => $form, 'user' => null]);
    }

    public function actionUpdate(int $id): Response|string
    {
        $user = $this->findModel($id);
        $form = UserForm::fromUser($user);

        // Prevent demoting the only superadmin
        $this->guardLastSuperadmin($user);

        if ($form->load(\Yii::$app->request->post()) && $form->save()) {
            \Yii::$app->get('auditService')->log(AuditLog::ACTION_USER_UPDATED, 'user', $user->id, null, ['username' => $form->username]);
            \Yii::$app->session->setFlash('success', "User \"{$form->username}\" updated.");
            return $this->redirect(['view', 'id' => $user->id]);
        }

        return $this->render('form', ['form' => $form, 'user' => $user]);
    }

    public function actionDelete(int $id): Response
    {
        $user = $this->findModel($id);
        $this->guardSelf($user);
        $this->guardLastSuperadmin($user);

        $username = $user->username;
        $user->delete();

        \Yii::$app->get('auditService')->log(AuditLog::ACTION_USER_DELETED, 'user', $id, null, ['username' => $username]);
        \Yii::$app->session->setFlash('success', "User \"{$username}\" deleted.");
        return $this->redirect(['index']);
    }

    public function actionToggleStatus(int $id): Response
    {
        $user = $this->findModel($id);
        $this->guardSelf($user);

        $user->status = ($user->status === User::STATUS_ACTIVE)
            ? User::STATUS_INACTIVE
            : User::STATUS_ACTIVE;
        $user->save(false);

        $label = $user->status === User::STATUS_ACTIVE ? 'activated' : 'deactivated';
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_USER_STATUS_CHANGED, 'user', $id, null, ['username' => $user->username, 'status' => $label]);
        \Yii::$app->session->setFlash('success', "User \"{$user->username}\" {$label}.");
        return $this->redirect(['view', 'id' => $id]);
    }

    private function guardSelf(User $user): void
    {
        if ($user->id === (int)\Yii::$app->user->id) {
            throw new ForbiddenHttpException('You cannot perform this action on your own account.');
        }
    }

    private function guardLastSuperadmin(User $user): void
    {
        if (!$user->is_superadmin) {
            return;
        }
        $count = (int)User::find()->where(['is_superadmin' => true])->count();
        if ($count <= 1) {
            throw new ForbiddenHttpException('Cannot remove or demote the only superadmin account.');
        }
    }

    private function findModel(int $id): User
    {
        $model = User::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("User #{$id} not found.");
        }
        return $model;
    }
}
