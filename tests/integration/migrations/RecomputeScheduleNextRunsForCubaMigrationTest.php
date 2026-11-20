<?php

declare(strict_types=1);

namespace app\tests\integration\migrations;

use app\models\JobTemplate;
use app\models\Schedule;
use app\services\ScheduleEnablement;
use app\tests\integration\DbTestCase;
use m000090_000000_recompute_schedule_next_runs_for_time_zone_data as TimeZoneDataMigration;
use m000095_000000_recompute_schedule_next_runs_for_cuba as CubaMigration;
use yii\db\Connection;

/**
 * m000095_000000_recompute_schedule_next_runs_for_cuba: the next runs of the
 * schedules in America/Havana and Cuba, as 2.15.1 stored them on PHP 8.5.12
 * with the time zone data 2026.5, computed anew with the time zone data of
 * this PHP (Schedule::nextRunOf()). The updates run inside the test's
 * transaction and are rolled back with it. launches() plays the scheduler
 * from the update on, so that a case says which runs follow it.
 *
 * Regression: PHP 8.5.12 bundles the time zone data 2026.5 (IANA's 2026e),
 * which turns Cuba's clocks back to UTC-5 from 2026-11-01 to 2027-03-14;
 * PHP 8.5.13 bundles 2026.6 (IANA's 2026f), which keeps Cuba at UTC-4 then.
 * A next run that 2.15.1 stored for a schedule there was an hour late after
 * the update, until it had run; and an update in the hour between a run's
 * time by 2026.6 and the time 2.15.1 stored for it lost that run, unless
 * the migration stores the moment of the update for it (owesRun()).
 *
 * Times are in UTC: 9:00 in Havana is 13:00 by 2026.6 and 14:00 by 2026.5
 * from 2026-11-01 to 2027-03-14, and 13:00 by both from then on.
 */
class RecomputeScheduleNextRunsForCubaMigrationTest extends DbTestCase
{
    /** The message of every case that needs the time zone data 2026.6 or later. */
    private const NEEDS_NEW_DATA = 'this PHP computes with the time zone data 2026.6 or later, which keeps Cuba at UTC-4 this winter';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/' . TimeZoneDataMigration::class . '.php';
        require_once dirname(__DIR__, 3) . '/migrations/' . CubaMigration::class . '.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(self::at('2026-11-20 13:00'), Schedule::nextRunOf('0 9 * * *', 'America/Havana', self::at('2026-11-20 12:00')), self::NEEDS_NEW_DATA);
    }

    /**
     * Regression: PHP 8.5.13 (time zone data 2026.6) keeps Cuba at UTC-4
     * from 2026-11-01 to 2027-03-14, where PHP 8.5.12 (2026.5) turned its
     * clocks back to UTC-5; the cases below need it.
     */
    public function testRegressionThisPhpKeepsCubaAtUtcMinusFourThisWinter(): void
    {
        $this->assertTrue(version_compare(timezone_version_get(), CubaMigration::TIME_ZONE_DATA, '>='), self::NEEDS_NEW_DATA);
        foreach (CubaMigration::ZONES as $zone) {
            $offset = (new \DateTimeZone($zone))->getOffset(new \DateTimeImmutable('2026-12-01 12:00 UTC'));
            $this->assertSame(-14400, $offset, $zone);
        }
        $this->assertSame(self::at('2027-03-15 13:00'), Schedule::nextRunOf('0 9 * * *', 'America/Havana', self::at('2027-03-15 12:00')), 'UTC-4 from 2027-03-14 by both data');
        $this->assertSame(self::at('2027-11-08 14:00'), Schedule::nextRunOf('0 9 * * *', 'America/Havana', self::at('2027-11-08 12:00')), 'UTC-5 from 2027-11-07 by both data');
    }

    /**
     * Regression: a next run that 2.15.1 stored an hour late for a daily
     * schedule at 9:00 moves to 9:00 by the new rules, before its time, in
     * America/Havana and its alias Cuba.
     *
     * @return array<string, array{0: string}>
     */
    public static function cubanZoneProvider(): array
    {
        return ['America/Havana' => ['America/Havana'], 'Cuba, its alias' => ['Cuba']];
    }

    /**
     * @dataProvider cubanZoneProvider
     */
    public function testRegressionANextRunOf2151MovesToItsTime(string $zone): void
    {
        $now = self::at('2026-11-20 12:30');
        $this->startWithoutLeftovers($now);
        $schedule = $this->storedSchedule('0 9 * * *', $zone, '2026-11-19 14:00:30', '2026-11-19 14:00:30', '2026-11-20 14:00');

        $moved = $this->migration()->moveNextRuns($now);

        $this->assertSame(['moved' => 1, 'owed' => 0], $moved);
        $this->assertSame('2026-11-20 13:00', $this->nextRunAt($schedule));
    }

    /**
     * Schedules in America/Havana and Cuba as 2.15.1 stored them: cron,
     * zone, the run it launched last (the claim, which sets updated_at too;
     * null for none), updated_at, the next run it stored, the moment of the
     * update, the next run the update stores and the launches that follow
     * it, in UTC. A run whose time passed in the hour before the update,
     * after the last run and the last change of its schedule, comes at once:
     * the update stores its own moment. A schedule that is due keeps its next
     * run, and the runs that passed come with it, as runs missed do.
     *
     * @return array<string, array{0: string, 1: string, 2: string|null, 3: string, 4: string, 5: string, 6: string, 7: list<string>}>
     */
    public static function havanaProvider(): array
    {
        // The run 2.15.1 launched on 2026-11-19 for 9:00 at UTC-5, which is 10:00 at UTC-4.
        $ran = '2026-11-19 14:00:30';
        // The run 2.15.1 launched on 2026-11-20 for 8:00 at UTC-5, which is 9:00 at UTC-4.
        $ranLate = '2026-11-20 13:00:30';
        $daily = ['0 9 * * *', 'America/Havana', $ran, $ran, '2026-11-20 14:00'];
        $twice = ['0 8,9 * * *', 'America/Havana', $ran, $ran, '2026-11-20 13:00'];

        return [
            'before the time: it moves to its time' => array_merge($daily, ['2026-11-20 12:30', '2026-11-20 13:00', ['11-20 13:00', '11-21 13:00']]),
            'regression: at the time, it comes at once' => array_merge($daily, ['2026-11-20 13:00', '2026-11-20 13:00', ['11-20 13:00', '11-21 13:00']]),
            'regression: in the hour after the time, it comes at once' => array_merge($daily, ['2026-11-20 13:30', '2026-11-20 13:30', ['11-20 13:30', '11-21 13:00']]),
            'regression: a minute before the time 2.15.1 stored, it comes at once' => array_merge($daily, ['2026-11-20 13:59', '2026-11-20 13:59', ['11-20 13:59', '11-21 13:00']]),
            'at the time 2.15.1 stored: due, it stays' => array_merge($daily, ['2026-11-20 14:00', '2026-11-20 14:00', ['11-20 14:00', '11-21 13:00']]),
            'after the time 2.15.1 stored: due, it stays' => array_merge($daily, ['2026-11-20 14:30', '2026-11-20 14:00', ['11-20 14:30', '11-21 13:00']]),
            'regression: Cuba, its alias, before the time' => ['0 9 * * *', 'Cuba', $ran, $ran, '2026-11-20 14:00', '2026-11-20 12:30', '2026-11-20 13:00', ['11-20 13:00', '11-21 13:00']],
            'regression: Cuba, its alias, in the hour after the time' => ['0 9 * * *', 'Cuba', $ran, $ran, '2026-11-20 14:00', '2026-11-20 13:30', '2026-11-20 13:30', ['11-20 13:30', '11-21 13:00']],
            'regression: a zone name in other letters' => ['0 9 * * *', 'america/havana', $ran, $ran, '2026-11-20 14:00', '2026-11-20 13:30', '2026-11-20 13:30', ['11-20 13:30', '11-21 13:00']],
            'regression: the alias in other letters' => ['0 9 * * *', 'CUBA', $ran, $ran, '2026-11-20 14:00', '2026-11-20 12:30', '2026-11-20 13:00', ['11-20 13:00', '11-21 13:00']],
            // Regression (review): PHP reads a zone after blanks and a "(" too, and the migration compared the stored text.
            'regression: a blank before the zone name' => ['0 9 * * *', ' America/Havana', $ran, $ran, '2026-11-20 14:00', '2026-11-20 12:30', '2026-11-20 13:00', ['11-20 13:00', '11-21 13:00']],
            'regression: a blank before the zone name, in the hour after the time' => ['0 9 * * *', ' America/Havana', $ran, $ran, '2026-11-20 14:00', '2026-11-20 13:30', '2026-11-20 13:30', ['11-20 13:30', '11-21 13:00']],
            'regression: a tab before the alias' => ['0 9 * * *', "\tCuba", $ran, $ran, '2026-11-20 14:00', '2026-11-20 12:30', '2026-11-20 13:00', ['11-20 13:00', '11-21 13:00']],
            'regression: the alias in parentheses' => ['0 9 * * *', '(Cuba)', $ran, $ran, '2026-11-20 14:00', '2026-11-20 12:30', '2026-11-20 13:00', ['11-20 13:00', '11-21 13:00']],
            'regression: a parenthesis before the zone name, in other letters' => ['0 9 * * *', '(america/havana', $ran, $ran, '2026-11-20 14:00', '2026-11-20 13:30', '2026-11-20 13:30', ['11-20 13:30', '11-21 13:00']],
            'regression: weekly, before the time' => ['0 9 * * 1', 'America/Havana', '2026-11-16 14:00:30', '2026-11-16 14:00:30', '2026-11-23 14:00', '2026-11-21 10:00', '2026-11-23 13:00', ['11-23 13:00', '11-30 13:00']],
            'regression: weekly, in the hour after the time' => ['0 9 * * 1', 'America/Havana', '2026-11-16 14:00:30', '2026-11-16 14:00:30', '2026-11-23 14:00', '2026-11-23 13:45', '2026-11-23 13:45', ['11-23 13:45', '11-30 13:00']],
            'regression: every two hours, in the hour after a time' => ['0 */2 * * *', 'America/Havana', $ranLate, $ranLate, '2026-11-20 15:00', '2026-11-20 14:30', '2026-11-20 14:30', ['11-20 14:30', '11-20 16:00', '11-20 18:00']],
            'twice a day, before the first time: it moves to it' => array_merge($twice, ['2026-11-20 11:30', '2026-11-20 12:00', ['11-20 12:00', '11-20 13:00', '11-21 12:00', '11-21 13:00']]),
            'regression: twice a day, after the first time: it comes at once, the second at its time' => array_merge($twice, ['2026-11-20 12:30', '2026-11-20 12:30', ['11-20 12:30', '11-20 13:00', '11-21 12:00', '11-21 13:00']]),
            'twice a day, after both times: due, one run, as for any runs missed' => array_merge($twice, ['2026-11-20 13:30', '2026-11-20 13:00', ['11-20 13:30', '11-21 12:00', '11-21 13:00']]),
            'regression: twice a day, the second after 2.15.1 ran the first an hour late' => ['0 8,9 * * *', 'America/Havana', $ranLate, $ranLate, '2026-11-20 14:00', '2026-11-20 13:45', '2026-11-20 13:45', ['11-20 13:45', '11-21 12:00', '11-21 13:00']],
            'regression: twice a day, its password answers sealed right after that run' => ['0 8,9 * * *', 'America/Havana', $ranLate, '2026-11-20 13:00:35', '2026-11-20 14:00', '2026-11-20 13:45', '2026-11-20 13:45', ['11-20 13:45', '11-21 12:00', '11-21 13:00']],
            'regression: twice a day, its updated_at a minute after that run: still that run\'s' => ['0 8,9 * * *', 'America/Havana', $ranLate, '2026-11-20 13:01:30', '2026-11-20 14:00', '2026-11-20 13:45', '2026-11-20 13:45', ['11-20 13:45', '11-21 12:00', '11-21 13:00']],
            'twice a day, changed a minute and a second after that run: it runs from the change on' => ['0 8,9 * * *', 'America/Havana', $ranLate, '2026-11-20 13:01:31', '2026-11-20 14:00', '2026-11-20 13:45', '2026-11-21 12:00', ['11-21 12:00', '11-21 13:00']],
            'twice a day, a next run that no rules have, stored at that run: the first run after the update' => ['0 8,9 * * *', 'America/Havana', $ranLate, $ranLate, '2026-11-20 14:30', '2026-11-20 13:45', '2026-11-21 12:00', ['11-21 12:00', '11-21 13:00']],
            'regression: twice a day, its updated_at older than its last run: the run counts' => ['0 8,9 * * *', 'America/Havana', $ran, '2026-10-01 12:00:00', '2026-11-20 13:00', '2026-11-20 12:30', '2026-11-20 12:30', ['11-20 12:30', '11-20 13:00', '11-21 12:00', '11-21 13:00']],
            'regression: changed before the time: it comes at once' => ['0 9 * * *', 'America/Havana', $ran, '2026-11-20 10:00:00', '2026-11-20 14:00', '2026-11-20 13:30', '2026-11-20 13:30', ['11-20 13:30', '11-21 13:00']],
            'changed after the time: it runs from the change on, as after any change' => ['0 9 * * *', 'America/Havana', $ran, '2026-11-20 13:10:00', '2026-11-20 14:00', '2026-11-20 13:30', '2026-11-21 13:00', ['11-21 13:00']],
            'regression: never ran, created before the time: it comes at once' => ['0 9 * * *', 'America/Havana', null, '2026-11-20 10:00:00', '2026-11-20 14:00', '2026-11-20 13:30', '2026-11-20 13:30', ['11-20 13:30', '11-21 13:00']],
            'never ran, created after the time: the next day' => ['0 9 * * *', 'America/Havana', null, '2026-11-20 13:10:00', '2026-11-20 14:00', '2026-11-20 13:30', '2026-11-21 13:00', ['11-21 13:00']],
            'every hour: as it was, no run more' => ['0 * * * *', 'America/Havana', '2026-11-20 14:00:30', '2026-11-20 14:00:30', '2026-11-20 15:00', '2026-11-20 14:45', '2026-11-20 15:00', ['11-20 15:00', '11-20 16:00', '11-20 17:00']],
            'every half hour: as it was, no run more' => ['*/30 * * * *', 'America/Havana', '2026-11-20 14:30:30', '2026-11-20 14:30:30', '2026-11-20 15:00', '2026-11-20 14:45', '2026-11-20 15:00', ['11-20 15:00', '11-20 15:30', '11-20 16:00']],
            'regression: monthly, its next run in the winter moves to its time' => ['0 9 1 * *', 'America/Havana', '2026-11-01 14:00:30', '2026-11-01 14:00:30', '2026-12-01 14:00', '2026-11-20 12:00', '2026-12-01 13:00', ['12-01 13:00']],
            'regression: yearly, saved in the summer for the first of January: it moves to its time' => ['0 9 1 1 *', 'America/Havana', null, '2026-06-01 12:00:00', '2027-01-01 14:00', '2026-11-20 12:00', '2027-01-01 13:00', ['01-01 13:00']],
        ];
    }

    /**
     * @dataProvider havanaProvider
     * @param list<string> $launches
     */
    public function testRegressionInCubaNoRunIsLateOrLost(
        string $cron,
        string $zone,
        ?string $ran,
        string $changed,
        string $stored,
        string $now,
        string $next,
        array $launches
    ): void {
        $this->assertUpdate($cron, $zone, $ran, $changed, $stored, $now, $next, $launches);
    }

    /**
     * The night of 2027-03-14, when 2026.5 turns Cuba's clocks forward from
     * 0:00 at UTC-5 to 1:00 at UTC-4 and so skips 0:00 to 0:59, which it runs
     * by the clock before the change (CronRuns), and the days after it, at
     * which both data agree; then the night of 2027-11-07, when both turn
     * the clocks back to UTC-5. In the shape of havanaProvider().
     *
     * @return array<string, array{0: string, 1: string, 2: string|null, 3: string, 4: string, 5: string, 6: string, 7: list<string>}>
     */
    public static function endOfTheWinterProvider(): array
    {
        // The run 2.15.1 launched on 2027-03-13 for 0:00 at UTC-5, which is 1:00 at UTC-4.
        $midnight = ['0 0 * * *', 'America/Havana', '2027-03-13 05:00:30', '2027-03-13 05:00:30', '2027-03-14 05:00'];
        $ninePast = ['0 9 * * *', 'America/Havana', '2027-03-13 14:00:30', '2027-03-13 14:00:30', '2027-03-14 13:00'];

        return [
            'regression: before midnight: it moves to midnight' => array_merge($midnight, ['2027-03-14 03:30', '2027-03-14 04:00', ['03-14 04:00', '03-15 04:00']]),
            'regression: after midnight, which 2026.5 skips: it comes at once' => array_merge($midnight, ['2027-03-14 04:30', '2027-03-14 04:30', ['03-14 04:30', '03-15 04:00']]),
            'regression: 0:30 after 1:00 at UTC-4, where 2026.5 runs it: it comes at once' => ['30 0 * * *', 'America/Havana', '2027-03-13 05:30:30', '2027-03-13 05:30:30', '2027-03-14 05:30', '2027-03-14 05:10', '2027-03-14 05:10', ['03-14 05:10', '03-15 04:30']],
            'regression: 0:59, the last time 2026.5 puts an hour late, before 06:00 UTC: it comes at once' => ['59 0 * * *', 'America/Havana', '2027-03-13 05:59:30', '2027-03-13 05:59:30', '2027-03-14 05:59', '2027-03-14 05:58', '2027-03-14 05:58', ['03-14 05:58', '03-15 04:59']],
            'regression: 0:00 and 1:00, which 2026.5 runs at once: both come' => ['0 0,1 * * *', 'America/Havana', '2027-03-13 06:00:30', '2027-03-13 06:00:30', '2027-03-14 05:00', '2027-03-14 04:30', '2027-03-14 04:30', ['03-14 04:30', '03-14 05:00', '03-15 04:00', '03-15 05:00']],
            '1:00 at UTC-4 by both data: it stays' => ['0 1 * * *', 'America/Havana', '2027-03-13 06:00:30', '2027-03-13 06:00:30', '2027-03-14 05:00', '2027-03-14 04:30', '2027-03-14 05:00', ['03-14 05:00', '03-15 05:00']],
            'the first 9:00 at which both data agree, stored in the winter: it stays' => array_merge($ninePast, ['2027-03-14 12:30', '2027-03-14 13:00', ['03-14 13:00', '03-15 13:00']]),
            'in the hour after that 9:00: due, it stays' => array_merge($ninePast, ['2027-03-14 13:30', '2027-03-14 13:00', ['03-14 13:30', '03-15 13:00']]),
            'after the winter both data agree: it stays' => ['0 9 * * *', 'America/Havana', '2027-03-19 13:00:30', '2027-03-19 13:00:30', '2027-03-20 13:00', '2027-03-20 12:30', '2027-03-20 13:00', ['03-20 13:00', '03-21 13:00']],
            'back at UTC-5 by both data on 2027-11-07: it stays' => ['0 9 * * *', 'America/Havana', '2027-11-06 13:00:30', '2027-11-06 13:00:30', '2027-11-07 14:00', '2027-11-07 13:30', '2027-11-07 14:00', ['11-07 14:00', '11-08 14:00']],
        ];
    }

    /**
     * @dataProvider endOfTheWinterProvider
     * @param list<string> $launches
     */
    public function testAtTheEndOfTheWinterOnlyTheRunsOf2026Point5Move(
        string $cron,
        string $zone,
        ?string $ran,
        string $changed,
        string $stored,
        string $now,
        string $next,
        array $launches
    ): void {
        $this->assertUpdate($cron, $zone, $ran, $changed, $stored, $now, $next, $launches);
    }

    /**
     * Schedules in other zones, in the shape of havanaProvider(), with a
     * next run an hour off or at its time: the migration does not touch
     * them. America/Puerto_Rico is at UTC-4 all year, as the update notes
     * of 2.13.1 suggested it for Cuba's times; America/New_York turns its
     * clocks back on 2026-11-01 by both data; America/Vancouver stays at
     * UTC-7 from then on, as m000090 computed it.
     *
     * @return array<string, array{0: string, 1: string, 2: string|null, 3: string, 4: string, 5: string, 6: string, 7: list<string>}>
     */
    public static function otherZoneProvider(): array
    {
        return [
            'America/Puerto_Rico, an hour off' => ['0 9 * * *', 'America/Puerto_Rico', '2026-11-19 13:00:30', '2026-11-19 13:00:30', '2026-11-20 14:00', '2026-11-20 13:30', '2026-11-20 14:00', ['11-20 14:00', '11-21 13:00']],
            'America/Puerto_Rico, at its time' => ['0 9 * * *', 'America/Puerto_Rico', '2026-11-19 13:00:30', '2026-11-19 13:00:30', '2026-11-20 13:00', '2026-11-20 12:30', '2026-11-20 13:00', ['11-20 13:00', '11-21 13:00']],
            'America/New_York, an hour off' => ['0 9 * * *', 'America/New_York', '2026-11-19 14:00:30', '2026-11-19 14:00:30', '2026-11-20 15:00', '2026-11-20 14:30', '2026-11-20 15:00', ['11-20 15:00', '11-21 14:00']],
            'America/New_York, at its time' => ['0 9 * * *', 'America/New_York', '2026-11-19 14:00:30', '2026-11-19 14:00:30', '2026-11-20 14:00', '2026-11-20 13:30', '2026-11-20 14:00', ['11-20 14:00', '11-21 14:00']],
            'America/Vancouver, an hour off' => ['0 9 * * *', 'America/Vancouver', '2026-11-19 16:00:30', '2026-11-19 16:00:30', '2026-11-20 17:00', '2026-11-20 16:30', '2026-11-20 17:00', ['11-20 17:00', '11-21 16:00']],
            'UTC, an hour off' => ['0 9 * * *', 'UTC', '2026-11-19 09:00:30', '2026-11-19 09:00:30', '2026-11-20 10:00', '2026-11-20 09:30', '2026-11-20 10:00', ['11-20 10:00', '11-21 09:00']],
            'without a timezone, an hour off' => ['0 9 * * *', '', '2026-11-19 09:00:30', '2026-11-19 09:00:30', '2026-11-20 10:00', '2026-11-20 09:30', '2026-11-20 10:00', ['11-20 10:00', '11-21 09:00']],
        ];
    }

    /**
     * @dataProvider otherZoneProvider
     * @param list<string> $launches
     */
    public function testRegressionTheNextRunsInOtherZonesStay(
        string $cron,
        string $zone,
        ?string $ran,
        string $changed,
        string $stored,
        string $now,
        string $next,
        array $launches
    ): void {
        $this->assertSame($stored, $next, 'the case keeps its next run');
        $this->assertUpdate($cron, $zone, $ran, $changed, $stored, $now, $next, $launches);
    }

    /**
     * An update from 2.13.1 or older runs m000090 first, at the same moment,
     * which stores the first run after the update for every schedule there
     * that is not yet due (2.13.1's time zone data 2026.1 has the same rules
     * for Cuba as 2026.5): the moment of the update, cron, the run 2.13.1
     * launched last, its next run as m000090 stores it, as this migration
     * stores it, and the launches that follow.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: list<string>}>
     */
    public static function updateFrom2131Provider(): array
    {
        return [
            'regression: in the hour after the time, it comes at once' => ['2026-11-20 13:30', '0 9 * * *', '2026-11-19 14:00:30', '2026-11-20 14:00', '2026-11-21 13:00', '2026-11-20 13:30', ['11-20 13:30', '11-21 13:00']],
            'before the time: at its time' => ['2026-11-20 12:30', '0 9 * * *', '2026-11-19 14:00:30', '2026-11-20 14:00', '2026-11-20 13:00', '2026-11-20 13:00', ['11-20 13:00', '11-21 13:00']],
            // m000090 replaced the next run 2.13.1 stored after the run it launched an hour late.
            'twice a day, the second after the first ran an hour late: the next day, as m000090 left it' => ['2026-11-20 13:45', '0 8,9 * * *', '2026-11-20 13:00:30', '2026-11-20 14:00', '2026-11-21 12:00', '2026-11-21 12:00', ['11-21 12:00', '11-21 13:00']],
        ];
    }

    /**
     * Regression: m000090 stores the first run after the update, past the
     * run that passed in the hour before it, so the next run that 2.13.1
     * stored an hour late is gone when this migration runs; it finds that
     * run from the schedule's last run.
     *
     * @dataProvider updateFrom2131Provider
     * @param list<string> $launches
     */
    public function testRegressionAfterAnUpdateFrom2131TheRunThatPassedComesToo(
        string $now,
        string $cron,
        string $ran,
        string $stored,
        string $afterTimeZoneData,
        string $next,
        array $launches
    ): void {
        $at = self::at($now);
        $this->startWithoutLeftovers($at);
        $schedule = $this->storedSchedule($cron, 'America/Havana', $ran, $ran, $stored);

        (new TimeZoneDataMigration(['db' => \Yii::$app->db, 'compact' => true]))->moveNextRuns($at);
        $this->assertSame($afterTimeZoneData, $this->nextRunAt($schedule), 'm000090 stores the first run after the update');
        $this->migrate($at);

        $this->assertSame($next, $this->nextRunAt($schedule), 'the next run');
        $this->assertSame($launches, $this->launches($schedule, $now, (string)end($launches)), 'the launches from the update on');
    }

    /**
     * A disabled schedule launches nothing, so it owes no run: it gets the
     * first run after the update, as the other schedules not yet due. Enabling
     * it computes its next run from that moment on, so it does not run at once
     * because of the update.
     */
    public function testADisabledScheduleOwesNoRunAndEnablingItComputesItsNextRun(): void
    {
        $at = self::at('2026-11-20 13:30');
        $this->startWithoutLeftovers($at);
        // Disabled with its edit form on 2026-11-19, which computed the next run.
        $schedule = $this->storedSchedule('0 9 * * *', 'America/Havana', '2026-11-19 14:00:30', '2026-11-19 18:00:00', '2026-11-20 14:00', false);

        $this->assertSame(['moved' => 1, 'owed' => 0], $this->migration()->moveNextRuns($at));

        $this->assertSame('2026-11-21 13:00', $this->nextRunAt($schedule));
        $before = time();
        $disabled = Schedule::findOne($schedule->id);
        $this->assertInstanceOf(Schedule::class, $disabled);
        $this->assertNull((new ScheduleEnablement())->toggle($disabled, (int)$schedule->created_by));
        $next = $this->nextRunOf($schedule);
        $this->assertNotNull($next);
        $this->assertGreaterThan($before, $next, 'enabled, it is not due');
        $this->assertContains($next, [Schedule::nextRunOf('0 9 * * *', 'America/Havana', $before), Schedule::nextRunOf('0 9 * * *', 'America/Havana')]);
    }

    /**
     * A due schedule keeps its next run, which the scheduler launches, and
     * so do one without a next run and one whose next run cannot be
     * computed.
     */
    public function testANextRunThatIsDueOrCannotBeComputedStays(): void
    {
        $now = self::at('2026-11-20 12:30');
        $this->startWithoutLeftovers($now);
        $template = $this->template();
        $old = self::at('2026-11-20 14:00');
        $saved = self::at('2026-11-19 14:00:30');
        $kept = [
            'due at the moment' => $this->schedule($template, '0 9 * * *', 'America/Havana', $now, $saved),
            'due an hour ago' => $this->schedule($template, '0 9 * * *', 'Cuba', $now - 3600, $saved),
            'without a next run' => $this->schedule($template, '0 9 * * *', 'America/Havana', null, $saved),
            'an expression the library cannot read' => $this->schedule($template, 'not a cron', 'America/Havana', $old, $saved),
            'an expression the library cannot be handed' => $this->schedule($template, '0-30/-5 * * * *', 'America/Havana', $old, $saved),
            'an expression without a run' => $this->schedule($template, '0 0 30 2 *', 'America/Havana', $old, $saved),
        ];
        $before = array_map(fn (Schedule $schedule): ?int => $this->nextRunOf($schedule), $kept);

        $moved = $this->migration()->moveNextRuns($now);

        $this->assertSame(['moved' => 0, 'owed' => 0], $moved);
        $this->assertSame($before, array_map(fn (Schedule $schedule): ?int => $this->nextRunOf($schedule), $kept));
    }

    /**
     * The migration writes no schedule whose next run stays, in Cuba or in
     * another zone: it builds an update only for a next run that moves.
     */
    public function testANextRunThatStaysIsNotWritten(): void
    {
        $at = self::at('2026-11-20 14:45');
        $this->startWithoutLeftovers($at);
        $staying = [
            $this->storedSchedule('0 * * * *', 'America/Havana', '2026-11-20 14:00:30', '2026-11-20 14:00:30', '2026-11-20 15:00'),
            // Due: the update stopped the scheduler before it launched the run of 14:30.
            $this->storedSchedule('*/15 * * * *', 'America/Havana', '2026-11-20 14:15:30', '2026-11-20 14:15:30', '2026-11-20 14:30'),
            $this->storedSchedule('0 9 * * *', 'America/Havana', '2026-11-20 13:00:30', '2026-11-20 13:00:30', '2026-11-21 13:00'),
            $this->storedSchedule('0 9 * * *', 'America/Puerto_Rico', '2026-11-19 13:00:30', '2026-11-19 13:00:30', '2026-11-20 15:00'),
            $this->storedSchedule('0 9 * * *', 'America/New_York', '2026-11-19 14:00:30', '2026-11-19 14:00:30', '2026-11-20 15:00'),
            $this->storedSchedule('0 9 * * *', 'UTC', '2026-11-20 09:00:30', '2026-11-20 09:00:30', '2026-11-21 10:00'),
        ];
        $before = array_map(fn (Schedule $schedule): ?int => $this->nextRunOf($schedule), $staying);
        $updates = 0;
        $count = static function () use (&$updates): void {
            $updates++;
        };

        $moved = $this->migration($this->connectionCalling($count, false))->moveNextRuns($at);

        $this->assertSame(['moved' => 0, 'owed' => 0], $moved);
        $this->assertSame(0, $updates, 'no update is built');
        $this->assertSame($before, array_map(fn (Schedule $schedule): ?int => $this->nextRunOf($schedule), $staying));
    }

    /**
     * A schedule whose next run changed between the migration's select and
     * its update, as when the scheduler launched it or a user saved it,
     * keeps the next run it got: the update applies only to the next run
     * the migration read.
     */
    public function testANextRunThatChangedMeanwhileIsUntouched(): void
    {
        $at = self::at('2026-11-20 12:30');
        $this->startWithoutLeftovers($at);
        $schedule = $this->storedSchedule('0 9 * * *', 'America/Havana', '2026-11-19 14:00:30', '2026-11-19 14:00:30', '2026-11-20 14:00');
        $meanwhile = self::at('2026-11-21 13:00');

        $moved = $this->migration($this->connectionThatChanges($schedule, $meanwhile))->moveNextRuns($at);

        $this->assertSame(['moved' => 0, 'owed' => 0], $moved);
        $this->assertSame($meanwhile, $this->nextRunOf($schedule));
    }

    /**
     * The schedules a first run of the migration changed, each at its moment
     * of the update: running it again at that moment, or ten minutes later
     * while the scheduler has not run, changes nothing. Cron, zone, last run,
     * the next run 2.15.1 stored, the moment of the update, and how many next
     * runs the first run moves and how many of them to that moment.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: array{moved: int, owed: int}}>
     */
    public static function changedOnceProvider(): array
    {
        return [
            'a next run an hour late, moved to its time' => ['0 9 * * *', 'America/Havana', '2026-11-19 14:00:30', '2026-11-20 14:00', '2026-11-20 12:30', ['moved' => 1, 'owed' => 0]],
            'a run that passed in the hour before the update' => ['0 9 * * *', 'Cuba', '2026-11-19 14:00:30', '2026-11-20 14:00', '2026-11-20 13:30', ['moved' => 1, 'owed' => 1]],
            'twice a day, the second after the first ran an hour late' => ['0 8,9 * * *', 'America/Havana', '2026-11-20 13:00:30', '2026-11-20 14:00', '2026-11-20 13:45', ['moved' => 1, 'owed' => 1]],
            'the night of 2027-03-14, after midnight' => ['0 0 * * *', 'America/Havana', '2027-03-13 05:00:30', '2027-03-14 05:00', '2027-03-14 04:30', ['moved' => 1, 'owed' => 1]],
        ];
    }

    /**
     * @dataProvider changedOnceProvider
     * @param array{moved: int, owed: int} $first
     */
    public function testRunningItAgainChangesNothing(
        string $cron,
        string $zone,
        string $ran,
        string $stored,
        string $now,
        array $first
    ): void {
        $at = self::at($now);
        $this->startWithoutLeftovers($at);
        $schedule = $this->storedSchedule($cron, $zone, $ran, $ran, $stored);
        $this->assertSame($first, $this->migration()->moveNextRuns($at));
        $once = Schedule::findOne($schedule->id)?->getAttributes();

        $this->assertSame(['moved' => 0, 'owed' => 0], $this->migration()->moveNextRuns($at), 'at the same moment');
        $this->assertSame(['moved' => 0, 'owed' => 0], $this->migration()->moveNextRuns($at + 600), 'ten minutes later');

        $this->assertSame($once, Schedule::findOne($schedule->id)?->getAttributes());
    }

    /**
     * Time zone data older than 2026.6 turns Cuba's clocks back on
     * 2026-11-01: the migration moves nothing with it and says so.
     *
     * @return array<string, array{0: string}>
     */
    public static function olderTimeZoneDataProvider(): array
    {
        return ['2026.5, of PHP 8.5.12' => ['2026.5'], '2026.1, of PHP 8.3.36' => ['2026.1'], '2025.2' => ['2025.2']];
    }

    /**
     * @dataProvider olderTimeZoneDataProvider
     */
    public function testWithOlderTimeZoneDataItMovesNothingAndSaysSo(string $timeZoneData): void
    {
        $at = self::at('2026-11-20 13:30');
        $this->startWithoutLeftovers($at);
        $schedule = $this->storedSchedule('0 9 * * *', 'America/Havana', '2026-11-19 14:00:30', '2026-11-19 14:00:30', '2026-11-20 14:00');

        $summary = $this->migration()->summaryAt($timeZoneData, $at);

        $this->assertSame(sprintf(CubaMigration::OLD_TIME_ZONE_DATA, $timeZoneData, '2026.6'), $summary);
        $this->assertSame(
            "Moved no next run: the time zone data {$timeZoneData} of this PHP turns the clocks of America/Havana and Cuba back on 2026-11-01, which 2026.6 and later do not.",
            $summary
        );
        $this->assertSame('2026-11-20 14:00', $this->nextRunAt($schedule), 'the next run stays');
    }

    /**
     * Time zone data 2026.6 and later: the migration moves the next runs and
     * says how many. Regression: 2026.10 comes after 2026.6 as versions,
     * though not as text.
     *
     * @return array<string, array{0: string}>
     */
    public static function newTimeZoneDataProvider(): array
    {
        return ['2026.6, of PHP 8.5.13' => ['2026.6'], 'regression: 2026.10' => ['2026.10'], '2027.1' => ['2027.1']];
    }

    /**
     * @dataProvider newTimeZoneDataProvider
     */
    public function testWithTimeZoneData2026Point6OrLaterItMovesTheNextRuns(string $timeZoneData): void
    {
        $at = self::at('2026-11-20 13:30');
        $this->startWithoutLeftovers($at);
        $owed = $this->storedSchedule('0 9 * * *', 'America/Havana', '2026-11-19 14:00:30', '2026-11-19 14:00:30', '2026-11-20 14:00');
        $weekly = $this->storedSchedule('0 9 * * 1', 'Cuba', '2026-11-16 14:00:30', '2026-11-16 14:00:30', '2026-11-23 14:00');

        $summary = $this->migration()->summaryAt($timeZoneData, $at);

        $this->assertSame(sprintf(CubaMigration::MOVED, $timeZoneData, 2, 1), $summary);
        $this->assertSame(
            "Computed anew the next runs of the schedules in America/Havana and Cuba that are not yet due, with time zone data {$timeZoneData}: 2 moved, 1 of them to now for a run that passed in the past hour.",
            $summary
        );
        $this->assertSame('2026-11-20 13:30', $this->nextRunAt($owed));
        $this->assertSame('2026-11-23 13:00', $this->nextRunAt($weekly));
    }

    /**
     * Up computes from the clock and prints how many next runs moved, and
     * how many of them to the moment of the update, with the version of the
     * time zone data.
     */
    public function testUpPrintsHowManyNextRunsMoved(): void
    {
        $this->up();
        $next = Schedule::nextRunOf('0 9 * * *', 'America/Havana');
        $this->assertNotNull($next);
        $template = $this->template();
        $schedule = $this->schedule($template, '0 9 * * *', 'America/Havana', $next + 3600);
        // Ran a minute and a half ago, with its next run an hour ahead: the run of the minute after it is owed.
        $ran = time() - 90;
        $owed = $this->schedule($template, '* * * * *', 'Cuba', $ran + 3600, $ran, $ran);
        $other = $this->schedule($template, '0 9 * * *', 'America/Puerto_Rico', $next + 3600);

        $output = $this->up();

        $this->assertSame($next, $this->nextRunOf($schedule));
        $this->assertLessThanOrEqual(time(), $this->nextRunOf($owed), 'the owed run is due');
        $this->assertSame($next + 3600, $this->nextRunOf($other), 'another zone is not touched');
        $this->assertSame(self::printed(2, 1), $output);
        $this->assertSame(self::printed(0, 0), $this->up(), 'nothing moves the second time');
    }

    /**
     * Down succeeds without touching data: a release on PHP 8.5.12 launches
     * the next runs this migration stored and computes the ones after them
     * itself.
     */
    public function testDownSucceedsAndRestoresNothing(): void
    {
        $at = self::at('2026-11-20 12:30');
        $this->startWithoutLeftovers($at);
        $schedule = $this->storedSchedule('0 9 * * *', 'America/Havana', '2026-11-19 14:00:30', '2026-11-19 14:00:30', '2026-11-20 14:00');
        $this->migration()->moveNextRuns($at);
        $moved = $this->nextRunOf($schedule);
        $this->assertSame(self::at('2026-11-20 13:00'), $moved);

        $this->assertTrue($this->migration()->safeDown());

        $this->assertSame($moved, $this->nextRunOf($schedule));
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * Stores a schedule as 2.15.1 left it, runs the migration at $now and
     * asserts the next run it stores and the launches of the scheduler from
     * $now to the last of $launches. Times in UTC: 'Y-m-d H:i', the last run
     * and the change with seconds; launches as 'm-d H:i'.
     *
     * @param list<string> $launches
     */
    private function assertUpdate(
        string $cron,
        string $zone,
        ?string $ran,
        string $changed,
        string $stored,
        string $now,
        string $next,
        array $launches
    ): void {
        $at = self::at($now);
        $this->startWithoutLeftovers($at);
        $schedule = $this->storedSchedule($cron, $zone, $ran, $changed, $stored);

        $this->migrate($at);

        $this->assertSame($next, $this->nextRunAt($schedule), 'the next run');
        $this->assertSame($launches, $this->launches($schedule, $now, (string)end($launches)), 'the launches from the update on');
    }

    /**
     * The launches of the scheduler from $from, UTC 'Y-m-d H:i', to $until,
     * 'm-d H:i' of the same year or, when that is earlier, of the next: it
     * ticks every minute, launches the schedule when its next run is due and
     * stores the first run after that tick, as
     * ScheduleService::claimSchedule() does.
     *
     * @return list<string> the ticks that launched it, as 'm-d H:i' in UTC
     */
    private function launches(Schedule $schedule, string $from, string $until): array
    {
        $tick = self::at($from);
        $year = (int)substr($from, 0, 4);
        $end = self::at($year . '-' . $until);
        if ($end < $tick) {
            $end = self::at(($year + 1) . '-' . $until);
        }
        $next = $this->nextRunOf($schedule);
        $launches = [];
        for (; $tick <= $end; $tick += 60) {
            if ($next !== null && $next <= $tick) {
                $launches[] = gmdate('m-d H:i', $tick);
                $next = Schedule::nextRunOf((string)$schedule->cron_expression, (string)$schedule->timezone ?: 'UTC', $tick);
            }
        }

        return $launches;
    }

    /**
     * A schedule as 2.15.1 stored it: its last run ($ran, null for none),
     * its updated_at ($changed) and its next run ($stored), in UTC.
     */
    private function storedSchedule(
        string $cron,
        string $zone,
        ?string $ran,
        string $changed,
        string $stored,
        bool $enabled = true
    ): Schedule {
        $schedule = $this->schedule(
            $this->template(),
            $cron,
            $zone,
            self::at($stored),
            self::at($changed),
            $ran === null ? null : self::at($ran)
        );
        if (!$enabled) {
            Schedule::updateAll(['enabled' => 0], ['id' => $schedule->id]);
        }

        return $schedule;
    }

    private function migration(?Connection $db = null): CubaMigration
    {
        return new CubaMigration(['db' => $db ?? \Yii::$app->db, 'compact' => true]);
    }

    /**
     * Runs the migration at $now.
     */
    private function migrate(int $now): void
    {
        $this->migration()->moveNextRuns($now);
    }

    /**
     * Moves the next runs of the schedules the test database holds already,
     * so that every count after it is the test's own.
     */
    private function startWithoutLeftovers(int $now): void
    {
        $this->migration()->moveNextRuns($now);
    }

    /**
     * What safeUp() prints when $moved next runs moved, $owed of them to the
     * moment of the update.
     */
    private static function printed(int $moved, int $owed): string
    {
        return '    > ' . sprintf(CubaMigration::MOVED, timezone_version_get(), $moved, $owed) . "\n";
    }

    /**
     * Runs safeUp() and returns what it printed.
     */
    private function up(): string
    {
        ob_start();
        try {
            $this->migration()->safeUp();
        } finally {
            $output = (string)ob_get_clean();
        }

        return $output;
    }

    /**
     * The test's connection, with its session and transaction, whose first
     * command created without a statement first stores $nextRunAt as the
     * next run of $schedule, as another process would: the migration
     * builds each update from such a command, after its select.
     */
    private function connectionThatChanges(Schedule $schedule, int $nextRunAt): Connection
    {
        $db = \Yii::$app->db;
        $change = static function () use ($db, $schedule, $nextRunAt): void {
            $db->createCommand()
                ->update('{{%schedule}}', ['next_run_at' => $nextRunAt], ['id' => $schedule->id])
                ->execute();
        };

        return $this->connectionCalling($change, true);
    }

    /**
     * The test's connection, with its session and transaction, that calls
     * $call before it creates a command without a statement, the first one
     * only when $once.
     */
    private function connectionCalling(\Closure $call, bool $once): Connection
    {
        $db = \Yii::$app->db;
        $config = ['pdo' => $db->pdo, 'dsn' => $db->dsn, 'tablePrefix' => $db->tablePrefix];

        return new class ($config, $call, $once) extends Connection {
            /** @var (\Closure(): void)|null */
            private ?\Closure $call;

            private bool $once;

            /**
             * @param array<string, mixed> $config
             */
            public function __construct(array $config, \Closure $call, bool $once)
            {
                $this->call = $call;
                $this->once = $once;
                parent::__construct($config);
            }

            /**
             * @param string|null $sql
             * @param array<mixed> $params
             * @return \yii\db\Command
             */
            public function createCommand($sql = null, $params = [])
            {
                if ($sql === null && $this->call !== null) {
                    $call = $this->call;
                    $this->call = $this->once ? null : $call;
                    $call();
                }

                return parent::createCommand($sql, $params);
            }
        };
    }

    private function template(): JobTemplate
    {
        $owner = $this->createUser('cuba_next_runs');

        return $this->createJobTemplate(
            (int)$this->createProject((int)$owner->id)->id,
            (int)$this->createInventory((int)$owner->id)->id,
            (int)$this->createRunnerGroup((int)$owner->id)->id,
            (int)$owner->id
        );
    }

    /**
     * A schedule with $nextRunAt as its next run, and, when given,
     * $changedAt as its updated_at and $ranAt as its last run.
     */
    private function schedule(
        JobTemplate $template,
        string $cron,
        string $timezone,
        ?int $nextRunAt,
        ?int $changedAt = null,
        ?int $ranAt = null
    ): Schedule {
        $schedule = new Schedule();
        $schedule->name = 'cuba-next-runs-' . uniqid('', true);
        $schedule->job_template_id = $template->id;
        $schedule->cron_expression = $cron;
        $schedule->timezone = $timezone;
        $schedule->enabled = true;
        $schedule->next_run_at = $nextRunAt;
        $schedule->created_by = $template->created_by;
        $schedule->save(false);
        if ($changedAt !== null) {
            // After the save, whose TimestampBehavior sets updated_at to the clock.
            Schedule::updateAll(['updated_at' => $changedAt, 'last_run_at' => $ranAt], ['id' => $schedule->id]);
        }

        return $schedule;
    }

    private function nextRunOf(Schedule $schedule): ?int
    {
        $value = Schedule::find()->select('next_run_at')->where(['id' => $schedule->id])->scalar();

        return $value === null ? null : (int)$value;
    }

    /**
     * The next run of $schedule as it is stored, as 'Y-m-d H:i' in UTC.
     */
    private function nextRunAt(Schedule $schedule): ?string
    {
        $next = $this->nextRunOf($schedule);

        return $next === null ? null : gmdate('Y-m-d H:i', $next);
    }

    /**
     * A moment in UTC, 'Y-m-d H:i' with or without seconds.
     */
    private static function at(string $utc): int
    {
        $at = strtotime($utc . ' UTC');
        self::assertIsInt($at, $utc);

        return $at;
    }
}
