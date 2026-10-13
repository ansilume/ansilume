<?php

declare(strict_types=1);

namespace app\components;

use yii\web\HttpException;

/**
 * What a log may say about an uncaught exception and the exceptions it wraps
 * (its previous ones): the text that the error handlers (WebErrorHandler,
 * ConsoleErrorHandler) and the queue's log behaviour (QueueLogBehavior)
 * write in place of the exception. It has the form PHP gives an exception as
 * a string: the innermost exception first, then each one that wraps it after
 * "Next", each with its file:line and its stack trace.
 *
 * Only a chain that holds a database error needs it
 * (DbErrorSummary::isDatabaseError(): a yii\db\Exception, which yii2-redis
 * throws too, or a PDOException); any other is logged as before. In such a
 * chain no message is logged that can carry the SQL statement or the Redis
 * command with its values:
 * - a database error is named by its class, SQLSTATE and driver error code
 *   (ErrorText::DATABASE_ERROR) and, for a connection that failed or was
 *   lost, also by the driver's text (DbErrorSummary::connectionFailure()),
 *   which names the host, the user or the database;
 * - an exception that wraps a database error is named by its class only
 *   (WITHHELD): its message is often built from the database error's;
 * - any other exception keeps its message.
 * The stack traces never show arguments, whatever zend.exception_ignore_args
 * says.
 */
final class ExceptionLogText
{
    /** Follows the class of an exception that wraps a database error, in place of its message. */
    public const WITHHELD = '(message withheld: it wraps a database error)';

    /** brief() of an exception that wraps a database error; sprintf(): its head, the database error's. */
    public const CAUSED_BY = '%s; caused by %s';

    /**
     * Whether $e, or an exception it wraps, is a database error.
     */
    public static function hasDatabaseError(\Throwable $e): bool
    {
        return DbErrorSummary::isDatabaseError($e) || self::databaseErrorBelow($e) !== null;
    }

    /**
     * The log text of $e and the exceptions it wraps (see the class).
     */
    public static function of(\Throwable $e): string
    {
        $entries = [];
        foreach (array_reverse(self::chain($e)) as $link) {
            $entries[] = self::head($link) . ' in ' . $link->getFile() . ':' . $link->getLine()
                . "\nStack trace:\n" . self::trace($link);
        }

        return implode("\n\nNext ", $entries);
    }

    /**
     * One line about $e, for a console that shows only an error's message:
     * how of() names $e and, when $e wraps a database error, that error too
     * (CAUSED_BY).
     */
    public static function brief(\Throwable $e): string
    {
        $cause = DbErrorSummary::isDatabaseError($e) ? null : self::databaseErrorBelow($e);

        return $cause === null ? self::head($e) : sprintf(self::CAUSED_BY, self::head($e), self::head($cause));
    }

    /**
     * The log category Yii's error handler files $e under: the HTTP status
     * of an HTTP exception, the severity of a PHP error, else the class.
     */
    public static function category(\Throwable $e): string
    {
        if ($e instanceof HttpException) {
            return 'yii\web\HttpException:' . $e->statusCode;
        }
        if ($e instanceof \ErrorException) {
            return $e::class . ':' . $e->getSeverity();
        }

        return $e::class;
    }

    /**
     * What Yii's error handler or the queue's log behaviour may print or log
     * in place of $e: $e itself when it holds no database error, else a
     * stand-in whose message is brief() and whose string form is of().
     */
    public static function redacted(\Throwable $e): \Throwable
    {
        return self::hasDatabaseError($e) ? new RedactedException(self::of($e), self::brief($e)) : $e;
    }

    /**
     * How of() names $e (see the class): a database error by its summary, an
     * exception that wraps one by its class, any other by class and message,
     * as PHP does.
     */
    private static function head(\Throwable $e): string
    {
        if (DbErrorSummary::isDatabaseError($e)) {
            $connection = DbErrorSummary::connectionFailure($e);

            return ErrorText::of($e) . ($connection === null ? '' : ': ' . $connection);
        }
        if (self::databaseErrorBelow($e) !== null) {
            return $e::class . ' ' . self::WITHHELD;
        }

        return $e->getMessage() === '' ? $e::class : $e::class . ': ' . $e->getMessage();
    }

    /**
     * The first database error among the exceptions $e wraps, if any.
     */
    private static function databaseErrorBelow(\Throwable $e): ?\Throwable
    {
        foreach (array_slice(self::chain($e), 1) as $link) {
            if (DbErrorSummary::isDatabaseError($link)) {
                return $link;
            }
        }

        return null;
    }

    /**
     * $e's stack trace as PHP prints it, without arguments.
     */
    private static function trace(\Throwable $e): string
    {
        $lines = [];
        foreach ($e->getTrace() as $index => $frame) {
            $lines[] = sprintf(
                '#%d %s: %s()',
                $index,
                isset($frame['file']) ? $frame['file'] . '(' . ($frame['line'] ?? 0) . ')' : '[internal function]',
                ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function']
            );
        }
        $lines[] = '#' . count($lines) . ' {main}';

        return implode("\n", $lines);
    }

    /**
     * $e and the exceptions it wraps, outermost first. A chain that loops
     * (only reflection can make one) ends before the first repeat.
     *
     * @return list<\Throwable>
     */
    private static function chain(\Throwable $e): array
    {
        $chain = [];
        for ($link = $e; $link !== null && !in_array($link, $chain, true); $link = $link->getPrevious()) {
            $chain[] = $link;
        }

        return $chain;
    }
}
