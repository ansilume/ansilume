<?php

declare(strict_types=1);

namespace app\commands;

use app\models\User;

/**
 * Seeds a directory-managed user so the UI can render the LDAP badge, the
 * locked edit form, and the "managed externally" notice on the profile
 * page without needing a real LDAP server in CI.
 */
class E2eLdapUserSeeder
{
    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    public function seed(string $prefix): void
    {
        $username = $prefix . 'ldap-user';
        $existing = User::find()->where(['username' => $username])->one();
        if ($existing !== null) {
            ($this->logger)("  LDAP user '{$username}' already exists (ID {$existing->id}).\n");
            $this->ensureViewerRole((int)$existing->id);
            return;
        }

        $user = new User();
        $user->username = $username;
        $user->email = $username . '@example.com';
        $user->status = User::STATUS_ACTIVE;
        $user->is_superadmin = false;
        $user->markAsLdapManaged();
        $user->ldap_dn = 'uid=' . $username . ',dc=e2e,dc=test';
        $user->ldap_uid = 'guid-e2e-ldap';
        $user->last_synced_at = time();
        $user->generateAuthKey();
        if (!$user->save()) {
            ($this->logger)("  Failed to create LDAP user: " . json_encode($user->errors) . "\n");
            return;
        }

        $this->ensureViewerRole((int)$user->id);
        ($this->logger)("  Created LDAP user '{$username}' (ID {$user->id}).\n");
    }

    private function ensureViewerRole(int $userId): void
    {
        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        $role = $auth->getRole('viewer');
        if ($role !== null && $auth->getAssignment('viewer', $userId) === null) {
            $auth->assign($role, $userId);
        }
    }
}
