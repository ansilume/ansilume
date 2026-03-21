<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\Schedule;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Schedule model validation and next-run computation.
 * Does NOT require a database.
 */
class ScheduleTest extends TestCase
{
    public function testValidCronExpressionPassesValidation(): void
    {
        $schedule = $this->makeSchedule('0 2 * * *');
        $schedule->validate(['cron_expression']);
        $this->assertFalse($schedule->hasErrors('cron_expression'));
    }

    public function testInvalidCronExpressionFailsValidation(): void
    {
        $schedule = $this->makeSchedule('not-a-cron');
        $schedule->validate(['cron_expression']);
        $this->assertTrue($schedule->hasErrors('cron_expression'));
    }

    public function testSixFieldCronFailsValidation(): void
    {
        // Standard cron is 5-field; 6-field (with seconds) should be rejected
        $schedule = $this->makeSchedule('0 0 2 * * *');
        $schedule->validate(['cron_expression']);
        $this->assertTrue($schedule->hasErrors('cron_expression'));
    }

    public function testValidTimezonePassesValidation(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->timezone = 'Europe/Berlin';
        $schedule->validate(['timezone']);
        $this->assertFalse($schedule->hasErrors('timezone'));
    }

    public function testInvalidTimezoneFailsValidation(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->timezone = 'Mars/Olympus';
        $schedule->validate(['timezone']);
        $this->assertTrue($schedule->hasErrors('timezone'));
    }

    public function testComputeNextRunAtProducesTimestampInFuture(): void
    {
        $schedule = $this->makeSchedule('* * * * *'); // every minute
        $schedule->timezone = 'UTC';
        $schedule->computeNextRunAt();

        $this->assertNotNull($schedule->next_run_at);
        $this->assertGreaterThan(time() - 5, $schedule->next_run_at);
    }

    public function testComputeNextRunAtWithTimezone(): void
    {
        $schedule = $this->makeSchedule('0 0 * * *'); // midnight
        $schedule->timezone = 'America/New_York';
        $schedule->computeNextRunAt();

        $this->assertNotNull($schedule->next_run_at);
        $this->assertGreaterThan(time() - 5, $schedule->next_run_at);
    }

    public function testComputeNextRunAtWithInvalidExpressionSetsNull(): void
    {
        $schedule = $this->makeSchedule('not-a-cron');
        $schedule->computeNextRunAt();
        $this->assertNull($schedule->next_run_at);
    }

    public function testIsDueReturnsFalseWhenDisabled(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->enabled    = false;
        $schedule->next_run_at = time() - 60;
        $this->assertFalse($schedule->isDue());
    }

    public function testIsDueReturnsFalseWhenNextRunInFuture(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->enabled    = true;
        $schedule->next_run_at = time() + 300;
        $this->assertFalse($schedule->isDue());
    }

    public function testIsDueReturnsTrueWhenNextRunPassed(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->enabled    = true;
        $schedule->next_run_at = time() - 60;
        $this->assertTrue($schedule->isDue());
    }

    public function testIsDueReturnsFalseWhenNextRunIsNull(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->enabled    = true;
        $schedule->next_run_at = null;
        // Without a DB-backed cron check this falls back to false
        $this->assertFalse($schedule->isDue());
    }

    public function testValidJsonExtraVarsPassesValidation(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->extra_vars = '{"env":"prod"}';
        $schedule->validate(['extra_vars']);
        $this->assertFalse($schedule->hasErrors('extra_vars'));
    }

    public function testInvalidJsonExtraVarsFailsValidation(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->extra_vars = '{not json}';
        $schedule->validate(['extra_vars']);
        $this->assertTrue($schedule->hasErrors('extra_vars'));
    }

    private function makeSchedule(string $cron = '0 2 * * *'): Schedule
    {
        $schedule = new Schedule();
        $schedule->cron_expression = $cron;
        $schedule->timezone        = 'UTC';
        return $schedule;
    }
}
