<?php

declare(strict_types=1);

$dotenv = \Dotenv\Dotenv::createUnsafeImmutable(dirname(__DIR__));
$dotenv->safeLoad();

defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV')   or define('YII_ENV', 'test');

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

$config = require dirname(__DIR__) . '/config/test.php';
new yii\console\Application($config);
