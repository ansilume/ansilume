<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\components\QueueLogBehavior;
use app\jobs\SyncProjectJob;
use app\services\MaintenanceService;
use app\services\ProjectService;
use app\tests\integration\commands\WorkerJobDbConnectionTest;
use app\tests\unit\docker\IdleDatabaseConnectionTest;
use PHPUnit\Framework\TestCase;
use yii\db\Exception as DbException;
use yii\log\Logger;
use yii\queue\ExecEvent;
use yii\queue\Queue;
use yii\queue\sync\Queue as SyncQueue;

/**
 * What the operator docs and CLAUDE.md say about the database connection
 * of the processes that keep running is what they do: none keeps one while
 * it waits, the queue-worker runs each project sync on one of its own, and
 * a sync that failed on the connection the database had closed left the
 * line that the queue's log behaviour writes.
 *
 * Regression: the queue-worker kept its connection for its whole life, so
 * that after a night without a project sync every sync failed with 2006 or
 * 4031 until it restarted, and troubleshooting.md told operators that the
 * database's wait_timeout "must be longer than the workers sit idle", which
 * a queue that sits idle overnight cannot meet. Each test fails on the docs
 * as they were before the fix.
 */
final class WorkerDbConnectionDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    private const TROUBLESHOOTING = 'docs/troubleshooting.md';

    private const SERVER_ERROR_ENTRY = '## A page or an API call fails with a server error';

    private const UPDATING = 'docs/updating.md';

    private const UPDATE_SECTION = '## After updating from 2.13.1 or older';

    private const FIXES = '**Fixes that change behaviour:**';

    /** What follows the list of fixes in the update section. */
    private const AFTER_FIXES = '**The web UI and the API log their errors again after an update.**';

    /**
     * The entry says which process opens a connection when, what the log of
     * a sync up to 2.13.1 showed, that nothing needs a restart, and how long
     * the database's wait_timeout needs to be; no longer that it must
     * outlast the time the queue sits idle.
     */
    public function testTheServerErrorEntrySaysThatNoProcessKeepsAConnectionWhileItWaits(): void
    {
        $entry = $this->flat(self::TROUBLESHOOTING, self::SERVER_ERROR_ENTRY);

        foreach (
            [
                'No process of Ansilume keeps a database connection while it waits: php-fpm opens one for each request,'
                    . ' the schedule-runner one for each run of `schedule/run` and of `maintenance/run`, and the queue-worker'
                    . ' one for each project sync, which it closes when the sync ends; the runners have none.',
                'Up to 2.13.1 the queue-worker kept its connection for its whole life: once the queue had sat idle longer'
                    . ' than the database\'s `wait_timeout` (28800 seconds, 8 hours, by default on MariaDB and MySQL), as'
                    . ' overnight, the database had closed it, and every project sync of the server failed until the'
                    . ' queue-worker restarted',
                '`' . self::lostConnectionLine() . '` or `4031` in its log',
                'the project showed `syncing` until the maintenance run gave up on the sync after '
                    . self::staleSyncMinutes() . ' minutes.',
                'The next page, run or project sync opens a new connection, so no container, the queue-worker included,'
                    . ' needs a restart for it.',
                'The `wait_timeout` and `interactive_timeout` of the database need to be longer than a page or a project'
                    . ' sync takes (a git command of a sync may take ' . self::gitTimeoutSeconds() . ' seconds), not than'
                    . ' the queue sits idle: keep their default of 28800 seconds.',
            ] as $needle
        ) {
            $this->assertMentions($needle, $entry, self::SERVER_ERROR_ENTRY);
        }
        $this->assertStringNotContainsString('longer than the workers sit idle', $entry, 'A queue that sits idle overnight outlasts any wait_timeout.');
    }

    /**
     * The fix is one of the fixes that change behaviour of the newest update
     * section, with the log line and what a wait_timeout of the operator's
     * own needs to outlast now.
     */
    public function testTheUpdateNotesListTheFixAmongTheFixesThatChangeBehaviour(): void
    {
        $fixes = $this->between($this->flat(self::UPDATING, self::UPDATE_SECTION), self::FIXES, self::AFTER_FIXES);

        foreach (
            [
                '- The queue-worker runs each project sync on a database connection of its own and closes it when the'
                    . ' sync ends. Up to 2.13.1 it kept its connection for its whole life: once the queue had sat idle'
                    . ' longer than the database\'s `wait_timeout` (28800 seconds, 8 hours, by default on MariaDB and'
                    . ' MySQL), as overnight, the database had closed it, and every project sync of the server failed'
                    . ' until the queue-worker restarted, with',
                '`' . self::lostConnectionLine() . '` or 4031 in its log, while the project showed `syncing` until the'
                    . ' maintenance run gave up on the sync after ' . self::staleSyncMinutes() . ' minutes.',
                'A `wait_timeout` of your own needs to outlast a page or a project sync, not the time the queue sits idle'
                    . ' (see [A page or an API call fails with a server error](troubleshooting.md#a-page-or-an-api-call-fails-with-a-server-error)).',
            ] as $needle
        ) {
            $this->assertMentions($needle, $fixes, 'The fixes that change behaviour of ' . self::UPDATE_SECTION);
        }
    }

    /**
     * CLAUDE.md has the rule, and the guards it names are there.
     */
    public function testClaudeMdHasTheRuleWithItsGuards(): void
    {
        $database = $this->flat('CLAUDE.md', '### Database');

        foreach (
            [
                '- A process that keeps running holds no database connection while it waits: the database closes a'
                    . ' connection that sat idle longer than its `wait_timeout` (28800 seconds by default), and every query'
                    . ' on it then fails with 2006 or 4031.',
                'The queue worker (`worker/start`), in whose process yii2-queue runs every job, closes the application\'s'
                    . ' connection before and after each queue job (`WorkerController::attachWorkerEvents()`, `WorkerJobDbConnectionTest`)',
                '`tests/unit/docker/IdleDatabaseConnectionTest.php` lists every process that the compose files and the'
                    . ' deploy role keep running with what it does with its connection (`PROCESSES`), and a new one, or'
                    . ' another command for one, goes on it with its reason.',
            ] as $needle
        ) {
            $this->assertMentions($needle, $database, 'CLAUDE.md, ### Database');
        }
        $this->assertTrue(class_exists(WorkerJobDbConnectionTest::class), 'the regression test CLAUDE.md names');
        $this->assertTrue(class_exists(IdleDatabaseConnectionTest::class), 'the guard CLAUDE.md names');
    }

    /**
     * The start of the line that the queue's log behaviour writes for a
     * project sync whose first query found the connection closed, as in the
     * queue-worker (PID 1): the database error by its innermost link, the
     * PDOException, with the driver's text of a lost connection.
     */
    private static function lostConnectionLine(): string
    {
        $lost = new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        $lost->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
        $error = new DbException(
            "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away\nThe SQL being executed was: SELECT * FROM `project` WHERE `id`=4",
            $lost->errorInfo,
            'HY000',
            $lost
        );
        $queue = new class () extends SyncQueue {
            public function getWorkerPid(): int
            {
                return 1;
            }
        };
        $queue->attachBehavior('log', QueueLogBehavior::class);
        $logger = new class () extends Logger {
            /** @var list<string> */
            public array $errors = [];

            public function flush($final = false): void
            {
                foreach ($this->messages as $message) {
                    if ($message[1] === Logger::LEVEL_ERROR && is_string($message[0])) {
                        $this->errors[] = $message[0];
                    }
                }
                $this->messages = [];
            }
        };
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $queue->trigger(Queue::EVENT_AFTER_ERROR, new ExecEvent([
                'id' => '4',
                'job' => new SyncProjectJob(['projectId' => 4]),
                'ttr' => 3600,
                'attempt' => 1,
                'error' => $error,
            ]));
            $logger->flush();
        } finally {
            \Yii::setLogger($previous);
        }
        self::assertCount(1, $logger->errors);
        $line = $logger->errors[0];
        self::assertStringNotContainsString('SELECT', $line, 'The log leaves the statement out.');
        $start = strpos($line, ' in /');
        self::assertIsInt($start, $line);

        return substr($line, 0, $start);
    }

    /** How long the maintenance run waits before it gives up on a sync, in minutes. */
    private static function staleSyncMinutes(): int
    {
        return intdiv((int)get_class_vars(MaintenanceService::class)['staleSyncThresholdSeconds'], 60);
    }

    /** How long a git command of a sync may take. */
    private static function gitTimeoutSeconds(): int
    {
        return (int)get_class_vars(ProjectService::class)['gitTimeoutSeconds'];
    }
}
