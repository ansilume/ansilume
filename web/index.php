<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Load .env before anything else
$dotenv = \Dotenv\Dotenv::createUnsafeImmutable(dirname(__DIR__));
$dotenv->safeLoad();

defined('YII_DEBUG') or define('YII_DEBUG', (bool)($_ENV['YII_DEBUG'] ?? false));
defined('YII_ENV')   or define('YII_ENV', $_ENV['YII_ENV'] ?? 'prod');
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

$config = require dirname(__DIR__) . '/config/web.php';

(new yii\web\Application($config))->run();
