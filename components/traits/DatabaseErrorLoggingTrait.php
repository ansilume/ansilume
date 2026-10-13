<?php

declare(strict_types=1);

namespace app\components\traits;

use app\components\ExceptionLogText;

/**
 * The logging of the error handlers (WebErrorHandler, ConsoleErrorHandler)
 * for an uncaught exception whose chain holds a database error: it is logged
 * as ExceptionLogText words it, under the category Yii gives it, never with
 * a message that can carry the SQL statement or the Redis command with its
 * values. Any other exception is logged as Yii logs it.
 *
 * An error while an error is handled ends in Yii's fallback, which writes
 * both exceptions to PHP's error log (and, in debug mode, to the output);
 * there, too, a chain with a database error appears as ExceptionLogText
 * words it.
 */
trait DatabaseErrorLoggingTrait
{
    /**
     * @param \Throwable $exception
     */
    public function logException($exception): void
    {
        if (!ExceptionLogText::hasDatabaseError($exception)) {
            parent::logException($exception);
            return;
        }
        \Yii::error(ExceptionLogText::of($exception), ExceptionLogText::category($exception));
    }

    /**
     * @param \Throwable $exception the error raised while $previousException was handled
     * @param \Throwable $previousException the error that was handled
     */
    protected function handleFallbackExceptionMessage($exception, $previousException): void
    {
        parent::handleFallbackExceptionMessage(
            ExceptionLogText::redacted($exception),
            ExceptionLogText::redacted($previousException)
        );
    }
}
