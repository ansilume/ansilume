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
}
