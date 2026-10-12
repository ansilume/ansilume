<?php

declare(strict_types=1);

namespace app\commands;

use app\models\User;
use yii\rbac\ManagerInterface;
use yii\rbac\Role;

/**
 * A user whose only role may view and cancel jobs but not launch them, for
 * the re-launch permission check of tests/e2e/tests/jobs/survey-secrets.spec.ts:
 * a re-launch needs job.launch (before, it needed job.cancel), and the
 * default roles hold both or neither.
 *
 * Restored on every seed: the role's permissions, the user (active, with the
 * fixture password of the spec) and its only role. Teardown removes both
 * (custom roles and users named e2e-*).
 */
final class E2eCancelOnlyUserSeeder
{
    public const ROLE = 'e2e-cancel-only';
    public const USERNAME = 'e2e-canceler';
    /** The role's permissions: no job.launch. */
    public const PERMISSIONS = ['job.view', 'job.cancel'];

    /** @var callable(string): void */
    private $logger;

    /**
     * @param callable(string): void $logger
     */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    public function seed(): void
    {
        /** @var ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        $role = $this->role($auth);

        $user = User::findOne(['username' => self::USERNAME]) ?? new User();
        $user->username = self::USERNAME;
        $user->email = self::USERNAME . '@example.com';
        $user->status = User::STATUS_ACTIVE;
        $user->is_superadmin = false;
        $user->setPassword('E2eCancelerPass1!');
        if ($user->isNewRecord) {
            $user->generateAuthKey();
        }
        if (!$user->save()) {
            ($this->logger)("  Failed to restore user '" . self::USERNAME . "': " . json_encode($user->errors) . "\n");
            return;
        }
        $auth->revokeAll((int)$user->id);
        $auth->assign($role, (int)$user->id);

        ($this->logger)("  Restored user '" . self::USERNAME . "' (ID {$user->id}) with the only role '" . self::ROLE . "'.\n");
    }

    /**
     * The role with exactly PERMISSIONS.
     */
    private function role(ManagerInterface $auth): Role
    {
        $role = $auth->getRole(self::ROLE);
        if ($role === null) {
            $role = $auth->createRole(self::ROLE);
            $role->description = 'E2E: may view and cancel jobs, not launch them';
            $auth->add($role);
        }
        $auth->removeChildren($role);
        foreach (self::PERMISSIONS as $name) {
            $permission = $auth->getPermission($name);
            if ($permission !== null) {
                $auth->addChild($role, $permission);
            }
        }

        return $role;
    }
}
