<?php

declare(strict_types=1);

namespace app\tests\integration\jobs;

use app\jobs\SyncProjectJob;
use app\models\JobTemplate;
use app\models\Project;
use app\tests\integration\DbTestCase;
use yii\log\Logger;

/**
 * The three lines SyncProjectJob logs about a failed sync, vault scan or lint
 * phase name a database error only by its class, SQLSTATE and driver error
 * code (ErrorText); any other error keeps its message.
 *
 * Regression: they logged the error's message, which for a database error
 * carries the SQL statement with its values.
 */
class SyncProjectJobDatabaseErrorTest extends DbTestCase
{
    private const MARKER = 'sync-db-marker-7140';

    /** @var array<string, mixed> application components replaced by a test, restored in tearDown() */
    private array $replaced = [];

    protected function tearDown(): void
    {
        foreach ($this->replaced as $id => $definition) {
            \Yii::$app->set($id, $definition);
        }
        $this->replaced = [];
        parent::tearDown();
    }

    public function testRegressionADatabaseErrorOfTheSyncWasLoggedWithItsMessage(): void
    {
        $project = $this->createProject((int)$this->createUser()->id);
        $this->stubPhases(new \PDOException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '" . self::MARKER . "' for key 'PRIMARY'"
        ), null, null);

        $logged = $this->runLogged($project);

        $this->assertSame(
            [[Logger::LEVEL_ERROR, "SyncProjectJob: project #{$project->id} sync failed: PDOException (SQLSTATE -, driver error -)"]],
            $logged
        );
        $this->assertSame(sprintf(SyncProjectJob::SYNC_FAILED, $project->id, 'PDOException (SQLSTATE -, driver error -)'), $logged[0][1]);
    }

    public function testRegressionADatabaseErrorOfTheVaultScanWasLoggedWithItsStatement(): void
    {
        $project = $this->createProject((int)$this->createUser()->id);
        $this->stubPhases(null, $this->databaseError(), null);

        $logged = $this->runLogged($project);

        $this->assertSame(
            [[Logger::LEVEL_WARNING, "SyncProjectJob: vault scan for project #{$project->id} failed (sync still committed): yii\\db\\Exception (SQLSTATE HY000, driver error 2006)"]],
            $logged
        );
        $this->assertSame(sprintf(SyncProjectJob::VAULT_SCAN_FAILED, $project->id, 'yii\db\Exception (SQLSTATE HY000, driver error 2006)'), $logged[0][1]);
    }

    public function testRegressionADatabaseErrorOfTheLintPhaseWasLoggedWithItsStatement(): void
    {
        $project = $this->createProject((int)$this->createUser()->id);
        $this->stubPhases(null, null, $this->databaseError());

        $logged = $this->runLogged($project);

        $this->assertSame(
            [[Logger::LEVEL_WARNING, "SyncProjectJob: lint phase for project #{$project->id} failed (sync still committed): yii\\db\\Exception (SQLSTATE HY000, driver error 2006)"]],
            $logged
        );
        $this->assertSame(sprintf(SyncProjectJob::LINT_FAILED, $project->id, 'yii\db\Exception (SQLSTATE HY000, driver error 2006)'), $logged[0][1]);
    }

    /**
     * Any other error keeps its message: the operator needs git's answer.
     */
    public function testAnotherErrorOfTheSyncKeepsItsMessage(): void
    {
        $project = $this->createProject((int)$this->createUser()->id);
        $this->stubPhases(new \RuntimeException("git clone failed: fatal: repository 'https://git.example.com/x.git/' not found"), null, null);

        $logged = $this->runLogged($project);

        $this->assertSame(
            [[Logger::LEVEL_ERROR, "SyncProjectJob: project #{$project->id} sync failed: git clone failed: fatal: repository 'https://git.example.com/x.git/' not found"]],
            $logged
        );
    }

    // -- Helpers ----------------------------------------------------------------

    private function databaseError(): \yii\db\Exception
    {
        return new \yii\db\Exception(
            "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away\n"
                . "The SQL being executed was: UPDATE `project` SET `lint_output`='" . self::MARKER . "'",
            ['HY000', 2006, 'MySQL server has gone away']
        );
    }

    private function replaceComponent(string $id, object $component): void
    {
        if (!array_key_exists($id, $this->replaced)) {
            $this->replaced[$id] = \Yii::$app->getComponents(true)[$id] ?? null;
        }
        \Yii::$app->set($id, $component);
    }

    /**
     * Stubs of the project, vault scan and lint services; each throws its
     * failure when it is not null.
     */
    private function stubPhases(?\Throwable $sync, ?\Throwable $scan, ?\Throwable $lint): void
    {
        $this->replaceComponent('projectService', new class ($sync) extends \yii\base\Component {
            public function __construct(private readonly ?\Throwable $failure)
            {
                parent::__construct();
            }

            public function sync(Project $project): void
            {
                if ($this->failure !== null) {
                    throw $this->failure;
                }
            }
        });
        $this->replaceComponent('vaultScanService', new class ($scan) extends \yii\base\Component {
            public function __construct(private readonly ?\Throwable $failure)
            {
                parent::__construct();
            }

            public function scanProject(Project $project): bool
            {
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                return true;
            }
        });
        $this->replaceComponent('lintService', new class ($lint) extends \yii\base\Component {
            public function __construct(private readonly ?\Throwable $failure)
            {
                parent::__construct();
            }

            public function runForProject(Project $project): void
            {
                if ($this->failure !== null) {
                    throw $this->failure;
                }
            }

            public function runForTemplate(JobTemplate $template): void
            {
            }
        });
    }

    /**
     * Run the job for $project; the errors and warnings SyncProjectJob logged.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function runLogged(Project $project): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            (new SyncProjectJob(['projectId' => $project->id]))->execute($this->createMock(\yii\queue\Queue::class));
        } finally {
            \Yii::setLogger($previous);
        }
        $logged = [];
        foreach ($logger->messages as $message) {
            $level = $message[1];
            if (in_array($level, [Logger::LEVEL_ERROR, Logger::LEVEL_WARNING], true) && $message[2] === SyncProjectJob::class && is_string($message[0])) {
                $this->assertStringNotContainsString(self::MARKER, $message[0]);
                $logged[] = [(int)$level, $message[0]];
            }
        }

        return $logged;
    }
}
