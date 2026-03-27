<?php

declare(strict_types=1);

// Minimal bootstrap for PHPStan — loads autoloader and Yii class definitions
// without starting a full application or connecting to a database.

defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV')   or define('YII_ENV', 'test');

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/vendor/yiisoft/yii2/Yii.php';
