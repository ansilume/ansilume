<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\JobTemplate;
use app\models\Project;
use app\services\VaultCheckService;
use app\services\VaultScanService;
use app\tests\integration\DbTestCase;
use yii\log\Logger;

/**
 * The warnings of a failed vault check or scan name a database error only
 * by its class, SQLSTATE and driver error code (ErrorText); any other error
 * keeps its message.
 *
 * Regression: VaultCheckService and VaultScanService logged the error's
 * message, which for a database error carries the SQL statement with its
 * values (here the statement ScheduleFailingCommand quotes).
 */
class VaultDatabaseErrorTest extends DbTestCase
{
    /** What ErrorText says about the error ScheduleFailingCommand throws. */
    private const SUMMARY = 'yii\db\Exception (SQLSTATE HY000, driver error 2006)';

    private int $userId;

    private string $checkout = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('vault-db-error')->id;
    }

    protected function tearDown(): void
    {
        if ($this->checkout !== '' && is_dir($this->checkout)) {
            \app\helpers\FileHelper::safeRmdir($this->checkout);
        }
        parent::tearDown();
    }

    public function testRegressionADatabaseErrorOfAVaultCheckWasLoggedWithItsStatement(): void
    {
        $template = $this->template();

        $warnings = $this->warningsOf(VaultCheckService::class, '~FROM `project` WHERE~', function () use ($template): void {
            $this->checks()->checkTemplate($template);
        });

        $this->assertSame(["Vault check for job template #{$template->id} failed: " . self::SUMMARY], $warnings);
        $this->assertSame([sprintf(VaultCheckService::CHECK_FAILED, $template->id, self::SUMMARY)], $warnings);
    }

    public function testRegressionADatabaseErrorWhileRemovingAFailedCheckWasLoggedWithItsStatement(): void
    {
        $template = $this->template();

        $warnings = $this->warningsOf(
            VaultCheckService::class,
            '~FROM `project` WHERE|^DELETE FROM `job_template_vault_check`~',
            function () use ($template): void {
                $this->checks()->checkTemplate($template);
            }
        );

        $this->assertSame([
            "Vault check for job template #{$template->id} failed: " . self::SUMMARY,
            "Vault check for job template #{$template->id} could not be removed: " . self::SUMMARY,
        ], $warnings);
        $this->assertSame([
            sprintf(VaultCheckService::CHECK_FAILED, $template->id, self::SUMMARY),
            sprintf(VaultCheckService::CHECK_NOT_REMOVED, $template->id, self::SUMMARY),
        ], $warnings);
    }

    public function testRegressionADatabaseErrorOfAVaultScanWasLoggedWithItsStatement(): void
    {
        $project = $this->manualProject();
        $result = null;

        // Only the store of a scan sets a summary; storing the failure afterwards works.
        $warnings = $this->warningsOf(VaultScanService::class, "~`vault_scan_summary`='~", function () use ($project, &$result): void {
            /** @var VaultScanService $scans */
            $scans = \Yii::$app->get('vaultScanService');
            $result = $scans->scanProject($project);
        });

        $this->assertFalse($result);
        $this->assertSame(["Vault scan of project #{$project->id} failed: " . self::SUMMARY], $warnings);
        $this->assertSame([sprintf(VaultScanService::SCAN_FAILED, $project->id, self::SUMMARY)], $warnings);
        $stored = Project::findOne($project->id);
        $this->assertNotNull($stored);
        $this->assertSame('The scan could not be completed; the application log has the details.', $stored->vault_scan_error);
    }

    // -- Helpers ----------------------------------------------------------------

    private function checks(): VaultCheckService
    {
        /** @var VaultCheckService $checks */
        $checks = \Yii::$app->get('vaultCheckService');

        return $checks;
    }

    private function template(): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        );
    }

    /** A manual project whose local path is an empty directory. */
    private function manualProject(): Project
    {
        $this->checkout = sys_get_temp_dir() . '/vault-db-error-' . uniqid('', true);
        mkdir($this->checkout, 0700, true);
        $project = $this->createProject($this->userId);
        $project->scm_type = Project::SCM_TYPE_MANUAL;
        $project->scm_url = null;
        $project->local_path = $this->checkout;
        $project->save(false);

        return $project;
    }

    /**
     * Run $action while every statement matching $failingPattern fails as a
     * lost connection would; the warnings $category logged meanwhile.
     *
     * @return list<string>
     */
    private function warningsOf(string $category, string $failingPattern, callable $action): array
    {
        $db = \Yii::$app->db;
        $driver = $db->getDriverName();
        $previousCommand = $db->commandMap[$driver] ?? null;
        $db->commandMap[$driver] = ['class' => ScheduleFailingCommand::class, 'failingPattern' => $failingPattern];
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previousLogger = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $action();
        } finally {
            \Yii::setLogger($previousLogger);
            if ($previousCommand === null) {
                unset($db->commandMap[$driver]);
            } else {
                $db->commandMap[$driver] = $previousCommand;
            }
        }

        $warnings = [];
        foreach ($logger->messages as $message) {
            if ($message[1] === Logger::LEVEL_WARNING && $message[2] === $category && is_string($message[0])) {
                $this->assertStringNotContainsString('The SQL being executed', $message[0]);
                $this->assertStringNotContainsString('SQLSTATE[', $message[0]);
                $warnings[] = $message[0];
            }
        }

        return $warnings;
    }
}
