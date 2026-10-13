<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\components\ExceptionLogText;
use app\tests\unit\docs\ReadsOperatorDocs;
use PHPUnit\Framework\TestCase;
use yii\db\Exception as DbException;
use yii\db\Migration;

/**
 * What the operator docs say a console command prints for an uncaught
 * database error, and what a migration that fails prints, is what the code
 * does: a console command run as `php yii` runs it (config/console.php, in
 * a child PHP process), and a migration run by Yii's migration runner.
 *
 * Regressions from wave 10 of step 3, each test fails on the docs as they
 * were before it: the docs said that a console command prints such an error
 * as "Error: " and one line, also in debug mode, where Yii prints the whole
 * error with its message; and no doc said that a migration that fails can
 * print its database error with its message, the SQL statement with its
 * values, to the app container's output, which the application log's
 * redaction does not reach.
 */
class ConsoleErrorOutputDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    private const UPDATE_SECTION = '## After updating from 2.9.0 or older: approvals and workflows';

    private const SERVER_ERROR_ENTRY = '## A page or an API call fails with a server error';

    private const MIGRATIONS_ENTRY = '### Database migrations';

    /** A value in the statement of the database error; never printed without debug mode. */
    private const MARKER = 'docs-console-marker-5521';

    /**
     * Arguments: the repository root, the log file, "debug" or "production",
     * and the marker. It builds the console application from
     * config/console.php as `php yii` does and throws an error that wraps a
     * deadlock whose message names its statement with the marker.
     */
    private const PROBE = <<<'PHP'
        <?php

        declare(strict_types=1);

        [, $root, $logFile, $mode, $marker] = $argv;
        define('YII_DEBUG', $mode === 'debug');
        define('YII_ENV', 'prod');
        require $root . '/vendor/autoload.php';
        \Dotenv\Dotenv::createUnsafeImmutable($root)->safeLoad();
        require $root . '/vendor/yiisoft/yii2/Yii.php';

        $config = require $root . '/config/console.php';
        $config['components']['log']['targets'][0]['logFile'] = $logFile;
        new yii\console\Application($config);

        $deadlock = new \yii\db\Exception(
            "SQLSTATE[40001]: Serialization failure: 1213 Deadlock found\nThe SQL being executed was: UPDATE `job` SET `note` = '" . $marker . "'",
            ['40001', 1213, 'Deadlock found when trying to get lock']
        );
        throw new \RuntimeException('Job launch failed: ' . $deadlock->getMessage(), 0, $deadlock);

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

    /**
     * Regression (wave 10 of step 3): only without debug mode does a console
     * command print an uncaught database error as "Error: " and one line;
     * with debug mode it prints the whole error, the statement with its
     * values included. The application log leaves the message out in both
     * modes. The docs say so where they quote the line.
     */
    public function testRegressionTheOneLineFormIsDocumentedForRunsWithoutDebugMode(): void
    {
        $deadlock = new DbException('Deadlock', ['40001', 1213, 'Deadlock found when trying to get lock']);
        $line = 'Error: ' . ExceptionLogText::brief(new \RuntimeException('Job launch failed', 0, $deadlock));
        $production = $this->runProbe('production');
        $debug = $this->runProbe('debug');

        $this->assertSame($line . "\n", $production['stderr'], 'Without debug mode: one line.');
        $this->assertStringContainsString(self::MARKER, $debug['stderr'], 'With debug mode: the message and its statement.');
        $this->assertStringContainsString('Caused by: ', $debug['stderr'], 'With debug mode: the whole chain.');
        foreach (['production' => $production, 'debug' => $debug] as $mode => $run) {
            $this->assertSame(1, $run['exit'], $mode);
            $this->assertStringContainsString('yii\db\Exception (SQLSTATE 40001, driver error 1213) in ', $run['log'], "{$mode}: the log names the error.");
            $this->assertStringNotContainsString(self::MARKER, $run['log'], "{$mode}: the log leaves the message out.");
        }

        $this->assertMentions(
            'Without debug mode (`YII_DEBUG`), the console commands print such an error as `Error: ` and one line, for example `' . $line . '`, which ends up in `docker compose logs` of the container that ran the command, such as the schedule-runner; with debug mode they print the whole error as before, its message with the statement and its values included, while the application log leaves the message out in both modes.',
            $this->updateUnit('- Errors that nothing in Ansilume catches are written to the application log by the framework'),
            'The fixes of updating.md'
        );
        $entry = $this->flat('docs/troubleshooting.md', self::SERVER_ERROR_ENTRY);
        $this->assertMentions('or a console command stops with `Error: ` and one line (with debug mode, with the whole error)', $entry, 'The symptom in troubleshooting.md');
        $this->assertMentions(
            'Without debug mode (`YII_DEBUG`), a console command prints such an error as one line, such as `' . $line . '`; with debug mode it prints the whole error, its message with the statement and its values included, while the application log leaves the message out in both modes.',
            $entry,
            'The cause in troubleshooting.md'
        );
        $this->assertMentions(
            'Debug mode (`YII_DEBUG`) shows the messages of errors on the error page, in the API\'s answers and in what console commands print, where they can hold values: never turn it on in production',
            $entry,
            'The fix in troubleshooting.md'
        );
    }

    /**
     * Regression (wave 10 of step 3): Yii's migration runner prints the
     * database error of a migration that fails with its message, the
     * statement with its values, to the output (the app container's when
     * it migrates at start), which no log target of Ansilume redacts, and
     * Ansilume runs Yii's migration command. The docs name the limit where
     * the update's limits and the failing migrations are.
     */
    public function testRegressionAFailingMigrationPrintsItsStatementToTheOutput(): void
    {
        $error = "SQLSTATE[40001]: Serialization failure: 1213 Deadlock found\nThe SQL being executed was: UPDATE `job` SET `note` = '" . self::MARKER . "'";
        $migration = new class (['db' => \Yii::$app->db, 'compact' => true, 'error' => $error]) extends Migration {
            /** The message of the database error the migration fails with. */
            public string $error = '';

            public function safeUp(): void
            {
                throw new DbException($this->error, ['40001', 1213, 'Deadlock found when trying to get lock']);
            }
        };
        ob_start();
        try {
            $result = $migration->up();
        } finally {
            $printed = (string)ob_get_clean();
        }

        $this->assertFalse($result, 'The migration failed.');
        $this->assertStringContainsString('Exception: ' . $error . ' (', $printed, 'The message with the statement and its values.');
        $config = require dirname(__DIR__, 3) . '/config/console.php';
        $this->assertIsArray($config);
        $this->assertArrayNotHasKey('controllerMap', $config, 'php yii migrate is Yii\'s migration command.');

        $this->assertMentions(
            '- A migration that fails can print its database error with the message, and so with the SQL statement and its values, and its stack trace to the output of the app container (`docker compose logs app`): the framework\'s migration runner prints it there, not to the application log, which leaves such a message out. Read that output before you share it, also as part of what `bin/diagnose` prints',
            $this->flat('docs/updating.md', self::UPDATE_SECTION),
            'The known limits of updating.md'
        );
        $this->assertMentions(
            'A migration that fails can print its database error with the message, which holds the SQL statement with its values: when the app container starts, to `docker compose logs app`, and in a manual run, to your terminal, but not to the application log. Read that output before you share it',
            $this->flat('docs/troubleshooting.md', self::MIGRATIONS_ENTRY),
            'The failing migrations of troubleshooting.md'
        );
    }

    /**
     * Runs the probe with config/console.php in debug or production mode.
     *
     * @return array{exit: int, stderr: string, log: string}
     */
    private function runProbe(string $mode): array
    {
        $root = dirname(__DIR__, 3);
        $probe = $this->temporaryFile('probe', '.php');
        $log = $this->temporaryFile('log', '.log');
        file_put_contents($probe, self::PROBE);

        $process = proc_open(
            [PHP_BINARY, $probe, $root, $log, $mode, self::MARKER],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            getenv()
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [
            'exit' => $exit,
            'stderr' => $stderr,
            'log' => file_exists($log) ? (string)file_get_contents($log) : '',
        ];
    }

    /**
     * The unit of updating.md's section that starts with $start (a list item
     * or paragraph), on one line.
     */
    private function updateUnit(string $start): string
    {
        foreach ($this->units('docs/updating.md', self::UPDATE_SECTION) as $unit) {
            if (str_starts_with($unit, $start)) {
                return $unit;
            }
        }
        $this->fail("updating.md has no paragraph that starts with: {$start}");
    }

    private function temporaryFile(string $kind, string $suffix): string
    {
        $file = tempnam(sys_get_temp_dir(), "ansilume-docs-console-{$kind}-");
        $this->assertIsString($file);
        unlink($file);
        $file .= $suffix;
        $this->files[] = $file;

        return $file;
    }
}
