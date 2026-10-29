<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\components\SubmittedInteger;
use app\models\AuditLog;
use app\models\User;
use app\models\UserForm;
use app\services\AssignedRoles;
use app\services\RoleGrantPolicy;
use app\services\UserChangePolicy;
use app\services\UserDeletionService;
use app\services\UserPrivileges;
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
 *
 * A "role" in the body of POST and PUT that the user already has, one of
 * the roles assigned to them, leaves every assigned role alone; another role
 * replaces them all; no "role", null and '' leave them alone too. Any other
 * value must name a role of the instance, compared exactly, else the request
 * answers 422 (INVALID_ROLE) before anything is written.
 *
 * What the caller may give (RoleGrantPolicy), checked before anything is
 * written: a role they may not assign answers 403
 * (RoleGrantPolicy::ROLE_REFUSED), and an is_superadmin that would change
 * the flag answers 403 (RoleGrantPolicy::SUPERADMIN_REFUSED) unless the
 * caller is a superadmin. user.created and user.updated record the user's
 * roles and superadmin flag as {from, to} when they change
 * (UserPrivileges).
 *
 * Whom the caller may change (UserChangePolicy): PUT and DELETE of a user
 * who is a superadmin, or who holds a role the caller may not assign, answer
 * 403 (UserChangePolicy::CHANGE_REFUSED) for a caller who is no superadmin,
 * or 403 (UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED) in its place where
 * only the admin role refuses, whatever the teams; where the roles allow the
 * change, of a user whose teams give them more access to a project than the
 * caller's teams give the caller, 403 (UserChangePolicy::TEAM_ACCESS_REFUSED)
 * for a caller who neither is a superadmin nor holds the admin role. They
 * come after the 404 of a user that does not exist and before every other
 * check.
 *
 * The only active superadmin (User::activeSuperadminExists()) changes their
 * own account, but neither takes their superadmin flag
 * (ONLY_SUPERADMIN_DEMOTED) nor deactivates it (ONLY_SUPERADMIN_DEACTIVATED),
 * nor is deleted (ONLY_SUPERADMIN_DELETED). An inactive superadmin may be
 * demoted, deactivated or deleted.
 *
 * A "status" that is no whole number answers 422 (INVALID_STATUS) before
 * anything is written: after the refusals of the user and of a role, and
 * before the refusal of is_superadmin and the 422s of the only active
 * superadmin and of the password (actionCreate() and actionUpdate() give
 * the order, as web/openapi.yaml does).
 */
class UsersController extends BaseApiController
{
    /**
     * The 422 of a "role" that names no role of the instance, also of one
     * that is not a string. sprintf(): the names of the instance's roles,
     * sorted by name and joined with ', ', e.g.
     * "role must be one of: admin, operator, viewer."
     */
    public const INVALID_ROLE = 'role must be one of: %s.';

    /** The 422 of PUT /api/v1/users/{id} that would take the superadmin flag from the only active superadmin. */
    public const ONLY_SUPERADMIN_DEMOTED = 'Cannot demote the only active superadmin.';

    /**
     * The 422 of PUT /api/v1/users/{id} that would deactivate the only
     * active superadmin (a status other than active).
     */
    public const ONLY_SUPERADMIN_DEACTIVATED = 'Cannot deactivate the only active superadmin.';

    /** The 422 of DELETE /api/v1/users/{id} of the only active superadmin. */
    public const ONLY_SUPERADMIN_DELETED = 'Cannot delete the only active superadmin.';

    /**
     * The 422 of POST and PUT for a "status" that is no whole number
     * (SubmittedInteger), such as true, null, 10.5 or a JSON number beyond
     * the int range: the model's own error for a status it does not know.
     */
    public const INVALID_STATUS = 'Status is invalid.';

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
        if (!$this->userCan('user.view')) {
            return $this->error('Forbidden.', 403);
        }

        $dp = new ActiveDataProvider([
            'query' => User::find()->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 25],
        ]);
        $page = $this->requestedPage();
        /** @var list<User> $users */
        $users = $this->modelsOfPage($dp, $page);

        return $this->paginated(
            $this->serializeAll($users),
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
        if (!$this->userCan('user.view')) {
            return $this->error('Forbidden.', 403);
        }
        return $this->success($this->serializeOne($this->findModel($id)));
    }

    /**
     * Creates the user. The answers come in the order web/openapi.yaml lists
     * for POST /api/v1/users: 403 without user.create; 422 for an unknown
     * auth_source or a "role" that names no role of the instance
     * (INVALID_ROLE); 403 for a role the caller may not assign
     * (RoleGrantPolicy::refusalOf()); 422 for a "status" that is no whole
     * number (INVALID_STATUS, applyBody()); 403 for is_superadmin true from
     * a caller who is no superadmin (RoleGrantPolicy::SUPERADMIN_REFUSED);
     * 422 for a password (applyAuthSourceOnCreate()) or another field that
     * is not valid, a status other than 0 and 10 included (the model's
     * rules). Each creates nothing.
     *
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionCreate(): array
    {
        if (!$this->userCan('user.create')) {
            return $this->error('Forbidden.', 403);
        }

        $model = new User();
        $body = (array)\Yii::$app->request->bodyParams;
        $grants = RoleGrantPolicy::forCurrentUser();

        // auth_source is set on insert and frozen thereafter — see actionUpdate.
        $authSource = isset($body['auth_source']) ? (string)$body['auth_source'] : User::AUTH_SOURCE_LOCAL;
        if (!in_array($authSource, [User::AUTH_SOURCE_LOCAL, User::AUTH_SOURCE_LDAP], true)) {
            return $this->error('Invalid auth_source. Must be "local" or "ldap".', 422);
        }
        $err = $this->rejectUnknownRole($body['role'] ?? null)
            ?? $this->refuseRoleGrant($grants, $body['role'] ?? null, []);
        if ($err !== null) {
            return $err;
        }

        $model->generateAuthKey();
        $err = $this->applyBody($model, $body)
            ?? $this->refuseSuperadminChange($grants, $model, false)
            ?? $this->applyAuthSourceOnCreate($model, $body, $authSource);
        if ($err !== null) {
            return $err;
        }

        if (!$model->validate()) {
            return $this->error($this->firstError($model), 422);
        }
        if (!$model->save(false)) {
            return $this->error('Failed to save user.', 422);
        }

        $this->assignRole($model, $body, []);

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_USER_CREATED,
            'user',
            $model->id,
            null,
            ['username' => $model->username, 'source' => 'api'] + UserPrivileges::of($model)->changesSince(null)
        );

        return $this->success($this->serializeOne($model), 201);
    }

    /**
     * Apply auth-source-specific fields on a fresh User. Returns an error
     * response array if validation fails, or null on success.
     *
     * @param array<string, mixed> $body
     * @return array{error: array{message: string}}|null
     */
    private function applyAuthSourceOnCreate(
        User $model,
        #[\SensitiveParameter] array $body,
        string $authSource
    ): ?array {
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
    private function applyLdapMetadata(User $model, #[\SensitiveParameter] array $body): void
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
    private function rejectAuthSourceChange(User $model, #[\SensitiveParameter] array $body): ?array
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
    private function applyPasswordOnUpdate(User $model, #[\SensitiveParameter] array $body): ?array
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
     * Updates the user. The answers come in the order web/openapi.yaml lists
     * for PUT /api/v1/users/{id}: 403 without user.update; 404 for a user
     * that does not exist; 403 for a user the caller may not change
     * (UserChangePolicy::refusalOf(), before every other check: CLAUDE.md
     * security rule 14); 422 for a change of auth_source or a "role" that
     * names no role of the instance (INVALID_ROLE); 403 for a role the user
     * does not have and the caller may not assign
     * (RoleGrantPolicy::refusalOf()); 422 for a "status" that is no whole
     * number (INVALID_STATUS, applyBody()); 403 for an is_superadmin that
     * would change the flag, from a caller who is no superadmin
     * (RoleGrantPolicy::SUPERADMIN_REFUSED); 422 for taking the superadmin
     * flag from the only active superadmin or deactivating them
     * (ONLY_SUPERADMIN_DEMOTED, ONLY_SUPERADMIN_DEACTIVATED); 422 for a
     * password or another field that is not valid, a status other than 0
     * and 10 included. Each changes nothing: neither the user nor their
     * roles.
     *
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionUpdate(int $id): array
    {
        if (!$this->userCan('user.update')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $body = (array)\Yii::$app->request->bodyParams;
        $grants = RoleGrantPolicy::forCurrentUser();
        $before = UserPrivileges::of($model);

        $err = $this->refuseChange($grants, $model)
            ?? $this->rejectAuthSourceChange($model, $body)
            ?? $this->rejectUnknownRole($body['role'] ?? null)
            ?? $this->refuseRoleGrant($grants, $body['role'] ?? null, $before->roles);
        if ($err !== null) {
            return $err;
        }

        $err = $this->applyBody($model, $body)
            ?? $this->refuseSuperadminChange($grants, $model, $before->superadmin)
            ?? $this->refuseLosingTheOnlySuperadmin($model)
            ?? $this->applyPasswordOnUpdate($model, $body);
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

        // Leaves the roles alone when the body names no role or one the user has.
        $this->assignRole($model, $body, $before->roles);

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_USER_UPDATED,
            'user',
            $model->id,
            null,
            ['username' => $model->username, 'source' => 'api'] + UserPrivileges::of($model)->changesSince($before)
        );

        return $this->success($this->serializeOne($model));
    }

    /**
     * Deletes the user and their roles. A user the caller may not change
     * answers 403 (UserChangePolicy::refusalOf()), after the 404 of a user
     * that does not exist; one's own account and the only active superadmin
     * answer 422. A user whom other records still refer to answers 409 and
     * stays, roles included.
     *
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionDelete(int $id): array
    {
        if (!$this->userCan('user.delete')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $err = $this->refuseChange(RoleGrantPolicy::forCurrentUser(), $model);
        if ($err !== null) {
            return $err;
        }

        if ((int)\Yii::$app->user->id === $id) {
            return $this->error('Cannot delete your own account.', 422);
        }

        if ($this->isTheOnlyActiveSuperadmin($model)) {
            return $this->error(self::ONLY_SUPERADMIN_DELETED, 422);
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
     * 403 when the caller, who gives what $grants allows, may not change
     * $model, with why (UserChangePolicy::refusalOf()).
     *
     * @return array{error: array{message: string}}|null
     */
    private function refuseChange(RoleGrantPolicy $grants, User $model): ?array
    {
        $refusal = UserChangePolicy::of($grants)->refusalOf($model);

        return $refusal === null ? null : $this->error($refusal, 403);
    }

    /**
     * 422 when saving $model would take the superadmin flag from the only
     * active superadmin (ONLY_SUPERADMIN_DEMOTED) or deactivate them
     * (ONLY_SUPERADMIN_DEACTIVATED): from active to any other status.
     *
     * @return array{error: array{message: string}}|null
     */
    private function refuseLosingTheOnlySuperadmin(User $model): ?array
    {
        $demoted = !$model->is_superadmin;
        $deactivated = (int)$model->status !== User::STATUS_ACTIVE;
        if ((!$demoted && !$deactivated) || !$this->isTheOnlyActiveSuperadmin($model)) {
            return null;
        }

        return $this->error($demoted ? self::ONLY_SUPERADMIN_DEMOTED : self::ONLY_SUPERADMIN_DEACTIVATED, 422);
    }

    /**
     * Whether $model, as stored before the request (its old attributes), is
     * the only active superadmin: a superadmin whose status is active while
     * no other user is one (User::activeSuperadminExists()). An inactive
     * superadmin, who cannot sign in, never is.
     */
    private function isTheOnlyActiveSuperadmin(User $model): bool
    {
        return (bool)$model->getOldAttribute('is_superadmin')
            && (int)$model->getOldAttribute('status') === User::STATUS_ACTIVE
            && !User::activeSuperadminExists((int)$model->id);
    }

    /**
     * 403 (RoleGrantPolicy::SUPERADMIN_REFUSED) when the body changed the
     * superadmin flag of $model from $before and the caller is no
     * superadmin; a body that repeats the flag is no change.
     *
     * @return array{error: array{message: string}}|null
     */
    private function refuseSuperadminChange(RoleGrantPolicy $grants, User $model, bool $before): ?array
    {
        if ((bool)$model->is_superadmin === $before || $grants->isSuperadmin()) {
            return null;
        }

        return $this->error(RoleGrantPolicy::SUPERADMIN_REFUSED, 403);
    }

    /**
     * 403 (RoleGrantPolicy::refusalOf(): ROLE_REFUSED, or ADMIN_ROLE_REFUSED
     * for the admin role or a role that contains it) when $role, the body's
     * "role", which rejectUnknownRole() checked, is a role the user does not
     * have ($assigned) and the caller may not assign. No "role", null, ''
     * and a role the user has assign nothing.
     *
     * @param list<string> $assigned the roles assigned to the user; [] for a new user
     * @return array{error: array{message: string}}|null
     */
    private function refuseRoleGrant(RoleGrantPolicy $grants, mixed $role, array $assigned): ?array
    {
        if (!is_string($role) || $role === '' || in_array($role, $assigned, true)) {
            return null;
        }
        $refusal = $grants->refusalOf($role);

        return $refusal === null ? null : $this->error($refusal, 403);
    }

    /**
     * Sets the fields $body holds. A "status" that is no whole number
     * (SubmittedInteger) answers 422 (INVALID_STATUS), and nothing may be
     * saved: the model's rule compares loosely and would take true for 10,
     * and an (int) cast fails on PHP 8.5 for a JSON number beyond the int
     * range. A whole number goes to the model's rule.
     *
     * @param array<string, mixed> $body
     * @return array{error: array{message: string}}|null
     */
    private function applyBody(User $model, #[\SensitiveParameter] array $body): ?array
    {
        foreach (['username', 'email'] as $field) {
            if (array_key_exists($field, $body)) {
                $model->$field = (string)$body[$field];
            }
        }
        if (array_key_exists('status', $body)) {
            $status = SubmittedInteger::of($body['status']);
            if ($status === null) {
                return $this->error(self::INVALID_STATUS, 422);
            }
            $model->status = $status;
        }
        if (array_key_exists('is_superadmin', $body)) {
            $model->is_superadmin = (bool)$body['is_superadmin'];
        }

        return null;
    }

    /**
     * 422 (INVALID_ROLE) when $role, the body's "role", is neither null nor ''
     * (both leave the roles alone) and names no role of the instance: the
     * roles the user form offers (UserForm::roleOptions()), compared
     * exactly. A value that is not a string names none. Checked before
     * anything is written.
     *
     * Regression: a role that named no role saved the user and left it
     * without any role (assignRole() revoked them before it looked the role
     * up), and a role that was not a string was ignored; both answered as if
     * the role had been taken.
     *
     * @return array{error: array{message: string}}|null
     */
    private function rejectUnknownRole(mixed $role): ?array
    {
        if ($role === null || $role === '') {
            return null;
        }
        $roles = UserForm::roleOptions();
        if (is_string($role) && isset($roles[$role])) {
            return null;
        }
        $names = array_map('strval', array_keys($roles));
        sort($names, SORT_STRING);

        return $this->error(sprintf(self::INVALID_ROLE, implode(', ', $names)), 422);
    }

    /**
     * Replaces the roles assigned to $model with the body's "role", which
     * rejectUnknownRole() and refuseRoleGrant() checked before anything was
     * written. No "role", null, '' and a role of $assigned, a role the user
     * already has, leave every assigned role alone.
     *
     * @param array<string, mixed> $body
     * @param list<string> $assigned the roles assigned to $model before the request; [] for a new user
     */
    private function assignRole(User $model, #[\SensitiveParameter] array $body, array $assigned): void
    {
        $roleName = $body['role'] ?? null;
        if (!is_string($roleName) || $roleName === '' || in_array($roleName, $assigned, true)) {
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
     * The users as the API answers them, with the roles of all of them read
     * in one query.
     *
     * @param list<User> $users
     * @return list<array{id: int, username: string, email: string, status: int, is_superadmin: bool, totp_enabled: bool, role: string|null, auth_source: string, ldap_dn: string|null, ldap_uid: string|null, last_synced_at: int|null, ldap_sync_disabled: bool, created_at: int, updated_at: int}>
     */
    private function serializeAll(array $users): array
    {
        $roles = AssignedRoles::fromApp()->ofUsers(array_map(static fn (User $u): int => (int)$u->id, $users));

        return array_map(fn (User $u): array => $this->serialize($u, $roles[(int)$u->id] ?? []), $users);
    }

    /**
     * @return array{id: int, username: string, email: string, status: int, is_superadmin: bool, totp_enabled: bool, role: string|null, auth_source: string, ldap_dn: string|null, ldap_uid: string|null, last_synced_at: int|null, ldap_sync_disabled: bool, created_at: int, updated_at: int}
     */
    private function serializeOne(User $u): array
    {
        return $this->serialize($u, AssignedRoles::fromApp()->of((int)$u->id));
    }

    /**
     * $u as the API answers it. Its role is the role assigned to the user,
     * the first by name when several are, and null when none is. A default
     * role, which every signed-in user holds, counts only when it is also
     * assigned (AssignedRoles). A client that sends the role back unchanged
     * keeps every role assigned to the user: a role the user has assigns
     * nothing.
     *
     * ldap_sync_disabled is read-only: true when ldap/sync deactivated the
     * account because the directory no longer had the user, so that an LDAP
     * sign-in or an ldap/sync that finds the user activates it again. POST
     * and PUT ignore it in the body, as they ignore totp_enabled,
     * last_synced_at, created_at and updated_at; a PUT that changes the
     * status clears it (LdapSyncDisabledBehavior, CLAUDE.md security rule
     * 15).
     *
     * @param list<string> $roles the names of the roles assigned to $u, sorted by name
     * @return array{id: int, username: string, email: string, status: int, is_superadmin: bool, totp_enabled: bool, role: string|null, auth_source: string, ldap_dn: string|null, ldap_uid: string|null, last_synced_at: int|null, ldap_sync_disabled: bool, created_at: int, updated_at: int}
     */
    private function serialize(User $u, array $roles): array
    {
        $roleName = $roles[0] ?? null;

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
            'ldap_sync_disabled' => (bool)$u->ldap_sync_disabled,
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
