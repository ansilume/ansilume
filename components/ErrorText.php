<?php

declare(strict_types=1);

namespace app\components;

/**
 * What a log line or an audit entry may say about an error whose message is
 * worth keeping, such as a notification channel's HTTP error: the message of
 * any error, but never that of a database error. For a database error
 * (yii\db\Exception, or a PDOException that escaped Yii's wrapping) it says
 * the class, the SQLSTATE and the driver's error code (DbErrorSummary)
 * instead: its message carries the SQL statement with its values, and the
 * driver's message can quote a value.
 *
 * An uncaught exception, with the exceptions it wraps, is logged through
 * ExceptionLogText (the error handlers and the queue's log behaviour).
 */
final class ErrorText
{
    /** What is said about a database error; sprintf(): its class, SQLSTATE and driver error code. */
    public const DATABASE_ERROR = '%s (SQLSTATE %s, driver error %s)';

    public static function of(\Throwable $e): string
    {
        if (DbErrorSummary::isDatabaseError($e)) {
            return sprintf(self::DATABASE_ERROR, ...DbErrorSummary::of($e));
        }

        return $e->getMessage();
    }
}
