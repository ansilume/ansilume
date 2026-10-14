<?php

declare(strict_types=1);

namespace app\commands;

use app\models\User;
use yii\rbac\ManagerInterface;
use yii\rbac\Permission;
use yii\rbac\Role;

/**
 * The user of tests/e2e/tests/roles/role-managers.spec.ts: USERNAME holds
 * only ROLE, which manages roles and nothing else. It creates roles only
 * with permissions it holds (the role.* permissions and, through the
 * default role, the viewer's), and changes or deletes only roles it may
 * assign (app\services\RoleChangePolicy): not admin or operator.
 *
 * Restored on every seed: the role's permissions, the user (active, no
 * superadmin, the fixture password of tests/e2e/lib/session.ts) and its only
 * role. Teardown removes both (users and custom roles named e2e-*).
 */
final class E2eRoleManagerSeeder
{
    public const ROLE = 'e2e-role-manager';

    /** What the role manager may do: view, create, change and delete roles. */
    public const PERMISSIONS = ['role.view', 'role.create', 'role.update', 'role.delete'];

    public const USERNAME = 'e2e-role-editor';

    /** What a seed says of the restored user: their name, ID and role. */
    public const RESTORED = "  Restored user '%s' (ID %d) with the only role '%s'.\n";

    /** What a seed says of a user it could not save: their name and the validation errors as JSON. */
    public const NOT_RESTORED = "  Failed to restore user '%s': %s\n";

    /** The fixture password of USERNAME in tests/e2e/lib/session.ts; not a secret. */
    private const FIXTURE_PASSWORD = 'E2eRoleEditorPass1!';

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
        $role = $this->roleWithExactlyItsPermissions($auth);

        $editor = User::findOne(['username' => self::USERNAME]) ?? new User(['username' => self::USERNAME]);
        if ($editor->isNewRecord) {
            $editor->generateAuthKey();
        }
        $editor->setPassword(self::FIXTURE_PASSWORD);
        $editor->setAttributes([
            'email' => self::USERNAME . '@example.com',
            'status' => User::STATUS_ACTIVE,
            'is_superadmin' => false,
        ], false);
        if (!$editor->save()) {
            ($this->logger)(sprintf(self::NOT_RESTORED, self::USERNAME, (string)json_encode($editor->errors)));
            return;
        }

        $auth->revokeAll((int)$editor->id);
        $auth->assign($role, (int)$editor->id);
        ($this->logger)(sprintf(self::RESTORED, self::USERNAME, (int)$editor->id, self::ROLE));
    }

    private function roleWithExactlyItsPermissions(ManagerInterface $auth): Role
    {
        $role = $auth->getRole(self::ROLE);
        if ($role === null) {
            $role = $auth->createRole(self::ROLE);
            $role->description = 'E2E: manages roles, and grants only what it holds';
            $auth->add($role);
        }
        $auth->removeChildren($role);
        $permissions = array_filter(
            array_map(static fn (string $name): ?Permission => $auth->getPermission($name), self::PERMISSIONS)
        );
        foreach ($permissions as $permission) {
            $auth->addChild($role, $permission);
        }

        return $role;
    }
}
