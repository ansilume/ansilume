<?php

declare(strict_types=1);

namespace app\components;

/**
 * A share in percent: a success or approval rate of the analytics reports,
 * or how much of the artifact storage's byte quota is used.
 *
 * Computed as part * 100 / whole, never as part / whole * 100. Since PHP 8.4
 * round() rounds the float it is given, without the pre-rounding of earlier
 * versions, and part / whole * 100 can lie just below an exact half:
 * 23 / 160 * 100 is 14.374999999999998, which PHP 8.3 rounded to 14.38 and
 * PHP 8.4 and later round to 14.37. part * 100 is exact, so its one division
 * gives the float nearest to the exact share, and an exact half rounds up on
 * every version (14.375: 14.38), as the reports showed on PHP 8.3.
 */
final class Percent
{
    /**
     * $part of $whole in percent, rounded to two decimals; 0.0 when $whole
     * is 0.
     */
    public static function rate(int $part, int $whole): float
    {
        return $whole > 0 ? round($part * 100 / $whole, 2) : 0.0;
    }

    /**
     * $used of $quota in whole percent and at most 100, as storage can
     * exceed its quota; 0 without a quota ($quota 0). The cap comes before
     * the int cast, which warns since PHP 8.5 for a float beyond the int
     * range.
     */
    public static function ofQuota(int $used, int $quota): int
    {
        return $quota > 0 ? (int)min(100.0, round($used * 100 / $quota)) : 0;
    }
}
