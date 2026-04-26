<?php

declare(strict_types=1);

$params = require __DIR__ . '/params.php';
$db = require __DIR__ . '/db-test.php';

return [
    'id' => 'ansilume-tests',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'components' => [
        'log' => [
            'targets' => [
                [
                    'class' => 'yii\log\FileTarget',
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
        'db' => $db,
        'cache' => ['class' => 'yii\caching\ArrayCache'],
        'authManager' => [
            'class' => 'yii\rbac\DbManager',
        ],
        'auditService' => [
            'class' => 'app\services\AuditService',
            'targets' => [new \app\services\audit\DatabaseAuditTarget()],
        ],
        'jobLaunchService' => [
            'class' => 'app\services\JobLaunchService',
        ],
        'scheduleService' => [
            'class' => 'app\services\ScheduleService',
        ],
        'roleService' => [
            'class' => 'app\services\RoleService',
        ],
        'notificationDispatcher' => [
            'class' => 'app\services\NotificationDispatcher',
        ],
        'analyticsService' => [
            'class' => 'app\services\AnalyticsService',
        ],
        'approvalService' => [
            'class' => 'app\services\ApprovalService',
        ],
        'workflowExecutionService' => [
            'class' => 'app\services\WorkflowExecutionService',
        ],
        'workflowStepReorderService' => [
            'class' => 'app\services\WorkflowStepReorderService',
        ],
        'webhookService' => [
            'class' => 'app\services\WebhookService',
        ],
        'lintService' => [
            'class' => 'app\services\LintService',
        ],
        'credentialService' => [
            'class' => 'app\services\CredentialService',
        ],
        'jobClaimService' => [
            'class' => 'app\services\JobClaimService',
        ],
        'jobCompletionService' => [
            'class' => 'app\services\JobCompletionService',
        ],
        'jobReclaimService' => [
            'class' => 'app\services\JobReclaimService',
            'progressTimeoutSeconds' => (int)(getenv('JOB_PROGRESS_TIMEOUT') ?: 600),
            'mode' => getenv('JOB_RECLAIM_MODE') ?: 'fail',
            'queueTimeoutSeconds' => (int)(getenv('JOB_QUEUE_TIMEOUT') ?: 1800),
        ],
        'projectAccessChecker' => [
            'class' => 'app\services\ProjectAccessChecker',
        ],
        'projectService' => [
            'class' => 'app\services\ProjectService',
        ],
        'totpService' => [
            'class' => 'app\services\TotpService',
            'rateLimiter' => [
                'class' => 'app\services\TotpRateLimiter',
            ],
        ],
        'inventoryService' => [
            'class' => 'app\services\InventoryService',
        ],
        'artifactService' => [
            'class' => 'app\services\ArtifactService',
            'storagePath' => '@runtime/test-artifacts',
        ],
        'maintenanceService' => [
            'class' => 'app\services\MaintenanceService',
            'artifactCleanupIntervalSeconds' => 86400,
        ],
        'ldapService' => [
            'class' => 'app\services\ldap\LdapService',
        ],
        'ldapUserProvisioner' => [
            'class' => 'app\services\ldap\LdapUserProvisioner',
        ],
        'mailer' => [
            'class' => 'yii\symfonymailer\Mailer',
            'useFileTransport' => true,
        ],
    ],
    'params' => $params,
];
