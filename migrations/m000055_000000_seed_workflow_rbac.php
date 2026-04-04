<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Seeds RBAC permissions for workflow templates and workflow jobs.
 */
class m000055_000000_seed_workflow_rbac extends Migration
{
    public function safeUp(): void
    {
        $auth = \Yii::$app->authManager;

        $permissions = [
            'workflow-template.view' => 'View workflow templates',
            'workflow-template.create' => 'Create workflow templates',
            'workflow-template.update' => 'Update workflow templates',
            'workflow-template.delete' => 'Delete workflow templates',
            'workflow.launch' => 'Launch workflow executions',
            'workflow.cancel' => 'Cancel running workflows',
            'workflow.view' => 'View workflow executions',
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
            $auth->addChild($viewer, $created['workflow-template.view']);
            $auth->addChild($viewer, $created['workflow.view']);
        }
        if ($operator !== null) {
            $auth->addChild($operator, $created['workflow-template.view']);
            $auth->addChild($operator, $created['workflow-template.create']);
            $auth->addChild($operator, $created['workflow-template.update']);
            $auth->addChild($operator, $created['workflow.launch']);
            $auth->addChild($operator, $created['workflow.cancel']);
            $auth->addChild($operator, $created['workflow.view']);
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
        $names = [
            'workflow-template.view', 'workflow-template.create',
            'workflow-template.update', 'workflow-template.delete',
            'workflow.launch', 'workflow.cancel', 'workflow.view',
        ];
        foreach ($names as $name) {
            $p = $auth->getPermission($name);
            if ($p !== null) {
                $auth->remove($p);
            }
        }
    }
}
