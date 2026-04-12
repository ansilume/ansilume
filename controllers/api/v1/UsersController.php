<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\AuditLog;
use app\models\User;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;

/**
 * API v1: Users
 *
 * GET    /api/v1/users
 * GET    /api/v1/users/{id}
 * POST   /api/v1/users
 * PUT    /api/v1/users/{id}
 * DELETE /api/v1/users/{id}
 *
 * Sensitive fields (password_hash, auth_key, totp_secret, recovery_codes)
 * are NEVER included in responses.
 */
class UsersController extends BaseApiController
{
    /**
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}|array{error: array{message: string}}
     */
    public function actionIndex(): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('user.view')) {
            return $this->error('Forbidden.', 403);
        }

        $dp = new ActiveDataProvider([
            'query' => User::find()->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 25],
        ]);
        /** @var int $page */
        $page = \Yii::$app->request->get('page', 1);

        return $this->paginated(
            array_map(fn ($u) => $this->serialize($u), $dp->getModels()),
            (int)$dp->totalCount,
            $page,
            25
        );
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionView(int $id): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('user.view')) {
            return $this->error('Forbidden.', 403);
        }
        return $this->success($this->serialize($this->findModel($id)));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionCreate(): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('user.create')) {
            return $this->error('Forbidden.', 403);
        }

        $model = new User();
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);
        $model->generateAuthKey();

        $password = (string)($body['password'] ?? '');
        if ($password === '') {
            return $this->error('Password is required.', 422);
        }
        if (strlen($password) < 5) {
            return $this->error('Password must be at least 5 characters.', 422);
        }
        $model->setPassword($password);

        if (!$model->validate()) {
            return $this->error($this->firstError($model), 422);
        }
        if (!$model->save(false)) {
            return $this->error('Failed to save user.', 422);
        }

        $this->assignRole($model, $body);

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_USER_CREATED,
            'user',
            $model->id,
            null,
            ['username' => $model->username, 'source' => 'api']
        );

        return $this->success($this->serialize($model), 201);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionUpdate(int $id): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('user.update')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);

        $password = $body['password'] ?? null;
        if (is_string($password) && $password !== '') {
            if (strlen($password) < 5) {
                return $this->error('Password must be at least 5 characters.', 422);
            }
            $model->setPassword($password);
        }

        if (!$model->validate()) {
            return $this->error($this->firstError($model), 422);
        }
        if (!$model->save(false)) {
            return $this->error('Failed to save user.', 422);
        }

        if (array_key_exists('role', $body)) {
            $this->assignRole($model, $body);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_USER_UPDATED,
            'user',
            $model->id,
            null,
            ['username' => $model->username, 'source' => 'api']
        );

        return $this->success($this->serialize($model));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionDelete(int $id): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('user.delete')) {
            return $this->error('Forbidden.', 403);
        }

        if ((int)$user->id === $id) {
            return $this->error('Cannot delete your own account.', 422);
        }

        $model = $this->findModel($id);

        if ($model->is_superadmin) {
            $otherSuperadmins = User::find()
                ->where(['is_superadmin' => true])
                ->andWhere(['!=', 'id', $id])
                ->count();
            if ((int)$otherSuperadmins === 0) {
                return $this->error('Cannot delete the only superadmin.', 422);
            }
        }

        $username = $model->username;

        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        $auth->revokeAll((string)$model->id);
        $model->delete();

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_USER_DELETED,
            'user',
            $id,
            null,
            ['username' => $username, 'source' => 'api']
        );

        return $this->success(['deleted' => true]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyBody(User $model, array $body): void
    {
        foreach (['username', 'email'] as $field) {
            if (array_key_exists($field, $body)) {
                $model->$field = (string)$body[$field];
            }
        }
        if (array_key_exists('status', $body)) {
            $model->status = (int)$body['status'];
        }
        if (array_key_exists('is_superadmin', $body)) {
            $model->is_superadmin = (bool)$body['is_superadmin'];
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assignRole(User $model, array $body): void
    {
        $roleName = $body['role'] ?? null;
        if (!is_string($roleName) || $roleName === '') {
            return;
        }

        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        $auth->revokeAll((string)$model->id);
        $role = $auth->getRole($roleName);
        if ($role !== null) {
            $auth->assign($role, (string)$model->id);
        }
    }

    /**
     * @return array{id: int, username: string, email: string, status: int, is_superadmin: bool, totp_enabled: bool, role: string|null, created_at: int, updated_at: int}
     */
    private function serialize(User $u): array
    {
        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        $roles = $auth->getRolesByUser((string)$u->id);
        $roleName = !empty($roles) ? (string)array_key_first($roles) : null;

        return [
            'id' => $u->id,
            'username' => $u->username,
            'email' => $u->email,
            'status' => $u->status,
            'is_superadmin' => (bool)$u->is_superadmin,
            'totp_enabled' => (bool)$u->totp_enabled,
            'role' => $roleName,
            'created_at' => $u->created_at,
            'updated_at' => $u->updated_at,
        ];
    }

    private function findModel(int $id): User
    {
        /** @var User|null $u */
        $u = User::findOne($id);
        if ($u === null) {
            throw new NotFoundHttpException("User #{$id} not found.");
        }
        return $u;
    }

    private function firstError(User $model): string
    {
        foreach ($model->errors as $errors) {
            return $errors[0] ?? 'Validation failed.';
        }
        return 'Validation failed.';
    }
}
