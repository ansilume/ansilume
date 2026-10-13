<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\commands\MaintenanceController;
use app\models\ApprovalRequest;
use app\models\Job;
use app\models\Schedule;
use app\models\WorkflowStep;
use app\services\ApprovalService;
use app\services\MaintenanceService;
use app\services\WorkflowTemplateDeletionService;
use app\tests\integration\DbTestCase;
use app\tests\integration\services\CancellationFixtures;
use app\tests\integration\TeamScopeFixtures;
use app\tests\unit\docs\ReadsOperatorDocs;
use yii\base\Module;
use yii\db\Migration;
use yii\log\Logger;

/**
 * What docs/updating.md quotes of the console output of the update to
 * approvals and workflows is what the code prints: the lines of the
 * maintenance run's two new tasks, with the counts they report, and the
 * lines the data migrations print. The tests run the code, in the test's
 * transaction, and compare its output with the docs, numbers aside.
 *
 * Regression from step 3: the docs named no output of the new maintenance
 * tasks and migrations. These tests fail on the docs from before.
 *
 * Regression from the review of step 3 (contract-3): the warning a
 * failing maintenance task logs is one the troubleshooting docs quote. The
 * queries to run before the update are checked by
 * ApprovalWorkflowQueriesDocsContractTest.
 */
class ApprovalWorkflowBehaviourDocsContractTest extends DbTestCase
{
    use ReadsOperatorDocs;
    use TeamScopeFixtures;
    use CancellationFixtures;

    private const UPDATE_SECTION = '## After updating from 2.9.0 or older: approvals and workflows';

    private const MAINTENANCE_ENTRY = '## Approval timeouts or the deleted-template sweep keep failing';

    /** @var array<string, mixed> component definitions to put back */
    private array $components = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $migrations = dirname(__DIR__, 3) . '/migrations/';
        require_once $migrations . 'm000082_000000_restrict_approval_rule_management.php';
        require_once $migrations . 'm000083_000000_protect_approval_rule_references.php';
        require_once $migrations . 'm000085_000000_close_orphaned_approval_requests.php';
    }

    protected function tearDown(): void
    {
        $this->restoreNotifications();
        foreach ($this->components as $id => $definition) {
            \Yii::$app->set($id, $definition);
        }
        $this->components = [];
        parent::tearDown();
    }

    /**
     * The maintenance run applies an expired request's timeout and sweeps
     * a deleted job template and a deleted workflow template; the lines it
     * prints for the two tasks have the keys the docs quote, in order.
     */
    public function testTheMaintenanceLinesAreThoseTheRunPrints(): void
    {
        $launcher = $this->createUserWithRole('docs-maintenance-launcher', 'operator');
        $approver = $this->createUserWithRole('docs-maintenance-approver', 'operator');
        $userId = (int)$launcher->id;
        $this->recordNotifications();
        ['request' => $request] = $this->jobWaitingForApproval($this->cancelFixtureTemplate($userId), $userId, (int)$approver->id);
        ApprovalRequest::updateAll(['expires_at' => time() - 60], ['id' => $request->id]);
        $deleted = $this->cancelFixtureTemplate($userId);
        $this->createJob((int)$deleted->id, $userId, Job::STATUS_QUEUED);
        $this->assertTrue($deleted->softDelete());
        $workflow = $this->createWorkflowTemplate($userId);
        $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_PAUSE);
        $this->createWorkflowJob((int)$workflow->id, $userId);
        $this->assertTrue($workflow->softDelete());

        $printed = $this->maintenanceRun();

        $docs = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        foreach ([MaintenanceService::TASK_APPROVAL_TIMEOUTS, MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP] as $task) {
            $prefix = "[maintenance] ran: {$task} (";
            $this->assertSame(1, preg_match('/^' . preg_quote($prefix, '/') . '(.*)\)$/m', $printed, $line), "The run prints {$task}: {$printed}");
            $this->assertSame(1, preg_match('/`' . preg_quote($prefix, '/') . '([^`]*)\)`/', $docs, $quoted), "updating.md quotes the line of {$task}.");
            $this->assertSame(self::keysOf($line[1] ?? ''), self::keysOf($quoted[1] ?? ''), "updating.md names the counts of {$task} as the run prints them.");
        }
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_TIMED_OUT, $request->status);
    }

    /**
     * m000082 prints what it took from the operator role and what to do;
     * the docs quote these lines.
     */
    public function testTheRbacMigrationPrintsTheLinesTheDocsQuote(): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $operator = $auth->getRole('operator');
        $this->assertNotNull($operator);
        // The test database is migrated: give the operator role back what m000052 granted.
        foreach (['approval-rule.create', 'approval-rule.update'] as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission);
            if (!$auth->hasChild($operator, $permission)) {
                $auth->addChild($operator, $permission);
            }
        }

        $migration = $this->migration('m000082_000000_restrict_approval_rule_management');
        $printed = $this->printed(static function () use ($migration): void {
            $migration->safeUp();
        });

        $lines = self::lines($printed);
        $this->assertCount(3, $lines, $printed);
        $docs = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        $this->assertMentions('`' . $lines[0] . '`', $docs, 'updating.md');
        $this->assertMentions('`' . $lines[2] . '`', $docs, 'updating.md');
    }

    /**
     * m000083 lists the rule IDs of workflow steps that point to no rule
     * before it clears them, in the words the docs quote.
     */
    public function testTheReferenceMigrationPrintsTheLineTheDocsQuote(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->createUser('docs-cleared-rule')->id);
        $db = \Yii::$app->db;
        // A step whose rule was deleted before the foreign key existed.
        $db->createCommand('SET FOREIGN_KEY_CHECKS = 0')->execute();
        try {
            $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_APPROVAL, null, 987654321);
        } finally {
            $db->createCommand('SET FOREIGN_KEY_CHECKS = 1')->execute();
        }
        $migration = $this->migration('m000083_000000_protect_approval_rule_references');

        $printed = $this->printed(static function () use ($migration): void {
            (new \ReflectionMethod($migration, 'clearUnknownRuleIds'))->invoke($migration, '{{%workflow_step}}', 'workflow step');
        });

        $lines = self::lines($printed);
        $this->assertNotEmpty($lines);
        $this->assertMentions('`' . self::numbersHidden($lines[0]) . '`', self::numbersHidden($this->flat('docs/updating.md', self::UPDATE_SECTION)), 'updating.md');
    }

    /**
     * m000085 prints how many requests it closed and schedules it disabled;
     * the docs quote both lines.
     */
    public function testTheDataMigrationPrintsTheLinesTheDocsQuote(): void
    {
        $userId = (int)$this->createUser('docs-orphans')->id;
        $deleted = $this->cancelFixtureTemplate($userId);
        $schedule = new Schedule();
        $schedule->name = 'docs-orphan-schedule';
        $schedule->job_template_id = (int)$deleted->id;
        $schedule->cron_expression = '0 2 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->created_by = $userId;
        $this->assertTrue($schedule->save(false));
        $this->assertTrue($deleted->softDelete());

        $migration = $this->migration('m000085_000000_close_orphaned_approval_requests');
        $printed = $this->printed(static function () use ($migration): void {
            $migration->safeUp();
        });

        $lines = self::lines($printed);
        $this->assertCount(2, $lines, $printed);
        $docs = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        foreach ($lines as $line) {
            $this->assertMentions('`' . self::numbersHidden($line) . '`', $docs, 'updating.md');
        }
    }

    /**
     * Regression (review of step 3, contract-3): when a whole task of the
     * maintenance run fails, the warning it logs is one that the
     * troubleshooting entry quotes, for the approval timeouts and for the
     * sweep alike.
     */
    public function testAFailingMaintenanceTaskLogsTheWarningTheDocsQuote(): void
    {
        $error = new \yii\db\Exception('Deadlock found', ['40001', 1213, 'Deadlock found']);
        $this->keepComponent('approvalService');
        \Yii::$app->set('approvalService', new class ($error) extends ApprovalService {
            public function __construct(private \Throwable $error)
            {
                parent::__construct();
            }

            public function processTimeouts(int $limit = 0, ?float $deadline = null): int
            {
                throw $this->error;
            }
        });
        $this->keepComponent('workflowTemplateDeletionService');
        \Yii::$app->set('workflowTemplateDeletionService', new class ($error) extends WorkflowTemplateDeletionService {
            public function __construct(private \Throwable $error)
            {
                parent::__construct();
            }

            public function sweep(int $limit = self::SWEEP_BATCH_SIZE, ?float $deadline = null): array
            {
                throw $this->error;
            }
        });
        $cache = \Yii::$app->cache;
        $this->assertNotNull($cache);
        $cache->delete(MaintenanceService::DELETED_TEMPLATE_SWEEP_KEY);
        $maintenance = new MaintenanceService();
        $maintenance->artifactCleanupIntervalSeconds = 0;

        $warnings = $this->warningsOf(static fn (): array => $maintenance->runIfDue());

        $this->assertCount(2, $warnings, implode("\n", $warnings));
        $entry = $this->flat('docs/troubleshooting.md', self::MAINTENANCE_ENTRY);
        foreach ($warnings as $warning) {
            $this->assertMentions('`' . $warning . '`', $entry, 'The troubleshooting entry');
        }
    }

    /**
     * What `php yii maintenance/run` prints, with artifact cleanup off.
     */
    private function maintenanceRun(): string
    {
        $this->keepComponent('maintenanceService');
        $maintenance = new MaintenanceService();
        $maintenance->artifactCleanupIntervalSeconds = 0;
        \Yii::$app->set('maintenanceService', $maintenance);
        $cache = \Yii::$app->cache;
        $this->assertNotNull($cache);
        $cache->delete(MaintenanceService::DELETED_TEMPLATE_SWEEP_KEY);
        $controller = new class ('maintenance', $this->app()) extends MaintenanceController {
            public string $printed = '';

            public function stdout($string): int
            {
                $this->printed .= (string)$string;

                return 0;
            }
        };
        $controller->actionRun();

        return $controller->printed;
    }

    /**
     * The warnings MaintenanceService logs while $run runs.
     *
     * @return list<string>
     */
    private function warningsOf(callable $run): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $run();
        } finally {
            \Yii::setLogger($previous);
        }
        $warnings = [];
        foreach ($logger->messages as $message) {
            if ($message[1] === Logger::LEVEL_WARNING && $message[2] === MaintenanceService::class && is_string($message[0])) {
                $warnings[] = $message[0];
            }
        }

        return $warnings;
    }

    /**
     * The migration $class (required in setUpBeforeClass()) on the test
     * database, printing only its own lines.
     */
    private function migration(string $class): Migration
    {
        if (!class_exists($class)) {
            $this->fail("No migration {$class}.");
        }
        $migration = new $class(['db' => \Yii::$app->db, 'compact' => true]);
        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    private function app(): Module
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);

        return $app;
    }

    private function keepComponent(string $id): void
    {
        if (!array_key_exists($id, $this->components)) {
            $this->components[$id] = $this->app()->getComponents(true)[$id] ?? null;
        }
    }

    /**
     * What $run echoes.
     */
    private function printed(callable $run): string
    {
        ob_start();
        try {
            $run();
        } finally {
            $printed = (string)ob_get_clean();
        }

        return $printed;
    }

    /**
     * The lines of a migration's output, without the indent.
     *
     * @return list<string>
     */
    private static function lines(string $printed): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $printed) ?: [])));
    }

    /**
     * Every number as N, as the docs show counts and IDs that vary.
     */
    private static function numbersHidden(string $text): string
    {
        return (string)preg_replace('/\d+/', 'N', $text);
    }

    /**
     * The names of the counts of a maintenance line, in order:
     * "a=1, b=N" gives ["a", "b"].
     *
     * @return list<string>
     */
    private static function keysOf(string $counts): array
    {
        preg_match_all('/(\w+)=/', $counts, $keys);

        return $keys[1];
    }
}
