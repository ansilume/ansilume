<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\MaintenanceController;
use app\models\ApprovalRequest;
use app\models\Job;
use app\services\ArtifactService;
use app\services\JobLaunchService;
use app\services\MaintenanceService;
use app\tests\integration\DbTestCase;
use app\tests\integration\services\CancellationFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\console\ExitCode;

/**
 * Integration tests for the console MaintenanceController.
 */
class MaintenanceControllerTest extends DbTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;

    private string $tempDir;

    private mixed $originalKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/ansilume_maintenance_cmd_' . uniqid('', true);
        mkdir($this->tempDir, 0750, true);
        \Yii::$app->cache->flush();
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = 'maintenance-console-test-key-0123456789';
    }

    protected function tearDown(): void
    {
        if ($this->originalKey === null) {
            unset($_ENV['APP_SECRET_KEY']);
        } else {
            $_ENV['APP_SECRET_KEY'] = $this->originalKey;
        }
        $this->removeDir($this->tempDir);
        \Yii::$app->cache->flush();
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    /**
     * @return MaintenanceController&object{captured: string}
     */
    private function makeController(): MaintenanceController
    {
        return new class ('maintenance', \Yii::$app) extends MaintenanceController {
            public string $captured = '';

            public function stdout($string): int
            {
                $this->captured .= $string;
                return 0;
            }

            public function stderr($string): int
            {
                $this->captured .= $string;
                return 0;
            }
        };
    }

    private function makeArtifactService(): ArtifactService
    {
        $svc = new ArtifactService();
        $svc->storagePath = $this->tempDir . '/storage';
        return $svc;
    }

    public function testRunPrintsTaskNameWhenSomethingExecutes(): void
    {
        $svc = $this->makeArtifactService();
        // Seed an orphan so the cleanup actually does something visible.
        $orphanDir = $this->tempDir . '/storage/job_999';
        mkdir($orphanDir, 0750, true);
        file_put_contents($orphanDir . '/o.txt', 'x');

        \Yii::$app->set('artifactService', $svc);
        $maintenance = new MaintenanceService();
        $maintenance->artifactCleanupIntervalSeconds = 3600;
        \Yii::$app->set('maintenanceService', $maintenance);

        $ctrl = $this->makeController();
        $exit = $ctrl->actionRun();

        $this->assertSame(ExitCode::OK, $exit);
        $this->assertStringContainsString('ran: artifact-cleanup', $ctrl->captured);
        $this->assertStringContainsString('orphans=1', $ctrl->captured);
    }

    public function testRunStaysQuietWhenNothingIsDue(): void
    {
        \Yii::$app->set('artifactService', $this->makeArtifactService());

        $maintenance = new MaintenanceService();
        $maintenance->artifactCleanupIntervalSeconds = 0; // disabled
        \Yii::$app->set('maintenanceService', $maintenance);

        $ctrl = $this->makeController();
        $exit = $ctrl->actionRun();

        $this->assertSame(ExitCode::OK, $exit);
        $this->assertSame('', $ctrl->captured, 'no output expected when no task ran');
    }

    public function testRunStaysQuietOnSecondInvocationDueToCooldown(): void
    {
        \Yii::$app->set('artifactService', $this->makeArtifactService());

        $maintenance = new MaintenanceService();
        $maintenance->artifactCleanupIntervalSeconds = 3600;
        \Yii::$app->set('maintenanceService', $maintenance);

        // First call: cleanup runs (no orphans → orphans=0, but the task did run).
        $first = $this->makeController();
        $first->actionRun();
        $this->assertStringContainsString('ran: artifact-cleanup', $first->captured);

        // Second call: cooldown is in effect → no output.
        $second = $this->makeController();
        $exit = $second->actionRun();
        $this->assertSame(ExitCode::OK, $exit);
        $this->assertSame('', $second->captured);
    }

    /**
     * While the survey password scrub works through the job history, each
     * tick prints its counts (docker logs of the schedule-runner), never a
     * value; once it is done the tick is quiet again.
     */
    public function testRunPrintsTheCountsOfTheSurveyPasswordScrub(): void
    {
        $answer = 'console-answer-marker-1189';
        $job = $this->finishedJobWithPlaintextAnswer($answer);
        $this->useMaintenanceWithoutArtifactCleanup();

        $first = $this->makeController();
        $firstExit = $first->actionRun();
        $second = $this->makeController();
        $second->actionRun();

        $this->assertSame(ExitCode::OK, $firstExit);
        $this->assertSame(
            "[maintenance] ran: survey-secret-scrub (checked=1, encrypted=1, undecryptable=0, too_long=0, failed=0, command_cleared=0, done=1)\n",
            $first->captured
        );
        $this->assertSame('', $second->captured, 'done: quiet again');
        $job->refresh();
        $this->assertStringNotContainsString($answer, (string)$job->extra_vars);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function scrubStateBeforeTheRestoreProvider(): array
    {
        return [
            'the scrub was done' => ['done'],
            'the scrub was under way, past the restored job' => ['position'],
        ];
    }

    /**
     * Regression (review finding DOC-4): once a database backup taken after
     * the update started is restored, its finished jobs hold plaintext
     * answers again, but its migration table already lists m000081, so
     * nothing reset the scrub. The done mark, or a position past those jobs,
     * kept in the cache made every maintenance/run skip them for good.
     * maintenance/reset-survey-secret-scrub makes the next run check the
     * whole history again. It deletes nothing else: flushing the cache
     * instead would also drop the sessions and the queue, which share Redis.
     *
     * @dataProvider scrubStateBeforeTheRestoreProvider
     */
    public function testResetSurveySecretScrubMakesTheNextRunEncryptARestoredHistory(string $stateBeforeTheRestore): void
    {
        $answer = 'console-reset-marker-5521';
        $job = $this->finishedJobWithPlaintextAnswer($answer);
        if ($stateBeforeTheRestore === 'done') {
            \Yii::$app->cache->set(MaintenanceService::SURVEY_SECRET_SCRUB_DONE_KEY, time(), 0);
        } else {
            \Yii::$app->cache->set(MaintenanceService::SURVEY_SECRET_SCRUB_CURSOR_KEY, ['template' => (int)$job->job_template_id, 'job' => (int)$job->id], 0);
        }
        \Yii::$app->cache->set('maintenance:artifact-cleanup', 1700000000, 0);
        $this->useMaintenanceWithoutArtifactCleanup();

        $skipping = $this->makeController();
        $skipping->actionRun();
        $job->refresh();
        $this->assertSame('', $skipping->captured, 'the stored state skips the restored job');
        $this->assertStringContainsString($answer, (string)$job->extra_vars);

        $reset = $this->makeController();
        $resetExit = $reset->runAction('reset-survey-secret-scrub');

        $this->assertSame(ExitCode::OK, $resetExit);
        $this->assertSame("The survey password scrub starts over at the next maintenance run.\n", $reset->captured);
        $this->assertFalse(\Yii::$app->cache->get(MaintenanceService::SURVEY_SECRET_SCRUB_DONE_KEY));
        $this->assertFalse(\Yii::$app->cache->get(MaintenanceService::SURVEY_SECRET_SCRUB_CURSOR_KEY));
        $this->assertSame(1700000000, \Yii::$app->cache->get('maintenance:artifact-cleanup'), 'other cache entries stay');

        $next = $this->makeController();
        $next->actionRun();
        $job->refresh();
        $this->assertSame(
            "[maintenance] ran: survey-secret-scrub (checked=1, encrypted=1, undecryptable=0, too_long=0, failed=0, command_cleared=0, done=1)\n",
            $next->captured
        );
        $this->assertStringNotContainsString($answer, (string)$job->extra_vars);
        $this->assertStringNotContainsString($answer, (string)$job->runner_payload);
    }

    /**
     * The approval timeouts run on every tick and print how many requests
     * they timed out, like the other tasks.
     */
    public function testRunPrintsTheApprovalTimeoutsItApplied(): void
    {
        $launcher = $this->createUserWithRole('console-timeout-launcher', 'operator');
        $approver = $this->createUserWithRole('console-timeout-approver', 'operator');
        $this->recordNotifications();
        try {
            ['request' => $request] = $this->jobWaitingForApproval(
                $this->cancelFixtureTemplate((int)$launcher->id),
                (int)$launcher->id,
                (int)$approver->id
            );
            ApprovalRequest::updateAll(['expires_at' => time() - 60], ['id' => $request->id]);
            $this->useMaintenanceWithoutArtifactCleanup();

            $ctrl = $this->makeController();
            $exit = $ctrl->actionRun();
        } finally {
            $this->restoreNotifications();
        }

        $this->assertSame(ExitCode::OK, $exit);
        $this->assertSame("[maintenance] ran: approval-timeouts (resolved=1)\n", $ctrl->captured);
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_TIMED_OUT, $request->status);
    }

    /**
     * A finished job as releases up to 2.8.1 stored it, of a template with a
     * password field: the answer in plaintext in job.extra_vars and in the
     * runner payload.
     */
    private function finishedJobWithPlaintextAnswer(string $answer): Job
    {
        $userId = (int)$this->createUser('scrub_console')->id;
        $template = $this->createPasswordSurveyTemplate($userId);
        $job = $this->createJob((int)$template->id, $userId, Job::STATUS_SUCCEEDED);
        $job->extra_vars = (string)json_encode([self::SURVEY_PASSWORD_FIELD => $answer]);
        $payload = (new \ReflectionMethod(JobLaunchService::class, 'buildRunnerPayload'))->invoke(new JobLaunchService(), $template, $job);
        $job->runner_payload = is_string($payload) ? $payload : null;
        $job->save(false);

        return $job;
    }

    private function useMaintenanceWithoutArtifactCleanup(): void
    {
        \Yii::$app->set('artifactService', $this->makeArtifactService());
        $maintenance = new MaintenanceService();
        $maintenance->artifactCleanupIntervalSeconds = 0;
        \Yii::$app->set('maintenanceService', $maintenance);
    }
}
