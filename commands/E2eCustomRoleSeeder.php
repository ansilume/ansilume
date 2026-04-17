<?php

declare(strict_types=1);

namespace app\commands;

/**
 * Seeds a custom RBAC role with a tiny set of permissions so the roles UI
 * can be exercised against a non-system role (custom-role-specific delete
 * buttons, edit flows, permission-matrix checkboxes).
 */
class E2eCustomRoleSeeder
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
        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        $name = $prefix . 'custom-role';
        if ($auth->getRole($name) !== null) {
            ($this->logger)("  Custom role '{$name}' already exists.\n");
            return;
        }

        $role = $auth->createRole($name);
        $role->description = 'E2E custom role';
        $auth->add($role);

        foreach (['project.view', 'job.view', 'analytics.view'] as $permName) {
            $perm = $auth->getPermission($permName);
            if ($perm !== null) {
                $auth->addChild($role, $perm);
            }
        }
        ($this->logger)("  Created custom role '{$name}'.\n");
    }
}
