<?php

declare(strict_types=1);

use app\models\Schedule;
use yii\db\Connection;
use yii\db\Migration;
use yii\db\Query;

/**
 * Computes anew the next runs of the schedules with the time zone data of
 * the PHP this release runs on, as m000089_000000_recompute_schedule_next_runs
 * computes them (CronRuns, Schedule::nextRunOf()).
 *
 * PHP bundles its own time zone data, and a PHP line in security support
 * gets new data seldom: PHP 8.3, which 2.13.1 runs on, got 2026.1 with
 * 8.3.31 of 2026-05-07, and 8.3.32 to 8.3.36 kept it. This release runs on
 * PHP 8.5: 8.5.12 has 2026.5. Since 1970 the rules of 14 zones differ
 * between the two, 11 of them in the coming years; America/Bogota, and
 * Asia/Tehran with its alias Iran, differ only in 1992 and 1979.
 * - Africa/Casablanca and Africa/El_Aaiun (EARLY_ZONES) are at UTC+0 since
 *   2026-09-20, where 2026.1 has UTC+1 but for its Ramadan periods
 *   (2027-02-07 to 2027-03-14, and so on): outside them 2.13.1 runs their
 *   schedules an hour early.
 * - From 2026-11-01 America/Vancouver stays at UTC-7, America/Edmonton,
 *   America/Inuvik and America/Yellowknife at UTC-6, and America/Winnipeg
 *   and America/Rainy_River at UTC-5, as do the aliases Canada/Pacific,
 *   Canada/Mountain and Canada/Central (LATE_ZONES), where 2026.1 turns
 *   their clocks back an hour every November and forward again every
 *   March: 2.13.1 runs their schedules an hour late from early November to
 *   mid-March.
 *
 * A next run that 2.13.1 stored for a schedule in these zones is an hour
 * off until it has run. Outside EARLY_ZONES a next run that is not yet due
 * moves to the first run after the update, unless the schedule owes a run
 * (owesRun()): in the hour between a run of the new rules and the time
 * 2.13.1 stored for it, an hour later, that first run would come only the
 * next day or week. An enabled schedule in LATE_ZONES owes the first run
 * after its last run and its last change, when that run passed in the hour
 * before the update; and, when its last run wrote it last, the next run
 * 2.13.1 stored then, when that run's time by the new rules has passed: as
 * 9:00 of "0 8,9 * * *" after 2.13.1 launched 8:00 an hour late, at the
 * new time of 9:00. Its next run becomes the moment of the update, so the
 * scheduler launches it at once and computes the runs after it. No run
 * before a change of the schedule (a save, enabling it) is owed, as the new
 * code runs a changed schedule from the change on. A disabled schedule
 * launches nothing and owes no run; enabling it computes its next run from
 * that moment on. A schedule whose next run is due keeps it: the scheduler
 * launches it at once, once for the runs that passed, as for any runs
 * missed.
 *
 * In EARLY_ZONES the run 2.13.1 launched an hour early was the one of the
 * hour after it, so a next run there never moves earlier: the runs of the
 * new rules before it were launched already. A next run that is no run of
 * the new rules while the time an hour later is one moves to that time,
 * also when it is due since less than an hour, and every other next run
 * stays: the schedule runs once, at its time. One whose runs are an hour
 * apart, as "0 8,9 * * *", still runs 8:00 a second time, at 8:00, after
 * 2.13.1 launched it at 7:00, as it would without this migration.
 *
 * An update from 2.12.2 or older runs m000089 on this PHP first, which
 * stores the first run after the update already. The run that passed in
 * the hour before the update is still owed then, after the schedule's last
 * run, but not the run 2.12.2 stored after a run it launched an hour late,
 * which m000089 replaced; and in EARLY_ZONES a schedule whose run 2.12.2
 * launched an hour early in the hour before the update runs again at that
 * run's time, which m000089 stored.
 *
 * A schedule keeps its next run as well when it cannot be computed or
 * changed meanwhile. Running the migration again changes nothing. It prints
 * counts only.
 */
class m000090_000000_recompute_schedule_next_runs_for_time_zone_data extends Migration
{
    /**
     * sprintf(): the version of the time zone data, how many next runs
     * moved, and how many of them to the moment of the update, for a run
     * the schedule owes (owesRun()).
     */
    public const MOVED = 'Computed anew the next runs of the schedules that are not yet due,'
        . ' with time zone data %s: %d moved, %d of them to now for a run that passed in the past hour.';

    /**
     * The zones whose schedules 2.13.1 runs an hour late from early
     * November to mid-March. Compared without case, as PHP reads the names
     * of zones.
     */
    public const LATE_ZONES = [
        'America/Vancouver', 'Canada/Pacific', 'America/Edmonton', 'America/Inuvik', 'America/Yellowknife',
        'Canada/Mountain', 'America/Winnipeg', 'America/Rainy_River', 'Canada/Central',
    ];

    /** The zones whose schedules 2.13.1 runs an hour early. Compared without case. */
    public const EARLY_ZONES = ['Africa/Casablanca', 'Africa/El_Aaiun'];

    /** How far 2.13.1 has the runs of these zones off. */
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
        ['moved' => $moved, 'owed' => $owed] = $this->moveNextRuns(time());
        echo '    > ' . sprintf(self::MOVED, timezone_version_get(), $moved, $owed) . "\n";
    }

    /**
     * Store the next run of every schedule whose next run is not yet due at
     * $now, or due since less than an hour: one moment for all, so that a
     * run the select saw as not yet due is not moved past itself when a
     * minute ends while the migration runs.
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
            ->where(['>', 'next_run_at', $now - self::HOUR])
            ->orderBy(['id' => SORT_ASC])
            ->all($db);
        $moved = 0;
        $owed = 0;
        foreach ($rows as $row) {
            $next = $this->nextRunAt($row, $now);
            if ($next !== null && $next !== (int)$row['next_run_at'] && self::store($db, $row, $next)) {
                $moved++;
                $owed += $next === $now ? 1 : 0;
            }
        }

        return ['moved' => $moved, 'owed' => $owed];
    }

    public function safeDown(): bool
    {
        // Nothing to restore: the schema is unchanged, and a release on
        // PHP 8.3 launches a schedule at the next run stored, a run by the
        // newer rules or the moment of the update, and computes the ones
        // after it itself.
        return true;
    }

    /**
     * The next run to store for the schedule $row at $now: in EARLY_ZONES
     * the time of a run 2.13.1 put an hour early (timeOfEarlyRun()); else,
     * for a next run not yet due, $now when the schedule owes a run
     * (owesRun()), or the first run after $now. Null keeps the stored one,
     * also when none can be computed.
     *
     * @param array<string, mixed> $row
     */
    private function nextRunAt(array $row, int $now): ?int
    {
        $expression = (string)$row['cron_expression'];
        if (!Schedule::isParsableCron($expression)) {
            return null;
        }
        $zone = (string)$row['timezone'] ?: 'UTC';
        $stored = (int)$row['next_run_at'];
        if (self::isOneOf($zone, self::EARLY_ZONES)) {
            return self::timeOfEarlyRun($expression, $zone, $stored);
        }
        if ($stored <= $now) {
            return null;
        }
        $owes = (bool)$row['enabled']
            && self::isOneOf($zone, self::LATE_ZONES)
            && self::owesRun($row, $expression, $zone, $now);

        return $owes ? $now : Schedule::nextRunOf($expression, $zone, $now);
    }

    /**
     * Whether the enabled schedule $row in one of LATE_ZONES, whose next run
     * is not yet due at $now, owes a run: the first run of the new rules
     * after its last run and its last change passed in the hour before $now;
     * or its last run wrote it last, and the next run 2.13.1 stored then is
     * no run of the new rules, an hour after one whose time has passed. A
     * run that passed earlier than an hour before $now is owed by no
     * schedule whose next run 2.13.1 stored: that next run would be due.
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

        return $ranLast && $stored - self::HOUR <= $now && self::isHourOff($expression, $zone, $stored, -self::HOUR);
    }

    /**
     * In one of EARLY_ZONES: the time an hour after $stored when $stored is
     * no run of the new rules but that time is one, the run 2.13.1 put an
     * hour early; null keeps $stored. That time has not passed: the select
     * reads only next runs due since less than an hour.
     */
    private static function timeOfEarlyRun(string $expression, string $zone, int $stored): ?int
    {
        return self::isHourOff($expression, $zone, $stored, self::HOUR) ? $stored + self::HOUR : null;
    }

    /**
     * Whether $stored is no run of $expression in $zone by the new rules,
     * while $stored + $shift is one.
     */
    private static function isHourOff(string $expression, string $zone, int $stored, int $shift): bool
    {
        return !self::isRun($expression, $zone, $stored) && self::isRun($expression, $zone, $stored + $shift);
    }

    private static function isRun(string $expression, string $zone, int $at): bool
    {
        return Schedule::firstRunFrom($expression, $zone, $at) === $at;
    }

    /**
     * Whether $zone is one of $zones, compared without case.
     *
     * @param list<string> $zones
     */
    private static function isOneOf(string $zone, array $zones): bool
    {
        return in_array(strtolower($zone), array_map('strtolower', $zones), true);
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
