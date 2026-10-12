<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\RunnerCommandBuilder;
use app\components\SurveySecretBackfill;
use app\components\SurveySecrets;
use app\components\SurveySecretTemplateScan;
use app\models\Job;
use app\models\JobTemplate;
use app\services\JobLaunchService;
use app\services\MaintenanceService;
use app\tests\integration\DbTestCase;
use yii\db\Query;
use yii\log\Logger;

/**
 * The survey password scrub that maintenance/run (the schedule-runner loop)
 * runs on every tick: it encrypts the password answers that finished jobs
 * stored in plaintext before 2.9, a time budget per tick, from where the
 * previous tick stopped, until it is done. Position and done mark live in
 * the cache (an ArrayCache in tests, flushed around each test).
 */
class MaintenanceSurveySecretScrubTest extends DbTestCase
{
    /** A test value standing for a password answer. */
    private const ANSWER = 'scrub-answer-marker-6402';

    private const VARS = ['app_env' => 'prod', 'db_password' => self::ANSWER];

    private mixed $originalKey;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = 'maintenance-scrub-test-key-0123456789';
        $this->userId = (int)$this->createUser('scrub')->id;
        \Yii::$app->cache->flush();
    }

    protected function tearDown(): void
    {
        \Yii::$app->cache->flush();
        if ($this->originalKey === null) {
            unset($_ENV['APP_SECRET_KEY']);
        } else {
            $_ENV['APP_SECRET_KEY'] = $this->originalKey;
        }
        parent::tearDown();
    }

    /**
     * Regression: the answers of finished jobs stayed in plaintext in
     * job.extra_vars, the runner payload and the stored command, readable
     * by every user with job.view, after the release that encrypts new ones.
     */
    public function testATickEncryptsTheStoredAnswersOfFinishedJobs(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $finished = $this->legacyJob($template, Job::STATUS_SUCCEEDED);
        $queued = $this->legacyJob($template, Job::STATUS_QUEUED);
        $queuedBefore = $this->row($queued);

        $report = $this->service()->runIfDue();

        $this->assertContains('survey-secret-scrub', $report['ran']);
        $this->assertSame(
            ['checked' => 1, 'encrypted' => 1, 'undecryptable' => 0, 'too_long' => 0, 'failed' => 0, 'command_cleared' => 0, 'done' => 1],
            $report['results']['survey-secret-scrub']
        );
        $row = $this->row($finished);
        foreach (['extra_vars', 'runner_payload', 'execution_command'] as $column) {
            $this->assertStringNotContainsString(self::ANSWER, (string)$row[$column], $column);
        }
        $this->assertSame(['db_password' => self::ANSWER], SurveySecrets::open(is_string($row['secret_extra_vars']) ? $row['secret_extra_vars'] : null));
        $this->assertSame($queuedBefore, $this->row($queued), 'unfinished jobs are the migration\'s');
        $this->assertNotFalse(\Yii::$app->cache->get(MaintenanceService::SURVEY_SECRET_SCRUB_DONE_KEY));
        $this->assertFalse(\Yii::$app->cache->get(MaintenanceService::SURVEY_SECRET_SCRUB_CURSOR_KEY));
    }

    public function testOnceDoneTheScrubIsSkipped(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $finished = $this->legacyJob($template, Job::STATUS_FAILED);
        $before = $this->row($finished);
        \Yii::$app->cache->set(MaintenanceService::SURVEY_SECRET_SCRUB_DONE_KEY, time(), 0);

        $report = $this->service()->runIfDue();

        $this->assertNotContains('survey-secret-scrub', $report['ran']);
        $this->assertContains(['task' => 'survey-secret-scrub', 'reason' => SurveySecretBackfill::STATUS_DONE], $report['skipped']);
        $this->assertSame($before, $this->row($finished));
    }

    /**
     * Each tick does a budget's worth (here one page of one job) and the
     * next tick goes on from there, until the history is done.
     */
    public function testEachTickContinuesWhereThePreviousOneStopped(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $jobs = [$this->legacyJob($template, Job::STATUS_SUCCEEDED), $this->legacyJob($template, Job::STATUS_CANCELED), $this->legacyJob($template, Job::STATUS_TIMED_OUT)];
        $service = $this->service(1);
        $service->surveySecretScrubBudgetSeconds = 0.000001;

        $ticks = [];
        for ($i = 0; $i < 4; $i++) {
            $report = $service->runIfDue();
            $ticks[] = [
                $report['results']['survey-secret-scrub']['checked'] ?? 0,
                \Yii::$app->cache->get(MaintenanceService::SURVEY_SECRET_SCRUB_CURSOR_KEY),
            ];
        }

        $this->assertSame([
            [1, ['template' => (int)$template->id, 'job' => $jobs[0]]],
            [1, ['template' => (int)$template->id, 'job' => $jobs[1]]],
            [1, ['template' => (int)$template->id, 'job' => $jobs[2]]],
            [0, false],
        ], $ticks);
        $this->assertNotFalse(\Yii::$app->cache->get(MaintenanceService::SURVEY_SECRET_SCRUB_DONE_KEY));
        foreach ($jobs as $id) {
            $this->assertStringNotContainsString(self::ANSWER, (string)$this->row($id)['extra_vars']);
        }
    }

    /**
     * After a reset (m000081 runs it: the data may come from a restored
     * backup), the next tick checks the whole history again.
     */
    public function testAResetMakesTheNextTickCheckTheHistoryAgain(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $finished = $this->legacyJob($template, Job::STATUS_SUCCEEDED);
        \Yii::$app->cache->set(MaintenanceService::SURVEY_SECRET_SCRUB_DONE_KEY, time(), 0);
        \Yii::$app->cache->set(MaintenanceService::SURVEY_SECRET_SCRUB_CURSOR_KEY, ['template' => PHP_INT_MAX, 'job' => 1], 0);
        $service = $this->service();

        $service->resetSurveySecretScrub();

        $this->assertFalse(\Yii::$app->cache->get(MaintenanceService::SURVEY_SECRET_SCRUB_DONE_KEY));
        $this->assertFalse(\Yii::$app->cache->get(MaintenanceService::SURVEY_SECRET_SCRUB_CURSOR_KEY));
        $this->assertSame(1, $service->runIfDue()['results']['survey-secret-scrub']['encrypted']);
        $this->assertStringNotContainsString(self::ANSWER, (string)$this->row($finished)['extra_vars']);
    }

    /**
     * A lost or damaged position means starting over, which is safe: rows
     * that are done are skipped.
     */
    public function testAStoredPositionThatIsNotOneStartsOver(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $finished = $this->legacyJob($template, Job::STATUS_SUCCEEDED);
        \Yii::$app->cache->set(MaintenanceService::SURVEY_SECRET_SCRUB_CURSOR_KEY, ['template' => PHP_INT_MAX, 'job' => 'x'], 0);

        $report = $this->service()->runIfDue();

        $this->assertSame(1, $report['results']['survey-secret-scrub']['encrypted']);
        $this->assertStringNotContainsString(self::ANSWER, (string)$this->row($finished)['extra_vars']);
    }

    public function testTheScrubCanBeDisabled(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $finished = $this->legacyJob($template, Job::STATUS_SUCCEEDED);
        $before = $this->row($finished);
        $service = $this->service();
        $service->surveySecretScrubBudgetSeconds = 0;

        $report = $service->runIfDue();

        $this->assertContains(['task' => 'survey-secret-scrub', 'reason' => 'disabled'], $report['skipped']);
        $this->assertSame($before, $this->row($finished));
    }

    /**
     * Without a usable APP_SECRET_KEY nothing is encrypted and nothing is
     * marked: the scrub runs once the key is fixed.
     */
    public function testWithoutAUsableKeyTheScrubWaitsAndStoresNothing(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $finished = $this->legacyJob($template, Job::STATUS_SUCCEEDED);
        $before = $this->row($finished);
        unset($_ENV['APP_SECRET_KEY']);

        $report = $this->service()->runIfDue();

        $this->assertContains(['task' => 'survey-secret-scrub', 'reason' => SurveySecretBackfill::STATUS_NO_KEY], $report['skipped']);
        $this->assertSame($before, $this->row($finished));
        $this->assertFalse(\Yii::$app->cache->get(MaintenanceService::SURVEY_SECRET_SCRUB_DONE_KEY));
        $this->assertFalse(\Yii::$app->cache->get(MaintenanceService::SURVEY_SECRET_SCRUB_CURSOR_KEY));
    }

    /**
     * An error keeps the position, logs a warning without values, and never
     * fails the tick: maintenance/run must exit 0, or the schedule-runner's
     * health check fails.
     */
    public function testAnErrorKeepsThePositionAndLogsAWarning(): void
    {
        \Yii::$app->cache->set(MaintenanceService::SURVEY_SECRET_SCRUB_CURSOR_KEY, ['template' => 5, 'job' => 77], 0);
        $service = new class () extends MaintenanceService {
            protected function surveySecretBackfill(): SurveySecretBackfill
            {
                return new class (\Yii::$app->db) extends SurveySecretBackfill {
                    protected function scanTemplates(): SurveySecretTemplateScan
                    {
                        throw new \yii\db\Exception('SQLSTATE[HY000]: lost; The SQL being executed was: SELECT ' . 'scrub-answer-marker-6402', ['HY000', 2006, 'gone']);
                    }
                };
            }
        };
        $service->artifactCleanupIntervalSeconds = 0;
        $logger = $this->captureLogs();

        try {
            $report = $service->runIfDue();
        } finally {
            \Yii::setLogger($logger['previous']);
        }

        $this->assertContains(['task' => 'survey-secret-scrub', 'reason' => SurveySecretBackfill::STATUS_ERROR], $report['skipped']);
        $this->assertSame(['template' => 5, 'job' => 77], \Yii::$app->cache->get(MaintenanceService::SURVEY_SECRET_SCRUB_CURSOR_KEY));
        $warnings = array_values(array_filter(
            $logger['logger']->messages,
            static fn (array $message): bool => $message[1] === Logger::LEVEL_WARNING && $message[2] === MaintenanceService::class
        ));
        $this->assertCount(1, $warnings);
        $this->assertSame('MaintenanceService: the encryption of stored survey password answers stopped after an error; the next run continues.', $warnings[0][0]);
        $this->assertStringNotContainsString(self::ANSWER, (string)json_encode(array_column($logger['logger']->messages, 0)));
    }

    /**
     * Regression guard (review finding TQ-2): an error after committed pages
     * (here the progress line after page 20 fails, as a failing log target
     * would; a failed page query or commit is the same) stores the position
     * the scrub reached, so the next tick goes on from there instead of
     * checking every page again. The test above starts from a stored
     * position and fails before the first row, so it cannot tell.
     */
    public function testAnErrorAfterProgressStoresTheAdvancedPosition(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $ids = [];
        for ($i = 0; $i < 21; $i++) {
            $ids[] = $this->legacyJob($template, Job::STATUS_SUCCEEDED);
        }
        $service = new class () extends MaintenanceService {
            protected function surveySecretBackfill(): SurveySecretBackfill
            {
                return new SurveySecretBackfill(\Yii::$app->db, static function (string $line): void {
                    if (str_ends_with($line, ' so far.')) {
                        throw new \RuntimeException('The log target failed.');
                    }
                }, 1);
            }
        };
        $service->artifactCleanupIntervalSeconds = 0;
        $service->surveySecretScrubBudgetSeconds = 60.0;
        $logger = $this->captureLogs();

        try {
            $report = $service->runIfDue();
        } finally {
            \Yii::setLogger($logger['previous']);
        }

        $this->assertSame(['template' => (int)$template->id, 'job' => $ids[19]], \Yii::$app->cache->get(MaintenanceService::SURVEY_SECRET_SCRUB_CURSOR_KEY));
        $this->assertFalse(\Yii::$app->cache->get(MaintenanceService::SURVEY_SECRET_SCRUB_DONE_KEY));
        $warnings = array_values(array_filter(
            $logger['logger']->messages,
            static fn (array $message): bool => $message[1] === Logger::LEVEL_WARNING && $message[2] === MaintenanceService::class
        ));
        $this->assertCount(1, $warnings);
        $this->assertSame('MaintenanceService: the encryption of stored survey password answers stopped after an error; the next run continues.', $warnings[0][0]);
        $this->assertStringNotContainsString(self::ANSWER, (string)json_encode(array_column($logger['logger']->messages, 0)));
        $this->assertSame(
            ['checked' => 20, 'encrypted' => 20, 'undecryptable' => 0, 'too_long' => 0, 'failed' => 0, 'command_cleared' => 0, 'done' => 0],
            $report['results']['survey-secret-scrub']
        );
        $this->assertStringContainsString(self::ANSWER, (string)$this->row($ids[20])['extra_vars'], 'not reached before the error');

        $next = $service->runIfDue();

        $this->assertSame(
            ['checked' => 1, 'encrypted' => 1, 'undecryptable' => 0, 'too_long' => 0, 'failed' => 0, 'command_cleared' => 0, 'done' => 1],
            $next['results']['survey-secret-scrub'],
            'the next tick checks only the job the error stopped before'
        );
        $this->assertStringNotContainsString(self::ANSWER, (string)$this->row($ids[20])['extra_vars']);
    }

    /**
     * The scrub's report lines (here: a job whose sealed answers cannot be
     * opened) go to the application log, with IDs and no value.
     */
    public function testReportLinesGoToTheApplicationLogWithoutValues(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $finished = $this->legacyJob($template, Job::STATUS_SUCCEEDED);
        \Yii::$app->db->createCommand()->update('{{%job}}', ['secret_extra_vars' => 'not-a-sealed-value'], ['id' => $finished])->execute();
        $logger = $this->captureLogs();

        try {
            $report = $this->service()->runIfDue();
        } finally {
            \Yii::setLogger($logger['previous']);
        }

        $this->assertSame(1, $report['results']['survey-secret-scrub']['undecryptable']);
        $info = array_values(array_filter(
            $logger['logger']->messages,
            static fn (array $message): bool => $message[1] === Logger::LEVEL_INFO && $message[2] === MaintenanceService::class
        ));
        $this->assertSame(
            [sprintf('survey-secret-scrub: Finished jobs left unchanged because their stored encrypted answers cannot be decrypted (was APP_SECRET_KEY changed?): 1 (#%d).', $finished)],
            array_column($info, 0)
        );
        $this->assertStringNotContainsString(self::ANSWER, (string)json_encode(array_column($logger['logger']->messages, 0)));
    }

    /**
     * The service of the application, with artifact cleanup off, or one whose
     * scrub pages $pageSize jobs at a time.
     */
    private function service(?int $pageSize = null): MaintenanceService
    {
        $service = $pageSize === null ? new MaintenanceService() : new class ($pageSize) extends MaintenanceService {
            public function __construct(private readonly int $pageSize)
            {
                parent::__construct();
            }

            protected function surveySecretBackfill(): SurveySecretBackfill
            {
                return new SurveySecretBackfill(\Yii::$app->db, null, $this->pageSize);
            }
        };
        $service->artifactCleanupIntervalSeconds = 0;

        return $service;
    }

    /**
     * @return array{logger: Logger, previous: Logger}
     */
    private function captureLogs(): array
    {
        $logger = new Logger();
        $logger->flushInterval = 0;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);

        return ['logger' => $logger, 'previous' => $previous];
    }

    /**
     * A job as releases up to 2.8.1 stored it, claimed by a runner: the
     * extra vars in plaintext in job.extra_vars, the runner payload and the
     * stored command.
     */
    private function legacyJob(JobTemplate $template, string $status): int
    {
        $job = $this->createJob((int)$template->id, $this->userId, $status);
        $job->extra_vars = (string)json_encode(self::VARS, JSON_UNESCAPED_UNICODE);
        $payload = (new \ReflectionMethod(JobLaunchService::class, 'buildRunnerPayload'))->invoke(new JobLaunchService(), $template, $job);
        $job->runner_payload = is_string($payload) ? $payload : null;
        $job->execution_command = implode(' ', (new RunnerCommandBuilder())->build([
            'inventory_type' => 'static',
            'playbook_path' => '/var/www/runtime/projects/site.yml',
            'extra_vars' => $job->extra_vars,
        ]));
        $job->save(false);

        return (int)$job->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        $row = (new Query())->from('{{%job}}')->where(['id' => $id])->one();
        $this->assertIsArray($row);

        return $row;
    }
}
