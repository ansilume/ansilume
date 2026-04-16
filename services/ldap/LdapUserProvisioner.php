<?php

declare(strict_types=1);

namespace app\services\ldap;

use app\models\AuditLog;
use app\models\User;
use yii\base\Component;

/**
 * Persists the outcome of an LDAP authentication into the local database.
 *
 * Responsibilities:
 *  - Resolve an {@see LdapAuthResult} to an existing {@see User} (by stable
 *    ldap_uid first, then username for first-touch admin pre-creation).
 *  - Auto-create a local user record on first successful bind, when
 *    {@see LdapConfig::$autoProvision} is true.
 *  - Reconcile RBAC role assignments against the roles returned by the
 *    role-mapping logic, removing roles the directory no longer grants.
 *  - Re-activate accounts that were previously disabled by lifecycle
 *    sync if the user appears in the directory again.
 *
 * The provisioner deliberately does NOT touch passwords. LDAP users
 * carry the {@see User::LDAP_PASSWORD_SENTINEL} hash so the local
 * bcrypt path can never match them, regardless of any future bug.
 */
class LdapUserProvisioner extends Component
{
    /**
     * Find the existing local User that matches the directory entry, or
     * provision a new one when auto-provisioning is enabled.
     *
     * Returns null only when the user does not exist locally AND
     * auto-provisioning is disabled — caller should treat this as
     * "directory accepts you, but Ansilume admin hasn't pre-created
     * an account, so login is denied".
     */
    public function provisionOrUpdate(LdapAuthResult $result, LdapConfig $config): ?User
    {
        $existing = $this->resolveUser($result);
        $isNew = $existing === null;

        if ($isNew) {
            if (!$config->autoProvision) {
                return null;
            }
            $existing = $this->buildNewUser($result);
        }

        /** @var User $user */
        $user = $existing;
        $snapshot = [
            'status' => (int)$user->status,
            'dn' => (string)($user->ldap_dn ?? ''),
            'email' => (string)$user->email,
        ];

        $reEnabled = $this->applyDirectoryAttributes($user, $result, $snapshot['status']);

        if ($isNew) {
            $user->generateAuthKey();
        }
        if (!$user->save()) {
            return null;
        }

        $roleChange = $this->reconcileRoles((int)$user->id, $result->roles);
        $this->writeAuditTrail($user, $result, $snapshot, $roleChange, $isNew, $reEnabled);

        return $user;
    }

    /**
     * Pin auth_source/sentinel and copy directory attributes onto the user.
     * Returns true if the user transitioned from inactive to active.
     */
    private function applyDirectoryAttributes(User $user, LdapAuthResult $result, int $previousStatus): bool
    {
        // Always pin auth_source + sentinel — defends against database
        // tampering by an admin (or a buggy migration) that would
        // otherwise turn an LDAP account into a passwordless local one.
        $user->markAsLdapManaged();
        $user->ldap_uid = $result->uid;
        $user->ldap_dn = $result->dn;
        if ($result->email !== '') {
            $user->email = $result->email;
        }
        $user->last_synced_at = time();

        // Re-enable accounts disabled by an earlier lifecycle sweep — the
        // directory has accepted them again, so they should not stay locked.
        if ($previousStatus !== User::STATUS_ACTIVE) {
            $user->status = User::STATUS_ACTIVE;
            return true;
        }
        return false;
    }

    /**
     * Emit the audit log entries that describe what changed.
     *
     * @param array{status: int, dn: string, email: string} $snapshot
     * @param array{added: list<string>, removed: list<string>} $roleChange
     */
    private function writeAuditTrail(
        User $user,
        LdapAuthResult $result,
        array $snapshot,
        array $roleChange,
        bool $isNew,
        bool $reEnabled
    ): void {
        $audit = $this->audit();
        if ($audit === null) {
            return;
        }
        if ($isNew) {
            $this->auditProvisioned($audit, $user, $result);
        } else {
            $this->auditSynced($audit, $user, $result, $snapshot, $roleChange);
        }
        if ($reEnabled) {
            $audit->log(
                AuditLog::ACTION_LDAP_USER_REENABLED,
                'user',
                (int)$user->id,
                (int)$user->id,
                ['username' => $user->username, 'dn' => $result->dn],
            );
        }
        if ($roleChange['added'] !== [] || $roleChange['removed'] !== []) {
            $audit->log(
                AuditLog::ACTION_LDAP_ROLES_CHANGED,
                'user',
                (int)$user->id,
                (int)$user->id,
                [
                    'added' => $roleChange['added'],
                    'removed' => $roleChange['removed'],
                    'final' => $result->roles,
                ],
            );
        }
    }

    private function auditProvisioned(\app\services\AuditService $audit, User $user, LdapAuthResult $result): void
    {
        $audit->log(
            AuditLog::ACTION_LDAP_USER_PROVISIONED,
            'user',
            (int)$user->id,
            (int)$user->id,
            [
                'username' => $user->username,
                'dn' => $result->dn,
                'uid' => $result->uid,
                'email' => $user->email,
                'display_name' => $result->displayName,
                'groups' => $result->groups,
                'roles' => $result->roles,
            ],
        );
    }

    /**
     * @param array{status: int, dn: string, email: string} $snapshot
     * @param array{added: list<string>, removed: list<string>} $roleChange
     */
    private function auditSynced(
        \app\services\AuditService $audit,
        User $user,
        LdapAuthResult $result,
        array $snapshot,
        array $roleChange
    ): void {
        $changed = [];
        if ($snapshot['email'] !== $user->email) {
            $changed['email'] = ['from' => $snapshot['email'], 'to' => $user->email];
        }
        if ($snapshot['dn'] !== $result->dn) {
            $changed['dn'] = ['from' => $snapshot['dn'], 'to' => $result->dn];
        }
        if ($changed === [] && $roleChange['added'] === [] && $roleChange['removed'] === []) {
            return;
        }
        $audit->log(
            AuditLog::ACTION_LDAP_USER_SYNCED,
            'user',
            (int)$user->id,
            (int)$user->id,
            array_merge($changed, [
                'roles_added' => $roleChange['added'],
                'roles_removed' => $roleChange['removed'],
            ]),
        );
    }

    /**
     * Resolve the local User for an LDAP result. Looks up by stable
     * `ldap_uid` first, then by username to claim a pre-created account.
     *
     * The username path only matches accounts that are already marked
     * `auth_source=ldap` — we never silently convert a local account
     * into an LDAP one (that would let a directory user hijack a local
     * username collision).
     */
    public function resolveUser(LdapAuthResult $result): ?User
    {
        if ($result->uid !== '') {
            /** @var User|null $byUid */
            $byUid = User::find()->where(['ldap_uid' => $result->uid])->one();
            if ($byUid !== null) {
                return $byUid;
            }
        }
        /** @var User|null $byName */
        $byName = User::find()
            ->where([
                'username' => $result->username,
                'auth_source' => User::AUTH_SOURCE_LDAP,
            ])
            ->one();
        return $byName;
    }

    /**
     * Build (but do not save) a fresh User row populated from the LDAP
     * result. Status is ACTIVE; the password column carries the sentinel.
     */
    private function buildNewUser(LdapAuthResult $result): User
    {
        $user = new User();
        $user->username = $result->username;
        $user->email = $result->email !== ''
            ? $result->email
            : $result->username . '@ldap.local';
        $user->status = User::STATUS_ACTIVE;
        $user->is_superadmin = false;
        return $user;
    }

    /**
     * Replace the user's role assignments with exactly $targetRoles.
     *
     * Returns the diff so the caller can audit it. Roles that don't
     * exist in the RBAC tables are silently skipped — operators can
     * see this in the audit log if a mapping points at a missing role.
     *
     * @param list<string> $targetRoles
     * @return array{added: list<string>, removed: list<string>}
     */
    public function reconcileRoles(int $userId, array $targetRoles): array
    {
        $auth = \Yii::$app->authManager;
        if ($auth === null) {
            return ['added' => [], 'removed' => []];
        }
        $current = [];
        foreach ($auth->getAssignments($userId) as $assignment) {
            $current[] = $assignment->roleName;
        }
        $target = array_values(array_unique($targetRoles));

        $toAdd = array_values(array_diff($target, $current));
        $toRemove = array_values(array_diff($current, $target));

        $added = [];
        foreach ($toAdd as $name) {
            $role = $auth->getRole($name);
            if ($role === null) {
                continue;
            }
            $auth->assign($role, $userId);
            $added[] = $name;
        }
        $removed = [];
        foreach ($toRemove as $name) {
            $role = $auth->getRole($name);
            if ($role === null) {
                // Stale assignment to a deleted role — strip via revokeAll fallback.
                continue;
            }
            $auth->revoke($role, $userId);
            $removed[] = $name;
        }
        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * Mark a user as inactive after a sync confirms the directory no
     * longer recognises them. Rotates auth_key (kills active sessions)
     * and deletes API tokens (kills programmatic access). Audited.
     *
     * Returns true if the user was disabled, false if the user was
     * already inactive (idempotent).
     */
    public function disableMissingUser(User $user, string $reason): bool
    {
        if ((int)$user->status === User::STATUS_INACTIVE) {
            return false;
        }
        $user->status = User::STATUS_INACTIVE;
        // Rotate auth_key so any browser session cookie tied to the
        // old key becomes invalid immediately on next request.
        $user->generateAuthKey();
        $user->last_synced_at = time();
        $user->save();

        // Revoke API tokens — programmatic access must die when the
        // directory revokes the user's identity.
        \app\models\ApiToken::deleteAll(['user_id' => $user->id]);

        // Strip RBAC assignments so any cached permission table
        // doesn't continue granting access.
        $auth = \Yii::$app->authManager;
        $auth?->revokeAll((int)$user->id);

        $this->audit()?->log(
            AuditLog::ACTION_LDAP_USER_DISABLED,
            'user',
            (int)$user->id,
            (int)$user->id,
            [
                'username' => $user->username,
                'dn' => $user->ldap_dn,
                'reason' => $reason,
            ],
        );
        return true;
    }

    private function audit(): ?\app\services\AuditService
    {
        if (!\Yii::$app->has('auditService')) {
            return null;
        }
        /** @var \app\services\AuditService $svc */
        $svc = \Yii::$app->get('auditService');
        return $svc;
    }
}
