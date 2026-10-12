<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\components\SurveySecrets;
use app\models\Job;
use app\models\Schedule;
use app\tests\integration\DbTestCase;
use yii\db\ActiveRecord;

/**
 * job.secret_extra_vars and schedule.secret_extra_vars: set in code only,
 * never by load() or toArray(), and tolerant of a database that has not run
 * m000080 yet.
 */
class SealedSecretExtraVarsTest extends DbTestCase
{
    private const SEALED = 'c2VhbGVkLXZhbHVlLWZvci10ZXN0cw==';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('sealed')->id;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function modelProvider(): array
    {
        return ['job' => ['job'], 'schedule' => ['schedule']];
    }

    /**
     * @dataProvider modelProvider
     */
    public function testTheSealedAnswersAreStoredAndReadBack(string $kind): void
    {
        $record = $this->record($kind);
        $this->assertNull($record->getSealedSecretExtraVars(), 'none by default');

        $record->setSealedSecretExtraVars(self::SEALED);
        $this->assertTrue($record->save(false));

        $reloaded = $this->reload($record);
        $this->assertSame(self::SEALED, $reloaded->getSealedSecretExtraVars());

        $reloaded->setSealedSecretExtraVars(null);
        $this->assertTrue($reloaded->save(false));
        $this->assertNull($this->reload($reloaded)->getSealedSecretExtraVars());
    }

    /**
     * @dataProvider modelProvider
     */
    public function testAnEmptyValueReadsAsNone(string $kind): void
    {
        $record = $this->record($kind);
        $record->setAttribute('secret_extra_vars', '');

        $this->assertNull($record->getSealedSecretExtraVars());
    }

    /**
     * A form or API body must not be able to choose the stored answers.
     *
     * @dataProvider modelProvider
     */
    public function testLoadNeverAssignsTheColumn(string $kind): void
    {
        $record = $this->record($kind);
        $formName = $record->formName();

        $record->load([$formName => ['secret_extra_vars' => self::SEALED, 'extra_vars' => '{"a":1}']]);
        $record->setAttributes(['secret_extra_vars' => self::SEALED]);

        $this->assertSame('{"a":1}', $record->extra_vars, 'other fields still load');
        $this->assertNull($record->getSealedSecretExtraVars());
        $this->assertNotContains('secret_extra_vars', $record->safeAttributes());
    }

    /**
     * @dataProvider modelProvider
     */
    public function testToArrayLeavesTheSealedAnswersOut(string $kind): void
    {
        $record = $this->record($kind);
        $record->setSealedSecretExtraVars(self::SEALED);

        $data = $record->toArray();

        $this->assertArrayNotHasKey('secret_extra_vars', $data);
        $this->assertArrayHasKey('extra_vars', $data);
        $this->assertNotContains(self::SEALED, $data);
    }

    /**
     * Regression guard for updates: code can run before m000080 (a schedule
     * runner that starts while the app container still migrates, bind-mounted
     * code after `git pull`). Without the column there are no answers, saving
     * works, and only storing an answer fails, with an actionable message.
     *
     * @dataProvider modelProvider
     */
    public function testWithoutTheColumnNothingIsReadAndOnlyStoringAnAnswerFails(string $kind): void
    {
        $record = $this->recordWithoutTheColumn($kind);

        $this->assertNull($record->getSealedSecretExtraVars());
        $record->setSealedSecretExtraVars(null);
        $this->assertTrue($record->save(false), 'a record without answers saves');

        try {
            $record->setSealedSecretExtraVars(self::SEALED);
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('The database update has not finished; run the migrations.', $e->getMessage());
        }
    }

    public function testTheJobFixtureStoresAnswersAsALaunchDoes(): void
    {
        $template = $this->createPasswordSurveyTemplate($this->userId);

        $job = $this->createJobWithSealedSecrets($template, $this->userId, [self::SURVEY_PASSWORD_FIELD => ['pin' => 1]], ['env' => 'prod']);

        $this->assertSame(['env' => 'prod', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER], json_decode((string)$job->extra_vars, true));
        $this->assertSame($job->extra_vars, $job->decodedRunnerPayload()['extra_vars']);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => ['pin' => 1]], SurveySecrets::open($this->reload($job)->getSealedSecretExtraVars()));
        $this->assertSame([self::SURVEY_PASSWORD_FIELD], $template->passwordFieldNames());
    }

    private function record(string $kind): Job|Schedule
    {
        if ($kind === 'job') {
            $job = $this->createJob((int)$this->template()->id, $this->userId);
            $this->assertNotNull(Job::findOne($job->id));

            return Job::findOne($job->id) ?? $job;
        }
        $schedule = new Schedule();
        $schedule->name = 'sealed-' . uniqid('', true);
        $schedule->job_template_id = (int)$this->template()->id;
        $schedule->cron_expression = '0 2 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->created_by = $this->userId;
        $this->assertTrue($schedule->save(false));

        return Schedule::findOne($schedule->id) ?? $schedule;
    }

    /**
     * A record as the code sees it before m000080: its table schema lacks
     * the column.
     */
    private function recordWithoutTheColumn(string $kind): Job|Schedule
    {
        if ($kind === 'job') {
            $job = new class () extends Job {
                public function attributes(): array
                {
                    return array_values(array_diff(parent::attributes(), ['secret_extra_vars']));
                }
            };
            $job->job_template_id = (int)$this->template()->id;
            $job->launched_by = $this->userId;
            $job->status = Job::STATUS_QUEUED;
            $job->timeout_minutes = 120;
            $job->has_changes = 0;

            return $job;
        }
        $schedule = new class () extends Schedule {
            public function attributes(): array
            {
                return array_values(array_diff(parent::attributes(), ['secret_extra_vars']));
            }
        };
        $schedule->name = 'sealed-old-schema-' . uniqid('', true);
        $schedule->job_template_id = (int)$this->template()->id;
        $schedule->cron_expression = '0 2 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->created_by = $this->userId;

        return $schedule;
    }

    private function template(): \app\models\JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        );
    }

    /**
     * @template T of ActiveRecord
     * @param T $record
     * @return T
     */
    private function reload(ActiveRecord $record): ActiveRecord
    {
        $reloaded = $record::findOne($record->getPrimaryKey());
        $this->assertNotNull($reloaded);

        return $reloaded;
    }
}
