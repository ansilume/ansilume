<?php

declare(strict_types=1);

namespace app\components;

use yii\log\FileTarget;

/**
 * The log target of every application config (config/web.php,
 * config/console.php, config/test.php): Yii's FileTarget, except that an
 * exception logged as such, \Yii::error($e), whose chain holds a database
 * error is written as ExceptionLogText words it, as the error handlers log
 * an uncaught one: class, SQLSTATE and driver error code, file:line and a
 * trace without arguments, never a message that can carry the SQL statement
 * or the Redis command with its values. Yii's own code logs exceptions so:
 * yii\db\Connection a rollback that failed inside transaction(), yii2-redis
 * a command it retries. The same holds for such an exception inside a
 * logged array, which Yii would write with the exception serialized,
 * message and all.
 *
 * Every other message is written as Yii writes it: text (also text that
 * names an error, such as ErrorText's or ExceptionLogText's), an exception
 * without a database error in its chain, any other value. Text built from a
 * database error's message cannot be told apart here: code logs
 * ErrorText::of() or DbErrorSummary::of(), never getMessage().
 */
class RedactingFileTarget extends FileTarget
{
    /**
     * The line Yii's FileTarget writes for $message, with the logged value
     * as loggable() gives it.
     *
     * @param array<int, mixed> $message a message as yii\log\Logger keeps it:
     *     the logged value, level, category, timestamp, traces, memory
     */
    public function formatMessage($message): string
    {
        $message[0] = self::loggable($message[0]);

        return parent::formatMessage($message);
    }

    /**
     * What may be written for a logged value: ExceptionLogText::of() for an
     * exception whose chain holds a database error, an array with each of its
     * values so, any other value unchanged.
     */
    private static function loggable(mixed $value): mixed
    {
        if ($value instanceof \Throwable) {
            return ExceptionLogText::hasDatabaseError($value) ? ExceptionLogText::of($value) : $value;
        }
        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::loggable($item), $value);
        }

        return $value;
    }
}
