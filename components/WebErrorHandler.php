<?php

declare(strict_types=1);

namespace app\components;

use app\components\traits\DatabaseErrorLoggingTrait;
use yii\web\ErrorHandler;

/**
 * The web application's error handler (config/web.php): Yii's, except that
 * an uncaught exception whose chain holds a database error is logged without
 * any message that can carry the SQL statement or the Redis command with its
 * values (DatabaseErrorLoggingTrait). The error pages are Yii's: without
 * YII_DEBUG they show no message of such an error.
 */
class WebErrorHandler extends ErrorHandler
{
    use DatabaseErrorLoggingTrait;
}
