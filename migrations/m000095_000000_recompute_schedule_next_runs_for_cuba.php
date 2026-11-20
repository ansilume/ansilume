<?php

declare(strict_types=1);

use app\models\Schedule;
use yii\db\Connection;
use yii\db\Migration;
use yii\db\Query;

/**
 * Computes anew the next runs of the schedules in America/Havana and its
 * alias Cuba (ZONES) with the time zone data of the PHP this release runs
 * on, as m000090_000000_recompute_schedule_next_runs_for_time_zone_data
 * computes them for the zones it names LATE_ZONES.
 *
 * 2.15.1 runs on PHP 8.5.12 with the time zone data 2026.5 (IANA's 2026e),
 * which turns Cuba's clocks back to UTC-5 on 2026-11-01 and forward to
 * UTC-4 again on 2027-03-14, as the data of 2.13.1 (2026.1) and the
 * releases before it do. This release runs on PHP 8.5.13 of 2026-11-19,
 * with 2026.6 (IANA's 2026f, TIME_ZONE_DATA): Cuba stays at UTC-4 from
 * 2026-03-08 until 2027-11-07, and turns its clocks back to UTC-5 then as
 * before. Between 2020 and 2040 these are the only rules that differ
 * between the two. From 2026-11-01 to 2027-03-14 2.15.1 runs the schedules
 * there an hour late: 9:00 at 14:00 UTC, where it is 13:00 UTC.
 *
 * A next run that 2.15.1 stored for a time in that winter is an hour late
 * until it has run. A next run that is not yet due moves to the first run
 * after the update, unless the schedule owes a run (owesRun()): in the hour
 * between a run of the new rules and the time 2.15.1 stored for it, an
 * hour later, that first run would come only the next day or week. An
 * enabled schedule owes the first run after its last run and its last
 * change, when that run passed in the hour before the update; and, when
 * its last run wrote it last, the next run 2.15.1 stored then, when that
 * run's time by the new rules has passed: as 9:00 of "0 8,9 * * *" after
 * 2.15.1 launched 8:00 an hour late, at the new time of 9:00. Its next run
 * becomes the moment of the update, so the scheduler launches it at once
 * and computes the runs after it. No run before a change of the schedule
 * (a save, enabling it) is owed, as the new code runs a changed schedule
 * from the change on. A disabled schedule launches nothing and owes no
 * run; enabling it computes its next run from that moment on. A schedule
 * whose next run is due keeps it: the scheduler launches it at once, once
 * for the runs that passed, as for any runs missed. 2026.5 runs no
 * schedule there earlier than 2026.6, so 2.15.1 launched no run early.
 *
 * An update from 2027-03-14 06:00 UTC on (2:00 in Havana) moves nothing:
 * every next run 2.15.1 stored that is not yet due then is a run of both
 * data. The latest run 2026.5 puts an hour late is 0:59 of that night,
 * which it skips and runs at 05:59 UTC, where 2026.6 has 04:59. An update
 * from 2.13.1 or older runs m000090 on this PHP first, which stores the
 * first run after the update already: the run that passed in the hour
 * before the update is still owed then, after the schedule's last run, but
 * not the run 2.13.1 stored after a run it launched an hour late, which
 * m000090 replaced.
 *
 * With time zone data older than TIME_ZONE_DATA, as of a PHP older than
 * 8.5.13, the migration moves nothing and says so (OLD_TIME_ZONE_DATA):
 * that PHP computes the runs in Cuba as 2.15.1 did. The migration counts
 * as applied all the same, so once the PHP has newer data each next run
 * stored for a time in that winter is an hour late until it has run.
 *
 * Schedules in other zones are not touched. A schedule keeps its next run
 * as well when it cannot be computed or changed meanwhile. Running the
 * migration again changes nothing. It prints counts only.
 */
class m000095_000000_recompute_schedule_next_runs_for_cuba extends Migration
{
    /**
     * sprintf(): the version of the time zone data, how many next runs
     * moved, and how many of them to the moment of the update, for a run
     * the schedule owes (owesRun()).
     */
    public const MOVED = 'Computed anew the next runs of the schedules in America/Havana and Cuba that are not yet due,'
        . ' with time zone data %s: %d moved, %d of them to now for a run that passed in the past hour.';

    /**
     * sprintf(): the version of the time zone data of this PHP, then
     * TIME_ZONE_DATA. Printed instead of MOVED when this PHP's data is
     * older than TIME_ZONE_DATA.
     */
    public const OLD_TIME_ZONE_DATA = 'Moved no next run: the time zone data %s of this PHP turns the clocks of'
        . ' America/Havana and Cuba back on 2026-11-01, which %s and later do not.';

    /**
     * The zones whose schedules 2.15.1 runs an hour late from 2026-11-01 to
     * 2027-03-14. Compared with the name PHP reads from a schedule's
     * timezone, without case (isInZones()).
     */
    public const ZONES = ['America/Havana', 'Cuba'];

    /** The first time zone data of PHP that keeps Cuba at UTC-4 from 2026-11-01. */
    public const TIME_ZONE_DATA = '2026.6';

    /** How far 2.15.1 has the runs of these zones off. */
    private const HOUR = 3600;

    /**
     * How long after a schedule's last_run_at its updated_at is still the
     * last run's own: the scheduler's claim (ScheduleService::claimSchedule())
     * sets both with a call of time() each, and the sealing of plain password
     * answers before the launch (ScheduleLaunchSecrets) sets updated_at again
     * right after. A later updated_at is a change: a save, enabling it.
     */
    private const RUN_WRITES = 60;

    public function safeUp(): void
    {
        echo '    > ' . $this->summaryAt(timezone_version_get(), time()) . "\n";
    }

    public function safeDown(): bool
    {
        // Nothing to restore: the schema is unchanged, and a release on
        // PHP 8.5.12 launches a schedule at the next run stored, a run by
        // the newer rules or the moment of the update, and computes the
        // ones after it itself.
        return true;
    }

    /**
     * What the migration did at $now with the time zone data $timeZoneData,
     * as safeUp() prints it: MOVED after moveNextRuns(), or, with data older
     * than TIME_ZONE_DATA, OLD_TIME_ZONE_DATA, without moving a next run.
     */
    public function summaryAt(string $timeZoneData, int $now): string
    {
        if (version_compare($timeZoneData, self::TIME_ZONE_DATA, '<')) {
            return sprintf(self::OLD_TIME_ZONE_DATA, $timeZoneData, self::TIME_ZONE_DATA);
        }
        ['moved' => $moved, 'owed' => $owed] = $this->moveNextRuns($now);

        return sprintf(self::MOVED, $timeZoneData, $moved, $owed);
    }

    /**
     * Store the next run of every schedule in ZONES whose next run is not
     * yet due at $now: one moment for all, so that a run the select saw as
     * not yet due is not moved past itself when a minute ends while the
     * migration runs.
     *
     * @return array{moved: int, owed: int} how many next runs moved, and how
     *     many of them to $now, for a run that passed in the hour before it
     */
    public function moveNextRuns(int $now): array
    {
        /** @var Connection $db as init() resolved it */
        $db = $this->db;
        $rows = (new Query())
            ->select(['id', 'cron_expression', 'timezone', 'enabled', 'last_run_at', 'next_run_at', 'updated_at'])
            ->from('{{%schedule}}')
            ->where(['>', 'next_run_at', $now])
            ->orderBy(['id' => SORT_ASC])
            ->all($db);
        $moved = 0;
        $owed = 0;
        foreach ($rows as $row) {
            $next = self::nextRunAt($row, $now);
            if ($next !== null && $next !== (int)$row['next_run_at'] && self::store($db, $row, $next)) {
                $moved++;
                $owed += $next === $now ? 1 : 0;
            }
        }

        return ['moved' => $moved, 'owed' => $owed];
    }

    /**
     * The next run to store for the schedule $row, whose next run is not
     * yet due at $now: $now when it owes a run (owesRun()), or the first run
     * after $now. Null keeps the stored one: in another zone, and when none
     * can be computed.
     *
     * @param array<string, mixed> $row
     */
    private static function nextRunAt(array $row, int $now): ?int
    {
        $expression = (string)$row['cron_expression'];
        $zone = (string)$row['timezone'];
        if (!self::isInZones($zone) || !Schedule::isParsableCron($expression)) {
            return null;
        }
        $owes = (bool)$row['enabled'] && self::owesRun($row, $expression, $zone, $now);

        return $owes ? $now : Schedule::nextRunOf($expression, $zone, $now);
    }

    /**
     * Whether the enabled schedule $row, whose next run is not yet due at
     * $now, owes a run: the first run of the new rules after its last run
     * and its last change passed in the hour before $now; or its last run
     * wrote it last, and the next run 2.15.1 stored then is no run of the
     * new rules, an hour after one whose time has passed. A run that passed
     * earlier than an hour before $now is owed by no schedule whose next run
     * 2.15.1 stored: that next run would be due.
     *
     * @param array<string, mixed> $row
     */
    private static function owesRun(array $row, string $expression, string $zone, int $now): bool
    {
        $ran = $row['last_run_at'] === null ? null : (int)$row['last_run_at'];
        $changed = (int)$row['updated_at'];
        $passed = Schedule::nextRunOf($expression, $zone, max((int)$ran, $changed));
        if ($passed !== null && $passed <= $now && $passed > $now - self::HOUR) {
            return true;
        }
        $stored = (int)$row['next_run_at'];
        $ranLast = $ran !== null && $changed - $ran <= self::RUN_WRITES;

        return $ranLast && $stored - self::HOUR <= $now && self::isHourLate($expression, $zone, $stored);
    }

    /**
     * Whether $stored is no run of $expression in $zone by the new rules,
     * while the time an hour before it is one.
     */
    private static function isHourLate(string $expression, string $zone, int $stored): bool
    {
        return !self::isRun($expression, $zone, $stored) && self::isRun($expression, $zone, $stored - self::HOUR);
    }

    private static function isRun(string $expression, string $zone, int $at): bool
    {
        return Schedule::firstRunFrom($expression, $zone, $at) === $at;
    }

    /**
     * Whether PHP reads the timezone $zone of a schedule as one of ZONES:
     * by the name it resolves, which leaves out blanks and a "(" before it,
     * as in " America/Havana" or "(Cuba)", which Schedule::validateTimezone()
     * takes and CronRuns computes in Cuba too; and without case, as that
     * name keeps the letters as written. An empty zone, which the scheduler
     * reads as UTC, and one PHP cannot read are none of them.
     *
     * Regression (review of this migration): it compared the stored text, so
     * the next runs of such a schedule stayed an hour late.
     */
    private static function isInZones(string $zone): bool
    {
        try {
            $name = (new \DateTimeZone($zone))->getName();
        } catch (\Exception | \ValueError $e) {
            return false;
        }

        return in_array(strtolower($name), array_map('strtolower', self::ZONES), true);
    }

    /**
     * Store $next on $db as the next run of the schedule $row, unless its
     * next run changed since the select read it, as when the scheduler of
     * the release before launched it or a user saved it meanwhile.
     *
     * @param array<string, mixed> $row
     * @return bool whether it was stored
     */
    private static function store(Connection $db, array $row, int $next): bool
    {
        return $db->createCommand()->update(
            '{{%schedule}}',
            ['next_run_at' => $next],
            ['id' => $row['id'], 'next_run_at' => $row['next_run_at']]
        )->execute() > 0;
    }
}
