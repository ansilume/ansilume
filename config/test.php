<?php

declare(strict_types=1);

$params = require __DIR__ . '/params.php';
// Every Redis connection (cache, session, queue) shares host, port, db and the
// optional REDIS_PASSWORD; see app\components\RedisSettings.
$redis = \app\components\RedisSettings::fromEnvironment($_ENV)->connectionConfig();
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
                    // Never dump request/environment context ($_SERVER contains
                    // APP_SECRET_KEY, DB_PASSWORD, ...) into the log file.
                    'logVars' => [],
                ],
            ],
        ],
        'db' => $db,
        'cache' => ['class' => 'yii\caching\ArrayCache'],
        // Minimum bcrypt cost. Tests create thousands of users, and hashing
        // their passwords at the default cost 13 dominated the suite's
        // runtime, pushing the CI PHPUnit job past its time limit.
        // Production keeps the default.
        'security' => ['passwordHashCost' => 4],
        // Real Redis-backed queue so tests of the worker-snapshot
        // queue_depth and is_stuck logic can plant fixtures via
        // redis-cli and read them back through the same channel
        // the controller queries.
        'queue' => [
            'class' => 'yii\queue\redis\Queue',
            // Only Ansilume's own job classes may be unserialized from Redis.
            'serializer' => \app\components\AllowlistQueueSerializer::class,
            'redis' => $redis,
            // Distinct channel so the live dev queue-worker doesn't drain
            // fixture jobs the tests push to assert is_stuck / queue_depth.
            'channel' => 'ansilume-test-queue',
        ],
        'redis' => $redis,
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
        'projectDeletionService' => [
            'class' => 'app\services\ProjectDeletionService',
        ],
        'userDeletionService' => [
            'class' => 'app\services\UserDeletionService',
        ],
        'jobTemplateCredentialService' => [
            'class' => 'app\services\JobTemplateCredentialService',
        ],
        'jobCredentialResolver' => [
            'class' => 'app\services\JobCredentialResolver',
        ],
        'credentialUsageService' => [
            'class' => 'app\services\CredentialUsageService',
        ],
        'credentialWriteService' => [
            'class' => 'app\services\CredentialWriteService',
        ],
        'vaultCredentialAssignmentService' => [
            'class' => 'app\services\VaultCredentialAssignmentService',
        ],
        'vaultScanService' => [
            'class' => 'app\services\VaultScanService',
        ],
        'vaultCheckService' => [
            'class' => 'app\services\VaultCheckService',
        ],
        'vaultOverviewService' => [
            'class' => 'app\services\VaultOverviewService',
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
        'workflowAccessChecker' => [
            'class' => 'app\services\WorkflowAccessChecker',
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
