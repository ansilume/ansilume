<?php

declare(strict_types=1);

namespace app\tests\integration\components;

use PHPUnit\Framework\TestCase;

/**
 * An uncaught database error in a console command, in a child PHP process
 * that builds the console application from config/console.php as `php yii`
 * does (production mode: YII_DEBUG off, YII_ENV prod), with the log file
 * moved to a temporary file:
 * - the application log names the error by class, SQLSTATE and driver error
 *   code, with file:line and trace, never with its message;
 * - STDERR, which the schedule-runner and queue-worker containers send to
 *   the Docker log, says "Error: " and the same summary;
 * - an error while the error is handled ends in Yii's fallback, which
 *   writes both exceptions to PHP's error log (here a temporary file): the
 *   database error appears there as summary, file:line and trace too.
 * The process ends with exit code 1, as Yii's handler ends it.
 *
 * Regression: an uncaught database error was logged with its SQL (console).
 */
class ConsoleErrorHandlerProcessTest extends TestCase
{
    private const MARKER = 'child-marker-6620';

    /**
     * Arguments: the repository root, the config file, the log file, PHP's
     * error log, and "fallback" to make the error output itself fail. It
     * throws a database error as Yii raises one for a failed INSERT, whose
     * driver message quotes the value and whose message names the statement.
     */
    private const PROBE = <<<'PHP'
        <?php

        declare(strict_types=1);

        [, $root, $configFile, $logFile, $phpErrorLog, $mode] = $argv;
        define('YII_DEBUG', false);
        define('YII_ENV', 'prod');
        require $root . '/vendor/autoload.php';
        \Dotenv\Dotenv::createUnsafeImmutable($root)->safeLoad();
        require $root . '/vendor/yiisoft/yii2/Yii.php';
        ini_set('error_log', $phpErrorLog);

        $config = require $configFile;
        $config['components']['log']['targets'][0]['logFile'] = $logFile;
        if ($mode === 'fallback') {
            // The configured handler, whose error output fails, so that
            // Yii's handler falls back.
            class_alias($config['components']['errorHandler']['class'] ?? \yii\console\ErrorHandler::class, 'ConfiguredErrorHandler');
            final class FailingOutputErrorHandler extends ConfiguredErrorHandler
            {
                protected function renderException($exception): void
                {
                    throw new \RuntimeException('The error output failed.');
                }
            }
            $config['components']['errorHandler']['class'] = FailingOutputErrorHandler::class;
        }
        new yii\console\Application($config);

        $pdo = new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'child-marker-6620' for key 'name'");
        $pdo->errorInfo = ['23000', 1062, "Duplicate entry 'child-marker-6620' for key 'name'"];
        throw new \yii\db\IntegrityException(
            $pdo->getMessage() . "\nThe SQL being executed was: INSERT INTO `job` (`name`) VALUES ('child-marker-6620')",
            $pdo->errorInfo,
            '23000',
            $pdo
        );

        PHP;

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        $this->files = [];
        parent::tearDown();
    }

    public function testAnUncaughtDatabaseErrorIsLoggedAndPrintedWithoutItsMessage(): void
    {
        $run = $this->runProbe('uncaught');

        $this->assertSame(1, $run['exit'], $run['stderr']);
        $this->assertSame("Error: yii\\db\\IntegrityException (SQLSTATE 23000, driver error 1062)\n", $run['stderr']);
        $this->assertStringContainsString(
            '[error][yii\db\IntegrityException] PDOException (SQLSTATE 23000, driver error 1062) in ',
            $run['log']
        );
        $this->assertStringContainsString(
            "\n\nNext yii\\db\\IntegrityException (SQLSTATE 23000, driver error 1062) in ",
            $run['log']
        );
        $this->assertStringContainsString("\nStack trace:\n#0 ", $run['log']);
        $this->assertNoMessage($run);
    }

    /**
     * Yii's fallback writes both exceptions to PHP's error log; the
     * database error that was handled appears there without its message.
     */
    public function testTheFallbackWritesTheDatabaseErrorWithoutItsMessage(): void
    {
        $run = $this->runProbe('fallback');

        $this->assertSame(1, $run['exit'], $run['stderr']);
        $this->assertSame('An internal server error occurred.', $run['stdout']);
        $this->assertStringContainsString("An Error occurred while handling another error:\nRuntimeException: The error output failed. in ", $run['phpErrors']);
        $this->assertStringContainsString("\nPrevious exception:\nPDOException (SQLSTATE 23000, driver error 1062) in ", $run['phpErrors']);
        $this->assertStringContainsString("\n\nNext yii\\db\\IntegrityException (SQLSTATE 23000, driver error 1062) in ", $run['phpErrors']);
        $this->assertStringContainsString(
            '[error][yii\db\IntegrityException] PDOException (SQLSTATE 23000, driver error 1062) in ',
            $run['log'],
            'the error was logged before the output failed'
        );
        $this->assertNoMessage($run);
    }

    /**
     * Runs the probe with config/console.php.
     *
     * @return array{exit: int, stdout: string, stderr: string, log: string, phpErrors: string}
     */
    private function runProbe(string $mode): array
    {
        $root = dirname(__DIR__, 3);
        $probe = $this->temporaryFile('probe');
        $log = $this->temporaryFile('log');
        $phpErrors = $this->temporaryFile('php-errors');
        file_put_contents($probe, self::PROBE);

        $process = proc_open(
            [PHP_BINARY, $probe, $root, $root . '/config/console.php', $log, $phpErrors, $mode],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            getenv()
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [
            'exit' => $exit,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'log' => file_exists($log) ? (string)file_get_contents($log) : '',
            'phpErrors' => file_exists($phpErrors) ? (string)file_get_contents($phpErrors) : '',
        ];
    }

    /**
     * @param array{exit: int, stdout: string, stderr: string, log: string, phpErrors: string} $run
     */
    private function assertNoMessage(array $run): void
    {
        foreach (['stdout', 'stderr', 'log', 'phpErrors'] as $output) {
            $this->assertStringNotContainsString(self::MARKER, $run[$output], $output);
            $this->assertStringNotContainsString('SQL being executed', $run[$output], $output);
            $this->assertStringNotContainsString('Duplicate entry', $run[$output], $output);
        }
    }

    private function temporaryFile(string $kind): string
    {
        $file = tempnam(sys_get_temp_dir(), "ansilume-console-error-{$kind}-");
        $this->assertIsString($file);
        unlink($file);
        $file .= $kind === 'probe' ? '.php' : '.log';
        $this->files[] = $file;

        return $file;
    }
}
