<?php

declare(strict_types=1);

namespace app\helpers;

/**
 * Static catalog that groups RBAC permissions by domain for the role
 * management UI. The auth_item table is the source of truth for existence;
 * this catalog is the source of truth for grouping + display labels.
 *
 * When a new permission is added to a migration, add it here too — the
 * guardrail test `PermissionCatalogTest` fails otherwise.
 */
final class PermissionCatalog
{
    /**
     * @var array<int, array{domain: string, label: string, permissions: array<int, array{name: string, label: string}>}>
     */
    private const GROUPS = [
            [
                'domain' => 'user',
                'label' => 'Users',
                'permissions' => [
                    ['name' => 'user.view', 'label' => 'View users'],
                    ['name' => 'user.create', 'label' => 'Create users'],
                    ['name' => 'user.update', 'label' => 'Update users'],
                    ['name' => 'user.delete', 'label' => 'Delete users'],
                ],
            ],
            [
                'domain' => 'role',
                'label' => 'Roles',
                'permissions' => [
                    ['name' => 'role.view', 'label' => 'View roles'],
                    ['name' => 'role.create', 'label' => 'Create roles'],
                    ['name' => 'role.update', 'label' => 'Update roles'],
                    ['name' => 'role.delete', 'label' => 'Delete roles'],
                ],
            ],
            [
                'domain' => 'project',
                'label' => 'Projects',
                'permissions' => [
                    ['name' => 'project.view', 'label' => 'View projects'],
                    ['name' => 'project.create', 'label' => 'Create projects'],
                    ['name' => 'project.update', 'label' => 'Update projects'],
                    ['name' => 'project.delete', 'label' => 'Delete projects'],
                ],
            ],
            [
                'domain' => 'inventory',
                'label' => 'Inventories',
                'permissions' => [
                    ['name' => 'inventory.view', 'label' => 'View inventories'],
                    ['name' => 'inventory.create', 'label' => 'Create inventories'],
                    ['name' => 'inventory.update', 'label' => 'Update inventories'],
                    ['name' => 'inventory.delete', 'label' => 'Delete inventories'],
                ],
            ],
            [
                'domain' => 'credential',
                'label' => 'Credentials',
                'permissions' => [
                    ['name' => 'credential.view', 'label' => 'View credentials'],
                    ['name' => 'credential.create', 'label' => 'Create credentials'],
                    ['name' => 'credential.update', 'label' => 'Update credentials'],
                    ['name' => 'credential.delete', 'label' => 'Delete credentials'],
                ],
            ],
            [
                'domain' => 'job-template',
                'label' => 'Job Templates',
                'permissions' => [
                    ['name' => 'job-template.view', 'label' => 'View job templates'],
                    ['name' => 'job-template.create', 'label' => 'Create job templates'],
                    ['name' => 'job-template.update', 'label' => 'Update job templates'],
                    ['name' => 'job-template.delete', 'label' => 'Delete job templates'],
                ],
            ],
            [
                'domain' => 'job',
                'label' => 'Jobs',
                'permissions' => [
                    ['name' => 'job.view', 'label' => 'View jobs'],
                    ['name' => 'job.launch', 'label' => 'Launch jobs'],
                    ['name' => 'job.cancel', 'label' => 'Cancel jobs'],
                ],
            ],
            [
                'domain' => 'runner-group',
                'label' => 'Runner Groups',
                'permissions' => [
                    ['name' => 'runner-group.view', 'label' => 'View runner groups'],
                    ['name' => 'runner-group.create', 'label' => 'Create runner groups'],
                    ['name' => 'runner-group.update', 'label' => 'Update runner groups'],
                    ['name' => 'runner-group.delete', 'label' => 'Delete runner groups'],
                ],
            ],
            [
                'domain' => 'notification-template',
                'label' => 'Notification Templates',
                'permissions' => [
                    ['name' => 'notification-template.view', 'label' => 'View notification templates'],
                    ['name' => 'notification-template.create', 'label' => 'Create notification templates'],
                    ['name' => 'notification-template.update', 'label' => 'Update notification templates'],
                    ['name' => 'notification-template.delete', 'label' => 'Delete notification templates'],
                ],
            ],
            [
                'domain' => 'analytics',
                'label' => 'Analytics',
                'permissions' => [
                    ['name' => 'analytics.view', 'label' => 'View analytics'],
                    ['name' => 'analytics.export', 'label' => 'Export analytics'],
                ],
            ],
            [
                'domain' => 'approval-rule',
                'label' => 'Approval Rules',
                'permissions' => [
                    ['name' => 'approval-rule.view', 'label' => 'View approval rules'],
                    ['name' => 'approval-rule.create', 'label' => 'Create approval rules'],
                    ['name' => 'approval-rule.update', 'label' => 'Update approval rules'],
                    ['name' => 'approval-rule.delete', 'label' => 'Delete approval rules'],
                ],
            ],
            [
                'domain' => 'approval',
                'label' => 'Approvals',
                'permissions' => [
                    ['name' => 'approval.view', 'label' => 'View approval requests'],
                    ['name' => 'approval.decide', 'label' => 'Approve or reject requests'],
                ],
            ],
            [
                'domain' => 'workflow-template',
                'label' => 'Workflow Templates',
                'permissions' => [
                    ['name' => 'workflow-template.view', 'label' => 'View workflow templates'],
                    ['name' => 'workflow-template.create', 'label' => 'Create workflow templates'],
                    ['name' => 'workflow-template.update', 'label' => 'Update workflow templates'],
                    ['name' => 'workflow-template.delete', 'label' => 'Delete workflow templates'],
                ],
            ],
            [
                'domain' => 'workflow',
                'label' => 'Workflows',
                'permissions' => [
                    ['name' => 'workflow.view', 'label' => 'View workflow executions'],
                    ['name' => 'workflow.launch', 'label' => 'Launch workflows'],
                    ['name' => 'workflow.cancel', 'label' => 'Cancel workflows'],
                ],
            ],
    ];

    /**
     * Ordered list of domains. Each domain has a label and an ordered list of
     * permissions with their human-readable label.
     *
     * @return array<int, array{domain: string, label: string, permissions: array<int, array{name: string, label: string}>}>
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public static function groups(): array
    {
        return self::GROUPS;
    }

    /**
     * Flat list of all known permission names.
     *
     * @return array<int, string>
     */
    public static function allPermissionNames(): array
    {
        $names = [];
        foreach (self::groups() as $group) {
            foreach ($group['permissions'] as $perm) {
                $names[] = $perm['name'];
            }
        }
        return $names;
    }

    /**
     * Human-readable label for a permission name, or the raw name if unknown.
     */
    public static function labelFor(string $permissionName): string
    {
        foreach (self::groups() as $group) {
            foreach ($group['permissions'] as $perm) {
                if ($perm['name'] === $permissionName) {
                    return $perm['label'];
                }
            }
        }
        return $permissionName;
    }
}
