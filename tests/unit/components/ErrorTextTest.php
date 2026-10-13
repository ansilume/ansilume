<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\ErrorText;
use PHPUnit\Framework\TestCase;
use yii\db\Exception as DbException;
use yii\db\IntegrityException;

/**
 * ErrorText: what a log line or an audit entry may say about an error whose
 * message is worth keeping. Any error keeps its message, but a database
 * error's message carries the SQL with its values: of a database error only
 * the class, the SQLSTATE and the driver's error code are told.
 */
class ErrorTextTest extends TestCase
{
    /**
     * @return array<string, array{0: \Throwable, 1: string}>
     */
    public static function databaseErrorProvider(): array
    {
        return [
            'a lost connection' => [
                new DbException(
                    "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away\n"
                    . "The SQL being executed was: INSERT INTO `audit_log` (`metadata`) VALUES ('marker-4711')",
                    ['HY000', 2006, 'MySQL server has gone away']
                ),
                'yii\db\Exception (SQLSTATE HY000, driver error 2006)',
            ],
            'a subclass, a duplicate key whose driver message quotes the value' => [
                new IntegrityException(
                    "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'marker-4711' for key 'name'",
                    ['23000', 1062, "Duplicate entry 'marker-4711' for key 'name'"]
                ),
                'yii\db\IntegrityException (SQLSTATE 23000, driver error 1062)',
            ],
            'a database error without error info' => [
                new DbException("SQLSTATE[HY000]: UPDATE `job` SET `name`='marker-4711'"),
                'yii\db\Exception (SQLSTATE -, driver error -)',
            ],
            'a PDOException that escaped the wrapping' => [
                new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'marker-4711'"),
                'PDOException (SQLSTATE -, driver error -)',
            ],
            'a PDOException with the error info PDO fills in' => [
                self::pdoException(
                    "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'marker-4711'",
                    ['23000', 1062, "Duplicate entry 'marker-4711' for key 'PRIMARY'"]
                ),
                'PDOException (SQLSTATE 23000, driver error 1062)',
            ],
            'yii2-redis errors, which are database errors too' => [
                new \yii\redis\SocketException("Failed to read from socket.\nRedis command was: SET session marker-4711"),
                'yii\redis\SocketException (SQLSTATE -, driver error -)',
            ],
        ];
    }

    /**
     * @param array<int, mixed> $errorInfo
     */
    private static function pdoException(string $message, array $errorInfo): \PDOException
    {
        $error = new \PDOException($message);
        $error->errorInfo = $errorInfo;

        return $error;
    }

    /**
     * @dataProvider databaseErrorProvider
     */
    public function testADatabaseErrorIsToldByItsClassSqlstateAndDriverCodeOnly(\Throwable $error, string $expected): void
    {
        $this->assertSame($expected, ErrorText::of($error));
        $this->assertStringNotContainsString('marker-4711', ErrorText::of($error), 'never the message');
    }

    public function testADatabaseErrorIsToldWithThePatternOfTheConstant(): void
    {
        $error = new DbException('SELECT marker-4711', ['40001', 1213, 'Deadlock found when trying to get lock']);

        $this->assertSame(sprintf(ErrorText::DATABASE_ERROR, DbException::class, '40001', '1213'), ErrorText::of($error));
    }

    /**
     * Any other error keeps its message, such as a notification channel's
     * HTTP error, which an operator needs to fix the channel.
     */
    public function testAnyOtherErrorKeepsItsMessage(): void
    {
        $this->assertSame('SlackChannel: HTTP 404 — no_service', ErrorText::of(new \RuntimeException('SlackChannel: HTTP 404 — no_service')));
        $this->assertSame('Mailer::send() returned false', ErrorText::of(new \LogicException('Mailer::send() returned false')));
        $this->assertSame('', ErrorText::of(new \RuntimeException()));
    }
}
