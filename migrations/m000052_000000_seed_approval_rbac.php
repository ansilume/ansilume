<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Seeds RBAC permissions for approval workflows.
 */
class m000052_000000_seed_approval_rbac extends Migration
{
    public function safeUp(): void
    {
        $auth = \Yii::$app->authManager;

        $permissions = [
            'approval.view' => 'View approval requests',
            'approval.decide' => 'Approve or reject approval requests',
            'approval-rule.view' => 'View approval rules',
            'approval-rule.create' => 'Create approval rules',
            'approval-rule.update' => 'Update approval rules',
            'approval-rule.delete' => 'Delete approval rules',
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
            $auth->addChild($viewer, $created['approval.view']);
            $auth->addChild($viewer, $created['approval-rule.view']);
        }
        if ($operator !== null) {
            $auth->addChild($operator, $created['approval.view']);
            $auth->addChild($operator, $created['approval.decide']);
            $auth->addChild($operator, $created['approval-rule.view']);
            $auth->addChild($operator, $created['approval-rule.create']);
            $auth->addChild($operator, $created['approval-rule.update']);
        }
        if ($admin !== null) {
            foreach ($created as $p) {
                $auth->addChild($admin, $p);
            }
        }
    }

    public function safeDown(): void
    {
        $auth = \Yii::$app->authManager;
        foreach (array_keys($this->getPermissionNames()) as $name) {
            $p = $auth->getPermission($name);
            if ($p !== null) {
                $auth->remove($p);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function getPermissionNames(): array
    {
        return [
            'approval.view' => '',
            'approval.decide' => '',
            'approval-rule.view' => '',
            'approval-rule.create' => '',
            'approval-rule.update' => '',
            'approval-rule.delete' => '',
        ];
    }
}
