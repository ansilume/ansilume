<?php

declare(strict_types=1);

namespace app\tests\integration\components;

use app\components\RunnerCommandBuilder;
use app\components\SurveySecretBackfill;
use app\components\SurveySecrets;
use app\models\Job;
use app\models\JobTemplate;
use app\services\JobLaunchService;
use app\tests\integration\DbTestCase;
use yii\db\Query;

/**
 * m000081 (SurveySecretBackfill::runMigration()) and the scrub of finished
 * jobs treat every password field name as a secret key, also one that
 * cannot carry an answer. Releases up to 2.8.1 stored such a field's answer
 * under its exact name: the backfill seals it there, matched as text, while
 * the launch still refuses the template until the field is renamed.
 *
 * Regression (R2V2-1): once every name outside printable ASCII was unusable,
 * ordinary letters included ("contraseña_db", "Passwort_für_root"), the
 * backfill took only usable names as secret keys. Answers that 2.8.1 stored
 * under such a name stayed in plaintext in schedules, unfinished jobs and
 * job history, and the summary counted the template as one without password
 * fields.
 */
class SurveySecretBackfillEveryNameTest extends DbTestCase
{
    private const ANSWER = 'backfill-every-name-marker-8264';

    private const KEY = 'survey-backfill-every-name-key-0123456789';

    private const ZERO_COUNTS = ['checked' => 0, 'encrypted' => 0, 'undecryptable' => 0, 'too_long' => 0, 'failed' => 0, 'command_cleared' => 0];

    private const HISTORY_LINE = 'Finished jobs of these job templates: %d. Their stored answers are encrypted in the background by maintenance/run in the '
        . 'schedule-runner container, a few seconds per minute; it logs its progress as "survey-secret-scrub".';

    private mixed $originalKey;

    private int $userId;

    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = self::KEY;
        $this->userId = (int)$this->createUser('backfill-every-name')->id;
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

    /**
     * Password field names that cannot carry an answer, of every kind.
     *
     * @return array<string, array{0: string}>
     */
    public static function unusableNameProvider(): array
    {
        return [
            'a letter outside ASCII' => ['contraseña_db'],
            'a German letter outside ASCII' => ['Passwort_für_root'],
            'a trailing space' => ['db_password '],
            'a non-breaking space' => ["db_password\u{00A0}"],
            'a line break' => ["db_password\n"],
            'a number' => ['123'],
            'zero' => ['0'],
            'brackets' => ['db[pw]'],
        ];
    }

    /**
     * @dataProvider unusableNameProvider
     */
    public function testTheMigrationSealsSchedulesAndUnfinishedJobsUnderSuchAName(string $name): void
    {
        $template = $this->templateWithPasswordField($name);
        $original = self::json(['app_env' => 'prod', $name => self::ANSWER]);
        $scheduleId = $this->legacySchedule($template, $original);
        $queued = $this->legacyJob($template, $original, Job::STATUS_QUEUED);
        $running = $this->legacyJob($template, $original, Job::STATUS_RUNNING);
        $finished = $this->legacyJob($template, $original, Job::STATUS_SUCCEEDED);
        $finishedBefore = $this->row('{{%job}}', $finished);

        $this->assertTrue($this->backfill()->runMigration());

        $schedule = $this->row('{{%schedule}}', $scheduleId);
        $this->assertSame(['app_env' => 'prod', $name => SurveySecrets::PLACEHOLDER], json_decode(self::text($schedule['extra_vars']), true));
        $this->assertSame([$name => self::ANSWER], SurveySecrets::open(self::text($schedule['secret_extra_vars'])));
        $this->assertSealedJob($queued, $original, $name);
        $this->assertSealedJob($running, $original, $name);
        $this->assertSame($finishedBefore, $this->row('{{%job}}', $finished), 'finished jobs are left to the scrub');
        $this->assertMatchesRegularExpression('/^Survey password answers: \d+ job templates checked, 1 of them with password fields\.$/', $this->lines[0]);
        $this->assertContains(sprintf(
            'Job templates with a password field whose name cannot carry an answer; they cannot be launched until the field is renamed '
            . 'in the survey editor: 1 (#%d).',
            $template->id
        ), $this->lines, 'still listed, so the operator renames the field');
        $this->assertContains(sprintf('Schedules that launch those job templates: 1 (#%d).', $scheduleId), $this->lines);
        $this->assertContains('Schedules: 1 checked, 1 encrypted.', $this->lines);
        $this->assertContains('Unfinished jobs: 2 checked, 2 encrypted.', $this->lines);
        $this->assertContains(sprintf(self::HISTORY_LINE, 1), $this->lines);
        $this->assertNoAnswerIn($this->lines);
    }

    /**
     * @dataProvider unusableNameProvider
     */
    public function testTheScrubSealsFinishedJobsUnderSuchAName(string $name): void
    {
        $template = $this->templateWithPasswordField($name);
        $original = self::json(['app_env' => 'prod', $name => self::ANSWER]);
        $finished = $this->legacyJob($template, $original, Job::STATUS_FAILED);

        $slice = $this->backfill()->scrubFinishedJobs(['template' => 0, 'job' => 0], 60.0);

        $this->assertSame(SurveySecretBackfill::STATUS_DONE, $slice['status']);
        $this->assertSame(['checked' => 1, 'encrypted' => 1] + self::ZERO_COUNTS, $slice['counts']);
        $this->assertSealedJob($finished, $original, $name);
        $this->assertSame([], $this->lines);
    }

    /**
     * Such a name needs APP_SECRET_KEY as much as any other: the run stops
     * before touching a row.
     */
    public function testWithoutAUsableKeyATemplateWithOnlySuchANameStopsTheRun(): void
    {
        $template = $this->templateWithPasswordField('contraseña_db');
        $scheduleId = $this->legacySchedule($template, self::json(['contraseña_db' => self::ANSWER]));
        $jobId = $this->legacyJob($template, self::json(['contraseña_db' => self::ANSWER]), Job::STATUS_SUCCEEDED);
        $before = [$this->row('{{%schedule}}', $scheduleId), $this->row('{{%job}}', $jobId)];
        unset($_ENV['APP_SECRET_KEY']);

        $this->assertFalse($this->backfill()->runMigration());
        $slice = $this->backfill()->scrubFinishedJobs(['template' => 0, 'job' => 0], 60.0);

        $this->assertSame([
            'APP_SECRET_KEY is not set or shorter than 16 characters, so the stored survey password answers cannot be encrypted. '
                . 'Set APP_SECRET_KEY in .env to the value your credentials were encrypted with, then start the containers again. Nothing was changed.',
        ], $this->lines);
        $this->assertSame(['status' => SurveySecretBackfill::STATUS_NO_KEY, 'cursor' => ['template' => 0, 'job' => 0], 'counts' => self::ZERO_COUNTS], $slice);
        $this->assertSame($before, [$this->row('{{%schedule}}', $scheduleId), $this->row('{{%job}}', $jobId)]);
    }

    /**
     * A password field named "0" whose answer is the only extra var:
     * json_decode() gives the key 0, and an array with the keys 0 to n-1 is
     * a list for json_encode(). The masked extra vars, of the schedule, the
     * job, the runner payload and the stored command, stay a JSON object.
     */
    public function testAnObjectWhoseOnlyKeyIsAPasswordFieldNamedZeroStaysAnObject(): void
    {
        $template = $this->templateWithPasswordField('0');
        $original = '{"0":"' . self::ANSWER . '"}';
        $scheduleId = $this->legacySchedule($template, $original);
        $queued = $this->legacyJob($template, $original, Job::STATUS_QUEUED);
        $finished = $this->legacyJob($template, $original, Job::STATUS_CANCELED);

        $this->assertTrue($this->backfill()->runMigration());
        $slice = $this->backfill()->scrubFinishedJobs(['template' => 0, 'job' => 0], 60.0);

        $this->assertSame(SurveySecretBackfill::STATUS_DONE, $slice['status']);
        $schedule = $this->row('{{%schedule}}', $scheduleId);
        $this->assertSame('{"0":"$encrypted$"}', $schedule['extra_vars']);
        $this->assertSame(['0' => self::ANSWER], SurveySecrets::open(self::text($schedule['secret_extra_vars'])));
        foreach ([$queued, $finished] as $jobId) {
            $row = $this->row('{{%job}}', $jobId);
            $this->assertSame('{"0":"$encrypted$"}', $row['extra_vars'], "job #{$jobId}");
            $this->assertSame('{"0":"$encrypted$"}', $this->payloadOf($row)['extra_vars'], "job #{$jobId}");
            $this->assertStringContainsString('--extra-vars {"0":"$encrypted$"} ', self::text($row['execution_command']), "job #{$jobId}");
            $this->assertSame(['0' => self::ANSWER], SurveySecrets::open(self::text($row['secret_extra_vars'])), "job #{$jobId}");
        }
    }

    /**
     * Such a lone answer as 2.8.1's launch stored it: its merge turned the
     * key "0" into 0, so the job's extra vars became the list ["..."]. The
     * position is the key; the list stays a list.
     */
    public function testALoneAnswerThatALaunchStoredAsAListStaysAList(): void
    {
        $template = $this->templateWithPasswordField('0');
        $original = '["' . self::ANSWER . '"]';
        $jobId = $this->legacyJob($template, $original, Job::STATUS_PENDING);

        $this->assertTrue($this->backfill()->runMigration());

        $row = $this->row('{{%job}}', $jobId);
        $this->assertSame('["$encrypted$"]', $row['extra_vars']);
        $this->assertSame('["$encrypted$"]', $this->payloadOf($row)['extra_vars']);
        $this->assertStringContainsString('--extra-vars ["$encrypted$"] ', self::text($row['execution_command']));
        $this->assertSame(['0' => self::ANSWER], SurveySecrets::open(self::text($row['secret_extra_vars'])));
    }

    // -- Helpers ----------------------------------------------------------------

    private function backfill(): SurveySecretBackfill
    {
        return new SurveySecretBackfill(\Yii::$app->db, function (string $line): void {
            $this->lines[] = $line;
        });
    }

    /**
     * A template saved before the survey rules, with a text field and a
     * password field named $name.
     */
    private function templateWithPasswordField(string $name): JobTemplate
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);
        $survey = self::json([['name' => 'app_env', 'type' => 'text'], ['name' => $name, 'type' => 'password']]);
        \Yii::$app->db->createCommand()->update('{{%job_template}}', ['survey_fields' => $survey], ['id' => $template->id])->execute();
        $template->refresh();

        return $template;
    }

    /**
     * A job as releases up to 2.8.1 stored it: the extra vars $extraVars in
     * plaintext in job.extra_vars, in the runner payload (built by the real
     * JobLaunchService) and in the command as the claim joined it.
     */
    private function legacyJob(JobTemplate $template, string $extraVars, string $status): int
    {
        $job = $this->createJob((int)$template->id, $this->userId, $status);
        $job->extra_vars = $extraVars;
        $payload = (new \ReflectionMethod(JobLaunchService::class, 'buildRunnerPayload'))->invoke(new JobLaunchService(), $template, $job);
        $job->runner_payload = is_string($payload) ? $payload : null;
        $job->execution_command = implode(' ', (new RunnerCommandBuilder())->build([
            'inventory_type' => 'static',
            'playbook_path' => '/var/www/runtime/projects/' . $template->project_id . '/site.yml',
            'verbosity' => 3,
            'forks' => 5,
            'extra_vars' => $extraVars,
            'check_mode' => true,
        ]));
        $job->save(false);

        return (int)$job->id;
    }

    private function legacySchedule(JobTemplate $template, string $extraVars): int
    {
        \Yii::$app->db->createCommand()->insert('{{%schedule}}', [
            'name' => 'backfill-every-name-' . uniqid('', true),
            'job_template_id' => $template->id,
            'cron_expression' => '0 3 * * *',
            'timezone' => 'UTC',
            'extra_vars' => $extraVars,
            'enabled' => 0,
            'created_by' => $this->userId,
            'created_at' => time(),
            'updated_at' => time(),
        ])->execute();

        return (int)\Yii::$app->db->getLastInsertID();
    }

    /**
     * The answer under $name is sealed, every column shows the placeholder,
     * and the runner still gets every value.
     */
    private function assertSealedJob(int $id, string $original, string $name): void
    {
        $row = $this->row('{{%job}}', $id);
        foreach (['extra_vars', 'runner_payload', 'execution_command'] as $column) {
            $this->assertStringNotContainsString(self::ANSWER, self::text($row[$column]), "job #{$id} {$column}");
        }
        $public = json_decode(self::text($row['extra_vars']), true);
        $this->assertIsArray($public);
        $this->assertSame(SurveySecrets::PLACEHOLDER, $public[$name]);
        $secret = SurveySecrets::open(self::text($row['secret_extra_vars']));
        $this->assertSame([$name => self::ANSWER], $secret);
        $this->assertSame(json_decode($original, true), SurveySecrets::reveal($public, $secret), 'the runner still gets every value');
        $this->assertSame($row['extra_vars'], $this->payloadOf($row)['extra_vars']);
        $this->assertStringContainsString('--extra-vars ' . self::text($row['extra_vars']) . ' ', self::text($row['execution_command']));
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
     * @param array<string, mixed> $row
     * @return array<mixed>
     */
    private function payloadOf(array $row): array
    {
        $payload = json_decode(self::text($row['runner_payload']), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * $value as the forms and 2.8.1's launch wrote JSON.
     */
    private static function json(mixed $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
