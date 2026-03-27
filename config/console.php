<?php

declare(strict_types=1);

$params = require __DIR__ . '/params.php';
$db = require __DIR__ . '/db.php';

return [
    'id' => 'ansilume-console',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log', 'queue'],
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm' => '@vendor/npm-asset',
    ],
    'controllerNamespace' => 'app\commands',
    'components' => [
        'cache' => [
            'class' => 'yii\redis\Cache',
            'redis' => [
                'hostname' => $_ENV['REDIS_HOST'] ?? 'redis',
                'port' => (int)($_ENV['REDIS_PORT'] ?? 6379),
                'database' => (int)($_ENV['REDIS_DB'] ?? 0),
            ],
        ],
        'log' => [
            'targets' => [
                [
                    'class' => 'yii\log\FileTarget',
                    'levels' => ['error', 'warning', 'info'],
                ],
            ],
        ],
        'db' => $db,
        'authManager' => [
            'class' => 'yii\rbac\DbManager',
        ],
        'mailer' => [
            'class' => 'yii\swiftmailer\SwiftMailer',
            'viewPath' => '@app/mail',
            'htmlLayout' => '@app/mail/layouts/html',
            'textLayout' => '@app/mail/layouts/text',
            'useFileTransport' => empty($_ENV['SMTP_HOST']),
            'transport' => empty($_ENV['SMTP_HOST']) ? [] : array_filter([
                'class' => 'Swift_SmtpTransport',
                'host' => $_ENV['SMTP_HOST'],
                'port' => (int)($_ENV['SMTP_PORT'] ?? 587),
                'encryption' => $_ENV['SMTP_ENCRYPTION'] ?: null,
                'username' => $_ENV['SMTP_USER'] ?: null,
                'password' => $_ENV['SMTP_PASSWORD'] ?: null,
            ]),
        ],
        'auditService' => [
            'class' => 'app\services\AuditService',
            'targets' => call_user_func(static function (): array {
                $targets = [new \app\services\audit\DatabaseAuditTarget()];
                if (filter_var(getenv('AUDIT_SYSLOG_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
                    $targets[] = new \app\services\audit\SyslogAuditTarget(
                        getenv('AUDIT_SYSLOG_IDENT') ?: 'ansilume',
                        getenv('AUDIT_SYSLOG_FACILITY') ?: 'LOG_LOCAL0',
                    );
                }
                return $targets;
            }),
        ],
        'projectService' => [
            'class' => 'app\services\ProjectService',
            'workspacePath' => '@runtime/projects',
        ],
        'credentialService' => [
            'class' => 'app\services\CredentialService',
        ],
        'notificationService' => [
            'class' => 'app\services\NotificationService',
        ],
        'lintService' => [
            'class' => 'app\services\LintService',
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
        'jobCompletionService' => [
            'class' => 'app\services\JobCompletionService',
        ],
        'jobClaimService' => [
            'class' => 'app\services\JobClaimService',
        ],
        'totpService' => [
            'class' => 'app\services\TotpService',
        ],
        'inventoryService' => [
            'class' => 'app\services\InventoryService',
            'timeout' => 30,
        ],
        'artifactService' => [
            'class' => 'app\services\ArtifactService',
            'storagePath' => '@runtime/artifacts',
            'maxFileSize' => (int)(getenv('ARTIFACT_MAX_FILE_SIZE') ?: 10485760),
        ],
        'queue' => [
            'class' => 'yii\queue\redis\Queue',
            'redis' => [
                'hostname' => $_ENV['REDIS_HOST'] ?? 'redis',
                'port' => (int)($_ENV['REDIS_PORT'] ?? 6379),
                'database' => (int)($_ENV['REDIS_DB'] ?? 0),
            ],
            'channel' => 'ansilume-queue',
            'ttr' => 3600,
            'as log' => 'yii\queue\LogBehavior',
        ],
    ],
    'params' => $params,
];
