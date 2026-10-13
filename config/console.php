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
                    // Yii's FileTarget, except that an exception logged as such
                    // (\Yii::error($e)) with a database error in its chain is
                    // written without its message, which carries the SQL
                    // statement with its values.
                    'class' => \app\components\RedactingFileTarget::class,
                    'levels' => ['error', 'warning', 'info'],
                    // Never dump request/environment context ($_SERVER contains
                    // APP_SECRET_KEY, DB_PASSWORD, ...) into the log file.
                    'logVars' => [],
                    // yii\db\Command logs every SQL statement at info with its
                    // values filled in (job extra vars, password answers, tokens).
                    // Database errors are still logged, under the exception class,
                    // by the error handler and the queue's log behaviour below,
                    // and under the category of the code that logs one itself
                    // (such as a failed rollback): with class, SQLSTATE and
                    // driver error code, not the message.
                    'except' => ['yii\db\Command::*'],
                ],
            ],
        ],
        'errorHandler' => [
            // Logs and prints an uncaught database error without its
            // message, which carries the SQL statement with its values.
            'class' => \app\components\ConsoleErrorHandler::class,
        ],
        'db' => $db,
        // The same default roles as config/web.php: scheduled launches, approval
        // timeouts and workflow steps advanced here must see the permissions a
        // web request sees, or a launch and a vote could disagree on who may
        // approve (app\services\ApproverEligibility).
        'authManager' => [
            'class' => 'yii\rbac\DbManager',
            'defaultRoles' => ['viewer'],
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
            // yii2-queue's LogBehavior, except that a job's database error is
            // logged without its message (the SQL statement with its values).
            'as log' => \app\components\QueueLogBehavior::class,
        ],
    ],
    'params' => $params,
];
