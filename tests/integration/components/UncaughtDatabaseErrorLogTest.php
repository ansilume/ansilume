<?php

declare(strict_types=1);

namespace app\tests\integration\components;

use app\components\ConsoleErrorHandler;
use app\components\ExceptionLogText;
use app\components\WebErrorHandler;
use app\jobs\SyncProjectJob;
use PHPUnit\Framework\TestCase;
use yii\base\ErrorHandler;
use yii\db\IntegrityException;
use yii\helpers\Console;
use yii\log\FileTarget;
use yii\log\Logger;
use yii\queue\ExecEvent;
use yii\queue\Queue;
use yii\queue\redis\Queue as RedisQueue;
use yii\redis\SocketException;
use yii\web\NotFoundHttpException;

/**
 * An uncaught exception is logged by the error handler of config/web.php or
 * config/console.php, and an exception that ends a queue job by the log
 * behaviour of the queue in config/console.php. Each is built from its
 * config here, as the application builds it.
 *
 * Regression: an uncaught database error was logged with its SQL. Yii's
 * error handlers and yii2-queue's LogBehavior logged the exception as a
 * string: the message of a database error (the SQL statement with its
 * values), the driver's message in its error info, and the same for a
 * database error that another exception wrapped. Now a chain with a
 * database error is logged as ExceptionLogText words it (class, SQLSTATE
 * and driver error code, file:line and trace); any other exception as
 * before. The console also prints it so without YII_DEBUG.
 *
 * Yii's fallback for an error while an error is handled ends the process:
 * ConsoleErrorHandlerProcessTest runs it in a child process.
 */
class UncaughtDatabaseErrorLogTest extends TestCase
{
    private const MARKER = 'uncaught-marker-5127';

    /** @var array<string, mixed> */
    private static array $configs = [];

    /**
     * @return array<string, array{0: \Throwable, 1: string, 2: string}>
     */
    public static function databaseErrorProvider(): array
    {
        $database = self::integrityError();

        return [
            'a database error, as Yii raises it' => [
                $database,
                'yii\db\IntegrityException',
                "PDOException (SQLSTATE 23000, driver error 1062) in ",
            ],
            'a database error that another exception wraps' => [
                new \RuntimeException('Launch failed: ' . $database->getMessage(), 0, $database),
                'RuntimeException',
                "\n\nNext RuntimeException (message withheld: it wraps a database error) in ",
            ],
            'an HTTP exception that wraps one' => [
                new NotFoundHttpException('Not found: ' . self::MARKER, 0, $database),
                'yii\web\HttpException:404',
                "\n\nNext yii\\web\\NotFoundHttpException (message withheld: it wraps a database error) in ",
            ],
            'a PDOException that escaped Yii' => [
                self::pdoException(),
                'PDOException',
                'PDOException (SQLSTATE 23000, driver error 1062) in ',
            ],
            'a Redis error, whose message quotes the command' => [
                new SocketException("Failed to read from socket.\nRedis command was: SET session " . self::MARKER),
                'yii\redis\SocketException',
                'yii\redis\SocketException (SQLSTATE -, driver error -) in ',
            ],
        ];
    }

    /**
     * Regression: an uncaught database error was logged with its SQL (web).
     *
     * @dataProvider databaseErrorProvider
     */
    public function testRegressionTheWebErrorHandlerLoggedADatabaseErrorWithItsSql(\Throwable $error, string $category, string $expected): void
    {
        $handler = $this->handler('web.php');

        $lines = $this->loggedLines(static fn () => $handler->logException($error));

        $this->assertDatabaseErrorLogged($lines, $category, $expected);
    }

    /**
     * Regression: an uncaught database error was logged with its SQL
     * (console: schedule/run, maintenance/run, migrations, the workers).
     *
     * @dataProvider databaseErrorProvider
     */
    public function testRegressionTheConsoleErrorHandlerLoggedADatabaseErrorWithItsSql(\Throwable $error, string $category, string $expected): void
    {
        $handler = $this->handler('console.php');

        $lines = $this->loggedLines(static fn () => $handler->logException($error));

        $this->assertDatabaseErrorLogged($lines, $category, $expected);
    }

    /**
     * Regression: a queue job's database error was logged with its SQL.
     * The log line keeps yii2-queue's form, and the event keeps the error
     * for every other handler.
     *
     * @dataProvider databaseErrorProvider
     */
    public function testRegressionTheQueueLoggedADatabaseErrorOfAJobWithItsSql(\Throwable $error, string $category, string $expected): void
    {
        $event = $this->jobEvent($error);

        $lines = $this->loggedLines(fn () => $this->queue()->trigger(Queue::EVENT_AFTER_ERROR, $event));

        $this->assertCount(1, $lines, implode("\n", $lines));
        $this->assertStringContainsString(
            '[error][yii\queue\Queue] [7] ' . SyncProjectJob::class . ' (attempt: 2) is finished with error: ',
            $lines[0]
        );
        $this->assertStringContainsString($expected, $lines[0]);
        $this->assertStringEndsWith(".", $lines[0]);
        $this->assertNoMessageOf($lines[0]);
        $this->assertSame($error, $event->error, 'the other handlers of the event see the error itself');
    }

    /**
     * The web's error output stays Yii's: without YII_DEBUG neither an API
     * answer nor the error page (site/error, a yii\web\ErrorAction) shows a
     * message of a database error.
     */
    public function testWithoutDebugTheWebShowsNoMessageOfADatabaseError(): void
    {
        $handler = $this->handler('web.php');
        $error = new \RuntimeException('Launch failed: ' . self::MARKER, 0, self::integrityError());

        $this->assertSame(
            ['name' => 'Internal Server Error', 'message' => 'An internal server error occurred.', 'code' => 0, 'status' => 500],
            (new \ReflectionMethod($handler, 'convertExceptionToArray'))->invoke($handler, $error)
        );
        $app = \Yii::$app;
        $this->assertNotNull($app);
        $page = new \yii\web\ErrorAction('error', new \app\controllers\SiteController('site', $app));
        (new \ReflectionProperty($page, 'exception'))->setValue($page, self::integrityError());
        $this->assertSame('An internal server error occurred.', (new \ReflectionMethod($page, 'getExceptionMessage'))->invoke($page));
    }

    /**
     * Any other uncaught exception is logged as Yii logs it: the exception
     * itself, under its class.
     */
    public function testTheErrorHandlersLogAnyOtherExceptionAsBefore(): void
    {
        $error = new \RuntimeException('Disk full: ' . self::MARKER, 0, new \LogicException('cause'));

        foreach (['web.php', 'console.php'] as $file) {
            $logger = $this->capturingLogger();
            $previous = \Yii::getLogger();
            \Yii::setLogger($logger);
            try {
                $this->handler($file)->logException($error);
            } finally {
                \Yii::setLogger($previous);
            }

            $this->assertCount(1, $logger->messages, $file);
            $this->assertSame($error, $logger->messages[0][0], "{$file}: the exception itself, as Yii logs it");
            $this->assertSame(Logger::LEVEL_ERROR, $logger->messages[0][1]);
            $this->assertSame('RuntimeException', $logger->messages[0][2]);
        }
    }

    public function testTheQueueLogsAnyOtherErrorOfAJobAsBefore(): void
    {
        $error = new \RuntimeException('Disk full.');
        $event = $this->jobEvent($error);

        $lines = $this->loggedLines(fn () => $this->queue()->trigger(Queue::EVENT_AFTER_ERROR, $event));

        $this->assertCount(1, $lines);
        $this->assertStringContainsString(' is finished with error: RuntimeException: Disk full. in ' . __FILE__, $lines[0]);
        $this->assertSame($error, $event->error);
    }

    /**
     * An event without an error (yii2-queue always sets one) is logged as
     * yii2-queue logs it.
     */
    public function testTheQueueLogsAnEventWithoutAnErrorAsBefore(): void
    {
        $event = $this->jobEvent(null);

        $lines = $this->loggedLines(fn () => $this->queue()->trigger(Queue::EVENT_AFTER_ERROR, $event));

        $this->assertCount(1, $lines);
        $this->assertStringEndsWith(' (attempt: 2) is finished with error: .', $lines[0]);
        $this->assertNull($event->error);
    }

    /**
     * Without YII_DEBUG the console prints "Error: " and the message: for a
     * database error, how ExceptionLogText::brief() words it. Other errors
     * as before.
     */
    public function testTheConsolePrintsADatabaseErrorWithoutItsMessage(): void
    {
        $handler = new ConsoleErrorHandler();
        $wrapped = new \RuntimeException('Launch failed: ' . self::MARKER, 0, self::integrityError());

        $this->assertSame(
            "Error: yii\\db\\IntegrityException (SQLSTATE 23000, driver error 1062)\n",
            $this->printed(fn () => $this->render($handler, self::integrityError()))
        );
        $this->assertSame(
            'Error: RuntimeException ' . ExceptionLogText::WITHHELD
                . "; caused by yii\\db\\IntegrityException (SQLSTATE 23000, driver error 1062)\n",
            $this->printed(fn () => $this->render($handler, $wrapped))
        );
        $this->assertSame("Error: Disk full.\n", $this->printed(fn () => $this->render($handler, new \RuntimeException('Disk full.'))));
    }

    /**
     * With YII_DEBUG (a developer's machine) the console prints every error
     * as Yii does, in full, a database error with its message too: the
     * error goes to Yii unchanged. (The tests run without YII_DEBUG, so Yii
     * prints it in its short form here.)
     */
    public function testInDebugModeTheConsolePrintsTheErrorAsYiiDoes(): void
    {
        $handler = new class () extends ConsoleErrorHandler {
            protected function showsDetails(): bool
            {
                return true;
            }
        };
        $error = self::integrityError();

        $printed = $this->printed(fn () => $this->render($handler, $error));

        $this->assertSame('Error: ' . $error->getMessage() . "\n", $printed);
        $this->assertStringContainsString(self::MARKER, $printed);
    }

    public function testDebugModeIsYiiDebug(): void
    {
        $handler = new class () extends ConsoleErrorHandler {
            public function debug(): bool
            {
                return $this->showsDetails();
            }
        };

        $this->assertSame(YII_DEBUG, $handler->debug());
    }

    /**
     * An error while an error is handled: the handlers hand Yii's fallback
     * the exceptions as ExceptionLogText::redacted() gives them. Yii's
     * fallback ends the process; here the second one stops it before, when
     * it is turned into text.
     */
    public function testTheFallbackGetsTheExceptionsRedacted(): void
    {
        $stop = new class ('stop') extends \RuntimeException {
            public function __toString(): string
            {
                throw new \LogicException('Yii turned the second exception into text');
            }
        };
        foreach ([new WebErrorHandler(), new ConsoleErrorHandler()] as $handler) {
            try {
                (new \ReflectionMethod($handler, 'handleFallbackExceptionMessage'))->invoke($handler, self::integrityError(), $stop);
                $this->fail('expected the fallback to stop at the second exception');
            } catch (\Throwable $e) {
                $this->assertInstanceOf(\LogicException::class, $e);
                $this->assertSame('Yii turned the second exception into text', $e->getMessage());
            }
        }
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * The error handler of config/$file, as the application builds it.
     */
    private function handler(string $file): ErrorHandler
    {
        $definition = $this->component($file, 'errorHandler');
        $this->assertIsArray($definition);
        $this->assertIsString($definition['class'] ?? null);
        /** @var array{class: class-string} $definition */
        $handler = \Yii::createObject($definition);
        $this->assertInstanceOf(ErrorHandler::class, $handler);

        return $handler;
    }

    /**
     * A queue with the log behaviour of config/console.php. It is never
     * connected.
     */
    private function queue(): RedisQueue
    {
        $definition = $this->component('console.php', 'queue');
        $this->assertIsArray($definition);
        $behavior = $definition['as log'] ?? null;
        $this->assertIsString($behavior);
        /** @var class-string<\yii\base\Behavior<RedisQueue>> $behavior */
        $queue = new RedisQueue(['redis' => \Yii::$app->get('redis'), 'channel' => 'uncaught-log-test']);
        $queue->attachBehavior('log', $behavior);

        return $queue;
    }

    private function jobEvent(?\Throwable $error): ExecEvent
    {
        return new ExecEvent(['id' => '7', 'job' => new SyncProjectJob(['projectId' => 3]), 'ttr' => 60, 'attempt' => 2, 'error' => $error]);
    }

    /**
     * The definition of component $id in config/$file.
     */
    private function component(string $file, string $id): mixed
    {
        if (!isset(self::$configs[$file])) {
            self::$configs[$file] = require dirname(__DIR__, 3) . '/config/' . $file;
        }
        $config = self::$configs[$file];
        $this->assertIsArray($config);
        $components = $config['components'] ?? null;
        $this->assertIsArray($components);

        return $components[$id] ?? null;
    }

    /**
     * The error lines logged while $action ran, as a file target writes
     * them (without the prefix).
     *
     * @return list<string>
     */
    private function loggedLines(callable $action): array
    {
        $logger = $this->capturingLogger();
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $action();
        } finally {
            \Yii::setLogger($previous);
        }
        $target = new FileTarget(['logVars' => [], 'prefix' => static fn (): string => '']);
        $lines = [];
        foreach (array_merge($logger->flushed, $logger->messages) as $message) {
            if ($message[1] === Logger::LEVEL_ERROR) {
                $lines[] = $target->formatMessage($message);
            }
        }

        return $lines;
    }

    /**
     * A logger that keeps what it flushes: the queue's log behaviour flushes
     * after each job.
     *
     * @return Logger&object{flushed: list<array<int, mixed>>}
     */
    private function capturingLogger(): Logger
    {
        $logger = new class () extends Logger {
            /** @var list<array<int, mixed>> */
            public array $flushed = [];

            /**
             * @param bool $final
             */
            public function flush($final = false): void
            {
                foreach ($this->messages as $message) {
                    $this->flushed[] = $message;
                }
                $this->messages = [];
            }
        };
        $logger->flushInterval = PHP_INT_MAX;
        $logger->traceLevel = 0;

        return $logger;
    }

    /**
     * @param list<string> $lines
     */
    private function assertDatabaseErrorLogged(array $lines, string $category, string $expected): void
    {
        $this->assertCount(1, $lines, implode("\n", $lines));
        $this->assertStringContainsString("[error][{$category}] ", $lines[0]);
        $this->assertStringContainsString($expected, $lines[0]);
        $this->assertStringContainsString("\nStack trace:\n#0 ", $lines[0]);
        $this->assertNoMessageOf($lines[0]);
    }

    private function assertNoMessageOf(string $line): void
    {
        $this->assertStringNotContainsString(self::MARKER, $line);
        $this->assertStringNotContainsString('SQL being executed', $line);
        $this->assertStringNotContainsString('Additional Information', $line);
        $this->assertStringNotContainsString('Redis command was', $line);
    }

    private function render(ConsoleErrorHandler $handler, \Throwable $error): void
    {
        (new \ReflectionMethod($handler, 'renderException'))->invoke($handler, $error);
    }

    /**
     * What $action wrote to STDERR, without colours. It does not reach the
     * test output.
     */
    private function printed(callable $action): string
    {
        $filter = CapturedStderr::start();
        $this->assertIsResource($filter);
        try {
            $action();
        } finally {
            stream_filter_remove($filter);
        }

        return Console::stripAnsiFormat(CapturedStderr::$text);
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
