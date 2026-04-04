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
        'mailer' => [
            'class' => 'yii\swiftmailer\SwiftMailer',
            'useFileTransport' => true,
        ],
    ],
    'params' => $params,
];
