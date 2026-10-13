<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\ExceptionLogText;
use app\components\RedactedException;
use PHPUnit\Framework\TestCase;
use yii\base\ErrorException;
use yii\db\Exception as DbException;
use yii\db\IntegrityException;
use yii\web\NotFoundHttpException;

/**
 * ExceptionLogText: what the error handlers and the queue's log behaviour
 * write about an uncaught exception and the exceptions it wraps. A chain
 * with a database error is written in PHP's form of an exception chain, but
 * without any message that can carry the SQL statement or a Redis command
 * with its values, and without trace arguments.
 */
class ExceptionLogTextTest extends TestCase
{
    private const MARKER = 'log-text-marker-8841';

    /** Short enough for PHP to show it whole as a trace argument (15 characters). */
    private const SHORT_MARKER = 'trace-arg-8841';

    /**
     * @return array<string, array{0: \Throwable, 1: bool}>
     */
    public static function chainProvider(): array
    {
        return [
            'a database error' => [new DbException('x'), true],
            'a PDOException' => [new \PDOException('x'), true],
            'a Redis error (yii2-redis)' => [new \yii\redis\SocketException('Failed to write to socket.'), true],
            'a database error two levels down' => [new \LogicException('a', 0, new \RuntimeException('b', 0, new DbException('c'))), true],
            'no database error' => [new \RuntimeException('a', 0, new \LogicException('b')), false],
        ];
    }

    /**
     * @dataProvider chainProvider
     */
    public function testAChainHoldsADatabaseErrorWhenAnyOfItsExceptionsIsOne(\Throwable $error, bool $expected): void
    {
        $this->assertSame($expected, ExceptionLogText::hasDatabaseError($error));
    }

    /**
     * A database error as Yii raises it: the PDOException first, then the
     * yii\db\Exception that wraps it, after "Next", each with file:line and
     * trace, and none with its message.
     */
    public function testADatabaseErrorIsLoggedByClassSqlstateAndDriverCodeWithFileLineAndTrace(): void
    {
        [$error, $line] = self::databaseError(self::MARKER);

        $text = ExceptionLogText::of($error);

        $pdo = $error->getPrevious();
        $this->assertInstanceOf(\PDOException::class, $pdo);
        $this->assertStringStartsWith(
            'PDOException (SQLSTATE 23000, driver error 1062) in ' . __FILE__ . ':' . $pdo->getLine() . "\nStack trace:\n#0 ",
            $text
        );
        $this->assertStringContainsString(
            "\n\nNext yii\\db\\IntegrityException (SQLSTATE 23000, driver error 1062) in " . __FILE__ . ':' . $line . "\nStack trace:\n#0 ",
            $text
        );
        $this->assertStringContainsString(self::class . '::databaseError()', $text);
        $this->assertMatchesRegularExpression('/\n#\d+ \{main\}$/', $text);
        $this->assertStringNotContainsString(self::MARKER, $text);
        $this->assertStringNotContainsString('SQL being executed', $text);
        $this->assertStringNotContainsString('Duplicate entry', $text);
    }

    /**
     * The tests record trace arguments (phpunit.xml), as a host without
     * zend.exception_ignore_args does: PHP's own trace shows the argument
     * (up to 15 characters), the log text does not.
     */
    public function testTheTraceShowsNoArguments(): void
    {
        [$error] = self::databaseError(self::SHORT_MARKER);

        $this->assertStringContainsString("'" . self::SHORT_MARKER . "'", $error->getTraceAsString(), 'PHP records the argument');
        $this->assertStringNotContainsString(self::SHORT_MARKER, ExceptionLogText::of($error));
    }

    /**
     * An exception that wraps a database error is named by its class: its
     * message is often built from the database error's. An exception below
     * the database error, and one in a chain without any, keep theirs.
     */
    public function testAWrapperOfADatabaseErrorLosesItsMessageAndOtherExceptionsKeepTheirs(): void
    {
        $cause = new \LogicException('the cause below');
        $database = new DbException('SQLSTATE[HY000]: ' . self::MARKER, ['HY000', 1366, 'Incorrect value ' . self::MARKER], 'HY000', $cause);
        $wrapper = new \RuntimeException('Launch failed: ' . $database->getMessage(), 0, $database);

        $text = ExceptionLogText::of($wrapper);

        $this->assertStringStartsWith('LogicException: the cause below in ' . __FILE__ . ':', $text);
        $this->assertStringContainsString("\n\nNext yii\\db\\Exception (SQLSTATE HY000, driver error 1366) in ", $text);
        $this->assertStringContainsString("\n\nNext RuntimeException " . ExceptionLogText::WITHHELD . ' in ' . __FILE__ . ':', $text);
        $this->assertStringContainsString('RuntimeException (message withheld: it wraps a database error) in ', $text);
        $this->assertStringNotContainsString(self::MARKER, $text);
    }

    public function testAnExceptionWithoutAMessageIsNamedByItsClass(): void
    {
        $text = ExceptionLogText::of(new DbException('x', [], '', new \LogicException()));

        $this->assertStringStartsWith('LogicException in ' . __FILE__ . ':', $text);
    }

    /**
     * A failed connection keeps the driver's text: it says why, and names
     * the host, the user or the database, never a statement or a value.
     */
    public function testAFailedConnectionKeepsTheDriversText(): void
    {
        $error = new DbException('SQLSTATE[HY000] [2002] Connection refused', ['HY000', 2002, 'Connection refused']);

        $this->assertStringStartsWith('yii\db\Exception (SQLSTATE HY000, driver error 2002): Connection refused in ', ExceptionLogText::of($error));
        $this->assertSame('yii\db\Exception (SQLSTATE HY000, driver error 2002): Connection refused', ExceptionLogText::brief($error));
    }

    /**
     * @return array<string, array{0: \Throwable, 1: string}>
     */
    public static function briefProvider(): array
    {
        $database = new IntegrityException('SQLSTATE[23000]: ' . self::MARKER, ['23000', 1062, self::MARKER], '23000', new \PDOException(self::MARKER));

        return [
            'a database error, without the PDOException it wraps' => [$database, 'yii\db\IntegrityException (SQLSTATE 23000, driver error 1062)'],
            'an exception that wraps one' => [
                new \RuntimeException(self::MARKER, 0, $database),
                'RuntimeException (message withheld: it wraps a database error); caused by yii\db\IntegrityException (SQLSTATE 23000, driver error 1062)',
            ],
            'any other exception' => [new \RuntimeException('Disk full.'), 'RuntimeException: Disk full.'],
        ];
    }

    /**
     * @dataProvider briefProvider
     */
    public function testBriefIsOneLineAboutTheErrorAndTheDatabaseErrorItWraps(\Throwable $error, string $expected): void
    {
        $this->assertSame($expected, ExceptionLogText::brief($error));
        $this->assertStringNotContainsString(self::MARKER, ExceptionLogText::brief($error));
    }

    public function testCausedByJoinsBothHeads(): void
    {
        $this->assertSame('a; caused by b', sprintf(ExceptionLogText::CAUSED_BY, 'a', 'b'));
    }

    /**
     * @return array<string, array{0: \Throwable, 1: string}>
     */
    public static function categoryProvider(): array
    {
        return [
            'an HTTP exception, by its status' => [new NotFoundHttpException('x', 0, new DbException('y')), 'yii\web\HttpException:404'],
            'a PHP error, by its severity' => [new ErrorException('x', 0, E_WARNING), 'yii\base\ErrorException:2'],
            'any other, by its class' => [new IntegrityException('x'), 'yii\db\IntegrityException'],
        ];
    }

    /**
     * The categories Yii's error handler files an exception under.
     *
     * @dataProvider categoryProvider
     */
    public function testTheCategoryIsTheOneYiisErrorHandlerUses(\Throwable $error, string $expected): void
    {
        $this->assertSame($expected, ExceptionLogText::category($error));
    }

    public function testAChainWithoutADatabaseErrorIsNotRedacted(): void
    {
        $error = new \RuntimeException(self::MARKER, 0, new \LogicException('b'));

        $this->assertSame($error, ExceptionLogText::redacted($error));
    }

    /**
     * Where Yii prints or logs an exception itself, a chain with a database
     * error is replaced by a stand-in: its message is brief(), its string
     * form of(), and it wraps nothing.
     */
    public function testAChainWithADatabaseErrorIsReplacedByAStandIn(): void
    {
        [$error] = self::databaseError(self::MARKER);

        $redacted = ExceptionLogText::redacted($error);

        $this->assertInstanceOf(RedactedException::class, $redacted);
        $this->assertSame(ExceptionLogText::brief($error), $redacted->getMessage());
        $this->assertSame(ExceptionLogText::of($error), (string)$redacted);
        $this->assertSame(ExceptionLogText::of($error), "{$redacted}");
        $this->assertNull($redacted->getPrevious());
        $this->assertStringNotContainsString(self::MARKER, (string)$redacted);
    }

    /**
     * A frame that PHP called, such as a callback of array_map(), has no
     * file: it is listed as an internal function, as PHP does.
     */
    public function testAFrameWithoutAFileIsAnInternalFunction(): void
    {
        try {
            array_map(static function (string $value): string {
                throw new DbException('SQLSTATE[HY000]: ' . $value, ['HY000', 1366]);
            }, [self::MARKER]);
            $this->fail('expected the callback to throw');
        } catch (DbException $e) {
            $text = ExceptionLogText::of($e);
        }

        $this->assertMatchesRegularExpression('/\n#0 \[internal function\]: [^\n]*\{closure[^\n]*\}\(\)\n/', $text);
        $this->assertStringNotContainsString(self::MARKER, $text);
    }

    /**
     * Only reflection can make a chain loop; it is followed until the first
     * repeat.
     */
    public function testAChainThatLoopsIsFollowedOnce(): void
    {
        $outer = new \RuntimeException('outer');
        $database = new DbException('SQLSTATE[HY000]: ' . self::MARKER, ['HY000', 1366], 'HY000', $outer);
        (new \ReflectionProperty(\Exception::class, 'previous'))->setValue($outer, $database);

        $text = ExceptionLogText::of($outer);

        $this->assertSame(1, substr_count($text, 'yii\db\Exception (SQLSTATE HY000, driver error 1366)'));
        $this->assertSame(1, substr_count($text, 'RuntimeException ' . ExceptionLogText::WITHHELD));
        $this->assertTrue(ExceptionLogText::hasDatabaseError($outer));
    }

    /**
     * A database error as Yii raises it for a failed INSERT, with the
     * PDOException it wraps; $value is a plain argument of this method, so
     * it is in the trace's arguments when PHP records them.
     *
     * @return array{0: IntegrityException, 1: int}
     */
    private static function databaseError(string $value): array
    {
        $pdo = new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '{$value}' for key 'name'");
        $pdo->errorInfo = ['23000', 1062, "Duplicate entry '{$value}' for key 'name'"];
        $line = __LINE__ + 1;
        $error = new IntegrityException(
            $pdo->getMessage() . "\nThe SQL being executed was: INSERT INTO `job` (`name`) VALUES ('{$value}')",
            $pdo->errorInfo,
            '23000',
            $pdo
        );

        return [0 => $error, 1 => $line];
    }
}
