<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\DbErrorSummary;
use PHPUnit\Framework\TestCase;
use yii\db\Exception as DbException;
use yii\db\IntegrityException;

/**
 * DbErrorSummary: what a log line may say about an error. A database error's
 * message carries the SQL with its values, so only the class, the SQLSTATE
 * and the driver's error code are taken from it.
 */
class DbErrorSummaryTest extends TestCase
{
    /** The driver's text of error 4031, as mysqlnd reports it since PHP 8.4. */
    private const INACTIVITY = 'The client was disconnected by the server because of inactivity.'
        . ' See wait_timeout and interactive_timeout for configuring this behavior.';

    /**
     * @return array<string, array{0: \Throwable, 1: array{0: string, 1: string, 2: string}}>
     */
    public static function errorProvider(): array
    {
        return [
            'a deadlock' => [
                new DbException("SQLSTATE[40001]: UPDATE `job` SET `extra_vars`='marker-4711'", ['40001', 1213, 'Deadlock found']),
                [DbException::class, '40001', '1213'],
            ],
            'a subclass, with the code as text' => [
                new IntegrityException('Duplicate entry', ['23000', '1062', 'Duplicate entry']),
                [IntegrityException::class, '23000', '1062'],
            ],
            'a database error without error info' => [new DbException('gone'), [DbException::class, '-', '-']],
            'error info without values' => [new DbException('odd', ['', null, null]), [DbException::class, '-', '-']],
            'error info that is no scalar' => [new DbException('odd', [['40001'], new \stdClass()]), [DbException::class, '-', '-']],
            'a PDOException as PDO raises a statement error' => [
                self::pdoException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'marker-4711'", '23000', ['23000', 1062, "Duplicate entry 'marker-4711'"]),
                [\PDOException::class, '23000', '1062'],
            ],
            'a PDOException without error info: its code is the SQLSTATE' => [
                self::pdoException('SQLSTATE[HY000]: marker-4711', 'HY000', null),
                [\PDOException::class, 'HY000', '-'],
            ],
            'a PDOException that PDO raised itself (code 0, no error info)' => [
                new \PDOException('There is already an active transaction marker-4711'),
                [\PDOException::class, '-', '-'],
            ],
            'any other error' => [new \RuntimeException('SQLSTATE[HY000] marker-4711'), [\RuntimeException::class, '-', '-']],
        ];
    }

    /**
     * Regression: a raw PDOException had no SQLSTATE in its summary. A
     * PDOException that escapes Yii's wrapping (a failed BEGIN or COMMIT)
     * read 'PDOException (SQLSTATE -, driver error -)' although PDO had
     * filled in its errorInfo, so the log line did not say which error it
     * was. tests/integration/components/DbErrorSummaryPdoTest.php shows it
     * with exceptions raised by PDO itself.
     */
    public function testRegressionARawPdoExceptionHadNoSqlstateInItsSummary(): void
    {
        $error = self::pdoException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found', '40001', ['40001', 1213, 'Deadlock found']);

        $this->assertSame([\PDOException::class, '40001', '1213'], DbErrorSummary::of($error));
        $this->assertTrue(DbErrorSummary::isLockConflict($error), 'a deadlock at COMMIT can be retried like any other');
    }

    /**
     * @return array<string, array{0: \Throwable, 1: bool}>
     */
    public static function databaseErrorProvider(): array
    {
        return [
            'a yii\db\Exception' => [new DbException('x'), true],
            'a subclass of it' => [new IntegrityException('x'), true],
            'yii2-redis errors, which are yii\db\Exception too' => [new \yii\redis\SocketException('Failed to read from socket.'), true],
            'a PDOException' => [new \PDOException('x'), true],
            'a RuntimeException' => [new \RuntimeException('SQLSTATE[HY000] x'), false],
            'a RuntimeException that wraps a database error' => [new \RuntimeException('x', 0, new DbException('y')), false],
        ];
    }

    /**
     * @dataProvider databaseErrorProvider
     */
    public function testADatabaseErrorIsAYiiDbExceptionOrAPdoException(\Throwable $error, bool $expected): void
    {
        $this->assertSame($expected, DbErrorSummary::isDatabaseError($error));
    }

    /**
     * @return array<string, array{0: \Throwable, 1: string|null}>
     */
    public static function connectionFailureProvider(): array
    {
        return [
            'connection refused, as Yii wraps it' => [
                new DbException('SQLSTATE[HY000] [2002] Connection refused', ['HY000', 2002, 'Connection refused']),
                'Connection refused',
            ],
            'an unknown host, as PDO raises it' => [
                self::pdoException('SQLSTATE[HY000] [2002] php_network_getaddresses', 2002, ['HY000', 2002, 'php_network_getaddresses: getaddrinfo for db failed: Name or service not known']),
                'php_network_getaddresses: getaddrinfo for db failed: Name or service not known',
            ],
            'access denied' => [
                new DbException('x', ['HY000', 1045, "Access denied for user 'ansilume'@'172.18.0.5' (using password: YES)"]),
                "Access denied for user 'ansilume'@'172.18.0.5' (using password: YES)",
            ],
            'an unknown database' => [new DbException('x', ['HY000', '1049', "Unknown database 'ansilume'"]), "Unknown database 'ansilume'"],
            'too many connections' => [new DbException('x', ['08004', 1040, 'Too many connections']), 'Too many connections'],
            'a server that went away' => [new DbException('x', ['HY000', 2006, 'MySQL server has gone away']), 'MySQL server has gone away'],
            'a lost connection' => [new DbException('x', ['HY000', 2013, 'Lost connection to MySQL server during query']), 'Lost connection to MySQL server during query'],
            'a statement error, whose text can quote a value' => [
                new IntegrityException('x', ['23000', 1062, "Duplicate entry 'marker-4711' for key 'name'"]),
                null,
            ],
            'a connection code without a text' => [new DbException('x', ['HY000', 2002]), null],
            'a connection code with an empty text' => [new DbException('x', ['HY000', 2002, '']), null],
            'a connection code with a text that is no string' => [new DbException('x', ['HY000', 2002, ['Connection refused']]), null],
            'a database error without error info' => [new DbException('SQLSTATE[HY000] [2002] Connection refused'), null],
            'any other error' => [new \RuntimeException('Connection refused'), null],
        ];
    }

    /**
     * Only the driver's text of a failed or lost connection is kept: it
     * names the host, the user or the database, never a statement or a
     * value.
     *
     * @dataProvider connectionFailureProvider
     */
    public function testTheDriverTextIsKeptOnlyForAFailedOrLostConnection(\Throwable $error, ?string $expected): void
    {
        $this->assertSame($expected, DbErrorSummary::connectionFailure($error));
    }

    /**
     * @return array<string, array{0: \Throwable}>
     */
    public static function inactivityDisconnectProvider(): array
    {
        $message = 'SQLSTATE[HY000]: General error: 4031 ' . self::INACTIVITY;
        $sql = "\nThe SQL being executed was: SELECT * FROM `job` WHERE `name`='marker-4711'";
        $errorInfo = ['HY000', 4031, self::INACTIVITY];

        return [
            'as Yii wraps it' => [new DbException($message . $sql, $errorInfo)],
            'as a PDOException that escaped the wrapping, at BEGIN or COMMIT' => [
                self::pdoException($message, 'HY000', $errorInfo),
            ],
        ];
    }

    /**
     * Regression: since PHP 8.4, mysqlnd reports a connection that a MySQL
     * server of 8.0.24 or later closed for inactivity (wait_timeout,
     * interactive_timeout) as error 4031, not as 2006 (server gone away).
     * 4031 counted as no connection failure, so the log line of such an
     * error lost the driver's text, the part that says why. MariaDB still
     * reports 2006.
     *
     * @dataProvider inactivityDisconnectProvider
     */
    public function testRegressionAnInactivityDisconnectOfMysqlKeepsTheDriverText(\Throwable $error): void
    {
        $this->assertSame(self::INACTIVITY, DbErrorSummary::connectionFailure($error));
        $this->assertSame([$error::class, 'HY000', '4031'], DbErrorSummary::of($error));
        $this->assertFalse(DbErrorSummary::isLockConflict($error), 'no lock conflict, which a new attempt gets past');
    }

    /**
     * A PDOException as PDO raises it: $code is the SQLSTATE of a statement
     * error or the driver's code of a failed connection, and $errorInfo is
     * public.
     *
     * @param array<int, mixed>|null $errorInfo
     */
    private static function pdoException(string $message, string|int $code, ?array $errorInfo): \PDOException
    {
        $error = new \PDOException($message);
        $error->errorInfo = $errorInfo;
        (new \ReflectionProperty(\Exception::class, 'code'))->setValue($error, $code);

        return $error;
    }

    /**
     * @param array{0: string, 1: string, 2: string} $expected
     * @dataProvider errorProvider
     */
    public function testOfGivesTheClassTheSqlstateAndTheDriverCode(\Throwable $error, array $expected): void
    {
        $this->assertSame($expected, DbErrorSummary::of($error));
        $this->assertStringNotContainsString('marker-4711', implode(' ', DbErrorSummary::of($error)), 'never the message');
    }

    /**
     * A database error whose errorInfo was overwritten with something that
     * is no array (it is a public property) still gives NONE.
     */
    public function testErrorInfoThatIsNoArrayGivesNone(): void
    {
        $error = new DbException('odd');
        $error->errorInfo = 'HY000'; // @phpstan-ignore assign.propertyType

        $this->assertSame([DbException::class, DbErrorSummary::NONE, DbErrorSummary::NONE], DbErrorSummary::of($error));
    }

    /**
     * The summary fills the log patterns that end in "%s (SQLSTATE %s, driver error %s)".
     */
    public function testItFillsALogPattern(): void
    {
        $line = sprintf('Run #%d failed: %s (SQLSTATE %s, driver error %s).', 7, ...DbErrorSummary::of(new DbException('x', ['HY000', 2006, 'gone away'])));

        $this->assertSame('Run #7 failed: yii\db\Exception (SQLSTATE HY000, driver error 2006).', $line);
    }

    /**
     * @return array<string, array{0: \Throwable, 1: bool}>
     */
    public static function lockConflictProvider(): array
    {
        return [
            'a deadlock (SQLSTATE 40001)' => [new DbException('x', ['40001', 1213, 'Deadlock']), true],
            'SQLSTATE 40001 alone' => [new DbException('x', ['40001']), true],
            'error 1213 under another SQLSTATE' => [new DbException('x', ['HY000', 1213]), true],
            'a lock wait timeout (1205)' => [new DbException('x', ['HY000', 1205, 'Lock wait timeout exceeded']), true],
            'a lock wait timeout, the code as text' => [new DbException('x', ['HY000', '1205']), true],
            'a lost connection' => [new DbException('x', ['HY000', 2006, 'MySQL server has gone away']), false],
            'a duplicate key' => [new IntegrityException('x', ['23000', 1062]), false],
            'a database error without error info' => [new DbException('x'), false],
            'a PDOException that escaped the wrapping, a deadlock' => [self::pdoException('x', '40001', ['40001', 1213, 'Deadlock']), true],
            'a PDOException without error info' => [new \PDOException('Deadlock found when trying to get lock'), false],
            'any other error' => [new \RuntimeException('Deadlock found when trying to get lock'), false],
        ];
    }

    /**
     * @dataProvider lockConflictProvider
     */
    public function testALockConflictIsADeadlockOrALockWaitTimeout(\Throwable $error, bool $expected): void
    {
        $this->assertSame($expected, DbErrorSummary::isLockConflict($error));
    }
}
