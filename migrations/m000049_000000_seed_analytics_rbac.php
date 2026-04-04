<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Seeds RBAC permissions for the analytics feature.
 *
 * - analytics.view  → viewer, operator, admin
 * - analytics.export → operator, admin
 */
class m000049_000000_seed_analytics_rbac extends Migration
{
    public function safeUp(): void
    {
        $auth = \Yii::$app->authManager;

        $permissions = [
            'analytics.view' => 'View analytics dashboards and reports',
            'analytics.export' => 'Export analytics data as CSV or JSON',
        ];

        $created = [];
        foreach ($permissions as $name => $desc) {
            $p = $auth->createPermission($name);
            $p->description = $desc;
            $auth->add($p);
            $created[$name] = $p;
        }

        $viewer = $auth->getRole('viewer');
        $operator = $auth->getRole('operator');
        $admin = $auth->getRole('admin');

        if ($viewer !== null) {
            $auth->addChild($viewer, $created['analytics.view']);
        }
        if ($operator !== null) {
            $auth->addChild($operator, $created['analytics.view']);
            $auth->addChild($operator, $created['analytics.export']);
        }
        if ($admin !== null) {
            $auth->addChild($admin, $created['analytics.view']);
            $auth->addChild($admin, $created['analytics.export']);
        }
    }

    public function safeDown(): void
    {
        $auth = \Yii::$app->authManager;

        foreach (['analytics.view', 'analytics.export'] as $name) {
            $p = $auth->getPermission($name);
            if ($p !== null) {
                $auth->remove($p);
            }
        }
    }
}
