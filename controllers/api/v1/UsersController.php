<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\AuditLog;
use app\models\User;
use app\services\UserDeletionService;
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
    protected function apiAccessRules(): array
    {
        return [
            'index' => 'user.view',
            'view' => 'user.view',
            'create' => 'user.create',
            'update' => 'user.update',
            'delete' => 'user.delete',
        ];
    }

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
        $page = $this->requestedPage();

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

        // auth_source is set on insert and frozen thereafter — see actionUpdate.
        $authSource = isset($body['auth_source']) ? (string)$body['auth_source'] : User::AUTH_SOURCE_LOCAL;
        if (!in_array($authSource, [User::AUTH_SOURCE_LOCAL, User::AUTH_SOURCE_LDAP], true)) {
            return $this->error('Invalid auth_source. Must be "local" or "ldap".', 422);
        }

        $this->applyBody($model, $body);
        $model->generateAuthKey();

        $err = $this->applyAuthSourceOnCreate($model, $body, $authSource);
        if ($err !== null) {
            return $err;
        }

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
     * Apply auth-source-specific fields on a fresh User. Returns an error
     * response array if validation fails, or null on success.
     *
     * @param array<string, mixed> $body
     * @return array{error: array{message: string}}|null
     */
    private function applyAuthSourceOnCreate(User $model, array $body, string $authSource): ?array
    {
        if ($authSource === User::AUTH_SOURCE_LDAP) {
            $model->markAsLdapManaged();
            $this->applyLdapMetadata($model, $body);
            if (isset($body['password'])) {
                return $this->error('Password is not accepted for LDAP-backed accounts.', 422);
            }
            return null;
        }

        $model->auth_source = User::AUTH_SOURCE_LOCAL;
        $password = (string)($body['password'] ?? '');
        if ($password === '') {
            return $this->error('Password is required.', 422);
        }
        if (strlen($password) < 5) {
            return $this->error('Password must be at least 5 characters.', 422);
        }
        $model->setPassword($password);
        return null;
    }

    /**
     * Copy ldap_dn / ldap_uid from request body if present (empty string → null).
     *
     * @param array<string, mixed> $body
     */
    private function applyLdapMetadata(User $model, array $body): void
    {
        if (array_key_exists('ldap_dn', $body)) {
            $dn = (string)$body['ldap_dn'];
            $model->ldap_dn = $dn !== '' ? $dn : null;
        }
        if (array_key_exists('ldap_uid', $body)) {
            $uid = (string)$body['ldap_uid'];
            $model->ldap_uid = $uid !== '' ? $uid : null;
        }
    }

    /**
     * Reject any attempt to switch auth_source after creation. Allowing a
     * flip would either orphan the bcrypt hash (local→ldap) or expose a
     * directory-managed account to local password login (ldap→local).
     *
     * @param array<string, mixed> $body
     * @return array{error: array{message: string}}|null
     */
    private function rejectAuthSourceChange(User $model, array $body): ?array
    {
        if (array_key_exists('auth_source', $body) && (string)$body['auth_source'] !== $model->auth_source) {
            return $this->error('auth_source is immutable once the user exists.', 422);
        }
        return null;
    }

    /**
     * Validate and apply a password change on an existing user. No-op when
     * the body has no password. Returns an error response array on rejection.
     *
     * @param array<string, mixed> $body
     * @return array{error: array{message: string}}|null
     */
    private function applyPasswordOnUpdate(User $model, array $body): ?array
    {
        $password = $body['password'] ?? null;
        if (!is_string($password) || $password === '') {
            return null;
        }
        if ($model->isLdap()) {
            return $this->error('Cannot set password for LDAP-backed user.', 422);
        }
        if (strlen($password) < 5) {
            return $this->error('Password must be at least 5 characters.', 422);
        }
        $model->setPassword($password);
        return null;
    }

    /**
     * Updates the user. Taking the superadmin flag from the only superadmin
     * answers 422 and changes nothing.
     *
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

        $err = $this->rejectAuthSourceChange($model, $body);
        if ($err !== null) {
            return $err;
        }

        $this->applyBody($model, $body);
        if ($this->demotesTheOnlySuperadmin($model)) {
            return $this->error('Cannot demote the only superadmin.', 422);
        }

        $err = $this->applyPasswordOnUpdate($model, $body);
        if ($err !== null) {
            return $err;
        }

        // Allow updating directory metadata only for LDAP-backed accounts.
        // Setting these on a local account would be a confusing no-op.
        if ($model->isLdap()) {
            $this->applyLdapMetadata($model, $body);
        }

        if (!$model->validate()) {
            return $this->error($this->firstError($model), 422);
        }
        if (!$model->save(false)) {
            return $this->error('Failed to save user.', 422);
        }

        // Leaves the roles alone when the body names no role.
        $this->assignRole($model, $body);

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
     * Deletes the user and their roles. A user whom other records still
     * refer to answers 409 and stays, roles included.
     *
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

        if ($model->is_superadmin && !$this->hasOtherSuperadmin($model)) {
            return $this->error('Cannot delete the only superadmin.', 422);
        }

        $username = $model->username;
        /** @var UserDeletionService $deletion */
        $deletion = \Yii::$app->get('userDeletionService');
        if (!$deletion->delete($model)) {
            return $this->error($deletion->refusalMessage($model), 409);
        }

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
     * Whether saving $model would take the superadmin flag from the only
     * user who has it.
     */
    private function demotesTheOnlySuperadmin(User $model): bool
    {
        return (bool)$model->getOldAttribute('is_superadmin')
            && !$model->is_superadmin
            && !$this->hasOtherSuperadmin($model);
    }

    private function hasOtherSuperadmin(User $model): bool
    {
        return User::find()
            ->where(['is_superadmin' => true])
            ->andWhere(['!=', 'id', $model->id])
            ->exists();
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
     * @return array{id: int, username: string, email: string, status: int, is_superadmin: bool, totp_enabled: bool, role: string|null, auth_source: string, ldap_dn: string|null, ldap_uid: string|null, last_synced_at: int|null, created_at: int, updated_at: int}
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
            'auth_source' => $u->auth_source,
            'ldap_dn' => $u->ldap_dn,
            'ldap_uid' => $u->ldap_uid,
            'last_synced_at' => $u->last_synced_at !== null ? (int)$u->last_synced_at : null,
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
