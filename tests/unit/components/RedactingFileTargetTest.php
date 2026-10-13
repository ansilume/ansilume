<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\ExceptionLogText;
use app\components\RedactingFileTarget;
use PHPUnit\Framework\TestCase;
use yii\db\IntegrityException;
use yii\log\FileTarget;
use yii\log\Logger;
use yii\redis\SocketException;

/**
 * RedactingFileTarget writes an exception logged as such whose chain holds a
 * database error as ExceptionLogText words it (as the error handlers log an
 * uncaught one), also inside a logged array, and every other message as
 * Yii's FileTarget writes it. tests/integration/components/
 * VendorLoggedDatabaseErrorTest checks the targets of the application
 * configs with yii\db\Connection's own Yii::error($e).
 */
class RedactingFileTargetTest extends TestCase
{
    private const MARKER = 'log-target-marker-3381';

    private const CATEGORY = 'yii\db\Connection::rollbackTransactionOnLevel';

    private string $logFile = '';

    protected function tearDown(): void
    {
        if ($this->logFile !== '' && file_exists($this->logFile)) {
            unlink($this->logFile);
        }
    }

    /**
     * @return array<string, array{0: \Throwable, 1: string}>
     */
    public static function databaseErrorProvider(): array
    {
        $database = self::integrityError();

        return [
            'a database error, as Yii raises it' => [
                $database,
                "PDOException (SQLSTATE 23000, driver error 1062) in ",
            ],
            'a database error that another exception wraps' => [
                new \RuntimeException('Launch failed: ' . $database->getMessage(), 0, $database),
                "\n\nNext RuntimeException " . ExceptionLogText::WITHHELD . ' in ',
            ],
            'a PDOException that escaped Yii' => [
                self::pdoException(),
                'PDOException (SQLSTATE 23000, driver error 1062) in ',
            ],
            'a Redis error, whose message quotes the command' => [
                new SocketException("Failed to read from socket.\nRedis command was: SET session " . self::MARKER),
                'yii\redis\SocketException (SQLSTATE -, driver error -) in ',
            ],
        ];
    }

    /**
     * An exception with a database error in its chain is written as the
     * error handlers log one: ExceptionLogText::of(), under the level and
     * category it was logged with.
     *
     * @dataProvider databaseErrorProvider
     */
    public function testADatabaseErrorIsWrittenAsExceptionLogTextWordsIt(\Throwable $error, string $expected): void
    {
        foreach ([Logger::LEVEL_ERROR => 'error', Logger::LEVEL_WARNING => 'warning'] as $level => $name) {
            $line = $this->target()->formatMessage($this->message($error, $level));

            $this->assertSame($this->plain()->formatMessage($this->message(ExceptionLogText::of($error), $level)), $line);
            $this->assertStringContainsString('[' . $name . '][' . self::CATEGORY . '] ', $line);
            $this->assertStringContainsString($expected, $line);
            $this->assertStringContainsString("\nStack trace:\n#0 ", $line);
            $this->assertNoMessageOf($line);
        }
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function otherMessageProvider(): array
    {
        return [
            'text' => ['Schedule #7 launched job #42.'],
            'text that names a database error' => ['Launch failed: yii\db\Exception (SQLSTATE 40001, driver error 1213)'],
            'an exception without a database error' => [new \RuntimeException('Disk full.', 0, new \LogicException('cause'))],
            'an array without a database error' => [['job' => 42, 'nested' => ['error' => new \RuntimeException('Disk full.')]]],
            'an empty array' => [[]],
            'a number' => [42],
            'null' => [null],
            'another object' => [new \ArrayObject(['job' => 42])],
        ];
    }

    /**
     * @dataProvider otherMessageProvider
     */
    public function testAnyOtherMessageIsWrittenAsYiisFileTargetWritesIt(mixed $value): void
    {
        $message = $this->message($value, Logger::LEVEL_ERROR);
        $target = $this->target();

        $this->assertSame($this->plain()->formatMessage($message), $target->formatMessage($message));
    }

    /**
     * Yii writes a logged array with VarDumper::export(), which serializes an
     * exception in it, message and all. A database error in it, at any
     * depth, is written as ExceptionLogText words it; its other values as
     * before.
     */
    public function testADatabaseErrorInsideALoggedArrayIsWrittenWithoutItsMessage(): void
    {
        $database = self::integrityError();
        $pdo = self::pdoException();
        $logged = ['job' => 42, 'attempt' => ['error' => $database, 'reason' => 'Disk full.'], 'errors' => [$pdo, 'retry']];
        $target = $this->target();

        $line = $target->formatMessage($this->message($logged, Logger::LEVEL_ERROR));

        $expected = [
            'job' => 42,
            'attempt' => ['error' => ExceptionLogText::of($database), 'reason' => 'Disk full.'],
            'errors' => [ExceptionLogText::of($pdo), 'retry'],
        ];
        $this->assertSame($this->plain()->formatMessage($this->message($expected, Logger::LEVEL_ERROR)), $line);
        $this->assertStringContainsString("'error' => 'PDOException (SQLSTATE 23000, driver error 1062) in ", $line);
        $this->assertNoMessageOf($line);
        $this->assertSame($database, $logged['attempt']['error'], 'the logged value itself is left as it was');
    }

    /**
     * The target writes what formatMessage() gives into its file: a database
     * error without its message, any other line as before.
     */
    public function testTheFileGetsTheDatabaseErrorWithoutItsMessage(): void
    {
        $error = self::integrityError();
        $target = $this->target();

        $target->collect([
            $this->message($error, Logger::LEVEL_ERROR),
            $this->message('Runner heartbeat is late.', Logger::LEVEL_WARNING),
        ], true);

        $written = (string)file_get_contents($this->logFile);
        $this->assertStringContainsString('[error][' . self::CATEGORY . '] ' . ExceptionLogText::of($error) . "\n", $written);
        $this->assertStringContainsString('[warning][' . self::CATEGORY . "] Runner heartbeat is late.\n", $written);
        $this->assertNoMessageOf($written);
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * The target under test, writing to a temporary file without a prefix.
     */
    private function target(): RedactingFileTarget
    {
        $logFile = tempnam(sys_get_temp_dir(), 'ansilume-redacting-target-');
        $this->assertIsString($logFile);
        unlink($logFile);
        $this->logFile = $logFile;

        return new RedactingFileTarget(['logFile' => $logFile, 'logVars' => [], 'prefix' => static fn (): string => '']);
    }

    /**
     * Yii's FileTarget with the same settings, for what Yii writes.
     */
    private function plain(): FileTarget
    {
        return new FileTarget(['logFile' => sys_get_temp_dir() . '/ansilume-plain-target.log', 'logVars' => [], 'prefix' => static fn (): string => '']);
    }

    /**
     * @return array{0: mixed, 1: int, 2: string, 3: float}
     */
    private function message(mixed $value, int $level): array
    {
        return [$value, $level, self::CATEGORY, 1760000000.0];
    }

    private function assertNoMessageOf(string $line): void
    {
        $this->assertStringNotContainsString(self::MARKER, $line);
        $this->assertStringNotContainsString('SQL being executed', $line);
        $this->assertStringNotContainsString('Additional Information', $line);
        $this->assertStringNotContainsString('Redis command was', $line);
    }

    /**
     * A database error as Yii raises it for a failed INSERT: the driver's
     * message quotes the value, Yii's adds the statement.
     */
    private static function integrityError(): IntegrityException
    {
        $pdo = self::pdoException();

        return new IntegrityException(
            $pdo->getMessage() . "\nThe SQL being executed was: INSERT INTO `job` (`name`) VALUES ('" . self::MARKER . "')",
            $pdo->errorInfo ?? [],
            '23000',
            $pdo
        );
    }

    private static function pdoException(): \PDOException
    {
        $pdo = new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '" . self::MARKER . "' for key 'name'");
        $pdo->errorInfo = ['23000', 1062, "Duplicate entry '" . self::MARKER . "' for key 'name'"];

        return $pdo;
    }
}
