<?php

declare(strict_types=1);

$params = require __DIR__ . '/params.php';
$db     = require __DIR__ . '/db.php';

return [
    'id'       => 'ansilume-console',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log', 'queue'],
    'aliases'   => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'controllerNamespace' => 'app\commands',
    'components' => [
        'cache' => [
            'class' => 'yii\redis\Cache',
            'redis'  => [
                'hostname' => $_ENV['REDIS_HOST'] ?? 'redis',
                'port'     => (int)($_ENV['REDIS_PORT'] ?? 6379),
                'database' => (int)($_ENV['REDIS_DB']   ?? 0),
            ],
        ],
        'log' => [
            'targets' => [
                [
                    'class'  => 'yii\log\FileTarget',
                    'levels' => ['error', 'warning', 'info'],
                ],
            ],
        ],
        'db' => $db,
        'authManager' => [
            'class' => 'yii\rbac\DbManager',
        ],
        'auditService' => [
            'class' => 'app\services\AuditService',
        ],
        'projectService' => [
            'class'         => 'app\services\ProjectService',
            'workspacePath' => '@runtime/projects',
        ],
        'credentialService' => [
            'class' => 'app\services\CredentialService',
        ],
        'notificationService' => [
            'class' => 'app\services\NotificationService',
        ],
        'jobLaunchService' => [
            'class' => 'app\services\JobLaunchService',
        ],
        'scheduleService' => [
            'class' => 'app\services\ScheduleService',
        ],
        'projectAccessChecker' => [
            'class' => 'app\services\ProjectAccessChecker',
        ],
        'webhookService' => [
            'class' => 'app\services\WebhookService',
        ],
        'queue' => [
            'class'   => 'yii\queue\redis\Queue',
            'redis'   => [
                'hostname' => $_ENV['REDIS_HOST'] ?? 'redis',
                'port'     => (int)($_ENV['REDIS_PORT'] ?? 6379),
                'database' => (int)($_ENV['REDIS_DB']   ?? 0),
            ],
            'channel' => 'ansilume-queue',
            'ttr'     => 3600,
            'as log'  => 'yii\queue\LogBehavior',
        ],
    ],
    'params' => $params,
];
