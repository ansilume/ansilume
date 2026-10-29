<?php

declare(strict_types=1);

namespace app\tests\integration\migrations;

use app\models\JobTemplate;
use app\models\Schedule;
use app\services\ScheduleEnablement;
use app\tests\integration\DbTestCase;
use m000089_000000_recompute_schedule_next_runs as NextRunsMigration;
use m000090_000000_recompute_schedule_next_runs_for_time_zone_data as TimeZoneDataMigration;
use yii\db\Connection;

/**
 * m000090_000000_recompute_schedule_next_runs_for_time_zone_data: the next
 * runs of the schedules, as 2.13.1 stored them on PHP 8.3 with the time zone
 * data 2026.1, computed anew with the time zone data of this PHP
 * (Schedule::nextRunOf()). The updates run inside the test's transaction and
 * are rolled back with it. launches() plays the scheduler from the update
 * on, so that a case says which runs follow it.
 *
 * Regression: PHP 8.3 bundles the time zone data 2026.1, PHP 8.5 2026.5, in
 * which Africa/Casablanca and Africa/El_Aaiun are at UTC+0 since 2026-09-20
 * and America/Vancouver, America/Edmonton and America/Winnipeg (with their
 * aliases) stay on their summer offset from 2026-11-01. A next run that PHP
 * 8.3 stored for a schedule there was an hour off after the update, until it
 * had run.
 *
 * Regression (review of the move to PHP 8.5): the migration stored the first
 * run after the update, also when the update came in the hour between a run
 * of the new rules and the time 2.13.1 had stored for it, an hour late, so
 * that run never came: that day's run of a daily schedule in Vancouver, that
 * week's of a weekly one, and the second of "0 8,9 * * *". An update from
 * 2.12.2 or older, whose m000089 had moved the run past that hour already,
 * lost it too. In Morocco it moved a schedule whose run 2.13.1 had launched
 * an hour early to the time of that run by the new rules, when the update
 * came in the hour after it, so the schedule ran twice that day, as it did
 * on the day before 2026.1 is at UTC+0 for Ramadan, where it moved the next
 * run earlier; and it left a run that was due an hour early, which ran again
 * at its time.
 */
class RecomputeScheduleNextRunsForTimeZoneDataMigrationTest extends DbTestCase
{
    /** The moment the tests with a fixed now take: 2026-10-31 16:30 UTC, the evening before the change in Canada. */
    private const NOW = 1793464200;

    /**
     * When the schedules of the tests at NOW were last saved: 2026-10-31
     * 16:00:30 UTC, the run of 9:00 in Vancouver that day.
     */
    private const SAVED = 1793462430;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/' . NextRunsMigration::class . '.php';
        require_once dirname(__DIR__, 3) . '/migrations/' . TimeZoneDataMigration::class . '.php';
    }

    /**
     * The next run of "0 9 * * *" after NOW in a zone whose rules changed, as
     * PHP 8.3 computed it with the time zone data 2026.1, and as this PHP
     * computes it with 2026.5: Vancouver, Edmonton and Winnipeg stay on their
     * summer offset from 2026-11-01, where 2026.1 has them go back an hour;
     * Casablanca is at UTC+0 since 2026-09-20, where 2026.1 has UTC+1, so
     * PHP 8.3 put its runs an hour early.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function changedZoneProvider(): array
    {
        return [
            'America/Vancouver' => ['America/Vancouver', '2026-11-01 09:00 -08:00', '2026-11-01 09:00 -07:00'],
            'Canada/Pacific, its alias' => ['Canada/Pacific', '2026-11-01 09:00 -08:00', '2026-11-01 09:00 -07:00'],
            'America/Edmonton' => ['America/Edmonton', '2026-11-01 09:00 -07:00', '2026-11-01 09:00 -06:00'],
            'America/Winnipeg' => ['America/Winnipeg', '2026-11-01 09:00 -06:00', '2026-11-01 09:00 -05:00'],
            'Africa/Casablanca' => ['Africa/Casablanca', '2026-11-01 09:00 +01:00', '2026-11-01 09:00 +00:00'],
        ];
    }

    /**
     * @dataProvider changedZoneProvider
     */
    public function testRegressionANextRunOfTheOldTimeZoneDataMovesToItsTime(
        string $zone,
        string $old,
        string $new
    ): void {
        $next = Schedule::nextRunOf('0 9 * * *', $zone, self::NOW);
        $this->assertSame(strtotime($new), $next, 'this PHP computes with the time zone data 2026.5 or later');
        $this->startWithoutLeftovers(self::NOW);
        $schedule = $this->schedule($this->template(), '0 9 * * *', $zone, (int)strtotime($old), self::SAVED);

        $moved = $this->migration()->moveNextRuns(self::NOW);

        $this->assertSame(['moved' => 1, 'owed' => 0], $moved);
        $this->assertSame($next, $this->nextRunOf($schedule));
    }

    /**
     * A schedule at 9:00 in every zone whose rules changed, the update in the
     * hour after a run that 2.13.1 put an hour off: the run 2.13.1 last
     * launched (the claim, which sets updated_at too), the next run it
     * stored, the moment of the update, the next run the update stores and
     * the launches that follow it, in UTC.
     * - Where 2.13.1 runs the schedules an hour late (America/Vancouver,
     *   UTC-7 from 2026-11-01 where 2026.1 has UTC-8, America/Edmonton and
     *   the other zones of UTC-6 where it has UTC-7, America/Winnipeg and
     *   those of UTC-5 where it has UTC-6, and their aliases), the update
     *   comes half an hour after that day's run by the new rules: the run
     *   comes at once, then the next day's at its time.
     * - Where 2.13.1 runs them an hour early (Africa/Casablanca and
     *   Africa/El_Aaiun, UTC+0 where 2026.1 has UTC+1), the update comes
     *   half an hour after the run 2.13.1 launched an hour early: that run
     *   was the day's, so the next is that of the next day, at its time.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: list<string>}>
     */
    public static function everyChangedZoneProvider(): array
    {
        $pacific = ['2026-10-31 16:00:30', '2026-11-01 17:00', '2026-11-01 16:30', '2026-11-01 16:30', ['11-01 16:30', '11-02 16:00']];
        $mountain = ['2026-10-31 15:00:30', '2026-11-01 16:00', '2026-11-01 15:30', '2026-11-01 15:30', ['11-01 15:30', '11-02 15:00']];
        $central = ['2026-10-31 14:00:30', '2026-11-01 15:00', '2026-11-01 14:30', '2026-11-01 14:30', ['11-01 14:30', '11-02 14:00']];
        $morocco = ['2026-10-28 08:00:30', '2026-10-29 08:00', '2026-10-28 08:30', '2026-10-29 09:00', ['10-29 09:00', '10-30 09:00']];
        $zones = [
            'America/Vancouver' => $pacific,
            'Canada/Pacific' => $pacific,
            'America/Edmonton' => $mountain,
            'America/Inuvik' => $mountain,
            'America/Yellowknife' => $mountain,
            'Canada/Mountain' => $mountain,
            'America/Winnipeg' => $central,
            'America/Rainy_River' => $central,
            'Canada/Central' => $central,
            'Africa/Casablanca' => $morocco,
            'Africa/El_Aaiun' => $morocco,
        ];
        $cases = [];
        foreach ($zones as $zone => $case) {
            $cases[$zone] = array_merge([$zone], $case);
        }

        return $cases;
    }

    /**
     * @dataProvider everyChangedZoneProvider
     * @param list<string> $launches
     */
    public function testRegressionTheRunOfTheDayComesOnceInEveryChangedZone(
        string $zone,
        string $ran,
        string $stored,
        string $now,
        string $next,
        array $launches
    ): void {
        $this->assertUpdate('0 9 * * *', $zone, $ran, $ran, $stored, $now, $next, $launches);
    }

    /**
     * Schedules in America/Vancouver and the zones whose time zone data
     * 2026.1 ran them an hour late as well, as 2.13.1 stored them: cron,
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
    public static function lateZoneProvider(): array
    {
        // The run of 9:00 on 2026-10-31, at UTC-7 by both rules.
        $ran = '2026-10-31 16:00:30';
        // The run 2.13.1 launched on 2026-11-01 for 8:00 at UTC-8, which is 9:00 at UTC-7.
        $ranLate = '2026-11-01 16:00:30';
        $daily = ['0 9 * * *', 'America/Vancouver', $ran, $ran, '2026-11-01 17:00'];
        $twice = ['0 8,9 * * *', 'America/Vancouver', $ran, $ran, '2026-11-01 16:00'];

        return [
            'before the time: it moves to its time' => array_merge($daily, ['2026-11-01 15:30', '2026-11-01 16:00', ['11-01 16:00', '11-02 16:00']]),
            'regression: in the hour after the time, it comes at once' => array_merge($daily, ['2026-11-01 16:30', '2026-11-01 16:30', ['11-01 16:30', '11-02 16:00']]),
            'at the time 2.13.1 stored: due, it stays' => array_merge($daily, ['2026-11-01 17:00', '2026-11-01 17:00', ['11-01 17:00', '11-02 16:00']]),
            'after the time 2.13.1 stored: due, it stays' => array_merge($daily, ['2026-11-01 17:30', '2026-11-01 17:00', ['11-01 17:30', '11-02 16:00']]),
            'regression: a zone name in other letters' => ['0 9 * * *', 'america/vancouver', $ran, $ran, '2026-11-01 17:00', '2026-11-01 16:30', '2026-11-01 16:30', ['11-01 16:30', '11-02 16:00']],
            'regression: weekly, in the hour after the time' => ['0 9 * * 1', 'America/Winnipeg', '2026-10-26 14:00:30', '2026-10-26 14:00:30', '2026-11-02 15:00', '2026-11-02 14:45', '2026-11-02 14:45', ['11-02 14:45', '11-09 14:00']],
            'regression: every two hours, in the hour after a time' => ['0 */2 * * *', 'America/Vancouver', '2026-11-02 16:00:30', '2026-11-02 16:00:30', '2026-11-02 18:00', '2026-11-02 17:30', '2026-11-02 17:30', ['11-02 17:30', '11-02 19:00', '11-02 21:00']],
            'twice a day, before the first time: it moves to it' => array_merge($twice, ['2026-11-01 14:30', '2026-11-01 15:00', ['11-01 15:00', '11-01 16:00', '11-02 15:00', '11-02 16:00']]),
            'regression: twice a day, after the first time: it comes at once, the second at its time' => array_merge($twice, ['2026-11-01 15:30', '2026-11-01 15:30', ['11-01 15:30', '11-01 16:00', '11-02 15:00', '11-02 16:00']]),
            'twice a day, after both times: due, one run, as for any runs missed' => array_merge($twice, ['2026-11-01 16:30', '2026-11-01 16:00', ['11-01 16:30', '11-02 15:00', '11-02 16:00']]),
            'regression: twice a day, the second after 2.13.1 ran the first an hour late' => ['0 8,9 * * *', 'America/Vancouver', $ranLate, $ranLate, '2026-11-01 17:00', '2026-11-01 16:45', '2026-11-01 16:45', ['11-01 16:45', '11-02 15:00', '11-02 16:00']],
            'regression: twice a day, its password answers sealed right after that run' => ['0 8,9 * * *', 'America/Vancouver', $ranLate, '2026-11-01 16:00:35', '2026-11-01 17:00', '2026-11-01 16:45', '2026-11-01 16:45', ['11-01 16:45', '11-02 15:00', '11-02 16:00']],
            'regression: twice a day, its updated_at a minute after that run: still that run\'s' => ['0 8,9 * * *', 'America/Vancouver', $ranLate, '2026-11-01 16:01:30', '2026-11-01 17:00', '2026-11-01 16:45', '2026-11-01 16:45', ['11-01 16:45', '11-02 15:00', '11-02 16:00']],
            'twice a day, changed a minute and a second after that run: it runs from the change on' => ['0 8,9 * * *', 'America/Vancouver', $ranLate, '2026-11-01 16:01:31', '2026-11-01 17:00', '2026-11-01 16:45', '2026-11-02 15:00', ['11-02 15:00', '11-02 16:00']],
            'twice a day, a next run that no rules have, stored at that run: the first run after the update' => ['0 8,9 * * *', 'America/Vancouver', $ranLate, $ranLate, '2026-11-01 17:30', '2026-11-01 16:45', '2026-11-02 15:00', ['11-02 15:00', '11-02 16:00']],
            'regression: twice a day, the first of the next day' => ['0 8,9 * * *', 'America/Vancouver', '2026-11-01 17:00:30', '2026-11-01 17:00:30', '2026-11-02 16:00', '2026-11-02 15:30', '2026-11-02 15:30', ['11-02 15:30', '11-02 16:00', '11-03 15:00', '11-03 16:00']],
            'regression: changed before the time: it comes at once' => ['0 9 * * *', 'America/Vancouver', $ran, '2026-11-01 12:00:00', '2026-11-01 17:00', '2026-11-01 16:30', '2026-11-01 16:30', ['11-01 16:30', '11-02 16:00']],
            'changed after the time: it runs from the change on, as after any change' => ['0 9 * * *', 'America/Vancouver', $ran, '2026-11-01 16:20:00', '2026-11-01 17:00', '2026-11-01 16:30', '2026-11-02 16:00', ['11-02 16:00']],
            'regression: never ran, created before the time: it comes at once' => ['0 9 * * *', 'America/Vancouver', null, '2026-11-01 12:00:00', '2026-11-01 17:00', '2026-11-01 16:30', '2026-11-01 16:30', ['11-01 16:30', '11-02 16:00']],
            'regression: twice a day, its updated_at older than its last run: the run counts' => ['0 8,9 * * *', 'America/Vancouver', $ran, '2026-10-01 12:00:00', '2026-11-01 16:00', '2026-11-01 15:30', '2026-11-01 15:30', ['11-01 15:30', '11-01 16:00', '11-02 15:00', '11-02 16:00']],
            // Its next run written without updated_at, as by m000089 of an earlier update: the run of 2026-10-26 passed long before.
            'its last run and change a week before, its next run one of the new rules: no run owed' => ['0 9 * * *', 'America/Vancouver', '2026-10-25 16:00:30', '2026-10-25 16:00:30', '2026-11-02 16:00', '2026-11-01 16:30', '2026-11-02 16:00', ['11-02 16:00']],
            'every half hour: as it was, no run more' => ['*/30 * * * *', 'America/Vancouver', '2026-11-02 12:30:30', '2026-11-02 12:30:30', '2026-11-02 13:00', '2026-11-02 12:45', '2026-11-02 13:00', ['11-02 13:00', '11-02 13:30', '11-02 14:00']],
            'every hour: as it was, no run more' => ['0 * * * *', 'America/Vancouver', $ranLate, $ranLate, '2026-11-01 17:00', '2026-11-01 16:45', '2026-11-01 17:00', ['11-01 17:00', '11-01 18:00', '11-01 19:00']],
        ];
    }

    /**
     * @dataProvider lateZoneProvider
     * @param list<string> $launches
     */
    public function testRegressionWhereTheOldDataRanThemLateNoRunIsLost(
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
     * Schedules in Africa/Casablanca, whose time zone data 2026.1 ran them an
     * hour early, as 2.13.1 stored them, in the shape of lateZoneProvider().
     * A next run there never moves earlier: the runs of the new rules before
     * it are those 2.13.1 launched an hour early. One that is no run of the
     * new rules moves an hour later, to its time, also when it is due, unless
     * that time has passed too: the schedule runs once, at its time.
     *
     * @return array<string, array{0: string, 1: string, 2: string|null, 3: string, 4: string, 5: string, 6: string, 7: list<string>}>
     */
    public static function earlyZoneProvider(): array
    {
        // The run 2.13.1 launched on 2026-10-28 for 9:00 at UTC+1, which is 8:00 at UTC+0.
        $ran = '2026-10-28 08:00:30';
        $daily = ['0 9 * * *', 'Africa/Casablanca', $ran, $ran, '2026-10-29 08:00'];
        $dueEarly = ['0 9 * * *', 'Africa/Casablanca', '2026-10-27 08:00:30', '2026-10-27 08:00:30', '2026-10-28 08:00'];

        return [
            'regression: in the hour after the run it launched early, the next is that of the next day' => array_merge($daily, ['2026-10-28 08:30', '2026-10-29 09:00', ['10-29 09:00', '10-30 09:00']]),
            'after the time of that run: the next is that of the next day' => array_merge($daily, ['2026-10-28 09:30', '2026-10-29 09:00', ['10-29 09:00', '10-30 09:00']]),
            'regression: a zone name in other letters' => ['0 9 * * *', 'africa/casablanca', $ran, $ran, '2026-10-29 08:00', '2026-10-28 08:30', '2026-10-29 09:00', ['10-29 09:00', '10-30 09:00']],
            'regression: due an hour early, it waits for its time' => array_merge($dueEarly, ['2026-10-28 08:20', '2026-10-28 09:00', ['10-28 09:00', '10-29 09:00']]),
            'due after its time too: it stays and comes at once' => array_merge($dueEarly, ['2026-10-28 09:10', '2026-10-28 08:00', ['10-28 09:10', '10-29 09:00']]),
            'every hour: as it was, no run less' => ['0 * * * *', 'Africa/Casablanca', $ran, $ran, '2026-10-28 09:00', '2026-10-28 08:30', '2026-10-28 09:00', ['10-28 09:00', '10-28 10:00', '10-28 11:00']],
            'regression: a next run that no rules have: it stays, and the run of its day follows, as without the update' => ['0 9 * * *', 'Africa/Casablanca', $ran, $ran, '2026-10-29 08:30', '2026-10-28 08:30', '2026-10-29 08:30', ['10-29 08:30', '10-29 09:00', '10-30 09:00']],
            'twice a day, an hour apart: as it was' => ['0 8,9 * * *', 'Africa/Casablanca', '2026-10-28 07:00:30', '2026-10-28 07:00:30', '2026-10-28 08:00', '2026-10-28 07:30', '2026-10-28 08:00', ['10-28 08:00', '10-28 09:00', '10-29 08:00', '10-29 09:00']],
            'regression: the day before 2026.1 is at UTC+0 for Ramadan, no run comes earlier' => ['0 9 * * *', 'Africa/Casablanca', '2027-02-06 08:00:30', '2027-02-06 08:00:30', '2027-02-07 09:00', '2027-02-06 08:30', '2027-02-07 09:00', ['02-07 09:00', '02-08 09:00']],
            'the last day 2026.1 is at UTC+0 for Ramadan: the next moves to its time' => ['0 9 * * *', 'Africa/Casablanca', '2027-03-13 09:00:30', '2027-03-13 09:00:30', '2027-03-14 08:00', '2027-03-13 10:00', '2027-03-14 09:00', ['03-14 09:00', '03-15 09:00']],
        ];
    }

    /**
     * @dataProvider earlyZoneProvider
     * @param list<string> $launches
     */
    public function testRegressionWhereTheOldDataRanThemEarlyARunComesOnceAtItsTime(
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
     * Schedules in zones whose rules did not change, as 2.13.1 stored them,
     * in the shape of lateZoneProvider(): their next runs stay, whatever the
     * moment of the update, as do their launches. America/Los_Angeles turns
     * its clocks back on 2026-11-01 by both rules.
     *
     * @return array<string, array{0: string, 1: string, 2: string|null, 3: string, 4: string, 5: string, 6: string, 7: list<string>}>
     */
    public static function otherZoneProvider(): array
    {
        $utc = ['0 9 * * *', 'UTC', '2026-10-31 09:00:30', '2026-10-31 09:00:30', '2026-11-01 09:00'];
        $losAngeles = ['0 9 * * *', 'America/Los_Angeles', '2026-10-31 16:00:30', '2026-10-31 16:00:30', '2026-11-01 17:00'];

        return [
            'UTC, before the time' => array_merge($utc, ['2026-11-01 08:30', '2026-11-01 09:00', ['11-01 09:00', '11-02 09:00']]),
            'UTC, due' => array_merge($utc, ['2026-11-01 09:30', '2026-11-01 09:00', ['11-01 09:30', '11-02 09:00']]),
            'Europe/Berlin' => ['0 9 * * *', 'Europe/Berlin', '2026-10-31 08:00:30', '2026-10-31 08:00:30', '2026-11-01 08:00', '2026-11-01 07:30', '2026-11-01 08:00', ['11-01 08:00', '11-02 08:00']],
            'Europe/Berlin, twice a day, after the first' => ['0 8,9 * * *', 'Europe/Berlin', '2026-11-01 07:00:30', '2026-11-01 07:00:30', '2026-11-01 08:00', '2026-11-01 07:30', '2026-11-01 08:00', ['11-01 08:00', '11-02 07:00', '11-02 08:00']],
            'America/Los_Angeles, an hour after the time in Vancouver' => array_merge($losAngeles, ['2026-11-01 16:30', '2026-11-01 17:00', ['11-01 17:00', '11-02 17:00']]),
        ];
    }

    /**
     * @dataProvider otherZoneProvider
     * @param list<string> $launches
     */
    public function testTheNextRunsInOtherZonesStay(
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
     * An update from 2.12.2 or older, which ran on PHP 8.2 with time zone
     * data of the same rules as 2026.1 for these zones, runs m000089 first,
     * at the same moment: the moment of the update, its next run as m000089
     * stores it, as this migration stores it, and the launches that follow.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: list<string>}>
     */
    public static function updateFrom2122Provider(): array
    {
        return [
            'regression: in the hour after the time, it comes at once' => ['2026-11-01 16:30', '2026-11-02 16:00', '2026-11-01 16:30', ['11-01 16:30', '11-02 16:00']],
            'before the time: at its time' => ['2026-11-01 15:30', '2026-11-01 16:00', '2026-11-01 16:00', ['11-01 16:00', '11-02 16:00']],
        ];
    }

    /**
     * Regression: m000089 stores the first run after the update, past the
     * run that passed in the hour before it, so the next run that 2.12.2
     * stored an hour late is gone when this migration runs; it finds that
     * run from the schedule's last run.
     *
     * @dataProvider updateFrom2122Provider
     * @param list<string> $launches
     */
    public function testRegressionAfterAnUpdateFrom2122TheRunThatPassedComesToo(
        string $now,
        string $afterNextRuns,
        string $next,
        array $launches
    ): void {
        $at = self::at($now);
        $this->startWithoutLeftovers($at);
        $schedule = $this->storedSchedule('0 9 * * *', 'America/Vancouver', '2026-10-31 16:00:30', '2026-10-31 16:00:30', '2026-11-01 17:00');

        (new NextRunsMigration(['db' => \Yii::$app->db, 'compact' => true]))->moveNextRuns($at);
        $this->assertSame($afterNextRuns, $this->nextRunAt($schedule), 'm000089 stores the first run after the update');
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
        $at = self::at('2026-11-01 16:30');
        $this->startWithoutLeftovers($at);
        // Disabled with its edit form on 2026-10-31, which computed the next run.
        $schedule = $this->storedSchedule('0 9 * * *', 'America/Vancouver', '2026-10-31 16:00:30', '2026-10-31 18:00:00', '2026-11-01 17:00', false);

        $this->migrate($at);

        $this->assertSame('2026-11-02 16:00', $this->nextRunAt($schedule));
        $before = time();
        $disabled = Schedule::findOne($schedule->id);
        $this->assertInstanceOf(Schedule::class, $disabled);
        $this->assertNull((new ScheduleEnablement())->toggle($disabled, (int)$schedule->created_by));
        $next = $this->nextRunOf($schedule);
        $this->assertNotNull($next);
        $this->assertGreaterThan($before, $next, 'enabled, it is not due');
        $this->assertContains($next, [Schedule::nextRunOf('0 9 * * *', 'America/Vancouver', $before), Schedule::nextRunOf('0 9 * * *', 'America/Vancouver')]);
    }

    /**
     * A due schedule keeps its next run, which the scheduler launches, and
     * so do one without a next run and one whose next run cannot be
     * computed.
     */
    public function testANextRunThatIsDueOrCannotBeComputedStays(): void
    {
        $this->startWithoutLeftovers(self::NOW);
        $template = $this->template();
        $zone = 'America/Vancouver';
        $old = (int)strtotime('2026-11-01 09:00 -08:00');
        $early = (int)strtotime('2026-10-31 09:00 +01:00');
        $kept = [
            'due, an hour early by 2026.1' => $this->schedule($template, '0 9 * * *', 'Africa/Casablanca', $early, self::SAVED),
            'due at the moment' => $this->schedule($template, '0 9 * * *', $zone, self::NOW, self::SAVED),
            'without a next run' => $this->schedule($template, '0 9 * * *', $zone, null, self::SAVED),
            'an expression the library cannot read' => $this->schedule($template, 'not a cron', $zone, $old, self::SAVED),
            'an expression the library cannot be handed' => $this->schedule($template, '0-30/-5 * * * *', $zone, $old, self::SAVED),
            'an unknown timezone' => $this->schedule($template, '0 9 * * *', 'Mars/Olympus', $old, self::SAVED),
        ];
        $before = array_map(fn (Schedule $schedule): ?int => $this->nextRunOf($schedule), $kept);

        $moved = $this->migration()->moveNextRuns(self::NOW);

        $this->assertSame(['moved' => 0, 'owed' => 0], $moved);
        $this->assertSame($before, array_map(fn (Schedule $schedule): ?int => $this->nextRunOf($schedule), $kept));
    }

    /**
     * Outside the zones whose rules changed 2.13.1 stored no run an hour
     * late, so no schedule there owes a run: a next run an hour off, as it
     * looks in Vancouver, moves to the first run after the update, as the
     * update from 2.12.2 moved it.
     */
    public function testOutsideTheChangedZonesNoRunIsOwed(): void
    {
        $this->assertUpdate('0 9 * * *', 'UTC', '2026-10-31 09:00:30', '2026-10-31 09:00:30', '2026-11-01 10:00', '2026-11-01 09:30', '2026-11-02 09:00', ['11-02 09:00']);
    }

    /**
     * The migration writes no schedule whose next run stays: it builds an
     * update only for a next run that moves.
     */
    public function testANextRunThatStaysIsNotWritten(): void
    {
        $at = self::at('2026-11-02 12:45');
        $this->startWithoutLeftovers($at);
        $staying = [
            $this->storedSchedule('*/30 * * * *', 'America/Vancouver', '2026-11-02 12:30:30', '2026-11-02 12:30:30', '2026-11-02 13:00'),
            // Due: the update stopped the scheduler before it launched the run of 12:30.
            $this->storedSchedule('*/15 * * * *', 'America/Vancouver', '2026-11-02 12:15:30', '2026-11-02 12:15:30', '2026-11-02 12:30'),
            $this->storedSchedule('0 * * * *', 'Africa/Casablanca', '2026-11-02 12:00:30', '2026-11-02 12:00:30', '2026-11-02 13:00'),
            $this->storedSchedule('0 9 * * *', 'UTC', '2026-11-02 09:00:30', '2026-11-02 09:00:30', '2026-11-03 09:00'),
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
     * A schedule without a timezone runs in UTC, as
     * Schedule::computeNextRunAt() and the scheduler read it.
     */
    public function testAScheduleWithoutATimezoneRunsInUtc(): void
    {
        $this->startWithoutLeftovers(self::NOW);
        $schedule = $this->schedule($this->template(), '0 9 * * *', '', (int)strtotime('2026-11-01 10:00 UTC'), self::SAVED);

        $this->assertSame(['moved' => 1, 'owed' => 0], $this->migration()->moveNextRuns(self::NOW));

        $this->assertSame((int)strtotime('2026-11-01 09:00 UTC'), $this->nextRunOf($schedule));
    }

    /**
     * A schedule whose next run changed between the migration's select and
     * its update, as when the scheduler launched it or a user saved it,
     * keeps the next run it got: the update applies only to the next run
     * the migration read.
     */
    public function testANextRunThatChangedMeanwhileIsUntouched(): void
    {
        $this->startWithoutLeftovers(self::NOW);
        $schedule = $this->vancouverScheduleAsPhp83StoredIt();
        $meanwhile = self::NOW + 7200;

        $moved = $this->migration($this->connectionThatChanges($schedule, $meanwhile))->moveNextRuns(self::NOW);

        $this->assertSame(['moved' => 0, 'owed' => 0], $moved);
        $this->assertSame($meanwhile, $this->nextRunOf($schedule));
    }

    public function testRunningItAgainChangesNothing(): void
    {
        $this->startWithoutLeftovers(self::NOW);
        $schedule = $this->vancouverScheduleAsPhp83StoredIt();
        $this->assertSame(['moved' => 1, 'owed' => 0], $this->migration()->moveNextRuns(self::NOW));
        $once = Schedule::findOne($schedule->id)?->getAttributes();

        $moved = $this->migration()->moveNextRuns(self::NOW);

        $this->assertSame(['moved' => 0, 'owed' => 0], $moved);
        $this->assertSame($once, Schedule::findOne($schedule->id)?->getAttributes());
    }

    /**
     * The schedules a first run of the migration changed, each at its moment
     * of the update: running it again at that moment, or ten minutes later
     * while the scheduler has not run, changes nothing. Cron, zone, last run,
     * the next run 2.13.1 stored, the moment of the update, and how many next
     * runs the first run moves and how many of them to that moment.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: array{moved: int, owed: int}}>
     */
    public static function changedOnceProvider(): array
    {
        return [
            'a run that passed in the hour before the update' => ['0 9 * * *', 'America/Vancouver', '2026-10-31 16:00:30', '2026-11-01 17:00', '2026-11-01 16:30', ['moved' => 1, 'owed' => 1]],
            'twice a day, the second after the first ran an hour late' => ['0 8,9 * * *', 'America/Vancouver', '2026-11-01 16:00:30', '2026-11-01 17:00', '2026-11-01 16:45', ['moved' => 1, 'owed' => 1]],
            'a next run an hour late, moved to its time' => ['0 9 * * *', 'America/Vancouver', '2026-10-31 16:00:30', '2026-11-01 17:00', '2026-11-01 15:30', ['moved' => 1, 'owed' => 0]],
            'a next run an hour early, moved to its time' => ['0 9 * * *', 'Africa/Casablanca', '2026-10-28 08:00:30', '2026-10-29 08:00', '2026-10-28 08:30', ['moved' => 1, 'owed' => 0]],
            'a due run an hour early, moved to its time' => ['0 9 * * *', 'Africa/Casablanca', '2026-10-27 08:00:30', '2026-10-28 08:00', '2026-10-28 08:20', ['moved' => 1, 'owed' => 0]],
        ];
    }

    /**
     * @dataProvider changedOnceProvider
     * @param array{moved: int, owed: int} $first
     */
    public function testRunningItAgainLaterChangesNothing(
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
     * Up computes from the clock and prints how many next runs moved, and
     * how many of them to the moment of the update, with the version of the
     * time zone data.
     */
    public function testUpPrintsHowManyNextRunsMoved(): void
    {
        $this->up();
        $next = Schedule::nextRunOf('0 9 * * *', 'America/Vancouver');
        $this->assertNotNull($next);
        $template = $this->template();
        $schedule = $this->schedule($template, '0 9 * * *', 'America/Vancouver', $next + 3600);
        // Ran a minute and a half ago, with its next run an hour ahead: the run of the minute after it is owed.
        $ran = time() - 90;
        $owed = $this->schedule($template, '* * * * *', 'America/Vancouver', $ran + 3600, $ran, $ran);

        $output = $this->up();

        $this->assertSame($next, $this->nextRunOf($schedule));
        $this->assertLessThanOrEqual(time(), $this->nextRunOf($owed), 'the owed run is due');
        $this->assertSame(self::printed(2, 1), $output);
        $this->assertSame(self::printed(0, 0), $this->up(), 'nothing moves the second time');
    }

    /**
     * Down succeeds without touching data: a release on PHP 8.3 launches the
     * next runs this migration stored and computes the ones after them
     * itself.
     */
    public function testDownSucceedsAndRestoresNothing(): void
    {
        $this->startWithoutLeftovers(self::NOW);
        $schedule = $this->vancouverScheduleAsPhp83StoredIt();
        $this->migration()->moveNextRuns(self::NOW);
        $moved = $this->nextRunOf($schedule);

        $this->assertTrue($this->migration()->safeDown());

        $this->assertSame($moved, $this->nextRunOf($schedule));
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * Stores a schedule as 2.13.1 left it, runs the migration at $now and
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
     * The launches of the scheduler from $from to $until, UTC 'Y-m-d H:i' or,
     * for $until, 'm-d H:i' of the same year, for $schedule as it is stored:
     * it ticks every minute, launches the schedule when its next run is due
     * and stores the first run after that tick, as
     * ScheduleService::claimSchedule() does.
     *
     * @return list<string> the ticks that launched it, as 'm-d H:i' in UTC
     */
    private function launches(Schedule $schedule, string $from, string $until): array
    {
        $tick = self::at($from);
        $end = self::at(substr($from, 0, 5) . $until);
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
     * A schedule as 2.13.1 stored it: its last run ($ran, null for none),
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

    private function migration(?Connection $db = null): TimeZoneDataMigration
    {
        return new TimeZoneDataMigration(['db' => $db ?? \Yii::$app->db, 'compact' => true]);
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
        return '    > ' . sprintf(TimeZoneDataMigration::MOVED, timezone_version_get(), $moved, $owed) . "\n";
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
        $owner = $this->createUser('tz_next_runs');

        return $this->createJobTemplate(
            (int)$this->createProject((int)$owner->id)->id,
            (int)$this->createInventory((int)$owner->id)->id,
            (int)$this->createRunnerGroup((int)$owner->id)->id,
            (int)$owner->id
        );
    }

    /**
     * A schedule at 9:00 in America/Vancouver with the next run that PHP 8.3
     * stored for it at NOW: 2026-11-01 09:00 at UTC-8, an hour late.
     */
    private function vancouverScheduleAsPhp83StoredIt(): Schedule
    {
        return $this->schedule(
            $this->template(),
            '0 9 * * *',
            'America/Vancouver',
            (int)strtotime('2026-11-01 09:00 -08:00'),
            self::SAVED
        );
    }

    /**
     * A schedule as PHP 8.3 stored it, with $nextRunAt as its next run, and,
     * when given, $changedAt as its updated_at and $ranAt as its last run.
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
        $schedule->name = 'tz-next-runs-' . uniqid('', true);
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
