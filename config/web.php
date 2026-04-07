<?php

declare(strict_types=1);

$params = require __DIR__ . '/params.php';
$db = require __DIR__ . '/db.php';

$config = [
    'id' => 'ansilume',
    'name' => 'Ansilume',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerMap' => [
        'api/v1/jobs' => 'app\controllers\api\v1\JobsController',
        'api/v1/job-templates' => 'app\controllers\api\v1\JobTemplatesController',
        'api/v1/projects' => 'app\controllers\api\v1\ProjectsController',
        'api/v1/inventories' => 'app\controllers\api\v1\InventoriesController',
        'api/v1/credentials' => 'app\controllers\api\v1\CredentialsController',
        'api/v1/schedules' => 'app\controllers\api\v1\SchedulesController',
        'api/v1/notification-templates' => 'app\controllers\api\v1\NotificationTemplatesController',
        'api/v1/analytics' => 'app\controllers\api\v1\AnalyticsController',
        'api/v1/approval-rules' => 'app\controllers\api\v1\ApprovalRulesController',
        'api/v1/approvals' => 'app\controllers\api\v1\ApprovalsController',
        'api/v1/workflow-templates' => 'app\controllers\api\v1\WorkflowTemplatesController',
        'api/v1/workflow-jobs' => 'app\controllers\api\v1\WorkflowJobsController',
        'api/v1/roles' => 'app\controllers\api\v1\RolesController',
        'api/v1/profile' => 'app\controllers\api\v1\ProfileController',
        'runner-api' => 'app\controllers\api\runner\JobsController',
        'runner-register' => 'app\controllers\api\runner\RegisterController',
    ],
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm' => '@vendor/npm-asset',
    ],
    'components' => [
        'request' => [
            'cookieValidationKey' => $_ENV['COOKIE_VALIDATION_KEY'] ?? '',
            'baseUrl' => '',
            'parsers' => [
                'application/json' => 'yii\web\JsonParser',
            ],
        ],
        'cache' => [
            'class' => 'yii\redis\Cache',
            'redis' => [
                'hostname' => $_ENV['REDIS_HOST'] ?? 'redis',
                'port' => (int)($_ENV['REDIS_PORT'] ?? 6379),
                'database' => (int)($_ENV['REDIS_DB'] ?? 0),
            ],
        ],
        'session' => [
            'class' => 'yii\redis\Session',
            'redis' => [
                'hostname' => $_ENV['REDIS_HOST'] ?? 'redis',
                'port' => (int)($_ENV['REDIS_PORT'] ?? 6379),
                'database' => (int)($_ENV['REDIS_DB'] ?? 0),
            ],
        ],
        'user' => [
            'identityClass' => 'app\models\User',
            'enableAutoLogin' => true,
            'loginUrl' => ['site/login'],
        ],
        'errorHandler' => [
            'errorAction' => 'site/error',
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
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class' => 'yii\log\FileTarget',
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
        'db' => $db,
        'authManager' => [
            'class' => 'yii\rbac\DbManager',
            'defaultRoles' => ['viewer'],
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
        'jobLaunchService' => [
            'class' => 'app\services\JobLaunchService',
        ],
        'credentialService' => [
            'class' => 'app\services\CredentialService',
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
        'lintService' => [
            'class' => 'app\services\LintService',
        ],
        'scheduleService' => [
            'class' => 'app\services\ScheduleService',
        ],
        'roleService' => [
            'class' => 'app\services\RoleService',
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
            'rateLimiter' => [
                'class' => 'app\services\TotpRateLimiter',
            ],
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
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'enableStrictParsing' => false,
            'rules' => [
                '' => 'site/index',
                'login' => 'site/login',
                'logout' => 'site/logout',
                'forgot-password' => 'site/forgot-password',
                'reset-password' => 'site/reset-password',
                'verify-totp' => 'site/verify-totp',
                // API v1
                ['pattern' => 'api/v1/jobs/<id:\d+>/cancel', 'route' => 'api/v1/jobs/cancel', 'verb' => 'POST'],
                ['pattern' => 'api/v1/jobs/<id:\d+>', 'route' => 'api/v1/jobs/view'],
                ['pattern' => 'api/v1/jobs', 'route' => 'api/v1/jobs/index', 'verb' => 'GET'],
                ['pattern' => 'api/v1/jobs', 'route' => 'api/v1/jobs/create', 'verb' => 'POST'],
                ['pattern' => 'api/v1/job-templates/<id:\d+>', 'route' => 'api/v1/job-templates/view'],
                ['pattern' => 'api/v1/job-templates', 'route' => 'api/v1/job-templates/index'],
                ['pattern' => 'api/v1/projects/<id:\d+>', 'route' => 'api/v1/projects/view'],
                ['pattern' => 'api/v1/projects', 'route' => 'api/v1/projects/index'],
                ['pattern' => 'api/v1/inventories/<id:\d+>', 'route' => 'api/v1/inventories/view', 'verb' => 'GET'],
                ['pattern' => 'api/v1/inventories/<id:\d+>', 'route' => 'api/v1/inventories/update', 'verb' => 'PUT'],
                ['pattern' => 'api/v1/inventories/<id:\d+>', 'route' => 'api/v1/inventories/delete', 'verb' => 'DELETE'],
                ['pattern' => 'api/v1/inventories', 'route' => 'api/v1/inventories/index', 'verb' => 'GET'],
                ['pattern' => 'api/v1/inventories', 'route' => 'api/v1/inventories/create', 'verb' => 'POST'],
                ['pattern' => 'api/v1/credentials/<id:\d+>', 'route' => 'api/v1/credentials/view', 'verb' => 'GET'],
                ['pattern' => 'api/v1/credentials/<id:\d+>', 'route' => 'api/v1/credentials/update', 'verb' => 'PUT'],
                ['pattern' => 'api/v1/credentials/<id:\d+>', 'route' => 'api/v1/credentials/delete', 'verb' => 'DELETE'],
                ['pattern' => 'api/v1/credentials', 'route' => 'api/v1/credentials/index', 'verb' => 'GET'],
                ['pattern' => 'api/v1/credentials', 'route' => 'api/v1/credentials/create', 'verb' => 'POST'],
                ['pattern' => 'api/v1/schedules/<id:\d+>/toggle', 'route' => 'api/v1/schedules/toggle', 'verb' => 'POST'],
                ['pattern' => 'api/v1/schedules/<id:\d+>', 'route' => 'api/v1/schedules/view'],
                ['pattern' => 'api/v1/schedules', 'route' => 'api/v1/schedules/index'],
                // Notification templates API
                ['pattern' => 'api/v1/notification-templates/<id:\d+>/test', 'route' => 'api/v1/notification-templates/test', 'verb' => 'POST'],
                ['pattern' => 'api/v1/notification-templates/<id:\d+>', 'route' => 'api/v1/notification-templates/view', 'verb' => 'GET'],
                ['pattern' => 'api/v1/notification-templates/<id:\d+>', 'route' => 'api/v1/notification-templates/update', 'verb' => 'PUT'],
                ['pattern' => 'api/v1/notification-templates/<id:\d+>', 'route' => 'api/v1/notification-templates/delete', 'verb' => 'DELETE'],
                ['pattern' => 'api/v1/notification-templates', 'route' => 'api/v1/notification-templates/index', 'verb' => 'GET'],
                ['pattern' => 'api/v1/notification-templates', 'route' => 'api/v1/notification-templates/create', 'verb' => 'POST'],
                // Approval rules API
                ['pattern' => 'api/v1/approval-rules/<id:\d+>', 'route' => 'api/v1/approval-rules/view', 'verb' => 'GET'],
                ['pattern' => 'api/v1/approval-rules/<id:\d+>', 'route' => 'api/v1/approval-rules/update', 'verb' => 'PUT'],
                ['pattern' => 'api/v1/approval-rules/<id:\d+>', 'route' => 'api/v1/approval-rules/delete', 'verb' => 'DELETE'],
                ['pattern' => 'api/v1/approval-rules', 'route' => 'api/v1/approval-rules/index', 'verb' => 'GET'],
                ['pattern' => 'api/v1/approval-rules', 'route' => 'api/v1/approval-rules/create', 'verb' => 'POST'],
                // Approvals API
                ['pattern' => 'api/v1/approvals/<id:\d+>/approve', 'route' => 'api/v1/approvals/approve', 'verb' => 'POST'],
                ['pattern' => 'api/v1/approvals/<id:\d+>/reject', 'route' => 'api/v1/approvals/reject', 'verb' => 'POST'],
                ['pattern' => 'api/v1/approvals/<id:\d+>', 'route' => 'api/v1/approvals/view', 'verb' => 'GET'],
                ['pattern' => 'api/v1/approvals', 'route' => 'api/v1/approvals/index', 'verb' => 'GET'],
                // Workflow templates API
                ['pattern' => 'api/v1/workflow-templates/<id:\d+>/launch', 'route' => 'api/v1/workflow-templates/launch', 'verb' => 'POST'],
                ['pattern' => 'api/v1/workflow-templates/<id:\d+>', 'route' => 'api/v1/workflow-templates/view', 'verb' => 'GET'],
                ['pattern' => 'api/v1/workflow-templates/<id:\d+>', 'route' => 'api/v1/workflow-templates/update', 'verb' => 'PUT'],
                ['pattern' => 'api/v1/workflow-templates/<id:\d+>', 'route' => 'api/v1/workflow-templates/delete', 'verb' => 'DELETE'],
                ['pattern' => 'api/v1/workflow-templates', 'route' => 'api/v1/workflow-templates/index', 'verb' => 'GET'],
                ['pattern' => 'api/v1/workflow-templates', 'route' => 'api/v1/workflow-templates/create', 'verb' => 'POST'],
                // Roles API
                ['pattern' => 'api/v1/roles/<name:[a-z][a-z0-9_-]*>', 'route' => 'api/v1/roles/view', 'verb' => 'GET'],
                ['pattern' => 'api/v1/roles/<name:[a-z][a-z0-9_-]*>', 'route' => 'api/v1/roles/update', 'verb' => 'PUT'],
                ['pattern' => 'api/v1/roles/<name:[a-z][a-z0-9_-]*>', 'route' => 'api/v1/roles/delete', 'verb' => 'DELETE'],
                ['pattern' => 'api/v1/roles', 'route' => 'api/v1/roles/index', 'verb' => 'GET'],
                ['pattern' => 'api/v1/roles', 'route' => 'api/v1/roles/create', 'verb' => 'POST'],
                ['pattern' => 'api/v1/permissions', 'route' => 'api/v1/roles/permissions', 'verb' => 'GET'],
                ['pattern' => 'api/v1/profile/change-password', 'route' => 'api/v1/profile/change-password', 'verb' => 'POST'],
                // Workflow jobs API
                ['pattern' => 'api/v1/workflow-jobs/<id:\d+>/cancel', 'route' => 'api/v1/workflow-jobs/cancel', 'verb' => 'POST'],
                ['pattern' => 'api/v1/workflow-jobs/<id:\d+>/resume', 'route' => 'api/v1/workflow-jobs/resume', 'verb' => 'POST'],
                ['pattern' => 'api/v1/workflow-jobs/<id:\d+>', 'route' => 'api/v1/workflow-jobs/view', 'verb' => 'GET'],
                ['pattern' => 'api/v1/workflow-jobs', 'route' => 'api/v1/workflow-jobs/index', 'verb' => 'GET'],
                // Runner pull API
                ['pattern' => 'api/runner/v1/register', 'route' => 'runner-register/register', 'verb' => 'POST'],
                ['pattern' => 'api/runner/v1/heartbeat', 'route' => 'runner-api/heartbeat', 'verb' => 'POST'],
                ['pattern' => 'api/runner/v1/jobs/claim', 'route' => 'runner-api/claim', 'verb' => 'POST'],
                ['pattern' => 'api/runner/v1/jobs/<id:\d+>/logs', 'route' => 'runner-api/logs'],
                ['pattern' => 'api/runner/v1/jobs/<id:\d+>/complete', 'route' => 'runner-api/complete'],
                ['pattern' => 'api/runner/v1/jobs/<id:\d+>/tasks', 'route' => 'runner-api/tasks'],
                // Runner group UI
                'runner-group/<action>' => 'runner-group/<action>',
                'runner-group/<action>/<id:\d+>' => 'runner-group/<action>',
                'runner/create' => 'runner/create',
                'runner/delete/<id:\d+>' => 'runner/delete',
                'runner/regenerate-token/<id:\d+>' => 'runner/regenerate-token',
                // Dashboard chart data
                'site/chart-data' => 'site/chart-data',
                // Credential helpers
                'credential/generate-ssh-key' => 'credential/generate-ssh-key',
                // Health check and metrics
                'health' => 'health/index',
                'metrics' => 'metrics/index',
                // Inbound trigger
                ['pattern' => 'trigger/<token:[a-f0-9]{64}>', 'route' => 'trigger/fire', 'verb' => 'POST'],
                // Roles (name-keyed, not id-keyed)
                ['pattern' => 'role', 'route' => 'role/index', 'verb' => 'GET'],
                ['pattern' => 'role/create', 'route' => 'role/create'],
                ['pattern' => 'role/view/<name:[a-z][a-z0-9_-]*>', 'route' => 'role/view'],
                ['pattern' => 'role/update/<name:[a-z][a-z0-9_-]*>', 'route' => 'role/update'],
                ['pattern' => 'role/delete/<name:[a-z][a-z0-9_-]*>', 'route' => 'role/delete', 'verb' => 'POST'],
                // Teams
                'team/add-member/<id:\d+>' => 'team/add-member',
                'team/remove-member/<id:\d+>/<userId:\d+>' => 'team/remove-member',
                'team/add-project/<id:\d+>' => 'team/add-project',
                'team/remove-project/<id:\d+>/<projectId:\d+>' => 'team/remove-project',
                'team/<action>' => 'team/<action>',
                'team/<action>/<id:\d+>' => 'team/<action>',
                // Schedules + Webhooks
                'schedule/<action>' => 'schedule/<action>',
                'schedule/<action>/<id:\d+>' => 'schedule/<action>',
                'webhook/<action>' => 'webhook/<action>',
                'webhook/<action>/<id:\d+>' => 'webhook/<action>',
                // Analytics API
                ['pattern' => 'api/v1/analytics/summary', 'route' => 'api/v1/analytics/summary', 'verb' => 'GET'],
                ['pattern' => 'api/v1/analytics/template-reliability', 'route' => 'api/v1/analytics/template-reliability', 'verb' => 'GET'],
                ['pattern' => 'api/v1/analytics/project-activity', 'route' => 'api/v1/analytics/project-activity', 'verb' => 'GET'],
                ['pattern' => 'api/v1/analytics/user-activity', 'route' => 'api/v1/analytics/user-activity', 'verb' => 'GET'],
                ['pattern' => 'api/v1/analytics/host-health', 'route' => 'api/v1/analytics/host-health', 'verb' => 'GET'],
                ['pattern' => 'api/v1/analytics/job-trend', 'route' => 'api/v1/analytics/job-trend', 'verb' => 'GET'],
                ['pattern' => 'api/v1/analytics/workflow-summary', 'route' => 'api/v1/analytics/workflow-summary', 'verb' => 'GET'],
                ['pattern' => 'api/v1/analytics/workflow-activity', 'route' => 'api/v1/analytics/workflow-activity', 'verb' => 'GET'],
                ['pattern' => 'api/v1/analytics/approval-summary', 'route' => 'api/v1/analytics/approval-summary', 'verb' => 'GET'],
                ['pattern' => 'api/v1/analytics/runner-activity', 'route' => 'api/v1/analytics/runner-activity', 'verb' => 'GET'],
                // Notification templates
                'notification-template/<action>' => 'notification-template/<action>',
                'notification-template/<action>/<id:\d+>' => 'notification-template/<action>',
                // Analytics
                'analytics/<action>' => 'analytics/<action>',
                // Approval rules
                'approval-rule/<action>' => 'approval-rule/<action>',
                'approval-rule/<action>/<id:\d+>' => 'approval-rule/<action>',
                // Approvals
                'approval/<action>' => 'approval/<action>',
                'approval/<action>/<id:\d+>' => 'approval/<action>',
                // Workflow templates
                'workflow-template/<action>' => 'workflow-template/<action>',
                'workflow-template/<action>/<id:\d+>' => 'workflow-template/<action>',
                // Workflow jobs
                'workflow-job/<action>' => 'workflow-job/<action>',
                'workflow-job/<action>/<id:\d+>' => 'workflow-job/<action>',
                // Hyphenated controller names
                'job-template/generate-trigger-token/<id:\d+>' => 'job-template/generate-trigger-token',
                'job-template/revoke-trigger-token/<id:\d+>' => 'job-template/revoke-trigger-token',
                'job-template/<action>' => 'job-template/<action>',
                'job-template/<action>/<id:\d+>' => 'job-template/<action>',
                'audit-log' => 'audit-log/index',
                'audit-log/<action>' => 'audit-log/<action>',
                'audit-log/<action>/<id:\d+>' => 'audit-log/<action>',
                'project/lint/<id:\d+>' => 'project/lint',
                // Job artifacts
                ['pattern' => 'job/<id:\d+>/artifact/<artifact_id:\d+>', 'route' => 'job/download-artifact'],
                // Inventory
                'inventory/parse-hosts/<id:\d+>' => 'inventory/parse-hosts',
                // Generic
                '<controller>/<action>' => '<controller>/<action>',
                '<controller>/<id:\d+>' => '<controller>/view',
                '<controller>/<action>/<id:\d+>' => '<controller>/<action>',
            ],
        ],
    ],
    'params' => $params,
];

// Configure LinkPager globally for Bootstrap 5 (Yii2 defaults to Bootstrap 3 classes).
\Yii::$container->set(\yii\widgets\LinkPager::class, [
    'options' => ['class' => 'pagination mt-3'],
    'pageCssClass' => 'page-item',
    'linkOptions' => ['class' => 'page-link'],
    'activePageCssClass' => 'active',
    'disabledPageCssClass' => 'disabled',
    'disabledListItemSubTagOptions' => ['tag' => 'a', 'class' => 'page-link'],
    'prevPageLabel' => '‹',
    'nextPageLabel' => '›',
    'firstPageLabel' => false,
    'lastPageLabel' => false,
]);

if (YII_ENV_DEV) {
    $config['bootstrap'][] = 'debug';
    $config['modules']['debug'] = [
        'class' => 'yii\debug\Module',
        'allowedIPs' => ['*'],
    ];
    $config['bootstrap'][] = 'gii';
    $config['modules']['gii'] = [
        'class' => 'yii\gii\Module',
        'allowedIPs' => ['*'],
    ];
}

return $config;
