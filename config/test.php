<?php

declare(strict_types=1);

$params = require __DIR__ . '/params.php';
$db     = require __DIR__ . '/db-test.php';

return [
    'id'       => 'ansilume-tests',
    'basePath'  => dirname(__DIR__),
    'bootstrap' => ['log'],
    'components' => [
        'log' => [
            'targets' => [
                [
                    'class'  => 'yii\log\FileTarget',
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
        'db'    => $db,
        'cache' => ['class' => 'yii\caching\ArrayCache'],
        'authManager' => [
            'class' => 'yii\rbac\DbManager',
        ],
        'auditService' => [
            'class' => 'app\services\AuditService',
        ],
        'jobLaunchService' => [
            'class' => 'app\services\JobLaunchService',
        ],
        'scheduleService' => [
            'class' => 'app\services\ScheduleService',
        ],
        'notificationService' => [
            'class' => 'app\services\NotificationService',
        ],
        'webhookService' => [
            'class' => 'app\services\WebhookService',
        ],
        'mailer' => [
            'class'            => 'yii\swiftmailer\SwiftMailer',
            'useFileTransport' => true,
        ],
    ],
    'params' => $params,
];
