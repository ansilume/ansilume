<?php

declare(strict_types=1);

$params = require __DIR__ . '/params.php';
$db     = require __DIR__ . '/db.php';

$config = [
    'id'                  => 'ansilume',
    'name'                => 'Ansilume',
    'basePath'            => dirname(__DIR__),
    'bootstrap'           => ['log'],
    'controllerMap'       => [
        'api/v1/jobs'          => 'app\controllers\api\v1\JobsController',
        'api/v1/job-templates' => 'app\controllers\api\v1\JobTemplatesController',
        'api/v1/projects'      => 'app\controllers\api\v1\ProjectsController',
        'api/v1/inventories'   => 'app\controllers\api\v1\InventoriesController',
        'api/v1/credentials'   => 'app\controllers\api\v1\CredentialsController',
        'api/v1/schedules'     => 'app\controllers\api\v1\SchedulesController',
    ],
    'aliases'             => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'components' => [
        'request' => [
            'cookieValidationKey' => $_ENV['COOKIE_VALIDATION_KEY'] ?? '',
            'baseUrl'             => '',
        ],
        'cache' => [
            'class' => 'yii\redis\Cache',
            'redis'  => [
                'hostname' => $_ENV['REDIS_HOST'] ?? 'redis',
                'port'     => (int)($_ENV['REDIS_PORT'] ?? 6379),
                'database' => (int)($_ENV['REDIS_DB']   ?? 0),
            ],
        ],
        'session' => [
            'class' => 'yii\redis\Session',
            'redis'  => [
                'hostname' => $_ENV['REDIS_HOST'] ?? 'redis',
                'port'     => (int)($_ENV['REDIS_PORT'] ?? 6379),
                'database' => (int)($_ENV['REDIS_DB']   ?? 0),
            ],
        ],
        'user' => [
            'identityClass'   => 'app\models\User',
            'enableAutoLogin' => true,
            'loginUrl'        => ['site/login'],
        ],
        'errorHandler' => [
            'errorAction' => 'site/error',
        ],
        'mailer' => [
            'class'            => 'yii\swiftmailer\SwiftMailer',
            'useFileTransport' => YII_ENV === 'dev',
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets'    => [
                [
                    'class'  => 'yii\log\FileTarget',
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
        'db' => $db,
        'authManager' => [
            'class'          => 'yii\rbac\DbManager',
            'defaultRoles'   => ['viewer'],
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
        ],
        'auditService' => [
            'class' => 'app\services\AuditService',
        ],
        'projectService' => [
            'class'         => 'app\services\ProjectService',
            'workspacePath' => '@runtime/projects',
        ],
        'jobLaunchService' => [
            'class' => 'app\services\JobLaunchService',
        ],
        'credentialService' => [
            'class' => 'app\services\CredentialService',
        ],
        'notificationService' => [
            'class' => 'app\services\NotificationService',
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
        'urlManager' => [
            'enablePrettyUrl'     => true,
            'showScriptName'      => false,
            'enableStrictParsing' => false,
            'rules'               => [
                ''                        => 'site/index',
                'login'                   => 'site/login',
                'logout'                  => 'site/logout',
                // API v1
                ['pattern' => 'api/v1/jobs/<id:\d+>/cancel', 'route' => 'api/v1/jobs/cancel', 'verb' => 'POST'],
                ['pattern' => 'api/v1/jobs/<id:\d+>',        'route' => 'api/v1/jobs/view'],
                ['pattern' => 'api/v1/jobs',                 'route' => 'api/v1/jobs/index', 'verb' => 'GET'],
                ['pattern' => 'api/v1/jobs',                 'route' => 'api/v1/jobs/create', 'verb' => 'POST'],
                ['pattern' => 'api/v1/job-templates/<id:\d+>', 'route' => 'api/v1/job-templates/view'],
                ['pattern' => 'api/v1/job-templates',          'route' => 'api/v1/job-templates/index'],
                ['pattern' => 'api/v1/projects/<id:\d+>',      'route' => 'api/v1/projects/view'],
                ['pattern' => 'api/v1/projects',             'route' => 'api/v1/projects/index'],
                ['pattern' => 'api/v1/inventories/<id:\d+>', 'route' => 'api/v1/inventories/view'],
                ['pattern' => 'api/v1/inventories',          'route' => 'api/v1/inventories/index'],
                ['pattern' => 'api/v1/credentials/<id:\d+>', 'route' => 'api/v1/credentials/view'],
                ['pattern' => 'api/v1/credentials',          'route' => 'api/v1/credentials/index'],
                ['pattern' => 'api/v1/schedules/<id:\d+>/toggle', 'route' => 'api/v1/schedules/toggle', 'verb' => 'POST'],
                ['pattern' => 'api/v1/schedules/<id:\d+>',   'route' => 'api/v1/schedules/view'],
                ['pattern' => 'api/v1/schedules',            'route' => 'api/v1/schedules/index'],
                // Health check
                'health' => 'health/index',
                // Inbound trigger
                ['pattern' => 'trigger/<token:[a-f0-9]{64}>', 'route' => 'trigger/fire', 'verb' => 'POST'],
                // Teams
                'team/add-member/<id:\d+>'           => 'team/add-member',
                'team/remove-member/<id:\d+>/<userId:\d+>' => 'team/remove-member',
                'team/add-project/<id:\d+>'          => 'team/add-project',
                'team/remove-project/<id:\d+>/<projectId:\d+>' => 'team/remove-project',
                'team/<action>'                      => 'team/<action>',
                'team/<action>/<id:\d+>'             => 'team/<action>',
                // Schedules + Webhooks
                'schedule/<action>'              => 'schedule/<action>',
                'schedule/<action>/<id:\d+>'     => 'schedule/<action>',
                'webhook/<action>'               => 'webhook/<action>',
                'webhook/<action>/<id:\d+>'      => 'webhook/<action>',
                // Hyphenated controller names
                'job-template/generate-trigger-token/<id:\d+>' => 'job-template/generate-trigger-token',
                'job-template/revoke-trigger-token/<id:\d+>'   => 'job-template/revoke-trigger-token',
                'job-template/<action>'              => 'job-template/<action>',
                'job-template/<action>/<id:\d+>'     => 'job-template/<action>',
                'audit-log'                          => 'audit-log/index',
                'audit-log/<action>'                 => 'audit-log/<action>',
                'audit-log/<action>/<id:\d+>'        => 'audit-log/<action>',
                // Generic
                '<controller>/<action>'              => '<controller>/<action>',
                '<controller>/<id:\d+>'              => '<controller>/view',
                '<controller>/<action>/<id:\d+>'     => '<controller>/<action>',
            ],
        ],
    ],
    'params' => $params,
];

if (YII_ENV_DEV) {
    $config['bootstrap'][]      = 'debug';
    $config['modules']['debug'] = [
        'class'      => 'yii\debug\Module',
        'allowedIPs' => ['*'],
    ];
    $config['bootstrap'][]    = 'gii';
    $config['modules']['gii'] = [
        'class'      => 'yii\gii\Module',
        'allowedIPs' => ['*'],
    ];
}

return $config;
