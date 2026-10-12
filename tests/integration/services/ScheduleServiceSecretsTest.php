<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\SurveySecrets;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\Schedule;
use app\services\ScheduleService;
use app\tests\integration\DbTestCase;
use app\tests\integration\ScheduleAnswerFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\log\Logger;

/**
 * Scheduled launches with password answers.
 *
 * Regression: a schedule passed its extra vars, password answers included,
 * to the launch in plaintext. Now it stores them sealed and passes them in
 * the LaunchContext, so the job gets them sealed too.
 */
class ScheduleServiceSecretsTest extends DbTestCase
{
    use ScheduleAnswerFixtures;
    use TeamScopeFixtures;

    private const ANSWER = 'scheduled-answer-marker-6626';

    private ScheduleService $service;
    private RecordingNotificationDispatcher $dispatcher;
    private mixed $originalDispatcher = null;
    private mixed $originalKey;
    private int $userId;
    private JobTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = 'scheduled-launch-test-key-0123456789';
        $this->service = \Yii::$app->get('scheduleService');
        $this->originalDispatcher = \Yii::$app->getComponents(true)['notificationDispatcher'] ?? null;
        $this->dispatcher = new RecordingNotificationDispatcher();
        \Yii::$app->set('notificationDispatcher', $this->dispatcher);
        $this->userId = (int)$this->createUserWithRole('scheduled-secrets', 'operator')->id;
        $this->template = $this->createPasswordSurveyTemplate($this->userId);
    }

    protected function tearDown(): void
    {
        \Yii::$app->set('notificationDispatcher', $this->originalDispatcher);
        if ($this->originalKey === null) {
            unset($_ENV['APP_SECRET_KEY']);
        } else {
            $_ENV['APP_SECRET_KEY'] = $this->originalKey;
        }
        parent::tearDown();
    }

    public function testAScheduledJobGetsTheStoredAnswersSealed(): void
    {
        $schedule = $this->createScheduleWithAnswers((int)$this->template->id, $this->userId, [self::SURVEY_PASSWORD_FIELD => self::ANSWER], [self::SURVEY_TEXT_FIELD => 'prod'], time() - 60);

        $this->assertSame(1, $this->service->runDue());

        $job = $this->onlyJobOf($this->template);
        $this->assertSame(
            [self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER],
            json_decode((string)$job->extra_vars, true)
        );
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], SurveySecrets::open($job->getSealedSecretExtraVars()));
        $this->assertStringNotContainsString(self::ANSWER, (string)$job->runner_payload);
        $audit = $this->launchAudit($job);
        $this->assertSame((int)$schedule->id, $audit['schedule_id']);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD], $audit['carried_secret_keys']);
        $this->assertSame([], $this->dispatcher->payloadsOf(NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH));
    }

    /**
     * A key that became a password field after the schedule was saved: the
     * run seals it in the schedule too, so the schedule page stops showing it.
     */
    public function testAPlainAnswerIsSealedInTheScheduleAndTheJob(): void
    {
        $schedule = $this->createPlainSchedule(
            (int)$this->template->id,
            $this->userId,
            (string)json_encode([self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => self::ANSWER]),
            time() - 60
        );

        $this->service->runDue();

        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], $this->sealedAnswersOf($schedule));
        $this->assertStringNotContainsString(self::ANSWER, (string)$this->reloadSchedule($schedule)->extra_vars);
        $job = $this->onlyJobOf($this->template);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], SurveySecrets::open($job->getSealedSecretExtraVars()));
        $this->assertSame(1, (int)AuditLog::find()->where(['action' => AuditLog::ACTION_SCHEDULE_UPDATED, 'object_id' => $schedule->id])->count());
    }

    /**
     * Once secret, always secret: a stored answer of a field the survey no
     * longer has still reaches the job sealed.
     */
    public function testAnAnswerOfAFieldRemovedSinceStaysSealed(): void
    {
        $this->createScheduleWithAnswers((int)$this->template->id, $this->userId, [self::SURVEY_PASSWORD_FIELD => self::ANSWER], [], time() - 60);
        $this->template->survey_fields = null;
        $this->template->save(false);

        $this->service->runDue();

        $job = $this->onlyJobOf($this->template);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER], json_decode((string)$job->extra_vars, true));
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], SurveySecrets::open($job->getSealedSecretExtraVars()));
    }

    /**
     * Answers sealed with an APP_SECRET_KEY that has been replaced: a failed
     * launch like any other (logged and notified, not audited), and the
     * schedules after it still run.
     */
    public function testUndecryptableAnswersAreAFailedLaunchAndTheOthersStillRun(): void
    {
        $broken = $this->createScheduleWithAnswers((int)$this->template->id, $this->userId, [self::SURVEY_PASSWORD_FIELD => self::ANSWER], [], time() - 60);
        $_ENV['APP_SECRET_KEY'] = 'a-rotated-key-that-cannot-open-it-0123';
        $other = $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        );
        $this->createPlainSchedule((int)$other->id, $this->userId, '{"env":"next"}', time() - 60);
        $auditsBefore = (int)AuditLog::find()->count();
        $logger = new Logger();
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);

        try {
            $launched = $this->service->runDue();
        } finally {
            \Yii::setLogger($previous);
        }

        $this->assertSame(1, $launched);
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $this->template->id])->count());
        $this->assertSame(1, (int)Job::find()->where(['job_template_id' => $other->id])->count(), 'the next schedule still runs');
        $message = "Schedule #{$broken->id} ({$broken->name}) failed to launch: "
            . 'Its stored password answers cannot be decrypted (was APP_SECRET_KEY changed?); edit the schedule and enter them again.';
        $errors = array_values(array_filter($logger->messages, static fn (array $m): bool => $m[2] === ScheduleService::class && $m[1] === Logger::LEVEL_ERROR));
        $this->assertSame([$message], array_column($errors, 0));
        $failures = $this->dispatcher->payloadsOf(NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH);
        $this->assertCount(1, $failures);
        $this->assertSame((string)$broken->id, $failures[0]['schedule']['id']);
        $this->assertStringStartsWith('Its stored password answers cannot be decrypted', $failures[0]['schedule']['error']);
        $this->assertSame(
            0,
            (int)AuditLog::find()->where(['>', 'id', 0])->andWhere(['object_type' => 'schedule', 'object_id' => $broken->id])->count(),
            'no audit entry for the failed launch'
        );
        $this->assertSame($auditsBefore + 1, (int)AuditLog::find()->count(), 'only the other job\'s launch');
    }

    // -- Helpers ----------------------------------------------------------------

    private function onlyJobOf(JobTemplate $template): Job
    {
        /** @var Job[] $jobs */
        $jobs = Job::find()->where(['job_template_id' => $template->id])->all();
        $this->assertCount(1, $jobs, 'failed launches: ' . json_encode($this->dispatcher->payloadsOf(NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH)));

        return $jobs[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function launchAudit(Job $job): array
    {
        /** @var AuditLog|null $entry */
        $entry = AuditLog::find()->where(['action' => AuditLog::ACTION_JOB_LAUNCHED, 'object_type' => 'job', 'object_id' => $job->id])->one();
        $this->assertNotNull($entry);
        $metadata = json_decode((string)$entry->metadata, true);
        $this->assertIsArray($metadata);

        return $metadata;
    }
}
