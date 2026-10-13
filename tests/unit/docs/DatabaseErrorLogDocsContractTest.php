<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\components\ConsoleErrorHandler;
use app\components\ErrorText;
use app\components\ExceptionLogText;
use app\components\QueueLogBehavior;
use app\components\RedactingFileTarget;
use app\components\WebErrorHandler;
use app\controllers\api\v1\WorkflowTemplatesController;
use app\controllers\WorkflowTemplateController;
use PHPUnit\Framework\TestCase;
use yii\db\Exception as DbException;
use yii\log\Logger;
use yii\redis\SocketException;

/**
 * What the operator docs say about how database errors are named in logs
 * and answers is what the code writes: a raw PDOException with its
 * SQLSTATE and driver error code, an uncaught error whose chain holds a
 * database error as the error handlers and the queue's log behaviour write
 * it, and a workflow launch that fails on a database error.
 *
 * Regressions from wave 9 of step 3, each test fails on the docs as they
 * were before it: a database error that escaped Yii's wrapping was named
 * without its codes; the error handlers of the web UI, the API and the
 * console and the queue's log behaviour wrote an uncaught database error
 * with its message, the SQL statement with its values; and the API's
 * workflow launch answered a database error as a refused launch (422).
 * No doc said any of it.
 *
 * Regression from wave 10 of step 3: the framework's own Yii::error($e),
 * such as a failed rollback, wrote a database error with its message; every
 * log target now writes it by its summary, and the docs say so. (The debug
 * mode of the console and the migrations: ConsoleErrorOutputDocsContractTest.)
 */
class DatabaseErrorLogDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    private const UPDATE_SECTION = '## After updating from 2.9.0 or older: approvals and workflows';

    private const SERVER_ERROR_ENTRY = '## A page or an API call fails with a server error';

    private const LAUNCH_ENTRY = '## Workflow launch refused';

    /** The statement a database error's message would carry, with a value. */
    private const STATEMENT = "The SQL being executed was: UPDATE job SET extra_vars = '{\"db_password\": \"docs-secret\"}'";

    /**
     * Regression (wave 9 of step 3): a PDOException that escaped Yii's
     * wrapping, such as a failed COMMIT, read "PDOException (SQLSTATE -,
     * driver error -)"; it is named with the codes PDO filled in, as the
     * docs quote it. One that PDO raised itself has none.
     */
    public function testARawPdoExceptionIsNamedWithItsCodes(): void
    {
        $named = ErrorText::of(self::lostConnection());
        $this->assertSame('PDOException (SQLSTATE HY000, driver error 2006)', $named);
        $unnamed = ErrorText::of(new \PDOException('There is already an active transaction'));
        $this->assertSame('PDOException (SQLSTATE -, driver error -)', $unnamed);

        $this->assertMentions(
            '- A database error that escaped Yii\'s wrapping (`PDOException`), such as a failed `BEGIN` or `COMMIT`, now shows its SQLSTATE and driver error code wherever a database error is named, for example `'
                . $named . '`; it read `' . $unnamed . '` before. One that PDO raised itself, such as a transaction that is already active, has no codes and still reads so.',
            $this->flat('docs/updating.md', self::UPDATE_SECTION),
            'The fixes of updating.md'
        );
    }

    /**
     * Regression (wave 9 of step 3): the error handlers and the queue's log
     * behaviour write a chain that holds a database error as the docs quote
     * it: the database error by its summary (with the driver's text for a
     * failed connection), an error that wraps it by its class only, never
     * the statement; the console prints one line.
     */
    public function testUncaughtDatabaseErrorsAreDocumentedAsTheHandlersWriteThem(): void
    {
        $deadlock = self::deadlock();
        $wrapped = new \RuntimeException('Job launch failed: ' . $deadlock->getMessage(), 0, $deadlock);
        $logged = ExceptionLogText::of($wrapped);
        $this->assertStringStartsWith(ErrorText::of($deadlock) . ' in ', $logged, 'The innermost error comes first, by its summary.');
        $this->assertStringContainsString("\n\nNext RuntimeException " . ExceptionLogText::WITHHELD . ' in ', $logged);
        $this->assertStringNotContainsString('docs-secret', $logged);
        $this->assertStringNotContainsString('SQLSTATE[', $logged);
        $printed = ExceptionLogText::brief($wrapped);
        $refused = new DbException('SQLSTATE[HY000] [2002] Connection refused', ['HY000', 2002, 'Connection refused']);
        $redis = new SocketException("Failed to read from socket.\nRedis command was: SET session docs-secret");

        $quotes = [
            'the deadlock' => '`' . ErrorText::of($deadlock) . '`',
            'the withheld message' => '`' . ExceptionLogText::WITHHELD . '`',
            'the failed connection' => '`' . ExceptionLogText::brief($refused) . '`',
            'the console line' => '`Error: ' . $printed . '`',
        ];
        $this->assertSame('yii\db\Exception (SQLSTATE HY000, driver error 2002): Connection refused', ExceptionLogText::brief($refused));
        $this->assertSame(sprintf(ExceptionLogText::CAUSED_BY, 'RuntimeException ' . ExceptionLogText::WITHHELD, ErrorText::of($deadlock)), $printed);
        $updating = $this->updateUnit('- Errors that nothing in Ansilume catches are written to the application log by the framework');
        $entry = $this->flat('docs/troubleshooting.md', self::SERVER_ERROR_ENTRY);
        foreach ($quotes as $what => $quote) {
            $this->assertMentions($quote, $updating, "updating.md, on {$what},");
            $this->assertMentions($quote, $entry, "troubleshooting.md, on {$what},");
        }
        $this->assertMentions('`' . ExceptionLogText::brief($redis) . '`: the command and its values are left out', $updating, 'updating.md');
        $this->assertStringNotContainsString('docs-secret', ExceptionLogText::brief($redis));
        $this->assertMentions(
            'by its error handler in the web UI, the API and the console commands, and by the queue-worker for a queue job that failed',
            $updating,
            'updating.md'
        );
        $this->assertMentions('[A page or an API call fails with a server error](troubleshooting.md#a-page-or-an-api-call-fails-with-a-server-error)', $updating, 'updating.md');
        $this->assertMentions('Before the update to approvals and workflows the log and the one line held the statement with its values', $entry, 'troubleshooting.md');

        // Where the docs say the handlers are: every application config wires them.
        $configs = [
            'config/web.php' => WebErrorHandler::class,
            'config/console.php' => ConsoleErrorHandler::class,
            'config/test.php' => ConsoleErrorHandler::class,
        ];
        foreach ($configs as $file => $class) {
            $this->assertStringContainsString('\\' . $class . '::class', $this->code($file), "{$file} uses {$class}.");
        }
        $this->assertStringContainsString("'as log' => \\" . QueueLogBehavior::class . '::class', $this->code('config/console.php'));
        // The console prints "Error: " and the message without debug mode (Yii).
        $this->assertStringContainsString(
            "\$message = \$this->formatMessage('Error: ') . \$exception->getMessage();",
            $this->code('vendor/yiisoft/yii2/console/ErrorHandler.php')
        );
    }

    /**
     * Regression (wave 9 of step 3): a database error of a workflow launch
     * is no refusal: the API answers 500 with its summary and logs it, the
     * web UI names it by its summary after "Launch failed: ".
     */
    public function testTheWorkflowLaunchThatFailsOnADatabaseErrorIsDocumented(): void
    {
        $logged = '`' . sprintf(WorkflowTemplatesController::LAUNCH_FAILED_LOG, 5, ErrorText::of(self::deadlock())) . '`';
        $flash = '`' . sprintf(WorkflowTemplateController::FLASH_LAUNCH_FAILED, ErrorText::of(self::lostConnection())) . '`';
        $updating = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        $this->assertMentions(
            'A database error is no refusal: the launch answers it with `500` and the error\'s class, SQLSTATE and driver error code, such as `'
                . ErrorText::of(self::deadlock()) . '`, and the application log has ' . $logged . '. It answered `422` with the error\'s message for one that escaped Yii\'s wrapping, and a bare server error for any other.',
            $updating,
            'The API contract change of updating.md'
        );
        $this->assertMentions('for the message of a job or workflow launch that failed on such an error, such as ' . $flash . ' on the page of a workflow', $updating, 'updating.md');
        $entry = $this->flat('docs/troubleshooting.md', self::LAUNCH_ENTRY);
        $this->assertMentions('A database error is no refusal. The page shows `Launch failed: ` with the error\'s class, SQLSTATE and driver error code when the error escaped Yii\'s wrapping, such as ' . $flash, $entry, 'troubleshooting.md');
        $this->assertMentions('`POST /api/v1/workflow-templates/{id}/launch` answers `500` with the error\'s class and codes', $entry, 'troubleshooting.md');
        $this->assertMentions('and the application log has ' . $logged, $entry, 'troubleshooting.md');
        $this->assertMentions('No run was created, unless the error struck while the first step started: then the run has ended as failed.', $entry, 'troubleshooting.md');
    }

    /**
     * CLAUDE.md names the error handlers and the queue's log behaviour in
     * its logging rule, and the README says what the logs leave out.
     */
    public function testClaudeMdNamesTheHandlersOfUncaughtErrors(): void
    {
        $claude = $this->flat('CLAUDE.md', '## Logging and Observability');
        foreach ([WebErrorHandler::class, ConsoleErrorHandler::class, QueueLogBehavior::class, ExceptionLogText::class] as $class) {
            $this->assertTrue(class_exists($class), "{$class} exists.");
            $this->assertMentions('`' . $class . '`', $claude, 'The logging rule of CLAUDE.md');
        }
        $this->assertMentions('Errors that nothing catches are logged by `' . WebErrorHandler::class . '`', $claude, 'The logging rule of CLAUDE.md');
        $this->assertMentions('never log an exception that can be a database error as an object or as its string form yourself (`Yii::error($e)`, `(string)$e`)', $claude, 'The logging rule of CLAUDE.md');
        $this->assertMentions(
            'the application log names database errors, also those that nothing catches and those that the framework logs itself, by their class, SQLSTATE and driver error code, never with their statement',
            $this->flat('README.md'),
            'README.md'
        );
    }

    /**
     * Regression (wave 10 of step 3): an error that the framework logs as an
     * object itself, such as yii\db\Connection's rollback that failed while
     * another error was handled, was written with its message. Every log
     * target writes it as the docs say: by its summary, without the
     * statement. updating.md, the README (above) and CLAUDE.md say so.
     */
    public function testRegressionAnErrorTheFrameworkLogsItselfIsDocumentedAsWritten(): void
    {
        $rollback = new DbException(
            "SQLSTATE[42000]: Syntax error or access violation: 1305 SAVEPOINT LEVEL1 does not exist\nThe SQL being executed was: ROLLBACK TO SAVEPOINT LEVEL1",
            ['42000', 1305, 'SAVEPOINT LEVEL1 does not exist']
        );
        $written = (new RedactingFileTarget())->formatMessage([$rollback, Logger::LEVEL_ERROR, 'yii\db\Connection::rollbackTransactionOnLevel', 1791843740.0, []]);
        $this->assertStringContainsString('[yii\db\Connection::rollbackTransactionOnLevel] ' . ErrorText::of($rollback) . ' in ', $written);
        $this->assertStringNotContainsString('ROLLBACK TO SAVEPOINT', $written);
        $this->assertStringNotContainsString('does not exist', $written);
        foreach (['config/web.php', 'config/console.php', 'config/test.php'] as $file) {
            $this->assertStringContainsString("'class' => \\" . RedactingFileTarget::class . '::class', $this->code($file), "{$file} logs through it.");
        }

        $this->assertMentions(
            '- An error that the framework writes to the application log itself, such as a rollback that failed while another error was handled, is written the same way when its chain holds a database error: by the error\'s class, SQLSTATE and driver error code, never with a message that can hold a statement or a Redis command with its values. Such an entry had the error\'s message, for a failed rollback the `ROLLBACK` statement or the driver\'s text.',
            $this->flat('docs/updating.md', self::UPDATE_SECTION),
            'The fixes of updating.md'
        );
        $this->assertMentions(
            'Every log target is `' . RedactingFileTarget::class . '` (`config/web.php`, `config/console.php`, `config/test.php`), which writes an exception logged as an object whose chain holds a database error as `ExceptionLogText` gives it',
            $this->flat('CLAUDE.md', '## Logging and Observability'),
            'The logging rule of CLAUDE.md'
        );
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

    private function code(string $file): string
    {
        $code = file_get_contents(dirname(__DIR__, 3) . '/' . $file);
        $this->assertIsString($code, "{$file} cannot be read.");

        return $code;
    }

    /** A deadlock whose message quotes its statement with a value. */
    private static function deadlock(): DbException
    {
        return new DbException("SQLSTATE[40001]: Serialization failure: 1213 Deadlock found\n" . self::STATEMENT, ['40001', 1213, 'Deadlock found when trying to get lock']);
    }

    /** A lost connection that escaped Yii's wrapping, as PDO fills it. */
    private static function lostConnection(): \PDOException
    {
        $lost = new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        $lost->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];

        return $lost;
    }
}
