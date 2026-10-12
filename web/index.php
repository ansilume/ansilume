<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Load .env before anything else
$dotenv = \Dotenv\Dotenv::createUnsafeImmutable(dirname(__DIR__));
$dotenv->safeLoad();

// EnvFlag, not (bool): (bool)'false' is true, so YII_DEBUG=false turned debug mode on.
defined('YII_DEBUG') or define('YII_DEBUG', \app\components\EnvFlag::isOn($_ENV['YII_DEBUG'] ?? null));
defined('YII_ENV') or define('YII_ENV', $_ENV['YII_ENV'] ?? 'prod');
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

$config = require dirname(__DIR__) . '/config/web.php';

(new yii\web\Application($config))->run();
