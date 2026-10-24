<?php

declare(strict_types=1);

namespace app\components;

/**
 * How the cron library walks a list of the minute or hour field to find the
 * next run (MinutesField::increment(), HoursField::increment()): it sorts the
 * minute items, as text once a range or step is among them, and takes the
 * hour items as written; from a value it goes to the one after the first two
 * neighbours that enclose it, else to the first. It looks for the next
 * minute from every minute, the run's own included, and for the next hour
 * only from an hour the list does not hold. An asterisk in a list matches
 * every value, so the library walks no hour list that holds one, but it walks
 * a minute list from it as the value 0. Where it does not reach the next
 * listed value, it skips some: 18,6 of the hour field ran at 18:00 only.
 */
final class CronListOrder
{
    /** An item of a minute or hour list: a value, a range or an asterisk, each with an optional step. */
    private const ITEM = '~^(?<from>\*|[0-9]{1,2})(?:-(?<to>[0-9]{1,2}))?(?:/(?<step>[0-9]+))?$~';

    /** The fewest values the same distance apart that suggestion() writes as a range. */
    private const RUN = 3;

    /** The smallest number shortest() writes with two digits where it starts an item. */
    private const PADDED_FROM = 1;

    /**
     * The list's values in ascending order when the library would skip some
     * of them, else null.
     *
     * @param list<string> $items the items as written, each one the rules accepted
     * @param bool $minutes whether they are minutes; else hours
     * @return list<int>|null
     */
    public static function skipped(array $items, bool $minutes, int $smallest, int $largest): ?array
    {
        if (in_array('*', $items, true)) {
            return $minutes ? range($smallest, $largest) : null;
        }
        if ($minutes) {
            sort($items);
        }
        $walked = [];
        foreach ($items as $item) {
            $walked = array_merge($walked, self::valuesOf($item, $smallest, $largest));
        }
        $listed = array_values(array_unique($walked));
        sort($listed);
        for ($value = $smallest; $value <= $largest; $value++) {
            $looked = $minutes || !in_array($value, $listed, true);
            if ($looked && self::libraryNext($walked, $value) !== self::next($listed, $value)) {
                return $listed;
            }
        }

        return null;
    }

    /**
     * $values as a list that the library walks in ascending order, in at most
     * $room characters, or null when it finds none that fits: "*" for every
     * value; the shortest list of ranges, steps and single numbers where it
     * is shorter than single numbers alone and the library walks it in order
     * (it sorts 5-10,45 as text, 45 first); single numbers; for minutes, the
     * shortest such list with the starts from 1 to 9 written with two digits,
     * which the library's sort keeps in ascending order (05-10,45).
     *
     * @param list<int> $values ascending
     * @param bool $minutes whether they are minutes; else hours
     */
    public static function suggestion(array $values, bool $minutes, int $smallest, int $largest, int $room): ?string
    {
        if ($values === range($smallest, $largest)) {
            return '*';
        }
        $single = implode(',', $values);
        $runs = self::shortest($values, false);
        $candidates = strlen($runs) < strlen($single) ? [$runs, $single] : [$single];
        if ($minutes) {
            $candidates[] = self::shortest($values, true);
        }
        foreach ($candidates as $candidate) {
            if (strlen($candidate) <= $room && self::skipped(explode(',', $candidate), $minutes, $smallest, $largest) === null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The value the library goes to from $value: the one after the first two
     * neighbours that enclose it, else the first.
     *
     * @param list<int> $walked
     */
    private static function libraryNext(array $walked, int $value): int
    {
        for ($i = 0; $i < count($walked) - 1; $i++) {
            if ($value >= $walked[$i] && $value < $walked[$i + 1]) {
                return $walked[$i + 1];
            }
        }

        return $walked[0];
    }

    /**
     * The smallest listed value after $value, else the smallest.
     *
     * @param list<int> $listed ascending
     */
    private static function next(array $listed, int $value): int
    {
        foreach ($listed as $candidate) {
            if ($candidate > $value) {
                return $candidate;
            }
        }

        return $listed[0];
    }

    /**
     * The values of an item, whose step may be larger than its range (0-30/45
     * is 0). Not range(): since PHP 8.3 it throws a ValueError for a step
     * larger than the range.
     *
     * @return list<int>
     */
    private static function valuesOf(string $item, int $smallest, int $largest): array
    {
        preg_match(self::ITEM, $item, $match);
        $asterisk = ($match['from'] ?? '*') === '*';
        $from = $asterisk ? $smallest : (int)($match['from'] ?? $smallest);
        $to = ($match['to'] ?? '') === '' ? ($asterisk ? $largest : $from) : (int)$match['to'];
        $step = max(1, (int)($match['step'] ?? 1));
        $values = [];
        for ($value = $from; $value <= $to; $value += $step) {
            $values[] = $value;
        }

        return $values;
    }

    /**
     * The shortest list of $values in ascending order, each item a single
     * number or a run of at least RUN values the same distance apart (a-b, or
     * a-b/n), of two equally short ones that with fewer runs; with $padded,
     * an item that starts from PADDED_FROM to 9 starts with two digits.
     *
     * @param list<int> $values ascending
     */
    private static function shortest(array $values, bool $padded): string
    {
        // From each index on: [length, runs, items], built from the end.
        $best = [count($values) => [0, 0, []]];
        for ($start = count($values) - 1; $start >= 0; $start--) {
            $best[$start] = self::bestFrom($values, $start, $best, $padded);
        }

        return implode(',', $best[0][2]);
    }

    /**
     * The shortest list of $values from $start on: its first item a single
     * number or a run, the rest as $best has it.
     *
     * @param list<int> $values ascending
     * @param array<int, array{0: int, 1: int, 2: list<string>}> $best the shortest lists from each later index
     * @return array{0: int, 1: int, 2: list<string>}
     */
    private static function bestFrom(array $values, int $start, array $best, bool $padded): array
    {
        $first = $padded && $values[$start] >= self::PADDED_FROM ? sprintf('%02d', $values[$start]) : (string)$values[$start];
        $choice = self::followedBy($first, 0, $best[$start + 1]);
        $step = $start + 1 < count($values) ? $values[$start + 1] - $values[$start] : 0;
        // Each value of a run is $step after the one before it.
        for ($end = $start + self::RUN - 1; $end < count($values) && $values[$end] - $values[$end - 1] === $step; $end++) {
            $run = self::followedBy($first . '-' . $values[$end] . ($step === 1 ? '' : '/' . $step), 1, $best[$end + 1]);
            if (self::better($run, $choice)) {
                $choice = $run;
            }
        }

        return $choice;
    }

    /**
     * Whether list $list is shorter than $than, or as short with fewer runs.
     *
     * @param array{0: int, 1: int, 2: list<string>} $list
     * @param array{0: int, 1: int, 2: list<string>} $than
     */
    private static function better(array $list, array $than): bool
    {
        return $list[0] < $than[0] || ($list[0] === $than[0] && $list[1] < $than[1]);
    }

    /**
     * The list of $item and then $rest, $runs the number of runs $item is.
     *
     * @param array{0: int, 1: int, 2: list<string>} $rest
     * @return array{0: int, 1: int, 2: list<string>}
     */
    private static function followedBy(string $item, int $runs, array $rest): array
    {
        $length = strlen($item) + ($rest[2] === [] ? 0 : 1 + $rest[0]);

        return [$length, $runs + $rest[1], [$item, ...$rest[2]]];
    }
}
