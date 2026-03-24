<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\Schedule;
use app\services\ScheduleService;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

/**
 * Tests for ScheduleService::isDue() — the core scheduling decision.
 * isDue() is private; accessed via ReflectionMethod.
 */
class ScheduleServiceTest extends TestCase
{
    private ScheduleService $service;
    private \ReflectionMethod $isDue;

    protected function setUp(): void
    {
        $this->service = new ScheduleService();
        $this->isDue   = new \ReflectionMethod(ScheduleService::class, 'isDue');
        $this->isDue->setAccessible(true);
    }

    // ── next_run_at is set ────────────────────────────────────────────────────

    public function testIsDueWhenNextRunAtIsInThePast(): void
    {
        $schedule = $this->makeSchedule(['next_run_at' => time() - 60]);
        $this->assertTrue($this->isDue->invoke($this->service, $schedule));
    }

    public function testIsDueWhenNextRunAtIsExactlyNow(): void
    {
        $schedule = $this->makeSchedule(['next_run_at' => time()]);
        $this->assertTrue($this->isDue->invoke($this->service, $schedule));
    }

    public function testIsNotDueWhenNextRunAtIsInTheFuture(): void
    {
        $schedule = $this->makeSchedule(['next_run_at' => time() + 3600]);
        $this->assertFalse($this->isDue->invoke($this->service, $schedule));
    }

    // ── next_run_at is null — falls back to CronExpression::isDue() ──────────

    public function testIsDueWhenNextRunNullAndCronMatchesNow(): void
    {
        // "* * * * *" always matches
        $schedule = $this->makeSchedule(['next_run_at' => null, 'cron_expression' => '* * * * *']);
        $this->assertTrue($this->isDue->invoke($this->service, $schedule));
    }

    public function testIsNotDueWhenNextRunNullAndCronDoesNotMatchNow(): void
    {
        // Use a minute offset guaranteed to not be the current minute.
        // If it's currently :00 we check :30, otherwise :00. This avoids
        // time-dependent flakiness while still exercising the cron path.
        $safeMinute = ((int)date('i') === 0) ? 30 : 0;
        $safeHour   = ((int)date('G') === 3) ? 4 : 3;
        $cron = "{$safeMinute} {$safeHour} 15 6 *"; // June 15 at an hour/minute that is not now

        $schedule = $this->makeSchedule(['next_run_at' => null, 'cron_expression' => $cron]);
        $this->assertFalse($this->isDue->invoke($this->service, $schedule));
    }

    public function testIsNotDueWhenNextRunNullAndCronIsInvalid(): void
    {
        $schedule = $this->makeSchedule(['next_run_at' => null, 'cron_expression' => 'not-a-cron']);
        $this->assertFalse($this->isDue->invoke($this->service, $schedule));
    }

    public function testIsDueUsesTimezoneWhenNextRunNullAndCronMatches(): void
    {
        // "* * * * *" is always due regardless of timezone
        $schedule = $this->makeSchedule([
            'next_run_at'     => null,
            'cron_expression' => '* * * * *',
            'timezone'        => 'America/New_York',
        ]);
        $this->assertTrue($this->isDue->invoke($this->service, $schedule));
    }

    public function testIsDueUsesUtcWhenTimezoneIsEmpty(): void
    {
        $schedule = $this->makeSchedule([
            'next_run_at'     => null,
            'cron_expression' => '* * * * *',
            'timezone'        => '',
        ]);
        $this->assertTrue($this->isDue->invoke($this->service, $schedule));
    }

    private function makeSchedule(array $attrs = []): Schedule
    {
        $s = $this->getMockBuilder(Schedule::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $s->method('attributes')->willReturn(
            ['id', 'name', 'job_template_id', 'cron_expression', 'timezone',
             'extra_vars', 'enabled', 'last_run_at', 'next_run_at', 'created_by', 'created_at', 'updated_at']
        );
        $s->method('save')->willReturn(true);
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($s, array_merge([
            'id'              => 1,
            'name'            => 'test',
            'job_template_id' => 1,
            'cron_expression' => '0 2 * * *',
            'timezone'        => 'UTC',
            'extra_vars'      => null,
            'enabled'         => true,
            'last_run_at'     => null,
            'next_run_at'     => null,
            'created_by'      => 1,
            'created_at'      => null,
            'updated_at'      => null,
        ], $attrs));
        return $s;
    }
}
