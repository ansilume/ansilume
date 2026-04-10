<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Adds RBAC permissions for notification templates.
 *
 * viewer  → notification-template.view
 * operator → notification-template.create, notification-template.update
 * admin   → notification-template.delete
 */
class m000047_000000_seed_notification_template_rbac extends Migration
{
    public function safeUp(): void
    {
        $auth = Yii::$app->authManager;

        $permissions = [
            'notification-template.view' => 'View notification templates',
            'notification-template.create' => 'Create notification templates',
            'notification-template.update' => 'Update notification templates',
            'notification-template.delete' => 'Delete notification templates',
        ];

        $created = [];
        foreach ($permissions as $name => $desc) {
            $p = $auth->createPermission($name);
            $p->description = $desc;
            $auth->add($p);
            $created[$name] = $p;
        }

        $viewer = $auth->getRole('viewer');
        if ($viewer !== null) {
            $auth->addChild($viewer, $created['notification-template.view']);
        }

        $operator = $auth->getRole('operator');
        if ($operator !== null) {
            $auth->addChild($operator, $created['notification-template.create']);
            $auth->addChild($operator, $created['notification-template.update']);
        }

        $admin = $auth->getRole('admin');
        if ($admin !== null) {
            $auth->addChild($admin, $created['notification-template.delete']);
        }
    }

    public function safeDown(): void
    {
        $auth = Yii::$app->authManager;
        foreach (
            [
            'notification-template.view',
            'notification-template.create',
            'notification-template.update',
            'notification-template.delete',
            ] as $name
        ) {
            $perm = $auth->getPermission($name);
            if ($perm !== null) {
                $auth->remove($perm);
            }
        }
    }
}
