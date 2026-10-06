<?php

declare(strict_types=1);

$params = require __DIR__ . '/params.php';
$services = require __DIR__ . '/services.php';
// Every Redis connection (cache, session, queue) shares host, port, db and the
// optional REDIS_PASSWORD; see app\components\RedisSettings.
$redis = \app\components\RedisSettings::fromEnvironment($_ENV)->connectionConfig();
$db = require __DIR__ . '/db.php';

return [
    'id' => 'ansilume-console',
    'basePath' => dirname(__DIR__),
    // ProcessHardeningBootstrap marks console workers non-dumpable so child
    // processes running repository code cannot read their environment.
    'bootstrap' => ['log', 'queue', \app\components\ProcessHardeningBootstrap::class],
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm' => '@vendor/npm-asset',
    ],
    'controllerNamespace' => 'app\commands',
    'components' => [
        'cache' => [
            'class' => 'yii\redis\Cache',
            'redis' => $redis,
            // Single Redis node. Auto-detection sends CLUSTER INFO and swallows
            // any error, including a failed AUTH, which then surfaces later as
            // a misleading NOAUTH instead of the real authentication error.
            'forceClusterMode' => false,
        ],
        'log' => [
            'targets' => [
                [
                    'class' => 'yii\log\FileTarget',
                    'levels' => ['error', 'warning', 'info'],
                    // Never dump request/environment context ($_SERVER contains
                    // APP_SECRET_KEY, DB_PASSWORD, ...) into the log file.
                    'logVars' => [],
                ],
            ],
        ],
        'db' => $db,
        'authManager' => [
            'class' => 'yii\rbac\DbManager',
        ],
        'mailer' => [
            'class' => 'yii\symfonymailer\Mailer',
            'viewPath' => '@app/mail',
            'htmlLayout' => '@app/mail/layouts/html',
            'textLayout' => '@app/mail/layouts/text',
            'useFileTransport' => empty($_ENV['SMTP_HOST']),
            'transport' => empty($_ENV['SMTP_HOST']) ? [] : array_filter([
                'scheme' => ($_ENV['SMTP_ENCRYPTION'] ?? '') === 'ssl' ? 'smtps' : 'smtp',
                'host' => $_ENV['SMTP_HOST'],
                'port' => (int)($_ENV['SMTP_PORT'] ?? 587),
                'username' => $_ENV['SMTP_USER'] ?: null,
                'password' => $_ENV['SMTP_PASSWORD'] ?: null,
            ]),
        ],
        ...$services,
        'queue' => [
            'class' => 'yii\queue\redis\Queue',
            // Only Ansilume's own job classes may be unserialized from Redis.
            'serializer' => \app\components\AllowlistQueueSerializer::class,
            'redis' => $redis,
            'channel' => 'ansilume-queue',
            'ttr' => 3600,
            'as log' => 'yii\queue\LogBehavior',
        ],
    ],
    'params' => $params,
];
