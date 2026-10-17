<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\SurveyField;
use app\components\SurveySecrets;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\Schedule;
use app\services\LaunchContext;
use app\services\ScheduleLaunchSecrets;
use app\services\ScheduleSecrets;
use app\services\ScheduleService;
use app\services\WorkflowAccessChecker;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;
use app\tests\integration\ScheduleAnswerFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\base\ModelEvent;
use yii\db\BaseActiveRecord;
use yii\log\Logger;

/**
 * A schedule edited while the scheduler launches it.
 *
 * Regression (SEC-EXP-1): when a schedule held a plain answer (the value of
 * a key that became a password field after the schedule was saved), the
 * launch re-read the row to seal it, but never checked that the row still
 * pointed at the job template being launched. A schedule moved to another
 * template right after the scheduler claimed its run had the answer entered
 * for the new template launched with the old one. The sealing save could
 * also overwrite an edit saved between that read and the save.
 *
 * The launch now reads, seals and saves the row in one transaction that
 * locks it (SELECT ... FOR UPDATE), fails when the locked row is gone or
 * points at another template, and launches what the locked row holds. A
 * save of the schedule writes its extra vars and its sealed answers
 * together, so a save that read the row before the seal cannot leave
 * behind the sealed answer of a key it removed, or a placeholder without
 * its answer.
 *
 * One database connection cannot run two transactions at once, so the
 * edits run at the points where they can land in production: committed
 * before the lock is taken (right after the claim, while the scheduler
 * checks whom the schedule runs as), or after the lock is released. That
 * the read and the save happen under one lock is shown by the statements
 * the launch sends.
 */
class ScheduleSecretsConcurrentEditTest extends DbTestCase
{
    use RestoresComponents;
    use ScheduleAnswerFixtures;
    use TeamScopeFixtures;

    private const PLAIN_ANSWER = 'concurrent-plain-marker-5521';
    private const OTHER_TEMPLATE_ANSWER = 'concurrent-other-template-marker-7730';
    private const EDITED_ANSWER = 'concurrent-edited-marker-8814';

    private const COMMAND_CATEGORIES = ['yii\db\Command::query', 'yii\db\Command::execute'];

    private ScheduleService $service;
    private RecordingNotificationDispatcher $dispatcher;
    private mixed $originalKey;
    private int $userId;
    private JobTemplate $template;
    private int $firstJobId;

    protected function setUp(): void
    {
        parent::setUp();
        // Tests replace the checker; tearDown() puts back only what setUp() kept.
        $this->keepComponent('workflowAccessChecker');
        $this->dispatcher = new RecordingNotificationDispatcher();
        $this->swapComponent('notificationDispatcher', $this->dispatcher);
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = 'concurrent-edit-test-key-0123456789';
        $this->service = \Yii::$app->get('scheduleService');
        $this->userId = (int)$this->createUserWithRole('concurrent-edit', 'operator')->id;
        $this->template = $this->createPasswordSurveyTemplate($this->userId);
        $this->firstJobId = (int)Job::find()->max('id') + 1;
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        if ($this->originalKey === null) {
            unset($_ENV['APP_SECRET_KEY']);
        } else {
            $_ENV['APP_SECRET_KEY'] = $this->originalKey;
        }
        parent::tearDown();
    }

    // -- An edit committed after the claim, before the launch locks the row ------------

    /**
     * Regression (SEC-EXP-1, probe A): the schedule is moved to another job
     * template, with the answer of that template's password field, after
     * the scheduler claimed the run. The run fails instead of launching the
     * old template with the new template's answer, and the edit is kept.
     */
    public function testAScheduleMovedToAnotherTemplateDuringItsLaunchIsNotLaunched(): void
    {
        $old = $this->templateWithPasswordField('api_key');
        $other = $this->createPasswordSurveyTemplate($this->userId);
        $schedule = $this->createPlainSchedule((int)$old->id, $this->userId, '{"api_key":"' . self::PLAIN_ANSWER . '"}', time() - 60);
        $this->editAfterTheClaim(function () use ($schedule, $other): void {
            $this->saveLikeTheForm($schedule, (int)$other->id, '{"db_password":"' . self::OTHER_TEMPLATE_ANSWER . '"}');
        });

        $logger = $this->runDueLogged($launched);

        $this->assertNoJobCarries(self::OTHER_TEMPLATE_ANSWER);
        $this->assertSame(0, $launched);
        $this->assertSame([], $this->newJobs(), 'nothing is launched');
        $this->assertFailedToLaunch($schedule, $logger);
        $stored = $this->reloadSchedule($schedule);
        $this->assertSame((int)$other->id, (int)$stored->job_template_id, 'the edit is kept');
        $this->assertSame(['db_password' => SurveySecrets::PLACEHOLDER], $this->storedExtraVarsOf($schedule));
        $this->assertSame(['db_password' => self::OTHER_TEMPLATE_ANSWER], $this->sealedAnswersOf($schedule));
        $this->assertSame([], $this->sealAudits($schedule), 'the scheduler sealed nothing');
    }

    /**
     * A schedule deleted after the claim is not launched either.
     */
    public function testAScheduleDeletedDuringItsLaunchIsNotLaunched(): void
    {
        $schedule = $this->createPlainSchedule(
            (int)$this->template->id,
            $this->userId,
            '{"app_env":"prod","db_password":"' . self::PLAIN_ANSWER . '"}',
            time() - 60
        );
        $this->editAfterTheClaim(function () use ($schedule): void {
            $this->assertSame(1, $this->reloadSchedule($schedule)->delete());
        });

        $logger = $this->runDueLogged($launched);

        $this->assertSame(0, $launched);
        $this->assertSame([], $this->newJobs());
        $this->assertFailedToLaunch($schedule, $logger);
        $this->assertNull(Schedule::findOne($schedule->id));
        $this->assertSame([], $this->sealAudits($schedule));
    }

    /**
     * An edit that keeps the job template: the run launches what the row
     * holds now, the edit's answer included, and the edit is kept.
     */
    public function testAnEditThatKeepsTheTemplateIsLaunchedAsSaved(): void
    {
        $schedule = $this->createPlainSchedule(
            (int)$this->template->id,
            $this->userId,
            '{"app_env":"prod","db_password":"' . self::PLAIN_ANSWER . '"}',
            time() - 60
        );
        $this->editAfterTheClaim(function () use ($schedule): void {
            $this->saveLikeTheForm($schedule, (int)$this->template->id, '{"app_env":"edited","db_password":"' . self::EDITED_ANSWER . '"}');
        });

        $this->runDueLogged($launched);

        $this->assertSame(1, $launched);
        $job = $this->onlyNewJob();
        $this->assertSame((int)$this->template->id, (int)$job->job_template_id);
        $this->assertSame(
            [self::SURVEY_TEXT_FIELD => 'edited', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER],
            json_decode((string)$job->extra_vars, true)
        );
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::EDITED_ANSWER], SurveySecrets::open($job->getSealedSecretExtraVars()));
        $this->assertNoJobCarries(self::PLAIN_ANSWER);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::EDITED_ANSWER], $this->sealedAnswersOf($schedule), 'the edit is kept');
        $this->assertSame([], $this->sealAudits($schedule), 'nothing was left to seal');
    }

    /**
     * An edit that leaves the plain answer as it is (an API update of other
     * fields): the run seals the answer in the row as it is now, and its
     * save writes only the extra vars and the sealed answers.
     */
    public function testAnEditOfOtherFieldsIsKeptWhileThePlainAnswerIsSealed(): void
    {
        $schedule = $this->createPlainSchedule(
            (int)$this->template->id,
            $this->userId,
            '{"app_env":"prod","db_password":"' . self::PLAIN_ANSWER . '"}',
            time() - 60
        );
        $this->editAfterTheClaim(function () use ($schedule): void {
            $edit = $this->reloadSchedule($schedule);
            $edit->name = 'renamed-during-the-launch';
            $edit->cron_expression = '30 4 * * *';
            $this->assertNull((new ScheduleSecrets())->prepare($edit, false));
            $this->assertTrue($edit->save(false));
        });

        $this->runDueLogged($launched);

        $this->assertSame(1, $launched);
        $stored = $this->reloadSchedule($schedule);
        $this->assertSame('renamed-during-the-launch', $stored->name);
        $this->assertSame('30 4 * * *', $stored->cron_expression);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::PLAIN_ANSWER], $this->sealedAnswersOf($schedule));
        $this->assertStringNotContainsString(self::PLAIN_ANSWER, (string)$stored->extra_vars);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::PLAIN_ANSWER], SurveySecrets::open($this->onlyNewJob()->getSealedSecretExtraVars()));
        $audits = $this->sealAudits($schedule);
        $this->assertCount(1, $audits);
        $this->assertSame('renamed-during-the-launch', $audits[0]['name'], 'the audit names the row as it was sealed');
    }

    // -- The lock -------------------------------------------------------------------------

    /**
     * Regression (SEC-EXP-1, probe C): the plain answers were sealed from a
     * plain re-read and saved without a lock, so an edit saved between the
     * read and the save was overwritten. The row is now read FOR UPDATE and
     * saved in the same transaction: in production any other write of the
     * row waits until the launch has committed. Neither the lock nor the
     * save put an answer into the SQL.
     */
    public function testThePlainAnswersAreReadAndSavedUnderOneRowLock(): void
    {
        $schedule = $this->createPlainSchedule((int)$this->template->id, $this->userId, '{"app_env":"prod","db_password":"' . self::PLAIN_ANSWER . '"}');
        $db = Schedule::getDb();
        $baseLevel = (int)$db->getTransaction()?->getLevel();
        $levelAtTheSave = null;
        $atTheSave = static function (ModelEvent $event) use (&$levelAtTheSave, $db, $schedule): void {
            if ($event->sender instanceof Schedule && (int)$event->sender->id === (int)$schedule->id) {
                $levelAtTheSave = $db->getTransaction()?->getLevel();
            }
        };
        Event::on(Schedule::class, BaseActiveRecord::EVENT_BEFORE_UPDATE, $atTheSave);
        $logger = new Logger();
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $overrides = (new ScheduleLaunchSecrets())->launchOverrides($schedule, $this->template);
        } finally {
            \Yii::setLogger($previous);
            Event::off(Schedule::class, BaseActiveRecord::EVENT_BEFORE_UPDATE, $atTheSave);
        }

        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::PLAIN_ANSWER], $overrides[LaunchContext::KEY]->carriedSecrets);
        $this->assertSame($baseLevel + 1, $levelAtTheSave, 'the sealing save runs in a transaction of its own');
        $this->assertSame($baseLevel, (int)$db->getTransaction()?->getLevel(), 'which has ended');
        $statements = $this->statementsOf($logger);
        $lock = $this->indexOf($statements, '~^SELECT \* FROM `schedule` WHERE `id` = ' . $schedule->id . ' FOR UPDATE$~');
        $savepoint = $this->indexOf($statements, '~^SAVEPOINT (LEVEL\d+)$~');
        $update = $this->indexOf($statements, '~^UPDATE `schedule` SET .* WHERE `id`=' . $schedule->id . '$~s');
        $release = $this->indexOf($statements, '~^RELEASE SAVEPOINT LEVEL\d+$~');
        $this->assertTrue($savepoint < $lock && $lock < $update && $update < $release, implode("\n", $statements));
        foreach ($statements as $statement) {
            $this->assertStringNotContainsString(self::PLAIN_ANSWER, $statement);
        }
    }

    /**
     * Stored answers that cannot be decrypted next to a plain one: the
     * launch fails, and the lock's transaction is rolled back and ended.
     */
    public function testUndecryptableAnswersFailTheLaunchAndEndTheLock(): void
    {
        $schedule = $this->createScheduleWithAnswers(
            (int)$this->template->id,
            $this->userId,
            ['old_password' => 'sealed-with-the-old-key'],
            [self::SURVEY_PASSWORD_FIELD => self::PLAIN_ANSWER]
        );
        $_ENV['APP_SECRET_KEY'] = 'a-rotated-key-that-cannot-open-it-0123';
        $db = Schedule::getDb();
        $baseLevel = (int)$db->getTransaction()?->getLevel();

        try {
            (new ScheduleLaunchSecrets())->launchOverrides($schedule, $this->template);
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame(ScheduleLaunchSecrets::LAUNCH_UNDECRYPTABLE, $e->getMessage());
        }

        $this->assertSame($baseLevel, (int)$db->getTransaction()?->getLevel());
        $this->assertStringContainsString(self::PLAIN_ANSWER, (string)$this->reloadSchedule($schedule)->extra_vars, 'nothing was saved');
        $this->assertSame([], $this->sealAudits($schedule));
    }

    // -- An edit that read the row before the seal and writes after it -----------------

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function answerRemovedProvider(): array
    {
        return ['the key removed' => ['{"app_env":"prod"}'], 'the extra vars cleared' => [null]];
    }

    /**
     * Regression (SEC-EXP-1): a save that read the schedule before the
     * scheduler sealed its plain answer, and wrote after the seal, removed
     * the answer from the extra vars but left it sealed, so the next run
     * carried the answer the user had just removed. A save writes the extra
     * vars and the sealed answers together now.
     *
     * @dataProvider answerRemovedProvider
     */
    public function testASaveThatReadTheRowBeforeTheSealReplacesTheSealedAnswers(?string $submitted): void
    {
        $schedule = $this->createPlainSchedule((int)$this->template->id, $this->userId, '{"app_env":"prod","db_password":"' . self::PLAIN_ANSWER . '"}');
        $form = $this->reloadSchedule($schedule);
        (new ScheduleLaunchSecrets())->launchOverrides($this->reloadSchedule($schedule), $this->template);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::PLAIN_ANSWER], $this->sealedAnswersOf($schedule), 'the scheduler sealed it');

        $form->extra_vars = $submitted;
        $this->assertNull((new ScheduleSecrets())->prepare($form, true));
        $this->assertTrue($form->save(false));

        $stored = $this->reloadSchedule($schedule);
        $this->assertSame($submitted, $stored->extra_vars);
        $this->assertNull($stored->getSealedSecretExtraVars(), 'the removed answer is gone');
        $this->assertAnswersBelongTogether($schedule);
        $this->assertSame([], (new ScheduleLaunchSecrets())->launchOverrides($stored, $this->template)[LaunchContext::KEY]->carriedSecrets);
    }

    /**
     * The other half: a save whose extra vars look unchanged writes them too.
     * Here a key became a password field after the save had checked the
     * row, and the scheduler sealed it before the save wrote. Writing only
     * the (empty) sealed answers would leave the scheduler's placeholder
     * without its answer, and the next run would send the placeholder.
     */
    public function testASaveWritesItsExtraVarsAndSealedAnswersTogether(): void
    {
        $template = $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        );
        $schedule = $this->createPlainSchedule((int)$template->id, $this->userId, '{"app_env":"prod","api_key":"' . self::PLAIN_ANSWER . '"}');
        $form = $this->reloadSchedule($schedule);
        $form->name = 'renamed-in-the-form';
        $this->assertNull((new ScheduleSecrets())->prepare($form, true), 'submitted as shown: no answers yet');
        $template->survey_fields = (string)json_encode([
            ['name' => 'api_key', 'label' => 'Key', 'type' => SurveyField::TYPE_PASSWORD, 'required' => false, 'default' => '', 'options' => [], 'hint' => ''],
        ]);
        $template->save(false);
        (new ScheduleLaunchSecrets())->launchOverrides($this->reloadSchedule($schedule), $template);
        $this->assertSame(['api_key' => self::PLAIN_ANSWER], $this->sealedAnswersOf($schedule), 'the scheduler sealed it');

        $this->assertTrue($form->save(false));

        $this->assertSame('renamed-in-the-form', $this->reloadSchedule($schedule)->name);
        $this->assertAnswersBelongTogether($schedule);
        $overrides = (new ScheduleLaunchSecrets())->launchOverrides($this->reloadSchedule($schedule), $template);
        $this->assertSame(['api_key' => self::PLAIN_ANSWER], $overrides[LaunchContext::KEY]->carriedSecrets);
        $this->assertSame(['app_env' => 'prod'], $overrides['extra_vars'], 'the next run sends the answer, not the placeholder');
    }

    /**
     * The same save that keeps the answer (the placeholder, as the form
     * shows it) or enters a new one stores exactly that.
     */
    public function testASaveThatReadTheRowBeforeTheSealStoresWhatItKeptOrEntered(): void
    {
        $schedule = $this->createPlainSchedule((int)$this->template->id, $this->userId, '{"app_env":"prod","db_password":"' . self::PLAIN_ANSWER . '"}');
        $keep = $this->reloadSchedule($schedule);
        $replace = $this->reloadSchedule($schedule);
        (new ScheduleLaunchSecrets())->launchOverrides($this->reloadSchedule($schedule), $this->template);

        $keep->extra_vars = (string)ScheduleSecrets::maskedExtraVars($keep);
        $this->assertNull((new ScheduleSecrets())->prepare($keep, true));
        $this->assertTrue($keep->save(false));
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::PLAIN_ANSWER], $this->sealedAnswersOf($schedule));

        $replace->extra_vars = '{"app_env":"prod","db_password":"' . self::EDITED_ANSWER . '"}';
        $this->assertNull((new ScheduleSecrets())->prepare($replace, true));
        $this->assertTrue($replace->save(false));
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::EDITED_ANSWER], $this->sealedAnswersOf($schedule));
        $this->assertSame([self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER], $this->storedExtraVarsOf($schedule));
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * Run $edit once, right after the scheduler claimed the run and before
     * the launch reads the row again: the scheduler checks in between that
     * the schedule's creator may still launch jobs.
     */
    private function editAfterTheClaim(\Closure $edit): void
    {
        \Yii::$app->set('workflowAccessChecker', new class ($edit) extends WorkflowAccessChecker {
            public function __construct(private ?\Closure $edit)
            {
                parent::__construct();
            }

            public function isActiveWithPermission(int $userId, string $permission): bool
            {
                $edit = $this->edit;
                $this->edit = null;
                if ($edit !== null) {
                    $edit();
                }

                return parent::isActiveWithPermission($userId, $permission);
            }
        });
    }

    /**
     * Save the schedule as the edit form saves it.
     */
    private function saveLikeTheForm(Schedule $schedule, int $templateId, string $extraVars): void
    {
        $form = $this->reloadSchedule($schedule);
        $form->job_template_id = $templateId;
        $form->extra_vars = $extraVars;
        $form->computeNextRunAt();
        $this->assertTrue($form->validate(), (string)json_encode($form->errors));
        $this->assertNull((new ScheduleSecrets())->prepare($form, true));
        $this->assertTrue($form->save(false));
    }

    /**
     * runDue() with its log captured; $launched receives its result.
     */
    private function runDueLogged(?int &$launched): Logger
    {
        $logger = new Logger();
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $launched = $this->service->runDue();
        } finally {
            \Yii::setLogger($previous);
        }

        return $logger;
    }

    /**
     * The run of $schedule failed with the message for a schedule that
     * changed during its launch: logged and notified as a failed launch.
     */
    private function assertFailedToLaunch(Schedule $schedule, Logger $logger): void
    {
        $failures = $this->dispatcher->payloadsOf(NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH);
        $this->assertCount(1, $failures);
        $this->assertSame((string)$schedule->id, $failures[0]['schedule']['id']);
        $this->assertSame(ScheduleLaunchSecrets::LAUNCH_SCHEDULE_CHANGED, $failures[0]['schedule']['error']);
        $errors = array_values(array_filter(
            $logger->messages,
            static fn (array $m): bool => $m[2] === ScheduleService::class && $m[1] === Logger::LEVEL_ERROR
        ));
        $this->assertSame(
            ["Schedule #{$schedule->id} ({$schedule->name}) failed to launch: " . ScheduleLaunchSecrets::LAUNCH_SCHEDULE_CHANGED],
            array_column($errors, 0)
        );
    }

    /**
     * The stored extra vars hold the placeholder exactly at the keys of the
     * stored sealed answers: neither a placeholder without its answer nor an
     * answer the extra vars no longer name.
     */
    private function assertAnswersBelongTogether(Schedule $schedule): void
    {
        $stored = $this->reloadSchedule($schedule);
        $vars = json_decode((string)$stored->extra_vars, true);
        $placeholders = array_keys(array_filter(is_array($vars) ? $vars : [], static fn (mixed $value): bool => $value === SurveySecrets::PLACEHOLDER));
        $sealed = array_keys(SurveySecrets::open($stored->getSealedSecretExtraVars()));
        sort($placeholders);
        sort($sealed);
        $this->assertSame($placeholders, $sealed, 'extra vars ' . (string)$stored->extra_vars);
    }

    private function assertNoJobCarries(string $answer): void
    {
        foreach ($this->newJobs() as $job) {
            $this->assertNotContains($answer, SurveySecrets::open($job->getSealedSecretExtraVars()), "job #{$job->id}");
            $this->assertStringNotContainsString($answer, (string)$job->extra_vars . (string)$job->runner_payload, "job #{$job->id}");
        }
    }

    /**
     * @return list<Job> the jobs this test launched
     */
    private function newJobs(): array
    {
        /** @var list<Job> $jobs */
        $jobs = Job::find()->where(['>=', 'id', $this->firstJobId])->orderBy(['id' => SORT_ASC])->all();

        return $jobs;
    }

    private function onlyNewJob(): Job
    {
        $jobs = $this->newJobs();
        $this->assertCount(1, $jobs, 'failed launches: ' . json_encode($this->dispatcher->payloadsOf(NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH)));

        return $jobs[0];
    }

    /**
     * The metadata of the scheduler's schedule.updated entries for $schedule.
     *
     * @return list<array<string, mixed>>
     */
    private function sealAudits(Schedule $schedule): array
    {
        /** @var list<AuditLog> $entries */
        $entries = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_SCHEDULE_UPDATED, 'object_type' => 'schedule', 'object_id' => $schedule->id])
            ->orderBy(['id' => SORT_ASC])
            ->all();
        $sealed = [];
        foreach ($entries as $entry) {
            $metadata = json_decode((string)$entry->metadata, true);
            if (is_array($metadata) && ($metadata['source'] ?? null) === 'console:schedule/run') {
                $sealed[] = $metadata;
            }
        }

        return $sealed;
    }

    /**
     * The SQL statements sent while $logger was the logger, in order.
     *
     * @return list<string>
     */
    private function statementsOf(Logger $logger): array
    {
        $statements = [];
        foreach ($logger->messages as $message) {
            // One info entry per statement; with profiling on, each also has a begin and an end entry.
            if (in_array($message[2], self::COMMAND_CATEGORIES, true) && $message[1] === Logger::LEVEL_INFO && is_string($message[0])) {
                $statements[] = $message[0];
            }
        }

        return $statements;
    }

    /**
     * @param list<string> $statements
     */
    private function indexOf(array $statements, string $pattern): int
    {
        foreach ($statements as $index => $statement) {
            if (preg_match($pattern, $statement) === 1) {
                return $index;
            }
        }
        $this->fail("No statement matches {$pattern}:\n" . implode("\n", $statements));
    }

    /**
     * A job template whose survey has one password field, $name.
     */
    private function templateWithPasswordField(string $name): JobTemplate
    {
        $template = $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        );
        $template->survey_fields = (string)json_encode([
            ['name' => $name, 'label' => 'Key', 'type' => SurveyField::TYPE_PASSWORD, 'required' => false, 'default' => '', 'options' => [], 'hint' => ''],
        ]);
        $template->save(false);

        return $template;
    }
}
