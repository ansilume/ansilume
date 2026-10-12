<?php

declare(strict_types=1);

namespace app\tests\integration\migrations;

use app\components\SurveySecrets;
use app\models\Job;
use app\services\JobLaunchService;
use app\services\MaintenanceService;
use app\tests\integration\DbTestCase;
use yii\db\Query;

/**
 * m000081_000000_encrypt_survey_password_answers: the data migration that
 * seals the password answers releases up to 2.8.1 stored in plaintext
 * (SurveySecretBackfillTest covers the backfill itself). It runs inside the
 * test's transaction, as it changes data only, and is rolled back with it.
 */
class EncryptSurveyPasswordAnswersMigrationTest extends DbTestCase
{
    /** A test value standing for a password answer. */
    private const ANSWER = 'migration-answer-marker-3307';

    private mixed $originalKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = 'survey-migration-test-key-0123456789';
    }

    protected function tearDown(): void
    {
        if ($this->originalKey === null) {
            unset($_ENV['APP_SECRET_KEY']);
        } else {
            $_ENV['APP_SECRET_KEY'] = $this->originalKey;
        }
        parent::tearDown();
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/m000081_000000_encrypt_survey_password_answers.php';
    }

    public function testUpSealsTheAnswersAndPrintsCountsOnly(): void
    {
        $jobId = $this->plaintextJob();

        [$result, $output] = $this->up();

        $this->assertTrue($result);
        $row = (new Query())->from('{{%job}}')->where(['id' => $jobId])->one();
        $this->assertIsArray($row);
        $this->assertSame('{"app_env":"prod","db_password":"$encrypted$"}', $row['extra_vars']);
        $this->assertSame(['db_password' => self::ANSWER], SurveySecrets::open(is_string($row['secret_extra_vars']) ? $row['secret_extra_vars'] : null));
        $this->assertStringContainsString("    > Unfinished jobs: 1 checked, 1 encrypted.\n", $output);
        $this->assertMatchesRegularExpression('/^    > Survey password answers: \d+ job templates checked, \d+ of them with password fields\.$/m', $output);
        $this->assertStringNotContainsString(self::ANSWER, $output);
        $this->assertStringNotContainsString('UPDATE', $output, 'no SQL is echoed');
    }

    /**
     * The history scrub of maintenance/run starts over: when this migration
     * runs, the data may be from before the release (a restored backup),
     * whatever the cache says.
     */
    public function testUpMakesTheHistoryScrubStartOver(): void
    {
        \Yii::$app->cache->set(MaintenanceService::SURVEY_SECRET_SCRUB_DONE_KEY, time(), 0);
        \Yii::$app->cache->set(MaintenanceService::SURVEY_SECRET_SCRUB_CURSOR_KEY, ['template' => 3, 'job' => 4], 0);

        try {
            $this->up();

            $this->assertFalse(\Yii::$app->cache->get(MaintenanceService::SURVEY_SECRET_SCRUB_DONE_KEY));
            $this->assertFalse(\Yii::$app->cache->get(MaintenanceService::SURVEY_SECRET_SCRUB_CURSOR_KEY));
        } finally {
            \Yii::$app->cache->flush();
        }
    }

    /**
     * Fails before any row is touched, with what to do; the entrypoint then
     * stops, and the next start runs it again.
     */
    public function testUpFailsWithAnActionableMessageWhenTheKeyCannotEncrypt(): void
    {
        $jobId = $this->plaintextJob();
        $before = (new Query())->from('{{%job}}')->where(['id' => $jobId])->one();
        $_ENV['APP_SECRET_KEY'] = 'CHANGE-ME';

        [$result, $output] = $this->up();

        $this->assertFalse($result);
        $this->assertSame(
            '    > APP_SECRET_KEY is not set or shorter than 16 characters, so the stored survey password answers cannot be encrypted. '
            . "Set APP_SECRET_KEY in .env to the value your credentials were encrypted with, then start the containers again. Nothing was changed.\n",
            $output
        );
        $this->assertSame($before, (new Query())->from('{{%job}}')->where(['id' => $jobId])->one());
    }

    /**
     * Nothing to revert: the answers stay sealed, and the schema belongs to
     * m000080.
     */
    public function testDownSucceedsAndChangesNothing(): void
    {
        $jobId = $this->plaintextJob();
        $this->up();
        $after = (new Query())->from('{{%job}}')->where(['id' => $jobId])->one();

        $this->assertTrue($this->migration()->down());
        $this->assertSame($after, (new Query())->from('{{%job}}')->where(['id' => $jobId])->one());
    }

    /**
     * A queued job as releases up to 2.8.1 stored it.
     */
    private function plaintextJob(): int
    {
        $userId = (int)$this->createUser('m81')->id;
        $template = $this->createPasswordSurveyTemplate($userId);
        $job = $this->createJob((int)$template->id, $userId, Job::STATUS_QUEUED);
        $job->extra_vars = (string)json_encode(['app_env' => 'prod', 'db_password' => self::ANSWER]);
        $payload = (new \ReflectionMethod(JobLaunchService::class, 'buildRunnerPayload'))->invoke(new JobLaunchService(), $template, $job);
        $job->runner_payload = is_string($payload) ? $payload : null;
        $job->save(false);

        return (int)$job->id;
    }

    private function migration(): \m000081_000000_encrypt_survey_password_answers
    {
        return new \m000081_000000_encrypt_survey_password_answers(['db' => \Yii::$app->db, 'compact' => true]);
    }

    /**
     * @return array{0: bool, 1: string} the result of up() and what it printed
     */
    private function up(): array
    {
        ob_start();
        try {
            $result = $this->migration()->up();
        } finally {
            $output = (string)ob_get_clean();
        }

        return [$result, $output];
    }
}
