<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Seeds RBAC permissions for the custom-role management UI.
 * Only admins may view or modify roles.
 */
class m000061_000000_seed_role_rbac extends Migration
{
    public function safeUp(): void
    {
        $auth = \Yii::$app->authManager;

        $permissions = [
            'role.view' => 'View roles',
            'role.create' => 'Create roles',
            'role.update' => 'Update roles',
            'role.delete' => 'Delete roles',
        ];

        $created = [];
        foreach ($permissions as $name => $desc) {
            $p = $auth->createPermission($name);
            $p->description = $desc;
            $auth->add($p);
            $created[$name] = $p;
        }

        $admin = $auth->getRole('admin');
        if ($admin !== null) {
            foreach ($created as $p) {
                $auth->addChild($admin, $p);
            }
        }
    }

    public function safeDown(): void
    {
        $auth = \Yii::$app->authManager;
        foreach (['role.view', 'role.create', 'role.update', 'role.delete'] as $name) {
            $p = $auth->getPermission($name);
            if ($p !== null) {
                $auth->remove($p);
            }
        }
    }
}
