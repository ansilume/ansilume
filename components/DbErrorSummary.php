<?php

declare(strict_types=1);

namespace app\components;

/**
 * What a log line may say about an error: the exception's class and, for a
 * database error (a yii\db\Exception, or a PDOException that escaped Yii's
 * wrapping, such as a failed BEGIN or COMMIT), its SQLSTATE and the driver's
 * error code. Never the message: a database error's message carries the SQL
 * statement with its values, and the driver's message can quote a value.
 * yii2-redis throws yii\db\Exception too: its messages quote the Redis
 * command with its values.
 *
 * A PDOException is read as PDO fills it: its errorInfo, else its code as the
 * SQLSTATE (PDO's code of a statement error is the SQLSTATE).
 *
 * Log patterns that use it end in "%s (SQLSTATE %s, driver error %s)" and
 * take the three parts of of(), for example
 * sprintf(PATTERN, $runId, ...DbErrorSummary::of($e)).
 */
final class DbErrorSummary
{
    /** A part the error has none of: the SQLSTATE and the code of an error that is no database error. */
    public const NONE = '-';

    /** The SQLSTATE of a deadlock (serialization failure). */
    private const SQLSTATE_SERIALIZATION_FAILURE = '40001';

    /** MariaDB/MySQL error codes of a lock conflict: a deadlock, a lock wait timeout. */
    private const LOCK_CONFLICT_CODES = ['1213', '1205'];

    /**
     * MariaDB/MySQL error codes of a connection that could not be made or
     * was lost: too many connections (1040, 1203), access denied (1044,
     * 1045), unknown database (1049), host blocked or not allowed (1129,
     * 1130), cannot connect (2002, 2003), unknown host (2005), server gone
     * away (2006), connection lost (2013). Their driver texts name the host,
     * the user or the database, never a statement or a value.
     */
    private const CONNECTION_FAILURE_CODES = [
        '1040', '1044', '1045', '1049', '1129', '1130', '1203', '2002', '2003', '2005', '2006', '2013',
    ];

    /**
     * The class of $e, its SQLSTATE and the driver's error code; NONE for
     * a part that $e has none of (any error but a database error).
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function of(\Throwable $e): array
    {
        $info = self::errorInfo($e);

        return [$e::class, self::part($info[0] ?? null), self::part($info[1] ?? null)];
    }

    /**
     * Whether $e is a database error: a yii\db\Exception (which yii2-redis
     * throws too) or a PDOException that escaped Yii's wrapping.
     */
    public static function isDatabaseError(\Throwable $e): bool
    {
        return $e instanceof \yii\db\Exception || $e instanceof \PDOException;
    }

    /**
     * Whether $e is a lock conflict that a new attempt can get past: a
     * deadlock (SQLSTATE 40001, error 1213) or a lock wait timeout (error
     * 1205). The database rolled back the statement or the transaction.
     */
    public static function isLockConflict(\Throwable $e): bool
    {
        [, $state, $code] = self::of($e);

        return $state === self::SQLSTATE_SERIALIZATION_FAILURE || in_array($code, self::LOCK_CONFLICT_CODES, true);
    }

    /**
     * The driver's own text of a database error that is a failed or lost
     * connection (CONNECTION_FAILURE_CODES), such as "Connection refused" or
     * "Access denied for user 'ansilume'@'172.18.0.5' (using password: YES)";
     * null for any other error. It never holds the password, a statement or
     * a value, so a log may keep it where it keeps no message.
     */
    public static function connectionFailure(\Throwable $e): ?string
    {
        $info = self::errorInfo($e);
        $text = $info[2] ?? null;
        if (!in_array(self::part($info[1] ?? null), self::CONNECTION_FAILURE_CODES, true)) {
            return null;
        }

        return is_string($text) && $text !== '' ? $text : null;
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function errorInfo(\Throwable $e): array
    {
        if ($e instanceof \yii\db\Exception) {
            return is_array($e->errorInfo) ? $e->errorInfo : [];
        }
        if ($e instanceof \PDOException) {
            // PDO's code is 0 for an error it raised itself, such as an
            // active transaction that cannot begin.
            return is_array($e->errorInfo) ? $e->errorInfo : [0 => $e->getCode() === 0 ? null : $e->getCode()];
        }

        return [];
    }

    private static function part(mixed $value): string
    {
        return is_scalar($value) && (string)$value !== '' ? (string)$value : self::NONE;
    }
}
