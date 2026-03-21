<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Seeds the initial RBAC role hierarchy.
 *
 * Roles:
 *   superadmin  — unrestricted access (enforced via User::isSuperadmin)
 *   admin       — manage users, all resources, launch jobs
 *   operator    — launch jobs, manage templates/inventories/credentials/projects
 *   viewer      — read-only access (default role)
 */
class m000010_seed_rbac_roles extends Migration
{
    public function safeUp(): void
    {
        $auth = Yii::$app->authManager;

        // --- Permissions ---
        $permissions = [
            // User management
            'user.view'   => 'View users',
            'user.create' => 'Create users',
            'user.update' => 'Update users',
            'user.delete' => 'Delete users',
            // Projects
            'project.view'   => 'View projects',
            'project.create' => 'Create projects',
            'project.update' => 'Update projects',
            'project.delete' => 'Delete projects',
            // Inventories
            'inventory.view'   => 'View inventories',
            'inventory.create' => 'Create inventories',
            'inventory.update' => 'Update inventories',
            'inventory.delete' => 'Delete inventories',
            // Credentials
            'credential.view'   => 'View credentials',
            'credential.create' => 'Create credentials',
            'credential.update' => 'Update credentials',
            'credential.delete' => 'Delete credentials',
            // Job Templates
            'job-template.view'   => 'View job templates',
            'job-template.create' => 'Create job templates',
            'job-template.update' => 'Update job templates',
            'job-template.delete' => 'Delete job templates',
            // Jobs
            'job.view'   => 'View jobs',
            'job.launch' => 'Launch jobs',
            'job.cancel' => 'Cancel jobs',
        ];

        $created = [];
        foreach ($permissions as $name => $desc) {
            $p = $auth->createPermission($name);
            $p->description = $desc;
            $auth->add($p);
            $created[$name] = $p;
        }

        // --- Roles ---
        $viewer = $auth->createRole('viewer');
        $viewer->description = 'Read-only access';
        $auth->add($viewer);
        foreach (['project.view', 'inventory.view', 'credential.view', 'job-template.view', 'job.view'] as $perm) {
            $auth->addChild($viewer, $created[$perm]);
        }

        $operator = $auth->createRole('operator');
        $operator->description = 'Can launch jobs and manage resources';
        $auth->add($operator);
        $auth->addChild($operator, $viewer);
        foreach ([
            'project.create', 'project.update',
            'inventory.create', 'inventory.update',
            'credential.create', 'credential.update',
            'job-template.create', 'job-template.update',
            'job.launch', 'job.cancel',
        ] as $perm) {
            $auth->addChild($operator, $created[$perm]);
        }

        $admin = $auth->createRole('admin');
        $admin->description = 'Full access except super-admin actions';
        $auth->add($admin);
        $auth->addChild($admin, $operator);
        foreach ([
            'user.view', 'user.create', 'user.update', 'user.delete',
            'project.delete', 'inventory.delete', 'credential.delete',
            'job-template.delete',
        ] as $perm) {
            $auth->addChild($admin, $created[$perm]);
        }
    }

    public function safeDown(): void
    {
        $auth = Yii::$app->authManager;
        $auth->removeAll();
    }
}
