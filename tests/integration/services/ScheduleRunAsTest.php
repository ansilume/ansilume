<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\SurveySecrets;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\services\ScheduleRunAs;
use app\tests\integration\DbTestCase;

/**
 * Whom a schedule runs as: the user who last armed it, that is who last
 * saved what or when it runs, or enabled it (decision "the editor becomes
 * the launcher"). ScheduleRunAs::claim() decides it from the stored values
 * (the old attributes) and the values about to be saved.
 */
class ScheduleRunAsTest extends DbTestCase
{
    private User $owner;
    private User $editor;
    private JobTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->createUser('run-as-owner');
        $this->editor = $this->createUser('run-as-editor');
        $this->template = $this->createPasswordSurveyTemplate((int)$this->owner->id);
    }

    // -- What arms a schedule -----------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function armingChangeProvider(): array
    {
        return [
            'cron expression' => ['cron_expression', '30 2 * * *'],
            'timezone' => ['timezone', 'Europe/Berlin'],
            'extra vars: another value' => ['extra_vars', '{"app_env": "staging"}'],
            'extra vars: another key' => ['extra_vars', '{"app_env": "prod", "region": "eu"}'],
            'extra vars: none' => ['extra_vars', null],
            'enabled' => ['enabled', true],
        ];
    }

    /**
     * Regression: editing another user's schedule let the editor approve
     * its job. A change to what or when it runs makes it run as the editor.
     *
     * @dataProvider armingChangeProvider
     */
    public function testAChangeOfWhatOrWhenItRunsMakesItRunAsTheEditor(string $attribute, mixed $value): void
    {
        $schedule = $this->storedSchedule(['enabled' => false]);
        $schedule->setAttribute($attribute, $value);

        $runAs = new ScheduleRunAs($schedule);
        $this->assertTrue($runAs->claim((int)$this->editor->id));

        $this->assertSame((int)$this->editor->id, (int)$schedule->created_by);
        $this->assertSame(
            [ScheduleRunAs::AUDIT_KEY => ['from' => (int)$this->owner->id, 'to' => (int)$this->editor->id]],
            $runAs->auditContext()
        );
    }

    public function testAnotherJobTemplateArmsTheSchedule(): void
    {
        $schedule = $this->storedSchedule();
        $schedule->job_template_id = (int)$this->createPasswordSurveyTemplate((int)$this->owner->id)->id;

        $this->assertTrue((new ScheduleRunAs($schedule))->claim((int)$this->editor->id));
    }

    /**
     * A password answer entered anew (ScheduleSecrets::answersChanged()),
     * even with extra vars that look the same as before: they hold the
     * placeholder for it either way.
     */
    public function testAPasswordAnswerEnteredAnewArmsTheSchedule(): void
    {
        $schedule = $this->storedSchedule();

        $this->assertTrue((new ScheduleRunAs($schedule))->claim((int)$this->editor->id, true));
        $this->assertSame((int)$this->editor->id, (int)$schedule->created_by);
    }

    // -- What keeps the user it runs as -------------------------------------------

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function keepingChangeProvider(): array
    {
        return [
            'nothing' => [[]],
            'the name' => [['name' => 'renamed']],
            'disabling' => [['enabled' => false]],
            'the same values as text' => [['job_template_id' => 'same-as-text', 'enabled' => '1']],
        ];
    }

    /**
     * @dataProvider keepingChangeProvider
     * @param array<string, mixed> $changes
     */
    public function testRenamingDisablingAndSavingUnchangedKeepTheOwner(array $changes): void
    {
        $schedule = $this->storedSchedule();
        foreach ($changes as $attribute => $value) {
            $schedule->setAttribute($attribute, $value === 'same-as-text' ? (string)$schedule->job_template_id : $value);
        }

        $runAs = new ScheduleRunAs($schedule);
        $this->assertFalse($runAs->claim((int)$this->editor->id));

        $this->assertSame((int)$this->owner->id, (int)$schedule->created_by);
        $this->assertSame([], $runAs->auditContext());
    }

    /**
     * The owner who arms their own schedule keeps it: nothing to audit.
     */
    public function testTheOwnerWhoArmsTheirScheduleKeepsIt(): void
    {
        $schedule = $this->storedSchedule();
        $schedule->cron_expression = '30 2 * * *';

        $runAs = new ScheduleRunAs($schedule);
        $this->assertFalse($runAs->claim((int)$this->owner->id, true));

        $this->assertSame((int)$this->owner->id, (int)$schedule->created_by);
        $this->assertSame([], $runAs->auditContext());
    }

    /**
     * A new schedule runs as its creator already.
     */
    public function testANewScheduleKeepsItsCreator(): void
    {
        $schedule = new Schedule();
        $schedule->job_template_id = (int)$this->template->id;
        $schedule->cron_expression = '0 2 * * *';
        $schedule->created_by = (int)$this->editor->id;

        $this->assertFalse((new ScheduleRunAs($schedule))->claim((int)$this->editor->id));
        $this->assertSame((int)$this->editor->id, (int)$schedule->created_by);
    }

    /**
     * Each claim() reports only its own change: a second one by the same
     * user changes nothing and reports nothing.
     */
    public function testTheAuditContextIsThatOfTheLastClaim(): void
    {
        $schedule = $this->storedSchedule();
        $schedule->cron_expression = '30 2 * * *';
        $runAs = new ScheduleRunAs($schedule);
        $this->assertTrue($runAs->claim((int)$this->editor->id));

        $this->assertFalse($runAs->claim((int)$this->editor->id));

        $this->assertSame([], $runAs->auditContext());
        $this->assertSame((int)$this->editor->id, (int)$schedule->created_by);
    }

    /**
     * Regression (found while building this): the stored values must be
     * those of the schedule when the object was created.
     * ScheduleSecrets::prepare() marks the extra vars dirty, which drops
     * their stored value from the old attributes: compared with the old
     * attributes at claim() time, every form save (the form always sends the
     * extra vars) made the schedule run as the user who saved it.
     */
    public function testExtraVarsMarkedDirtyAfterwardsAreComparedWithTheStoredOnes(): void
    {
        $schedule = $this->storedSchedule();
        $runAs = new ScheduleRunAs($schedule);
        $schedule->extra_vars = '{"app_env": "prod"}';
        $schedule->markAttributeDirty('extra_vars');

        $this->assertFalse($runAs->claim((int)$this->editor->id));
        $this->assertSame((int)$this->owner->id, (int)$schedule->created_by);
    }

    // -- How extra vars are compared ------------------------------------------------

    /**
     * Text that writes the same JSON values differently: the same extra vars.
     *
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function sameExtraVarsProvider(): array
    {
        return [
            'identical' => ['{"a": 1}', '{"a": 1}'],
            'whitespace' => ['{"a": 1, "b": [1, 2]}', "{\"a\":1,\n  \"b\":[ 1,2 ]}"],
            'line breaks of a browser form' => ["{\n  \"a\": 1\n}", "{\r\n  \"a\": 1\r\n}"],
            'unicode and slash escapes' => ['{"a": "é/b"}', '{"a": "é\/b"}'],
            'the same float spelled otherwise' => ['{"a": 100.0}', '{"a": 1e2}'],
            'null and empty' => [null, ''],
            'empty and null' => ['', null],
            'the same text that is no JSON' => ['{not json', '{not json'],
        ];
    }

    /**
     * @dataProvider sameExtraVarsProvider
     */
    public function testExtraVarsWithTheSameValuesKeepTheOwner(?string $stored, ?string $saved): void
    {
        $schedule = $this->storedSchedule(['extra_vars' => $stored]);
        $schedule->extra_vars = $saved;

        $this->assertFalse((new ScheduleRunAs($schedule))->claim((int)$this->editor->id));
    }

    /**
     * Another value, another type or another order of keys: other extra
     * vars, as is a text that is no JSON, or that holds a number too large
     * for JSON, unless it stays exactly the same.
     *
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function otherExtraVarsProvider(): array
    {
        return [
            'another value' => ['{"a": 1}', '{"a": 2}'],
            'an int and a float' => ['{"a": 1}', '{"a": 1.0}'],
            'an object and a list' => ['{"a": {}}', '{"a": []}'],
            'a number and a string' => ['{"a": 1}', '{"a": "1"}'],
            'another order of keys' => ['{"a": 1, "b": 2}', '{"b": 2, "a": 1}'],
            'two integers too large for PHP' => ['{"a": 12345678901234567890}', '{"a": 12345678901234567891}'],
            'an integer too large for PHP and its digits' => ['{"a": 12345678901234567890}', '{"a": "12345678901234567890"}'],
            'none and an empty object' => [null, '{}'],
            'JSON null and none' => ['null', ''],
            'text that is no JSON, then JSON' => ['{not json', '{"a": 1}'],
            'text that is no JSON, changed' => ['{not json', '{not json}'],
            'a number too large for JSON, then JSON' => ['{"a": 1e999}', '{"a": 0}'],
        ];
    }

    /**
     * @dataProvider otherExtraVarsProvider
     */
    public function testOtherExtraVarsMakeItRunAsTheEditor(?string $stored, ?string $saved): void
    {
        $schedule = $this->storedSchedule(['extra_vars' => $stored]);
        $schedule->extra_vars = $saved;

        $this->assertTrue((new ScheduleRunAs($schedule))->claim((int)$this->editor->id));
    }

    /**
     * A password answer the stored extra vars still hold in plain text (its
     * key became a password field after the save) shows as the placeholder
     * in the form: submitted unchanged, it keeps the owner, though the save
     * seals it.
     */
    public function testAPlainAnswerSubmittedAsThePlaceholderKeepsTheOwner(): void
    {
        $schedule = $this->storedSchedule([
            'extra_vars' => (string)json_encode([self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => 'plain-answer-0815']),
        ]);
        $schedule->extra_vars = (string)json_encode([self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER]);

        $this->assertFalse((new ScheduleRunAs($schedule))->claim((int)$this->editor->id));
    }

    /**
     * A plain answer does not hide a change of the other values, nor a
     * number too large for JSON next to it, which the form shows as 0.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function plainAnswerBesideAChangeProvider(): array
    {
        return [
            'another value' => ['{"app_env": "prod", "db_password": "plain-0815"}', '{"app_env": "stage", "db_password": "$encrypted$"}'],
            'a number too large for JSON' => ['{"n": 1e999, "db_password": "plain-0815"}', '{"n": 0, "db_password": "$encrypted$"}'],
        ];
    }

    /**
     * @dataProvider plainAnswerBesideAChangeProvider
     */
    public function testAPlainAnswerDoesNotHideAChange(string $stored, string $saved): void
    {
        $schedule = $this->storedSchedule(['extra_vars' => $stored]);
        $schedule->extra_vars = $saved;

        $this->assertTrue((new ScheduleRunAs($schedule))->claim((int)$this->editor->id));
    }

    // -- Helpers ---------------------------------------------------------------------

    /**
     * The owner's schedule of the template as stored, with $attributes.
     *
     * @param array<string, mixed> $attributes
     */
    private function storedSchedule(array $attributes = []): Schedule
    {
        $schedule = new Schedule();
        $schedule->name = 'run-as-' . uniqid('', true);
        $schedule->job_template_id = (int)$this->template->id;
        $schedule->cron_expression = '0 2 * * *';
        $schedule->timezone = 'UTC';
        $schedule->extra_vars = '{"app_env": "prod"}';
        $schedule->enabled = true;
        $schedule->created_by = (int)$this->owner->id;
        foreach ($attributes as $attribute => $value) {
            $schedule->setAttribute($attribute, $value);
        }
        $schedule->save(false);
        $stored = Schedule::findOne($schedule->id);
        $this->assertNotNull($stored);

        return $stored;
    }
}
