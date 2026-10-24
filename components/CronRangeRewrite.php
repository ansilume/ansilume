<?php

declare(strict_types=1);

namespace app\components;

/**
 * How CronExpressionRules suggests to write a range it refuses: a range that
 * runs backwards split where it would wrap around, and a day-of-week range
 * with Sunday as 0 at its start or as 7 at its end.
 */
final class CronRangeRewrite
{
    /**
     * The range from $low back to $high split where it would wrap around the
     * field from $smallest to $largest, each part with $step (0 for none)
     * where it holds more than one value: 22-2 of the hour field as
     * 0-2,22-23, 50-10/5 of the minute field as 0-10/5,50-59/5.
     */
    public static function split(int $smallest, int $largest, int $low, int $high, int $step): string
    {
        return self::part($smallest, $high, $step) . ',' . self::part($low, $largest, $step);
    }

    /**
     * The day-of-week range from $from to $to, whose numbers are $low and
     * $high: as written without a step; in numbers with one, as the library
     * counts a step after names from 0 (STEP_AFTER_NAMES), and to 6 where it
     * would step from 0 to 7 (SUNDAY_STEP).
     */
    public static function sunday(string $from, string $to, int $low, int $high, string $step): string
    {
        if ($step === '') {
            return $from . '-' . $to;
        }
        $high = $low === 0 && $high === 7 && (int)$step > 1 ? 6 : $high;

        return $low . '-' . $high . '/' . $step;
    }

    /**
     * The values from $from to $to with $step: the one value, or the range
     * with its step.
     */
    private static function part(int $from, int $to, int $step): string
    {
        if ($from + max(1, $step) > $to) {
            return (string)$from;
        }

        return $from . '-' . $to . ($step > 1 ? '/' . $step : '');
    }
}
