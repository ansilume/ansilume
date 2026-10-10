<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\tests\integration\DbTestCase;

class ScheduleTest extends DbTestCase
{
    private function createScheduleFixture(int $userId): Schedule
    {
        $project = $this->createProject($userId);
        $inventory = $this->createInventory($userId);
        $group = $this->createRunnerGroup($userId);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $userId);

        $s = new Schedule();
        $s->name = 'test-schedule-' . uniqid('', true);
        $s->job_template_id = $tpl->id;
        $s->cron_expression = '*/5 * * * *';
        $s->timezone = 'UTC';
        $s->enabled = true;
        $s->created_by = $userId;
        $s->created_at = time();
        $s->updated_at = time();
        $s->save(false);
        return $s;
    }

    public function testTableName(): void
    {
        $this->assertSame('{{%schedule}}', Schedule::tableName());
    }

    public function testPersistAndRetrieve(): void
    {
        $user = $this->createUser();
        $schedule = $this->createScheduleFixture($user->id);

        $this->assertNotNull($schedule->id);
        $reloaded = Schedule::findOne($schedule->id);
        $this->assertNotNull($reloaded);
        $this->assertSame($schedule->name, $reloaded->name);
        $this->assertSame('*/5 * * * *', $reloaded->cron_expression);
        $this->assertSame('UTC', $reloaded->timezone);
    }

    public function testValidCronExpressionPasses(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);

        $s = new Schedule();
        $s->name = 'valid-cron';
        $s->job_template_id = $tpl->id;
        $s->cron_expression = '0 2 * * 1';
        $s->timezone = 'UTC';
        $s->created_by = $user->id;
        $this->assertTrue($s->validate(['cron_expression']));
    }

    public function testInvalidCronExpressionFails(): void
    {
        $s = new Schedule();
        $s->cron_expression = 'not-a-cron';
        $s->validate(['cron_expression']);
        $this->assertArrayHasKey('cron_expression', $s->errors);
    }

    public function testValidTimezonePasses(): void
    {
        $s = new Schedule();
        $s->timezone = 'America/New_York';
        $s->validate(['timezone']);
        $this->assertArrayNotHasKey('timezone', $s->errors);
    }

    public function testInvalidTimezoneFails(): void
    {
        $s = new Schedule();
        $s->timezone = 'Not/A_Timezone';
        $s->validate(['timezone']);
        $this->assertArrayHasKey('timezone', $s->errors);
    }

    public function testValidJsonPasses(): void
    {
        $s = new Schedule();
        $s->extra_vars = '{"key": "value"}';
        $s->validate(['extra_vars']);
        $this->assertArrayNotHasKey('extra_vars', $s->errors);
    }

    public function testInvalidJsonFails(): void
    {
        $s = new Schedule();
        $s->extra_vars = 'not-json';
        $s->validate(['extra_vars']);
        $this->assertArrayHasKey('extra_vars', $s->errors);
    }

    public function testComputeNextRunAt(): void
    {
        $s = new Schedule();
        $s->cron_expression = '* * * * *';
        $s->timezone = 'UTC';
        $s->computeNextRunAt();
        $this->assertNotNull($s->next_run_at);
        $this->assertGreaterThanOrEqual(time(), $s->next_run_at);
    }

    public function testComputeNextRunAtInvalidCron(): void
    {
        $s = new Schedule();
        $s->cron_expression = 'bad cron';
        $s->timezone = 'UTC';
        $s->computeNextRunAt();
        $this->assertNull($s->next_run_at);
    }

    /**
     * Regression: a cron expression that is not text (missing from a request
     * body or form, or an array from a crafted form) reached CronExpression,
     * which only takes a string, and failed with a TypeError. The required
     * and string rules report these values.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function cronExpressionThatIsNoTextProvider(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'an array' => [['0 2 * * *']],
            'a number' => [5],
        ];
    }

    /**
     * @dataProvider cronExpressionThatIsNoTextProvider
     */
    public function testComputeNextRunAtClearsTheNextRunWithoutACronExpression(mixed $cron): void
    {
        $s = new Schedule();
        $s->cron_expression = $cron;
        $s->timezone = 'UTC';
        $s->next_run_at = 1700000000;

        $s->computeNextRunAt();

        $this->assertNull($s->next_run_at);
    }

    /**
     * Regression: a timezone that is not text (a list from a crafted form, a
     * number or a boolean from a JSON body) reached CronExpression, which
     * takes ?string, and failed with a TypeError. The string rule reports it.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function timezoneThatIsNoTextProvider(): array
    {
        return [
            'a list' => [['Europe/Berlin']],
            'a number' => [5],
            'a float' => [1.5],
            'true' => [true],
        ];
    }

    /**
     * @dataProvider timezoneThatIsNoTextProvider
     */
    public function testComputeNextRunAtClearsTheNextRunForATimezoneThatIsNoText(mixed $timezone): void
    {
        $s = new Schedule();
        $s->cron_expression = '0 2 * * *';
        $s->timezone = $timezone;
        $s->next_run_at = 1700000000;

        $s->computeNextRunAt();

        $this->assertNull($s->next_run_at);
        $this->assertFalse($s->validate(['timezone']));
        $this->assertSame(['Timezone must be a string.'], $s->getErrors('timezone'));
    }

    /**
     * The default rule stores an empty timezone as UTC, so the next run is
     * computed in UTC.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function emptyTimezoneProvider(): array
    {
        return ['missing' => [null], 'empty' => [''], 'an empty list' => [[]]];
    }

    /**
     * @dataProvider emptyTimezoneProvider
     */
    public function testComputeNextRunAtUsesUtcForAnEmptyTimezoneAsItIsStored(mixed $timezone): void
    {
        $s = new Schedule();
        $s->cron_expression = '0 2 * * *';
        $s->timezone = $timezone;

        $s->computeNextRunAt();

        $this->assertNotNull($s->next_run_at);
        $this->assertSame('02:00', gmdate('H:i', (int)$s->next_run_at));
        $this->assertTrue($s->validate(['timezone']));
        $this->assertSame('UTC', $s->timezone);
    }

    /**
     * Regression: forms compute the next run before they validate, and the
     * cron parser's cost grows quadratically with the length of the text (a
     * 300 KB expression took about 35 s), so a long expression tied up a PHP
     * worker before the 64-character rule refused it. An expression longer
     * than the rule allows is not parsed, even a valid one like this.
     */
    public function testComputeNextRunAtSkipsACronExpressionLongerThanTheRuleAllows(): void
    {
        $s = new Schedule();
        $s->cron_expression = '0,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21 1,2 * * *';
        $s->timezone = 'UTC';
        $s->next_run_at = 1700000000;

        $s->computeNextRunAt();

        $this->assertSame(65, strlen($s->cron_expression));
        $this->assertNull($s->next_run_at);
        $this->assertFalse($s->validate(['cron_expression']));
        $this->assertSame(['Cron Expression should contain at most 64 characters.'], $s->getErrors('cron_expression'));
    }

    public function testComputeNextRunAtParsesACronExpressionOfTheLongestAllowedLength(): void
    {
        $s = new Schedule();
        $s->cron_expression = '0,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21 10 * * *';
        $s->timezone = 'UTC';

        $s->computeNextRunAt();

        $this->assertSame(Schedule::CRON_FIELD_MAX_LENGTH, strlen($s->cron_expression));
        $this->assertNotNull($s->next_run_at);
        $this->assertSame('10', gmdate('H', (int)$s->next_run_at));
        $this->assertTrue($s->validate(['cron_expression']));
    }

    /**
     * Control, not a regression: DateTimeZone refuses such text quickly as
     * well. The guard keeps the timezone to what the string rule accepts.
     */
    public function testComputeNextRunAtSkipsATimezoneLongerThanTheRuleAllows(): void
    {
        $s = new Schedule();
        $s->cron_expression = '0 2 * * *';
        $s->timezone = str_repeat('A', Schedule::CRON_FIELD_MAX_LENGTH + 1);
        $s->next_run_at = 1700000000;

        $s->computeNextRunAt();

        $this->assertNull($s->next_run_at);
        $this->assertFalse($s->validate(['timezone']));
        $this->assertSame(['Timezone should contain at most 64 characters.'], $s->getErrors('timezone'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function negativeStepProvider(): array
    {
        return [
            'a minute step of -1' => ['*/-1 * * * *'],
            'a minute range with a step of -1' => ['1-5/-1 * * * *'],
            'an hour step past its range' => ['* */-30 * * *'],
            'a month step past its range' => ['* * * */-30 *'],
            'a weekday step past its range' => ['* * * * */-30'],
            'the smallest integer' => ['*/-2147483648 * * * *'],
            'a single-value range' => ['0 0-0/-1 * * *'],
        ];
    }

    /**
     * Regression: the cron library accepts a negative step, then loops until
     * PHP runs out of memory or throws a ValueError when it computes a run.
     * The rule now refuses such an expression and computeNextRunAt() does not
     * parse it. validate() is asserted first: the old rule accepted these, and
     * computing the run would have ended the test process.
     *
     * @dataProvider negativeStepProvider
     */
    public function testACronExpressionWithANegativeStepIsInvalidAndNotParsed(string $cron): void
    {
        $s = new Schedule();
        $s->cron_expression = $cron;
        $s->timezone = 'UTC';
        $s->next_run_at = 1700000000;

        $this->assertFalse($s->validate(['cron_expression']));
        $this->assertSame(
            ['Invalid cron expression. Use standard 5-field format: min hour dom mon dow'],
            $s->getErrors('cron_expression')
        );
        $this->assertFalse(Schedule::isParsableCron($cron));

        $s->computeNextRunAt();
        $this->assertNull($s->next_run_at);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function positiveStepProvider(): array
    {
        return [
            'every 15 minutes' => ['*/15 * * * *'],
            'a minute range with a step' => ['1-30/5 * * * *'],
            'a list of steps' => ['*/5,*/7 * * * *'],
            'a weekday range without a step' => ['0 2 * * 1-5'],
        ];
    }

    /**
     * @dataProvider positiveStepProvider
     */
    public function testACronExpressionWithPositiveStepsIsValidAndParsed(string $cron): void
    {
        $s = new Schedule();
        $s->cron_expression = $cron;
        $s->timezone = 'UTC';

        $s->computeNextRunAt();

        $this->assertTrue(Schedule::isParsableCron($cron));
        $this->assertNotNull($s->next_run_at);
        $this->assertTrue($s->validate(['cron_expression']));
    }

    /**
     * Regression: DateTimeZone throws a ValueError, not an Exception, for a
     * timezone with a NUL byte. Neither the timezone rule nor
     * computeNextRunAt() caught it, so web and API answered 500.
     */
    public function testATimezoneWithANulByteIsInvalidAndGivesNoNextRun(): void
    {
        $s = new Schedule();
        $s->cron_expression = '0 2 * * *';
        $s->timezone = "UTC\0x";
        $s->next_run_at = 1700000000;

        $s->computeNextRunAt();

        $this->assertNull($s->next_run_at);
        $this->assertFalse($s->validate(['timezone']));
        $this->assertSame(['Invalid timezone identifier.'], $s->getErrors('timezone'));
    }

    public function testIsDueReturnsTrueWhenPast(): void
    {
        $user = $this->createUser();
        $schedule = $this->createScheduleFixture($user->id);
        $schedule->enabled = true;
        $schedule->next_run_at = time() - 60;
        $this->assertTrue($schedule->isDue());
    }

    public function testIsDueReturnsFalseWhenDisabled(): void
    {
        $user = $this->createUser();
        $schedule = $this->createScheduleFixture($user->id);
        $schedule->enabled = false;
        $schedule->next_run_at = time() - 60;
        $this->assertFalse($schedule->isDue());
    }

    public function testIsDueReturnsFalseWhenNextRunAtNull(): void
    {
        $user = $this->createUser();
        $schedule = $this->createScheduleFixture($user->id);
        $schedule->enabled = true;
        $schedule->next_run_at = null;
        $this->assertFalse($schedule->isDue());
    }

    public function testIsDueReturnsFalseWhenFuture(): void
    {
        $user = $this->createUser();
        $schedule = $this->createScheduleFixture($user->id);
        $schedule->enabled = true;
        $schedule->next_run_at = time() + 3600;
        $this->assertFalse($schedule->isDue());
    }

    public function testJobTemplateRelation(): void
    {
        $user = $this->createUser();
        $schedule = $this->createScheduleFixture($user->id);
        $this->assertInstanceOf(JobTemplate::class, $schedule->jobTemplate);
        $this->assertSame($schedule->job_template_id, $schedule->jobTemplate->id);
    }

    public function testCreatorRelation(): void
    {
        $user = $this->createUser();
        $schedule = $this->createScheduleFixture($user->id);
        $this->assertInstanceOf(User::class, $schedule->creator);
        $this->assertSame($user->id, $schedule->creator->id);
    }

    /**
     * Regression: the schedule form could set created_by, the user a
     * schedule launches as.
     */
    public function testTheCreatorCannotBeSetFromAForm(): void
    {
        $user = $this->createUser('sched_mass');
        $schedule = $this->createScheduleFixture($user->id);

        $schedule->load(['Schedule' => ['name' => 'renamed', 'created_by' => $user->id + 1000]]);

        $this->assertSame('renamed', $schedule->name);
        $this->assertSame($user->id, (int)$schedule->created_by);
        $this->assertNotContains('created_by', $schedule->safeAttributes());
    }
}
