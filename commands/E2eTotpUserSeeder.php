<?php

declare(strict_types=1);

namespace app\commands;

use app\models\User;
use app\services\TotpService;

/**
 * Seeds a TOTP-enabled user with a known, well-documented secret so the
 * login-with-2FA round-trip can be exercised end-to-end. The shared secret
 * is baked into the spec (tests/e2e/lib/totp.ts) and is only ever used by
 * the test fixture — it must never match a production secret.
 */
class E2eTotpUserSeeder
{
    /**
     * Base32 TOTP shared secret. 160-bit (32 chars) so it matches what
     * TotpService::generateSecret() would produce in the real flow. Changing
     * this value requires updating tests/e2e/lib/totp.ts in the same commit.
     */
    public const TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    public function seed(string $prefix): void
    {
        $username = $prefix . 'totp';
        $user = User::find()->where(['username' => $username])->one();

        if ($user === null) {
            $user = new User();
            $user->username = $username;
            $user->email = $username . '@example.com';
            $user->status = User::STATUS_ACTIVE;
            $user->is_superadmin = false;
            $user->setPassword('E2eTotpPass1!');
            $user->generateAuthKey();
            if (!$user->save()) {
                ($this->logger)("  Failed to create TOTP user: " . json_encode($user->errors) . "\n");
                return;
            }
            ($this->logger)("  Created TOTP user '{$username}' (ID {$user->id}).\n");
        } else {
            ($this->logger)("  TOTP user '{$username}' already exists (ID {$user->id}).\n");
        }

        $this->ensureViewerRole((int)$user->id);

        // Always (re-)enable TOTP with the known fixture secret so a previous
        // disable-totp run can't leave the seed in an inconsistent state.
        /** @var TotpService $totp */
        $totp = \Yii::$app->get('totpService');
        $totp->enable($user, self::TOTP_SECRET);
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
