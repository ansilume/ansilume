<?php

declare(strict_types=1);

namespace app\components;

use Cron\CronExpression;

/**
 * When a schedule's cron expression runs in its timezone, also on the
 * nights the clocks change. The cron library is asked only which wall-clock
 * times an expression holds, and only in UTC, where the clocks never
 * change. In the schedule's own timezone the library runs a time that comes
 * twice, as 2:00 to 2:59 does in Central Europe on the night the clocks go
 * back, at both, and a schedule at the hour after it at the second 2:00 as
 * well, an hour early; where the clocks change otherwise than by an hour or
 * by its rules for the change (Africa/Casablanca, Antarctica/Troll,
 * Australia/Lord_Howe) it also skips runs, runs at other times, finds no run
 * at all, so that the schedule shows no next run, or finds an earlier one
 * again (CronRunsTest pins each). Here:
 * - a time that comes twice runs once, the first time, unless the minute or
 *   the hour field starts with an asterisk, as cron decides it: a schedule
 *   that runs every few minutes or every hour runs at each time that comes,
 *   the repeated ones too, but only at the times it holds;
 * - a time the clocks skip, as 2:00 to 2:59 on the night they go forward,
 *   runs when it would have come by the clock before the change, 2:30 at
 *   3:30, as the library ran it for a change by an hour. cron runs the
 *   skipped times of a schedule without an asterisk in those fields right
 *   after the change, and those of one with an asterisk not at all.
 */
final class CronRuns
{
    /** A stretch of time in which the clock runs on as before. */
    private const PLAIN = 'plain';

    /** The stretch after the clock went back: each of its times came before. */
    private const AGAIN = 'again';

    /** The stretch after the clock went forward: the times it skipped fall in it. */
    private const SKIPPED = 'skipped';

    /** How far back a change of the clock is looked for: longer than any change lasts. */
    private const LOOK_BACK = 172800;

    /** How far ahead the changes of the clock are read at a time. */
    private const LOOK_AHEAD = 31622400;

    /**
     * The first run of $cron at or after $from, a Unix timestamp, in
     * $timezone.
     *
     * @throws \RuntimeException when the library finds no run, as for the 30th of February
     * @throws \TypeError when a day of the month and a day of the week are both set and neither finds one
     * @throws \Exception for an unknown timezone, and a \ValueError for one with a NUL byte
     */
    public static function first(CronExpression $cron, int $from, string $timezone): int
    {
        $zone = new \DateTimeZone($timezone);
        $at = $from;
        while (true) {
            $stretch = self::stretch($zone, $at);
            $run = self::runIn($cron, $at, $stretch);
            if ($run !== null && $run < $stretch['end']) {
                return $run;
            }
            $at = $stretch['end'];
        }
    }

    /**
     * The stretch of time from $at on in which $zone's clock runs alike: its
     * offset from UTC, when the stretch ends (at the next change of the
     * clock, or where the changes read end), its kind (PLAIN, AGAIN or
     * SKIPPED) and, for AGAIN and SKIPPED, by how many seconds the clock
     * changed before it.
     *
     * @return array{offset: int, end: int, kind: string, shift: int}
     */
    private static function stretch(\DateTimeZone $zone, int $at): array
    {
        $offset = $zone->getOffset(new \DateTimeImmutable('@' . $at));
        $end = $at + self::LOOK_AHEAD;
        $last = null;
        foreach (self::changes($zone, $at - self::LOOK_BACK, $end) as $change) {
            if ($change['at'] > $at) {
                $end = $change['at'];
                break;
            }
            $last = $change;
        }
        $shift = abs($last['shift'] ?? 0);
        if ($last === null || $at >= $last['at'] + $shift) {
            return ['offset' => $offset, 'end' => $end, 'kind' => self::PLAIN, 'shift' => 0];
        }

        return [
            'offset' => $offset,
            'end' => min($end, $last['at'] + $shift),
            'kind' => $last['shift'] < 0 ? self::AGAIN : self::SKIPPED,
            'shift' => $shift,
        ];
    }

    /**
     * The changes of $zone's clock from $begin to $end in ascending order:
     * when, and by how many seconds it went forward (back when negative).
     * getTransitions() also lists changes of the abbreviation alone, and
     * leaves out a change at $end or at $begin for some timezones and not
     * for others, so callers read beyond the time they ask about.
     *
     * @return list<array{at: int, shift: int}>
     */
    private static function changes(\DateTimeZone $zone, int $begin, int $end): array
    {
        $transitions = $zone->getTransitions($begin, $end) ?: [];
        $changes = [];
        for ($i = 1; $i < count($transitions); $i++) {
            $shift = $transitions[$i]['offset'] - $transitions[$i - 1]['offset'];
            if ($shift !== 0) {
                $changes[] = ['at' => $transitions[$i]['ts'], 'shift' => $shift];
            }
        }

        return $changes;
    }

    /**
     * The first time from $at on at which $cron runs by the rule of its
     * stretch, which may lie beyond the stretch; null when it runs at no time
     * of it.
     *
     * @param array{offset: int, end: int, kind: string, shift: int} $stretch
     */
    private static function runIn(CronExpression $cron, int $at, array $stretch): ?int
    {
        if ($stretch['kind'] === self::AGAIN && !self::runsAgain($cron)) {
            return null;
        }
        $offset = $stretch['offset'];
        $run = self::wallTime($cron, $at + $offset) - $offset;
        if ($stretch['kind'] === self::SKIPPED) {
            // A time the clock skipped comes when it would have come by the clock before the change.
            $before = $offset - $stretch['shift'];
            $run = min($run, self::wallTime($cron, $at + $before) - $before);
        }

        return $run;
    }

    /**
     * Whether a time that comes twice runs the second time too: when the
     * minute or the hour field starts with an asterisk, as cron decides it.
     */
    private static function runsAgain(CronExpression $cron): bool
    {
        foreach ([CronExpression::MINUTE, CronExpression::HOUR] as $position) {
            if (str_starts_with((string)$cron->getExpression($position), '*')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first wall-clock time at or after $wall, counted in seconds as in
     * UTC, that $cron holds: the library looks for it in UTC, as the first
     * after the minute before, as versions up to 2.12.2 asked it after each
     * run. Asked for the first at or after the minute itself, it runs some
     * lists that the cron rules refuse and those versions saved at other
     * times than before: one with * and 5 every minute, not at minutes 0 and
     * 5. A wall time between two minutes, as of an offset with seconds
     * (+01:00:30), counts from the minute after it, so that no run lies
     * before $wall.
     */
    private static function wallTime(CronExpression $cron, int $wall): int
    {
        $minute = intdiv($wall + 59, 60) * 60;

        return $cron->getNextRunDate(new \DateTimeImmutable('@' . ($minute - 60)), 0, false, 'UTC')->getTimestamp();
    }
}
