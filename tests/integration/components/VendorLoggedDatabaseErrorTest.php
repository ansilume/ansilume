<?php

declare(strict_types=1);

namespace app\tests\integration\components;

use app\components\ExceptionLogText;
use app\tests\integration\DbTestCase;
use yii\db\Connection;
use yii\db\IntegrityException;
use yii\log\FileTarget;
use yii\log\Logger;
use yii\log\Target;
use yii\redis\SocketException;

/**
 * Regression: a vendor Yii::error($e) logged a database error's message.
 * yii\db\Connection logs a rollback that failed inside transaction() with
 * \Yii::error($e) (yii2-redis does the same for a command it retries): the
 * exception object itself, which Yii's FileTarget wrote as PHP prints it,
 * with the message of the database error (the SQL statement, with its
 * values) and the driver's message in its error info. Now the log target of
 * every application config (app\components\RedactingFileTarget) writes such
 * an exception as ExceptionLogText words it, as the error handlers log an
 * uncaught one; every other message as before.
 *
 * Each target is built from its config here, as the application builds it,
 * and gets the messages as the application's logger hands them over
 * (yii\log\Dispatcher calls collect() with them).
 */
class VendorLoggedDatabaseErrorTest extends DbTestCase
{
    private const MARKER = 'vendor-log-marker-6047';

    /** @var array<string, mixed> */
    private static array $configs = [];

    /** @var list<string> */
    private array $logFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->logFiles as $logFile) {
            if (file_exists($logFile)) {
                unlink($logFile);
            }
        }
        $this->logFiles = [];
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function configProvider(): array
    {
        return ['web' => ['web.php'], 'console' => ['console.php'], 'test' => ['test.php']];
    }

    /**
     * Regression: a vendor Yii::error($e) logged a database error's message.
     * yii\db\Connection::transaction() rolls back when its callback throws;
     * here that rollback fails because the savepoint of the nested
     * transaction is gone, as after a deadlock that rolled back the whole
     * transaction. Connection logs the failure with \Yii::error($e).
     *
     * @dataProvider configProvider
     */
    public function testRegressionAVendorYiiErrorLoggedADatabaseErrorsMessage(string $file): void
    {
        $this->assertSame(1, \Yii::$app->db->getTransaction()?->level, 'DbTestCase opened the outer transaction');
        $rethrown = null;

        $messages = $this->logged(static function () use (&$rethrown): void {
            try {
                \Yii::$app->db->transaction(static function (Connection $db): void {
                    $db->createCommand('RELEASE SAVEPOINT LEVEL1')->execute();
                    throw new \RuntimeException('The work failed.');
                });
            } catch (\RuntimeException $e) {
                $rethrown = $e;
            }
        });
        $written = $this->writtenBy($this->configTarget($file), $messages);

        $this->assertInstanceOf(\RuntimeException::class, $rethrown);
        $this->assertSame('The work failed.', $rethrown->getMessage(), 'transaction() rethrows the error of its callback');
        $this->assertStringContainsString(
            '[error][yii\db\Connection::rollbackTransactionOnLevel] PDOException (SQLSTATE 42000, driver error 1305) in ',
            $written
        );
        $this->assertStringContainsString("\n\nNext yii\\db\\Exception (SQLSTATE 42000, driver error 1305) in ", $written);
        $this->assertStringContainsString("\nStack trace:\n#0 ", $written);
        $this->assertStringNotContainsString('The SQL being executed was', $written);
        $this->assertStringNotContainsString('ROLLBACK TO SAVEPOINT', $written);
        $this->assertStringNotContainsString('does not exist', $written);
        $this->assertStringNotContainsString('Additional Information', $written);
    }

    /**
     * @return array<string, array{0: string, 1: \Throwable, 2: string}>
     */
    public static function configAndErrorProvider(): array
    {
        $database = self::integrityError();
        $errors = [
            'a database error, as Yii raises it' => [$database, 'PDOException (SQLSTATE 23000, driver error 1062) in '],
            'a database error that another exception wraps' => [
                new \RuntimeException('Launch failed: ' . $database->getMessage(), 0, $database),
                "\n\nNext RuntimeException " . ExceptionLogText::WITHHELD . ' in ',
            ],
            'a PDOException that escaped Yii' => [self::pdoException(), 'PDOException (SQLSTATE 23000, driver error 1062) in '],
            'a Redis error, whose message quotes the command' => [
                new SocketException("Failed to read from socket.\nRedis command was: SET session " . self::MARKER),
                'yii\redis\SocketException (SQLSTATE -, driver error -) in ',
            ],
        ];
        $cases = [];
        foreach (self::configProvider() as $config => [$file]) {
            foreach ($errors as $name => [$error, $expected]) {
                $cases["{$config}: {$name}"] = [$file, $error, $expected];
            }
        }

        return $cases;
    }

    /**
     * Regression: a vendor Yii::error($e) logged a database error's message,
     * with the values that the statement or the Redis command carries.
     *
     * @dataProvider configAndErrorProvider
     */
    public function testRegressionAnExceptionLoggedAsSuchWasWrittenWithItsDatabaseErrorsMessage(
        string $file,
        \Throwable $error,
        string $expected
    ): void {
        $messages = $this->logged(static fn () => \Yii::error($error, 'yii\redis\Connection::executeCommand'));
        $written = $this->writtenBy($this->configTarget($file), $messages);

        $this->assertStringContainsString('[error][yii\redis\Connection::executeCommand] ' . ExceptionLogText::of($error) . "\n", $written);
        $this->assertStringContainsString($expected, $written);
        $this->assertStringNotContainsString(self::MARKER, $written);
        $this->assertStringNotContainsString('SQL being executed', $written);
        $this->assertStringNotContainsString('Additional Information', $written);
        $this->assertStringNotContainsString('Redis command was', $written);
    }

    /**
     * Every other message is written as Yii's FileTarget with the same
     * settings writes it: text, an exception without a database error (with
     * its message) and an array.
     *
     * @dataProvider configProvider
     */
    public function testEveryOtherMessageIsWrittenAsBefore(string $file): void
    {
        $error = new \RuntimeException('Disk full: ' . self::MARKER, 0, new \LogicException('cause'));
        $messages = $this->logged(static function () use ($error): void {
            \Yii::error($error, 'app\services\ArtifactService');
            \Yii::warning('Runner heartbeat is late.', 'app\services\RunnerService');
            \Yii::error(['artifact' => 42, 'reason' => 'Disk full.'], 'app\services\ArtifactService');
        });
        $target = $this->configTarget($file);
        $yii = $this->yiiFileTarget($file);

        $written = $this->writtenBy($target, $messages);

        $this->assertSame($this->writtenBy($yii, $messages), $written);
        $this->assertStringContainsString('[error][app\services\ArtifactService] LogicException: cause in ', $written);
        $this->assertStringContainsString("\n\nNext RuntimeException: Disk full: " . self::MARKER . ' in ', $written);
        $this->assertStringContainsString('[warning][app\services\RunnerService] Runner heartbeat is late.', $written);
        $this->assertStringContainsString("'reason' => 'Disk full.'", $written);
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * What was logged while $action ran, as the application's logger keeps it.
     *
     * @return list<array<int, mixed>>
     */
    private function logged(callable $action): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $logger->traceLevel = 0;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $action();
        } finally {
            \Yii::setLogger($previous);
        }

        return array_values($logger->messages);
    }

    /**
     * What $target writes to its file for $messages.
     *
     * @param list<array<int, mixed>> $messages
     */
    private function writtenBy(FileTarget $target, array $messages): string
    {
        $target->collect($messages, true);
        $logFile = (string)$target->logFile;

        return file_exists($logFile) ? (string)file_get_contents($logFile) : '';
    }

    /**
     * The only log target of config/$file, as the application builds it,
     * writing to a temporary file instead of the file the config names
     * (runtime/logs/app.log; runtime/logs/test.log for config/test.php).
     */
    private function configTarget(string $file): FileTarget
    {
        return $this->fileTarget($this->targetDefinition($file));
    }

    /**
     * Yii's FileTarget with the settings of the target of config/$file: what
     * the config wrote before.
     */
    private function yiiFileTarget(string $file): FileTarget
    {
        $target = $this->fileTarget(['class' => FileTarget::class] + $this->targetDefinition($file));
        $this->assertSame(FileTarget::class, $target::class);

        return $target;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function fileTarget(array $definition): FileTarget
    {
        $logFile = tempnam(sys_get_temp_dir(), 'ansilume-vendor-log-');
        $this->assertIsString($logFile);
        unlink($logFile);
        $this->logFiles[] = $logFile;
        $definition = ['logFile' => $logFile] + $definition;
        $this->assertIsString($definition['class'] ?? null);
        /** @var array{class: class-string} $definition */
        $target = \Yii::createObject($definition);
        $this->assertInstanceOf(FileTarget::class, $target);

        return $target;
    }

    /**
     * @return array<string, mixed>
     */
    private function targetDefinition(string $file): array
    {
        if (!isset(self::$configs[$file])) {
            self::$configs[$file] = require dirname(__DIR__, 3) . '/config/' . $file;
        }
        $config = self::$configs[$file];
        $this->assertIsArray($config);
        $targets = $config['components']['log']['targets'] ?? null;
        $this->assertIsArray($targets);
        $this->assertCount(1, $targets, "{$file}: one log target");
        $definition = reset($targets);
        $this->assertIsArray($definition);
        $this->assertTrue(is_a((string)($definition['class'] ?? ''), Target::class, true), "{$file}: a log target class");

        /** @var array<string, mixed> $definition */
        return $definition;
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
