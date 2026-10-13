<?php

declare(strict_types=1);

namespace app\components;

use app\components\traits\DatabaseErrorLoggingTrait;
use yii\console\ErrorHandler;

/**
 * The console application's error handler (config/console.php, also the
 * tests' config/test.php): Yii's, except that an uncaught exception whose
 * chain holds a database error is logged without any message that can carry
 * the SQL statement or the Redis command with its values
 * (DatabaseErrorLoggingTrait), and printed so too: the long-running
 * containers (schedule-runner, queue-worker) send what a command prints to
 * the Docker log.
 */
class ConsoleErrorHandler extends ErrorHandler
{
    use DatabaseErrorLoggingTrait;

    /**
     * Prints $exception to STDERR as Yii does. Without YII_DEBUG, where Yii
     * prints only "Error: " and the message, an exception whose chain holds
     * a database error is printed as ExceptionLogText::brief() words it.
     * With YII_DEBUG (a developer's machine) Yii prints every error in full.
     *
     * @param \Throwable $exception
     */
    protected function renderException($exception): void
    {
        parent::renderException($this->showsDetails() ? $exception : ExceptionLogText::redacted($exception));
    }

    /**
     * Whether errors are printed in full, with their messages, traces and
     * the exceptions they wrap: in debug mode (YII_DEBUG).
     */
    protected function showsDetails(): bool
    {
        return YII_DEBUG;
    }
}
