<?php

declare(strict_types=1);

namespace app\components;

use app\models\Schedule;
use Cron\CronExpression;

/**
 * The rules a schedule's cron expression meets beyond what the cron library
 * (dragonmantank/cron-expression) checks.
 *
 * The library accepts expressions it then never runs, or runs at other times
 * than cron does, so a schedule with one was saved and never ran, or ran at
 * times its expression did not say (CronExpressionRulesTest pins each kind):
 * - a range that runs backwards, such as 5-1 or FRI-MON, has no run; in a
 *   list, such as 1,3-1, that item never matches;
 * - a list in the minute or hour field whose values the library does not
 *   walk in ascending order: it orders the minutes as text (5-10,45 ran at
 *   minute 45 only) and the hours as written (18,6 ran at 18:00 only), and
 *   walks a minute list with an asterisk from it as 0 (*,5 ran at minutes 0
 *   and 5 only);
 * - day-of-week ranges with Sunday: one that starts with Sunday as 7 (7-3,
 *   SUN-SAT), Sunday alone as a range (7-7 and 0-0 ran on every day), one
 *   that ends with the name SUN (SAT-SUN ran on Saturdays only), and 0-7
 *   with a step (0-7/2 never ran on a Sunday); one that ends with Sunday as
 *   0, such as 5-0, the library runs as 5-7, but Vixie cron refuses it as
 *   backwards, so 5-7 is asked for;
 * - a step as large as the field's largest value or larger: the library
 *   runs many of them at other times than cron (a step of 90 in the minute
 *   field at minute 30, one of 59 at minute 59 only, one of 13 months in
 *   February), so all are refused;
 * - a step after a range of names, such as MON-FRI/2, ran on Sundays;
 * - W after a day that not every month has: 31W ran on the 1st of November;
 * - W, L and # after a range, a step or a list item.
 * Numbers written with a sign, an exponent or three digits, such as +5 or
 * 1e1, are no standard cron and are refused as well. problem() refuses them
 * with the reason. Whether the library computes a run at all, which it does
 * not for the 30th of February, Schedule checks with the library itself
 * (NEVER_RUNS).
 */
final class CronExpressionRules
{
    /** Every expression the cron library refuses, and those these rules refuse without a reason of their own. */
    public const INVALID = 'Invalid cron expression. Use standard 5-field format: min hour dom mon dow';

    /**
     * sprintf(): the range as written, with its step, the field (such as
     * "minute"), then the range split where it would wrap around (5-1 as
     * 0-1,5-59, 50-10/5 as 0-10/5,50-59/5).
     */
    public const RANGE_BACKWARDS = 'Invalid cron expression: the range %1$s of the %2$s field runs backwards. '
        . 'Write the smaller value first; ranges do not wrap around, so split one that would, as in %3$s.';

    /**
     * RANGE_BACKWARDS where the split, in place of the range, would not be
     * accepted, as in a list out of order, or would not fit; sprintf(): the
     * range as written, with its step, then the field.
     */
    public const RANGE_BACKWARDS_UNSPLIT = 'Invalid cron expression: the range %1$s of the %2$s field runs '
        . 'backwards. Write the smaller value first; ranges do not wrap around, so split one that would.';

    /** sprintf(): the list as written, the field, then its values as a list the scheduler walks in order. */
    public const LIST_ORDER = 'Invalid cron expression: the scheduler would skip some values of the list %1$s of '
        . 'the %2$s field. Write its values in ascending order, as in %3$s.';

    /**
     * sprintf(): the list as written, the field, then the most characters
     * of an expression (Schedule::CRON_FIELD_MAX_LENGTH).
     */
    public const LIST_TOO_LONG = 'Invalid cron expression: the scheduler would skip some values of the list %1$s of '
        . 'the %2$s field, and Ansilume finds no list of these values in an order it walks that fits beside the '
        . 'other fields into the %3$d characters a cron expression may have. Shorten the other fields, or split '
        . 'the values over two schedules with no value in common.';

    /** sprintf(): the list as written. */
    public const ASTERISK_IN_LIST = 'Invalid cron expression: the list %s of the minute field holds *, which the '
        . 'scheduler takes for minute 0 when it looks for the next run, so it may skip minutes. Write * alone, '
        . 'which means every minute.';

    /** sprintf(): the range as written, with its step, then the range starting with 0 (CronRangeRewrite). */
    public const SUNDAY_FIRST = 'Invalid cron expression: the range %1$s of the day-of-week field starts with '
        . 'Sunday as 7. Write Sunday as 0 at the start of a range, as in %2$s.';

    /** sprintf(): the range as written, with its step. */
    public const SUNDAY_ALONE = 'Invalid cron expression: the range %s of the day-of-week field is Sunday alone, '
        . 'which the scheduler may run on every day or never. Write Sunday as 0 or 7.';

    /** sprintf(): the range as written, with its step, then the range ending with 7 (CronRangeRewrite). */
    public const SUNDAY_LAST = 'Invalid cron expression: the range %1$s of the day-of-week field ends with SUN, '
        . 'so the scheduler never runs it on a Sunday. Write Sunday as 7 at the end of a range, as in %2$s.';

    /** sprintf(): the range as written, with its step, then the range ending with 7 (CronRangeRewrite). */
    public const SUNDAY_ZERO_LAST = 'Invalid cron expression: the range %1$s of the day-of-week field ends with '
        . 'Sunday as 0, a smaller value than its start. Write Sunday as 7 at the end of a range, as in %2$s.';

    /** sprintf(): the range and step as written, then the same range from 0 to 6. */
    public const SUNDAY_STEP = 'Invalid cron expression: %1$s of the day-of-week field steps from 0 to 7, so the '
        . 'scheduler never runs it on a Sunday. Write it as %2$s.';

    /** sprintf(): the step as written (such as "/90"), the field, then the field's largest value. */
    public const STEP_TOO_LARGE = 'Invalid cron expression: the step %1$s of the %2$s field is too large. '
        . 'Use a step smaller than %3$d, or list the values.';

    /** sprintf(): the range and step as written, the field, then the range and step with numbers. */
    public const STEP_AFTER_NAMES = 'Invalid cron expression: %1$s of the %2$s field has a step after names. '
        . 'Write the range with numbers, as in %3$s.';

    /** sprintf(): the day and its W as written. */
    public const NEAREST_WEEKDAY_LATE = 'Invalid cron expression: %s of the day-of-month field needs a day '
        . 'from 1 to 28, which every month has.';

    /**
     * Schedule::validateCronExpression(): the cron library computes no run of
     * an expression these rules accept.
     */
    public const NEVER_RUNS = 'Invalid cron expression: the scheduler finds no date that matches it, so the '
        . 'schedule would never run. A day of the month must exist in the months it names; the 30th of '
        . 'February never does.';

    /** Each field's name, smallest and largest value, by position. */
    private const FIELDS = [
        CronExpression::MINUTE => ['minute', 0, 59],
        CronExpression::HOUR => ['hour', 0, 23],
        CronExpression::DAY => ['day-of-month', 1, 31],
        CronExpression::MONTH => ['month', 1, 12],
        CronExpression::WEEKDAY => ['day-of-week', 0, 7],
    ];

    /** The names the library knows, by position, with their numbers: Sunday is 7. */
    private const NAMES = [
        CronExpression::MONTH => [
            'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
            'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
        ],
        CronExpression::WEEKDAY => ['MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6, 'SUN' => 7],
    ];

    /** A value, a range or an asterisk, each with an optional step. */
    private const ITEM = '~^(?<from>\*|[0-9]{1,2}|[A-Za-z]{3})(?:-(?<to>[0-9]{1,2}|[A-Za-z]{3}))?(?:/(?<step>[0-9]+))?$~';

    /** The nearest weekday to a day of the month, such as 15W. */
    private const NEAREST_WEEKDAY = '~^(?<day>[0-9]{1,2})W$~';

    /** The last given weekday of the month (5L) or its nth (1#2). */
    private const WEEKDAY_OF_MONTH = '~^(?<day>[0-9]|[A-Za-z]{3})(?:L|#[1-5])$~';

    /** The latest day W may follow: every month has it. */
    private const LATEST_NEAREST_WEEKDAY = 28;

    /** itemProblem(): the item is a range that runs backwards, which backwardsProblem() words. */
    private const BACKWARDS = 'backwards';

    /**
     * Why the cron library would never run $expression or would run it at
     * other times than cron does, or null when it does neither. Expects an
     * expression the library accepts; an alias such as @daily is checked as
     * the expression it stands for.
     */
    public static function problem(string $expression): ?string
    {
        $fields = self::fields($expression);
        if ($fields === null) {
            return self::INVALID;
        }
        foreach ($fields as $position => $field) {
            // What a suggestion for the field may take, so that the expression with it still fits.
            $room = Schedule::CRON_FIELD_MAX_LENGTH - strlen($expression) + strlen($field);
            $problem = self::fieldProblem($position, $field, $room);
            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }

    /**
     * The five fields, as the library splits them, or null for another
     * number of fields.
     *
     * @return array<int, string>|null
     */
    private static function fields(string $expression): ?array
    {
        $expression = CronExpression::getAliases()[strtolower($expression)] ?? $expression;
        $fields = preg_split('/\s/', $expression, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($fields) && count($fields) === count(self::FIELDS) ? $fields : null;
    }

    private static function fieldProblem(int $position, string $field, int $room): ?string
    {
        if ($field === '?' && ($position === CronExpression::DAY || $position === CronExpression::WEEKDAY)) {
            return null;
        }
        if ($position === CronExpression::DAY && ($field === 'L' || preg_match(self::NEAREST_WEEKDAY, $field) === 1)) {
            return self::nearestWeekdayProblem($field);
        }

        return self::itemsProblem($position, $field, $room);
    }

    /**
     * The items of a field, and the order of a minute or hour list, whose
     * suggestion may take $room characters.
     */
    private static function itemsProblem(int $position, string $field, int $room): ?string
    {
        foreach (explode(',', $field) as $item) {
            $problem = self::itemProblem($position, $item);
            if ($problem === self::BACKWARDS) {
                return self::backwardsProblem($position, $field, $item, $room);
            }
            if ($problem !== null) {
                return $problem;
            }
        }
        $walked = $position === CronExpression::MINUTE || $position === CronExpression::HOUR;

        return $walked && str_contains($field, ',') ? self::listOrderProblem($position, $field, $room) : null;
    }

    /**
     * L, or the nearest weekday to a day (15W): the library takes the nearest
     * weekday to a day that the month lacks from the next month.
     */
    private static function nearestWeekdayProblem(string $field): ?string
    {
        $day = (int)$field;
        if ($field === 'L' || ($day >= 1 && $day <= self::LATEST_NEAREST_WEEKDAY)) {
            return null;
        }

        return $day > self::LATEST_NEAREST_WEEKDAY && $day <= self::FIELDS[CronExpression::DAY][2]
            ? sprintf(self::NEAREST_WEEKDAY_LATE, $field)
            : self::INVALID;
    }

    private static function itemProblem(int $position, string $item): ?string
    {
        if ($position === CronExpression::WEEKDAY && preg_match(self::WEEKDAY_OF_MONTH, $item, $match) === 1) {
            return self::valueOf($position, $match['day']) === null ? self::INVALID : null;
        }
        if (preg_match(self::ITEM, $item, $match) !== 1) {
            return self::INVALID;
        }

        return self::valueRangeOrStepProblem($position, $item, $match['from'], $match['to'] ?? '', $match['step'] ?? '');
    }

    /**
     * An asterisk with an optional step, a value, or a range with an
     * optional step: a value takes no step.
     */
    private static function valueRangeOrStepProblem(int $position, string $item, string $from, string $to, string $step): ?string
    {
        if ($from === '*') {
            return $to === '' ? self::stepProblem($position, $step) : self::INVALID;
        }
        if ($to === '') {
            return $step === '' && self::valueOf($position, $from) !== null ? null : self::INVALID;
        }

        return self::rangeProblem($position, $item, $from, $to, $step) ?? self::rangeStepProblem($position, $item, $from, $to, $step);
    }

    /**
     * A range's values, then its step, so that a suggestion holds a valid
     * step, then Sunday and the order of its values; BACKWARDS for one that
     * runs backwards.
     */
    private static function rangeProblem(int $position, string $item, string $from, string $to, string $step): ?string
    {
        $low = self::valueOf($position, $from);
        $high = self::valueOf($position, $to);
        if ($low === null || $high === null) {
            return self::INVALID;
        }
        $problem = self::stepProblem($position, $step);
        if ($problem === null && $position === CronExpression::WEEKDAY) {
            $problem = self::sundayRangeProblem($item, $from, $to, $low, $high, $step);
        }

        return $problem ?? ($low <= $high ? null : self::BACKWARDS);
    }

    /**
     * A range that runs backwards, with the split that would follow it where
     * the field, the split in place of the range, is accepted and fits into
     * $room: in a list it may be out of order, as 0-5,50-59 after 8. Sunday
     * is 0 at the start of a split, so its end is Saturday.
     */
    private static function backwardsProblem(int $position, string $field, string $item, int $room): string
    {
        [$name, $smallest, $largest] = self::FIELDS[$position];
        preg_match(self::ITEM, $item, $match);
        $low = (int)self::valueOf($position, $match['from'] ?? '');
        $high = (int)self::valueOf($position, $match['to'] ?? '');
        $largest = $position === CronExpression::WEEKDAY ? 6 : $largest;
        $split = CronRangeRewrite::split($smallest, $largest, $low, $high, (int)($match['step'] ?? 0));
        $items = explode(',', $field);
        $items[(int)array_search($item, $items, true)] = $split;
        $followed = implode(',', $items);
        if (strlen($followed) <= $room && self::itemsProblem($position, $followed, $room) === null) {
            return sprintf(self::RANGE_BACKWARDS, $item, $name, $split);
        }

        return sprintf(self::RANGE_BACKWARDS_UNSPLIT, $item, $name);
    }

    /**
     * Day-of-week ranges with Sunday that the library misreads, or that crons
     * read in different ways. Cron reads the name SUN as 0, and both 0 and 7
     * as Sunday; cronie reads an end of 0 after a later day as 7, and Vixie
     * cron refuses that range as backwards. The library reads SUN as 7,
     * turns a start of 7 into 0 and an end of 0 into 7 (7-7 and 0-0 ran on
     * every day, 5-0 from Friday to Sunday), and treats Sunday as 7 only when
     * the text holds the digit 7 (SAT-SUN ran on Saturdays only).
     */
    private static function sundayRangeProblem(string $item, string $from, string $to, int $low, int $high, string $step): ?string
    {
        if (self::isSundayAlone($from, $to, $low, $high)) {
            return sprintf(self::SUNDAY_ALONE, $item);
        }
        if ($low === 7) {
            return sprintf(self::SUNDAY_FIRST, $item, CronRangeRewrite::sunday('0', $to, 0, $high, $step));
        }
        if ($high === 0) {
            // After a later day: 0-0 is Sunday alone.
            return sprintf(self::SUNDAY_ZERO_LAST, $item, CronRangeRewrite::sunday($from, '7', $low, 7, $step));
        }

        return strcasecmp($to, 'SUN') === 0
            ? sprintf(self::SUNDAY_LAST, $item, CronRangeRewrite::sunday($from, '7', $low, 7, $step))
            : null;
    }

    /**
     * Whether cron reads the range as Sunday alone: with SUN as 0, and, as
     * cronie does, an end of 0 after a later day as 7 (7-0).
     */
    private static function isSundayAlone(string $from, string $to, int $low, int $high): bool
    {
        $cronLow = strcasecmp($from, 'SUN') === 0 ? 0 : $low;
        $cronHigh = strcasecmp($to, 'SUN') === 0 ? 0 : $high;
        if ($cronLow > $cronHigh && $cronHigh === 0) {
            $cronHigh = 7;
        }

        return $cronLow === $cronHigh && ($cronLow === 0 || $cronLow === 7);
    }

    /**
     * The step of a range, which rangeProblem() checked: after names, the
     * library counts from 0, and a day-of-week range from 0 to 7 with a step
     * never meets 7.
     */
    private static function rangeStepProblem(int $position, string $item, string $from, string $to, string $step): ?string
    {
        if ($step === '') {
            return null;
        }
        $low = (int)self::valueOf($position, $from);
        $high = (int)self::valueOf($position, $to);
        if (!ctype_digit($from) || !ctype_digit($to)) {
            // A weekday range of names that holds SUN was refused before: no suggestion here holds a 7.
            return sprintf(self::STEP_AFTER_NAMES, $item, self::FIELDS[$position][0], $low . '-' . $high . '/' . $step);
        }
        $zeroToSeven = $position === CronExpression::WEEKDAY && $low === 0 && $high === 7;

        return $zeroToSeven && (int)$step > 1 ? sprintf(self::SUNDAY_STEP, $item, '0-6/' . $step) : null;
    }

    /**
     * A step from 1 to below the field's largest value: from that value on,
     * the library runs many steps at other times than cron (a step of 90
     * minutes at minute 30, one of 59 minutes at minute 59 only).
     */
    private static function stepProblem(int $position, string $step): ?string
    {
        if ($step === '') {
            return null;
        }
        [$field, , $largest] = self::FIELDS[$position];
        if ((int)$step === 0) {
            return self::INVALID;
        }

        return (int)$step < $largest ? null : sprintf(self::STEP_TOO_LARGE, '/' . $step, $field, $largest);
    }

    /**
     * A list of minutes or hours, which the library walks in its own order
     * to find the next run, so that it may skip some values (CronListOrder),
     * with a suggestion of at most $room characters.
     */
    private static function listOrderProblem(int $position, string $field, int $room): ?string
    {
        [$name, $smallest, $largest] = self::FIELDS[$position];
        $minutes = $position === CronExpression::MINUTE;
        $items = explode(',', $field);
        $values = CronListOrder::skipped($items, $minutes, $smallest, $largest);
        if ($values === null) {
            return null;
        }
        if (in_array('*', $items, true)) {
            return sprintf(self::ASTERISK_IN_LIST, $field);
        }
        $suggestion = CronListOrder::suggestion($values, $minutes, $smallest, $largest, $room);

        return $suggestion === null
            ? sprintf(self::LIST_TOO_LONG, $field, $name, Schedule::CRON_FIELD_MAX_LENGTH)
            : sprintf(self::LIST_ORDER, $field, $name, $suggestion);
    }

    /**
     * The number of a value as the library reads it, or null when the field
     * has no such value: a number from its smallest to its largest value, or
     * one of its names (NAMES).
     */
    private static function valueOf(int $position, string $value): ?int
    {
        if (!ctype_digit($value)) {
            return self::NAMES[$position][strtoupper($value)] ?? null;
        }
        [, $smallest, $largest] = self::FIELDS[$position];
        $number = (int)$value;

        return $number >= $smallest && $number <= $largest ? $number : null;
    }
}
