<?php

declare(strict_types=1);

namespace app\tests\integration\components;

use app\components\RunnerCommandBuilder;
use app\components\SurveySecretBackfill;
use app\components\SurveySecrets;
use app\components\SurveySecretTemplateScan;
use app\models\Job;
use app\models\JobTemplate;
use app\services\JobLaunchService;
use app\tests\integration\DbTestCase;
use yii\db\Connection;
use yii\db\Query;
use yii\log\Logger;

/**
 * SurveySecretBackfill against the database, with rows as releases up to
 * 2.8.1 stored them: password answers in plaintext in job.extra_vars, in the
 * runner payload, in the stored command and in schedule.extra_vars; surveys
 * with password defaults, legacy and malformed definitions; rows near the
 * TEXT limit and sealed sets that cannot be opened.
 *
 * Everything runs inside the test's transaction (each page of the backfill
 * becomes a savepoint) and is rolled back with it.
 */
class SurveySecretBackfillTest extends DbTestCase
{
    /** A test value standing for a password answer. */
    private const ANSWER = 'backfill-answer-marker-7731';

    private const KEY = 'survey-backfill-test-key-0123456789';

    private const VARS = ['app_env' => 'prod', 'db_password' => self::ANSWER];

    private const ZERO_COUNTS = ['checked' => 0, 'encrypted' => 0, 'undecryptable' => 0, 'too_long' => 0, 'failed' => 0, 'command_cleared' => 0];

    private mixed $originalKey;

    private int $userId;

    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = self::KEY;
        $this->userId = (int)$this->createUser('backfill')->id;
    }

    protected function tearDown(): void
    {
        if ($this->originalKey === null) {
            unset($_ENV['APP_SECRET_KEY']);
        } else {
            $_ENV['APP_SECRET_KEY'] = $this->originalKey;
        }
        \Yii::$app->db->enableLogging = true;
        \Yii::$app->db->enableProfiling = true;
        parent::tearDown();
    }

    // -- migration: schedules and unfinished jobs; finished jobs: the scrub --------

    public function testTheMigrationSealsSchedulesAndUnfinishedJobsAndLeavesFinishedJobsToTheScrub(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $jobs = $this->jobInEveryStatus($template);
        $finishedBefore = $this->rows('{{%job}}', array_map(static fn (string $status): int => $jobs[$status], SurveySecretBackfill::FINISHED_STATUSES));
        $scheduleId = $this->legacySchedule($template, "{\n  \"app_env\": \"prod\",\n  \"db_password\": \"" . self::ANSWER . "\"\n}");

        $this->assertTrue($this->backfill()->runMigration());

        foreach (SurveySecretBackfill::UNFINISHED_STATUSES as $status) {
            $this->assertSealedJob($jobs[$status], self::VARS);
        }
        $this->assertSame($finishedBefore, $this->rows('{{%job}}', array_keys($finishedBefore)), 'finished jobs are left to the maintenance scrub');
        $schedule = $this->row('{{%schedule}}', $scheduleId);
        $this->assertSame('{"app_env":"prod","db_password":"$encrypted$"}', $schedule['extra_vars']);
        $this->assertSame(['db_password' => self::ANSWER], SurveySecrets::open($this->text($schedule['secret_extra_vars'])));
        $this->assertCount(4, $this->lines);
        $this->assertMatchesRegularExpression('/^Survey password answers: \d+ job templates checked, 1 of them with password fields\.$/', $this->lines[0]);
        $this->assertSame([
            'Schedules: 1 checked, 1 encrypted.',
            'Unfinished jobs: 4 checked, 4 encrypted.',
            'Finished jobs of these job templates: 5. Their stored answers are encrypted in the background by maintenance/run in the '
                . 'schedule-runner container, a few seconds per minute; it logs its progress as "survey-secret-scrub".',
        ], array_slice($this->lines, 1));
        $this->assertNoAnswerIn($this->lines);
    }

    public function testTheScrubSealsFinishedJobsAndLeavesUnfinishedOnesAlone(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $jobs = $this->jobInEveryStatus($template);
        $unfinishedBefore = $this->rows('{{%job}}', array_map(static fn (string $status): int => $jobs[$status], SurveySecretBackfill::UNFINISHED_STATUSES));

        $slice = $this->backfill()->scrubFinishedJobs(['template' => 0, 'job' => 0], 60.0);

        $this->assertSame(SurveySecretBackfill::STATUS_DONE, $slice['status']);
        $this->assertSame(['checked' => 5, 'encrypted' => 5] + self::ZERO_COUNTS, $slice['counts']);
        foreach (SurveySecretBackfill::FINISHED_STATUSES as $status) {
            $this->assertSealedJob($jobs[$status], self::VARS);
        }
        $this->assertSame($unfinishedBefore, $this->rows('{{%job}}', array_keys($unfinishedBefore)));
        $this->assertSame([], $this->lines, 'a slice without problems prints nothing; maintenance/run reports the counts');
    }

    /**
     * Every job status is either one a runner may still run or one that is
     * over: no job falls between the migration and the scrub.
     */
    public function testEveryJobStatusIsHandledByTheMigrationOrTheScrub(): void
    {
        $statuses = array_merge(SurveySecretBackfill::UNFINISHED_STATUSES, SurveySecretBackfill::FINISHED_STATUSES);
        sort($statuses);
        $all = Job::statuses();
        sort($all);

        $this->assertSame($all, $statuses);
        $this->assertSame([], array_intersect(SurveySecretBackfill::UNFINISHED_STATUSES, SurveySecretBackfill::FINISHED_STATUSES));
    }

    public function testRunningItAgainChangesNothing(): void
    {
        $ids = $this->everyKindOfRow();
        $this->backfill()->runMigration();
        $this->backfill()->scrubFinishedJobs(['template' => 0, 'job' => 0], 60.0);
        $once = $this->snapshot($ids);
        $this->lines = [];

        $this->assertTrue($this->backfill()->runMigration());
        $slice = $this->backfill()->scrubFinishedJobs(['template' => 0, 'job' => 0], 60.0);

        $this->assertSame($once, $this->snapshot($ids));
        $this->assertSame(0, $slice['counts']['encrypted']);
        $this->assertContains('Schedules: 1 checked, 0 encrypted.', $this->lines);
        $this->assertNotContains('Job templates whose password fields lost their default value: 1 (#' . $ids['template_with_default'] . ').', $this->lines);
        $this->assertCount(1, preg_grep('/^Unfinished jobs: \d+ checked, 0 encrypted\.$/', $this->lines) ?: []);
    }

    public function testSoftDeletedTemplatesAreIncluded(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $survey = '[{"name":"db_password","type":"password","default":"' . self::ANSWER . '"}]';
        \Yii::$app->db->createCommand()->update('{{%job_template}}', ['survey_fields' => $survey, 'deleted_at' => time()], ['id' => $template->id])->execute();
        $jobId = $this->legacyJob($template, self::VARS, Job::STATUS_QUEUED);

        $this->assertTrue($this->backfill()->runMigration());

        $this->assertSealedJob($jobId, self::VARS);
        $this->assertSame('[{"name":"db_password","type":"password","default":""}]', $this->row('{{%job_template}}', (int)$template->id)['survey_fields']);
    }

    /**
     * Not fixable, and documented: without the template there is no survey
     * that says which keys are passwords.
     */
    public function testJobsOfAPurgedTemplateAreLeftAlone(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $ids = [$this->legacyJob($template, self::VARS, Job::STATUS_QUEUED), $this->legacyJob($template, self::VARS, Job::STATUS_SUCCEEDED)];
        \Yii::$app->db->createCommand()->update('{{%job}}', ['job_template_id' => null], ['id' => $ids])->execute();
        $before = $this->rows('{{%job}}', $ids);

        $this->backfill()->runMigration();
        $this->backfill()->scrubFinishedJobs(['template' => 0, 'job' => 0], 60.0);

        $this->assertSame($before, $this->rows('{{%job}}', $ids));
    }

    // -- job templates ------------------------------------------------------------

    public function testPasswordDefaultsAreRemovedAndOtherSurveysKeepTheirBytes(): void
    {
        $withDefault = $this->templateWith('[{"name":"app_env","type":"text","default":"staging"},{"name":"db_password","type":"password","default":"' . self::ANSWER . '"}]');
        $unusable = $this->templateWith('[{"name":"db[pw]","type":"password","default":"' . self::ANSWER . '"}]');
        $untouched = $this->templateWith("[\n  {\"name\": \"db_password\", \"type\": \"password\", \"default\": \"\", \"hint\": \"\\u00e4\"}\n]");
        $noSurvey = $this->templateWith(null);

        $this->assertTrue($this->backfill()->runMigration());

        $this->assertSame(
            '[{"name":"app_env","type":"text","default":"staging"},{"name":"db_password","type":"password","default":""}]',
            $this->row('{{%job_template}}', $withDefault)['survey_fields']
        );
        $this->assertSame('[{"name":"db[pw]","type":"password","default":""}]', $this->row('{{%job_template}}', $unusable)['survey_fields']);
        $this->assertSame(
            "[\n  {\"name\": \"db_password\", \"type\": \"password\", \"default\": \"\", \"hint\": \"\\u00e4\"}\n]",
            $this->row('{{%job_template}}', $untouched)['survey_fields'],
            'a survey without a password default is not rewritten'
        );
        $this->assertNull($this->row('{{%job_template}}', $noSurvey)['survey_fields']);
        $this->assertContains(sprintf('Job templates whose password fields lost their default value: 2 (#%d, #%d).', $withDefault, $unusable), $this->lines);
        $this->assertNoAnswerIn($this->lines);
    }

    public function testTemplatesAnOperatorHasToFixAreListedWithTheirSchedules(): void
    {
        $bracket = $this->templateWith('[{"name":"db[pw]","type":"password"}]');
        $numeric = $this->templateWith('[{"name":"5","type":"password"}]');
        $empty = $this->templateWith('{"first":{"name":"","type":"password"}}');
        $mixed = $this->templateWith('[{"name":["x"],"type":"password"},{"name":"db_password","type":"password"}]');
        $extraVars = '{"db_password":"' . self::ANSWER . '","other":1}';
        $keyInExtraVars = $this->templateWith('[{"name":"db_password","type":"password"}]', $extraVars);
        $invalid = $this->templateWith('[{"name": ');
        $scalar = $this->templateWith('"just text"');
        $bracketSchedule = $this->legacySchedule($this->template($bracket), null);
        $mixedSchedule = $this->legacySchedule($this->template($mixed), '{"db_password":"' . self::ANSWER . '"}');

        $this->assertTrue($this->backfill()->runMigration());

        $this->assertContains(sprintf('Job templates skipped because their survey is not valid JSON: 2 (#%d, #%d).', $invalid, $scalar), $this->lines);
        $this->assertContains(sprintf(
            'Job templates with a password field whose name cannot carry an answer; they cannot be launched until the field is renamed '
            . 'in the survey editor: 4 (#%d, #%d, #%d, #%d).',
            $bracket,
            $numeric,
            $empty,
            $mixed
        ), $this->lines);
        $this->assertContains(sprintf('Schedules that launch those job templates: 2 (#%d, #%d).', $bracketSchedule, $mixedSchedule), $this->lines);
        $this->assertContains(sprintf(
            'Job templates whose Extra Vars hold a password field\'s name, a stored default of the password; remove the key from the Extra Vars: 1 (#%d).',
            $keyInExtraVars
        ), $this->lines);
        $this->assertSame($extraVars, $this->row('{{%job_template}}', $keyInExtraVars)['extra_vars'], 'only reported: removing it would change launches');
        $this->assertSame(['db_password' => self::ANSWER], SurveySecrets::open($this->text($this->row('{{%schedule}}', $mixedSchedule)['secret_extra_vars'])), 'usable names are sealed');
        $this->assertNoAnswerIn($this->lines);
    }

    // -- rows the backfill leaves as they are ---------------------------------------

    public function testMalformedRowsDoNotStopTheRun(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $good = $this->legacyJob($template, self::VARS, Job::STATUS_QUEUED);
        $notJson = $this->jobWith($template, ['extra_vars' => '{"db_password": ', 'runner_payload' => null]);
        $infinite = $this->jobWith($template, ['extra_vars' => '{"db_password":"' . self::ANSWER . '","huge":1e999}', 'runner_payload' => null]);
        $brokenPayload = $this->jobWith($template, ['extra_vars' => '{"db_password":"' . self::ANSWER . '"}', 'runner_payload' => 'not json']);
        $before = $this->rows('{{%job}}', [$notJson, $infinite]);

        $this->assertTrue($this->backfill()->runMigration());

        $this->assertSealedJob($good, self::VARS);
        $this->assertSame($before, $this->rows('{{%job}}', [$notJson, $infinite]));
        $broken = $this->row('{{%job}}', $brokenPayload);
        $this->assertSame('{"db_password":"$encrypted$"}', $broken['extra_vars']);
        $this->assertSame('not json', $broken['runner_payload']);
        $this->assertContains(sprintf('The job #%d was left unchanged after an error: UnexpectedValueException.', $infinite), $this->lines);
        $this->assertContains('Unfinished jobs: 4 checked, 2 encrypted.', $this->lines);
        $this->assertContains(sprintf('Unfinished jobs left unchanged after an error (see the lines above): 1 (#%d).', $infinite), $this->lines);
        $this->assertNoAnswerIn($this->lines);
    }

    public function testARowWhoseMaskedValueWouldNotFitItsColumnIsLeftAsItIs(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $fits = $this->jobWithExtraVarsOf($template, 65535 - 10);
        $tooLong = $this->jobWithExtraVarsOf($template, 65535 - 9);
        $before = $this->row('{{%job}}', $tooLong);

        $this->assertTrue($this->backfill()->runMigration());

        $this->assertSame(65535, strlen($this->text($this->row('{{%job}}', $fits)['extra_vars'])), 'exactly the limit still fits');
        $this->assertSame(['db_password' => 'x'], SurveySecrets::open($this->text($this->row('{{%job}}', $fits)['secret_extra_vars'])));
        $this->assertSame($before, $this->row('{{%job}}', $tooLong));
        $this->assertContains(sprintf('Unfinished jobs left unchanged because the changed value would not fit its column: 1 (#%d).', $tooLong), $this->lines);
    }

    /**
     * Regression guard: sealed answers that cannot be opened (APP_SECRET_KEY
     * changed) are never dropped or overwritten.
     */
    public function testAnUndecryptableSealedSetIsNeverDropped(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $_ENV['APP_SECRET_KEY'] = 'the-key-before-it-was-changed-42';
        $sealed = SurveySecrets::seal(['db_password' => 'sealed-under-the-old-key']);
        $_ENV['APP_SECRET_KEY'] = self::KEY;
        $jobId = $this->legacyJob($template, self::VARS, Job::STATUS_QUEUED);
        $scheduleId = $this->legacySchedule($template, '{"db_password":"' . self::ANSWER . '"}');
        \Yii::$app->db->createCommand()->update('{{%job}}', ['secret_extra_vars' => $sealed], ['id' => $jobId])->execute();
        \Yii::$app->db->createCommand()->update('{{%schedule}}', ['secret_extra_vars' => $sealed], ['id' => $scheduleId])->execute();
        $before = [$this->row('{{%job}}', $jobId), $this->row('{{%schedule}}', $scheduleId)];

        $this->assertTrue($this->backfill()->runMigration());

        $this->assertSame($before, [$this->row('{{%job}}', $jobId), $this->row('{{%schedule}}', $scheduleId)]);
        $this->assertContains(sprintf('Unfinished jobs left unchanged because their stored encrypted answers cannot be decrypted (was APP_SECRET_KEY changed?): 1 (#%d).', $jobId), $this->lines);
        $this->assertContains(sprintf('Schedules left unchanged because their stored encrypted answers cannot be decrypted (was APP_SECRET_KEY changed?): 1 (#%d).', $scheduleId), $this->lines);
    }

    public function testAStoredCommandWithoutTheExtraVarsAsStoredIsCleared(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $jobId = $this->legacyJob($template, self::VARS, Job::STATUS_RUNNING);
        \Yii::$app->db->createCommand()->update('{{%job}}', ['execution_command' => 'e2e-artifact-job ' . self::ANSWER], ['id' => $jobId])->execute();

        $this->backfill()->runMigration();

        $this->assertNull($this->row('{{%job}}', $jobId)['execution_command']);
        $this->assertContains(sprintf('Unfinished jobs whose stored command was cleared because it did not contain the extra vars as stored: 1 (#%d).', $jobId), $this->lines);
    }

    // -- APP_SECRET_KEY and the schema ---------------------------------------------

    public function testWithoutAUsableKeyNothingIsChanged(): void
    {
        $ids = $this->everyKindOfRow();
        $before = $this->snapshot($ids);
        unset($_ENV['APP_SECRET_KEY']);

        $this->assertFalse($this->backfill()->runMigration());
        $slice = $this->backfill()->scrubFinishedJobs(['template' => 0, 'job' => 0], 60.0);

        $this->assertSame($before, $this->snapshot($ids), 'not even the template defaults: the key is checked before any row is touched');
        $this->assertSame([
            'APP_SECRET_KEY is not set or shorter than 16 characters, so the stored survey password answers cannot be encrypted. '
                . 'Set APP_SECRET_KEY in .env to the value your credentials were encrypted with, then start the containers again. Nothing was changed.',
        ], $this->lines);
        $this->assertSame(['status' => SurveySecretBackfill::STATUS_NO_KEY, 'cursor' => ['template' => 0, 'job' => 0], 'counts' => self::ZERO_COUNTS], $slice);
    }

    public function testWithoutPasswordFieldsTheKeyIsNotNeeded(): void
    {
        $this->templateWith('[{"name":"app_env","type":"text","default":"staging"}]');
        $_ENV['APP_SECRET_KEY'] = 'short';

        $this->assertTrue($this->backfill()->runMigration());
        $slice = $this->backfill()->scrubFinishedJobs(['template' => 0, 'job' => 0], 60.0);

        $this->assertCount(1, $this->lines);
        $this->assertMatchesRegularExpression('/^Survey password answers: \d+ job templates checked, 0 of them with password fields\.$/', $this->lines[0]);
        $this->assertSame(SurveySecretBackfill::STATUS_DONE, $slice['status']);
    }

    /**
     * Code can run before m000080 (a schedule-runner that starts while the
     * app container migrates): nothing is read or written then.
     */
    public function testWithoutTheColumnsOfM000080NothingIsRead(): void
    {
        $db = new class () extends Connection {
            public function getTableSchema($name, $refresh = false)
            {
                return null;
            }
        };
        $backfill = new SurveySecretBackfill($db, function (string $line): void {
            $this->lines[] = $line;
        });

        $slice = $backfill->scrubFinishedJobs(['template' => 3, 'job' => 7], 60.0);
        $migrated = $backfill->runMigration();

        $this->assertSame(['status' => SurveySecretBackfill::STATUS_PENDING_MIGRATION, 'cursor' => ['template' => 3, 'job' => 7], 'counts' => self::ZERO_COUNTS], $slice);
        $this->assertFalse($migrated);
        $this->assertSame(['The secret_extra_vars columns of migration m000080 are missing; run the migrations in order. Nothing was changed.'], $this->lines);
        $this->assertFalse($db->getIsActive(), 'it never connected');
    }

    // -- logs, failures, pages ------------------------------------------------------

    /**
     * Neither the report lines nor the application log carry an answer, on
     * success and on every failure path; no SQL statement is logged while
     * it runs, and the connection's logging is restored afterwards.
     */
    public function testNeitherOutputNorLogsCarryAnAnswer(): void
    {
        $ids = $this->everyKindOfRow();
        $failing = $this->legacyJob($this->template($ids['password_template']), self::VARS, Job::STATUS_QUEUED);
        $logger = new Logger();
        $logger->flushInterval = 0;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);

        try {
            $backfill = $this->failingOn($failing);
            $backfill->runMigration();
            $backfill->scrubFinishedJobs(['template' => 0, 'job' => 0], 60.0);
        } finally {
            \Yii::setLogger($previous);
        }

        $this->assertNoAnswerIn($this->lines);
        $messages = array_map(static fn (array $message): string => is_string($message[0]) ? $message[0] : (string)json_encode($message[0]), $logger->messages);
        $this->assertNoAnswerIn($messages);
        $categories = array_column($logger->messages, 2);
        $this->assertSame([], preg_grep('/^yii\\\\db\\\\Command/', $categories), 'no SQL statement was logged');
        $this->assertTrue(\Yii::$app->db->enableLogging);
        $this->assertTrue(\Yii::$app->db->enableProfiling);
        $this->assertContains(sprintf('The job #%d was left unchanged after an error: yii\db\Exception (SQLSTATE 22001, driver error 1406).', $failing), $this->lines);
    }

    public function testAnErrorInOneRowLeavesOnlyThatRowUnchanged(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $first = $this->legacyJob($template, self::VARS, Job::STATUS_QUEUED);
        $failing = $this->legacyJob($template, self::VARS, Job::STATUS_QUEUED);
        $last = $this->legacyJob($template, self::VARS, Job::STATUS_QUEUED);
        $before = $this->row('{{%job}}', $failing);

        $this->assertTrue($this->failingOn($failing, new \yii\db\Exception('Lost the statement ' . self::ANSWER))->runMigration());

        $this->assertSealedJob($first, self::VARS);
        $this->assertSealedJob($last, self::VARS);
        $this->assertSame($before, $this->row('{{%job}}', $failing));
        $this->assertContains(sprintf('The job #%d was left unchanged after an error: yii\db\Exception (SQLSTATE unknown, driver error unknown).', $failing), $this->lines);
        $this->assertContains('Unfinished jobs: 3 checked, 2 encrypted.', $this->lines);
        $this->assertNoAnswerIn($this->lines);
    }

    public function testAnUnexpectedErrorStopsTheRunWithAFixedLine(): void
    {
        $backfill = new class (\Yii::$app->db, function (string $line): void {
            $this->lines[] = $line;
        }) extends SurveySecretBackfill {
            protected function scanTemplates(): SurveySecretTemplateScan
            {
                throw new \yii\db\Exception(
                    "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away\nThe SQL being executed was: UPDATE `job` SET `extra_vars`='backfill-answer-marker-7731'",
                    ['HY000', 2006, 'MySQL server has gone away']
                );
            }
        };

        $migrated = $backfill->runMigration();
        $slice = $backfill->scrubFinishedJobs(['template' => 4, 'job' => 9], 60.0);

        $this->assertFalse($migrated);
        $this->assertSame(['status' => SurveySecretBackfill::STATUS_ERROR, 'cursor' => ['template' => 4, 'job' => 9], 'counts' => self::ZERO_COUNTS], $slice);
        $line = 'The encryption of stored survey password answers stopped after an error: yii\db\Exception (SQLSTATE HY000, driver error 2006). '
            . 'Rows already done stay done; running it again continues.';
        $this->assertSame([$line, $line], $this->lines);
        $this->assertTrue(\Yii::$app->db->enableLogging, 'restored after a failure too');
        $this->assertTrue(\Yii::$app->db->enableProfiling);
    }

    public function testProgressIsReportedEveryTwentyPages(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $ids = [];
        for ($i = 0; $i < 21; $i++) {
            $ids[] = $this->legacyJob($template, self::VARS, Job::STATUS_QUEUED, false);
        }

        $this->assertTrue($this->backfill(1)->runMigration());

        foreach ($ids as $id) {
            $this->assertSealedJob($id, self::VARS);
        }
        $this->assertContains('Unfinished jobs: 20 checked, 20 encrypted so far.', $this->lines);
        $this->assertContains('Unfinished jobs: 21 checked, 21 encrypted.', $this->lines);
    }

    /**
     * One transaction per page: when the run is interrupted (here by the
     * output after page 20; in real life a killed container), the pages
     * before stay done, and the next run finishes the rest.
     */
    public function testPagesDoneBeforeAnInterruptionStayDone(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $ids = [];
        for ($i = 0; $i < 21; $i++) {
            $ids[] = $this->legacyJob($template, self::VARS, Job::STATUS_QUEUED, false);
        }
        $interrupted = new SurveySecretBackfill(\Yii::$app->db, function (string $line): void {
            $this->lines[] = $line;
            if (str_ends_with($line, 'so far.')) {
                throw new \RuntimeException('interrupted');
            }
        }, 1);
        $last = $this->row('{{%job}}', $ids[20]);

        $this->assertFalse($interrupted->runMigration());

        foreach (array_slice($ids, 0, 20) as $id) {
            $this->assertSealedJob($id, self::VARS);
        }
        $this->assertSame($last, $this->row('{{%job}}', $ids[20]), 'not reached');
        $this->assertSame('The encryption of stored survey password answers stopped after an error: RuntimeException. Rows already done stay done; running it again continues.', end($this->lines));

        $this->assertTrue($this->backfill(1)->runMigration());

        $this->assertSealedJob($ids[20], self::VARS);
        $this->assertContains('Unfinished jobs: 21 checked, 1 encrypted.', $this->lines);
    }

    // -- the scrub's budget and cursor -----------------------------------------------

    public function testTheScrubStopsAtItsBudgetAndContinuesFromItsCursor(): void
    {
        $first = $this->createPasswordSurveyTemplate($this->userId);
        $second = $this->createPasswordSurveyTemplate($this->userId);
        $jobs = [];
        foreach ([$first, $first, $first, $second, $second] as $template) {
            $jobs[] = $this->legacyJob($template, self::VARS, Job::STATUS_SUCCEEDED);
        }
        $backfill = $this->backfill(2);

        $slice1 = $backfill->scrubFinishedJobs(['template' => 0, 'job' => 0], 0.0);
        $slice2 = $backfill->scrubFinishedJobs($slice1['cursor'], 0.0);
        $slice3 = $backfill->scrubFinishedJobs($slice2['cursor'], 0.0);

        $this->assertSame([SurveySecretBackfill::STATUS_PARTIAL, ['template' => (int)$first->id, 'job' => $jobs[1]], 2], [$slice1['status'], $slice1['cursor'], $slice1['counts']['checked']]);
        $this->assertSame([SurveySecretBackfill::STATUS_PARTIAL, ['template' => (int)$second->id, 'job' => $jobs[4]], 3], [$slice2['status'], $slice2['cursor'], $slice2['counts']['checked']]);
        $this->assertSame([SurveySecretBackfill::STATUS_DONE, 0], [$slice3['status'], $slice3['counts']['checked']]);
        foreach ($jobs as $id) {
            $this->assertSealedJob($id, self::VARS);
        }
    }

    /**
     * Regression guard (review finding TQ-1): job IDs are global, so the
     * jobs of different templates interleave. The cursor's job ID belongs to
     * the cursor's template only; every later template starts at its first
     * job. Starting it after the cursor's job ID would skip its older jobs
     * for good: the scrub would still report done, and the done mark would
     * stop every later tick, while those answers stay in plaintext.
     */
    public function testTheScrubStartsEveryLaterTemplateFromItsFirstJob(): void
    {
        $first = $this->createPasswordSurveyTemplate($this->userId);
        $second = $this->createPasswordSurveyTemplate($this->userId);
        $jobs = [$this->legacyJob($second, self::VARS, Job::STATUS_SUCCEEDED)];
        for ($i = 0; $i < 3; $i++) {
            $jobs[] = $this->legacyJob($first, self::VARS, Job::STATUS_SUCCEEDED);
        }
        $backfill = $this->backfill(2);

        $cursor = ['template' => 0, 'job' => 0];
        $checked = 0;
        $slices = 0;
        do {
            $slice = $backfill->scrubFinishedJobs($cursor, 0.0);
            $cursor = $slice['cursor'];
            $checked += $slice['counts']['checked'];
            $slices++;
        } while ($slice['status'] !== SurveySecretBackfill::STATUS_DONE && $slices < 10);

        $this->assertSame(SurveySecretBackfill::STATUS_DONE, $slice['status']);
        $this->assertSame(4, $checked, 'every finished job is checked once');
        foreach ($jobs as $id) {
            $this->assertSealedJob($id, self::VARS);
        }
    }

    /**
     * @return array<string, array{0: mixed, 1: array{template: int, job: int}}>
     */
    public static function storedCursorProvider(): array
    {
        return [
            'missing' => [false, ['template' => 0, 'job' => 0]],
            'a cursor' => [['template' => 12, 'job' => 3456], ['template' => 12, 'job' => 3456]],
            'negative' => [['template' => -1, 'job' => -5], ['template' => 0, 'job' => 0]],
            'strings' => [['template' => '12', 'job' => '3'], ['template' => 0, 'job' => 0]],
            'incomplete' => [['template' => 12], ['template' => 0, 'job' => 0]],
            'a scalar' => ['12:3', ['template' => 0, 'job' => 0]],
        ];
    }

    /**
     * @dataProvider storedCursorProvider
     * @param array{template: int, job: int} $expected
     */
    public function testAStoredCursorIsReadOnlyWhenItIsOne(mixed $stored, array $expected): void
    {
        $this->assertSame($expected, SurveySecretBackfill::cursorFrom($stored));
    }

    // -- fixtures and assertions ----------------------------------------------------

    private function backfill(int $pageSize = SurveySecretBackfill::PAGE_SIZE): SurveySecretBackfill
    {
        return new SurveySecretBackfill(\Yii::$app->db, function (string $line): void {
            $this->lines[] = $line;
        }, $pageSize);
    }

    /**
     * A backfill whose write of job $failingId fails like a database error,
     * with a message that quotes the statement and its values.
     */
    private function failingOn(int $failingId, ?\yii\db\Exception $error = null): SurveySecretBackfill
    {
        $error ??= new \yii\db\Exception(
            "SQLSTATE[22001]: String data, right truncated: 1406 Data too long\nThe SQL being executed was: UPDATE `job` SET `extra_vars`='" . self::ANSWER . "'",
            ['22001', 1406, "Data too long for column 'extra_vars' at row 1"]
        );

        return new class (\Yii::$app->db, function (string $line): void {
            $this->lines[] = $line;
        }, $failingId, $error) extends SurveySecretBackfill {
            public function __construct(Connection $db, \Closure $output, private readonly int $failingId, private readonly \yii\db\Exception $error)
            {
                parent::__construct($db, $output);
            }

            protected function updateRow(string $table, array $columns, int $id): void
            {
                if ($table === '{{%job}}' && $id === $this->failingId) {
                    throw $this->error;
                }
                parent::updateRow($table, $columns, $id);
            }
        };
    }

    /**
     * One job of $template in every status, with the extra vars in
     * plaintext; jobs a runner claimed have a stored command.
     *
     * @return array<string, int> job IDs by status
     */
    private function jobInEveryStatus(JobTemplate $template): array
    {
        $unclaimed = [Job::STATUS_PENDING, Job::STATUS_QUEUED, Job::STATUS_PENDING_APPROVAL, Job::STATUS_REJECTED];
        $jobs = [];
        foreach (array_merge(SurveySecretBackfill::UNFINISHED_STATUSES, SurveySecretBackfill::FINISHED_STATUSES) as $status) {
            $jobs[$status] = $this->legacyJob($template, self::VARS, $status, !in_array($status, $unclaimed, true));
        }

        return $jobs;
    }

    /**
     * Rows of every kind the backfill meets, for the idempotency and the
     * failure tests.
     *
     * @return array<string, int>
     */
    private function everyKindOfRow(): array
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $ids = ['password_template' => (int)$template->id];
        foreach ($this->jobInEveryStatus($template) as $status => $id) {
            $ids['job_' . $status] = $id;
        }
        $ids['schedule'] = $this->legacySchedule($template, '{"db_password":"' . self::ANSWER . '"}');
        $ids['template_with_default'] = $this->templateWith('[{"name":"db_password","type":"password","default":"' . self::ANSWER . '"}]');
        $ids['template_unusable'] = $this->templateWith('[{"name":"db[pw]","type":"password"}]');
        $ids['job_infinite'] = $this->jobWith($template, ['extra_vars' => '{"db_password":"' . self::ANSWER . '","huge":1e999}', 'runner_payload' => null]);
        $ids['job_too_long'] = $this->jobWithExtraVarsOf($template, 65535 - 9);
        $_ENV['APP_SECRET_KEY'] = 'the-key-before-it-was-changed-42';
        $sealed = SurveySecrets::seal(['db_password' => 'old']);
        $_ENV['APP_SECRET_KEY'] = self::KEY;
        $ids['job_undecryptable'] = $this->jobWith($template, ['extra_vars' => '{"db_password":"' . self::ANSWER . '"}', 'secret_extra_vars' => $sealed]);
        $ids['job_finished_cleared'] = $this->legacyJob($template, self::VARS, Job::STATUS_FAILED);
        \Yii::$app->db->createCommand()->update('{{%job}}', ['execution_command' => 'e2e-artifact-job'], ['id' => $ids['job_finished_cleared']])->execute();

        return $ids;
    }

    /**
     * A job template stored with $survey and $extraVars as given: older
     * releases and the API stored them without checks.
     */
    private function templateWith(?string $survey, ?string $extraVars = null): int
    {
        $template = $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        );
        \Yii::$app->db->createCommand()->update('{{%job_template}}', ['survey_fields' => $survey, 'extra_vars' => $extraVars], ['id' => $template->id])->execute();

        return (int)$template->id;
    }

    private function template(int $id): JobTemplate
    {
        $template = JobTemplate::findWithDeleted()->where(['id' => $id])->one();
        $this->assertInstanceOf(JobTemplate::class, $template);

        return $template;
    }

    /**
     * A job as releases up to 2.8.1 stored it: the extra vars in plaintext
     * in job.extra_vars, in the runner payload (built by the real
     * JobLaunchService) and, once claimed, in the command as the claim
     * joined it.
     *
     * @param array<string, mixed> $vars
     */
    private function legacyJob(JobTemplate $template, array $vars, string $status, bool $claimed = true): int
    {
        $job = $this->createJob((int)$template->id, $this->userId, $status);
        $job->extra_vars = (string)json_encode($vars, JSON_UNESCAPED_UNICODE);
        $payload = (new \ReflectionMethod(JobLaunchService::class, 'buildRunnerPayload'))->invoke(new JobLaunchService(), $template, $job);
        $job->runner_payload = is_string($payload) ? $payload : null;
        if ($claimed) {
            $raw = $job->decodedRunnerPayload();
            $job->execution_command = implode(' ', (new RunnerCommandBuilder())->build([
                'inventory_type' => 'static',
                'playbook_path' => '/var/www/runtime/projects/' . $template->project_id . '/site.yml',
                'verbosity' => 3,
                'forks' => 5,
                'extra_vars' => (string)$raw['extra_vars'],
                'check_mode' => true,
            ]));
        }
        $job->save(false);

        return (int)$job->id;
    }

    /**
     * A queued job of $template with $columns stored as given.
     *
     * @param array<string, string|null> $columns
     */
    private function jobWith(JobTemplate $template, array $columns): int
    {
        $id = (int)$this->createJob((int)$template->id, $this->userId, Job::STATUS_QUEUED)->id;
        \Yii::$app->db->createCommand()->update('{{%job}}', $columns, ['id' => $id])->execute();

        return $id;
    }

    /**
     * A queued job whose extra vars are $bytes long and grow by ten bytes
     * when the one-character answer becomes the placeholder.
     */
    private function jobWithExtraVarsOf(JobTemplate $template, int $bytes): int
    {
        $pad = $bytes - strlen('{"pad":"","db_password":"x"}');
        $payload = (string)json_encode(['template_id' => $template->id, 'extra_vars' => '{"db_password":"x"}'], JSON_UNESCAPED_UNICODE);

        return $this->jobWith($template, ['extra_vars' => '{"pad":"' . str_repeat('a', $pad) . '","db_password":"x"}', 'runner_payload' => $payload]);
    }

    private function legacySchedule(JobTemplate $template, ?string $extraVars): int
    {
        \Yii::$app->db->createCommand()->insert('{{%schedule}}', [
            'name' => 'backfill-' . uniqid('', true),
            'job_template_id' => $template->id,
            'cron_expression' => '0 3 * * *',
            'timezone' => 'UTC',
            'extra_vars' => $extraVars,
            'enabled' => 1,
            'created_by' => $this->userId,
            'created_at' => time(),
            'updated_at' => time(),
        ])->execute();

        return (int)\Yii::$app->db->getLastInsertID();
    }

    /**
     * The job's answers are sealed, every other column shows the
     * placeholder, and the runner still gets every value.
     *
     * @param array<string, mixed> $original the extra vars as launched
     */
    private function assertSealedJob(int $id, array $original): void
    {
        $row = $this->row('{{%job}}', $id);
        foreach (['extra_vars', 'runner_payload', 'execution_command'] as $column) {
            $this->assertStringNotContainsString(self::ANSWER, (string)$row[$column], "job #{$id} {$column}");
        }
        $public = json_decode($this->text($row['extra_vars']), true);
        $this->assertIsArray($public);
        $this->assertSame(SurveySecrets::PLACEHOLDER, $public['db_password']);
        $this->assertSame($original, SurveySecrets::reveal($public, SurveySecrets::open($this->text($row['secret_extra_vars']))), 'the runner still gets every value');
        $payload = json_decode($this->text($row['runner_payload']), true);
        $this->assertIsArray($payload);
        $this->assertSame($row['extra_vars'], $payload['extra_vars']);
        if ($row['execution_command'] !== null) {
            $this->assertStringContainsString('--extra-vars ' . $row['extra_vars'], (string)$row['execution_command']);
        }
    }

    /**
     * @param list<string> $lines
     */
    private function assertNoAnswerIn(array $lines): void
    {
        $this->assertStringNotContainsString(self::ANSWER, implode("\n", $lines));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $table, int $id): array
    {
        $row = (new Query())->from($table)->where(['id' => $id])->one();
        $this->assertIsArray($row, "{$table} #{$id}");

        return $row;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $table, array $ids): array
    {
        $rows = [];
        foreach ($ids as $id) {
            $rows[$id] = $this->row($table, $id);
        }

        return $rows;
    }

    /**
     * Every column of the rows in $ids.
     *
     * @param array<string, int> $ids
     * @return array<string, array<string, mixed>>
     */
    private function snapshot(array $ids): array
    {
        $tables = ['schedule' => '{{%schedule}}', 'template_with_default' => '{{%job_template}}', 'template_unusable' => '{{%job_template}}', 'password_template' => '{{%job_template}}'];
        $snapshot = [];
        foreach ($ids as $name => $id) {
            $snapshot[$name] = $this->row($tables[$name] ?? '{{%job}}', $id);
        }

        return $snapshot;
    }

    private function text(mixed $value): string
    {
        $this->assertIsString($value);

        return $value;
    }
}
