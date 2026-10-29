<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\Percent;
use PHPUnit\Framework\TestCase;

/**
 * Percent rounds a share as the exact decimal share rounds, an exact half
 * up, on every PHP version. The tests run under the application's error
 * handler, which turns a warning into an exception, so a warning here fails
 * them.
 *
 * Regression: since PHP 8.4 round() has no pre-rounding, and a rate
 * computed as part / whole * 100 rounded an exact half down: 23 of 160 gave
 * 14.37 instead of 14.38, 575 MB of a 1000 MB quota 57 % instead of 58 %.
 */
class PercentTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: int, 2: float}>
     */
    public static function rateProvider(): array
    {
        return [
            '23 of 160, an exact half' => [23, 160, 14.38],
            '41 of 160, an exact half' => [41, 160, 25.63],
            '29 of 800, an exact half' => [29, 800, 3.63],
            '2 of 3' => [2, 3, 66.67],
            '1 of 3' => [1, 3, 33.33],
            '3 of 4' => [3, 4, 75.0],
            'all' => [160, 160, 100.0],
            'none' => [0, 5, 0.0],
            'of nothing' => [0, 0, 0.0],
            'of nothing, a part given' => [5, 0, 0.0],
        ];
    }

    /**
     * @dataProvider rateProvider
     */
    public function testARateHasTwoDecimalsAndAnExactHalfRoundsUp(int $part, int $whole, float $rate): void
    {
        $this->assertSame($rate, Percent::rate($part, $whole));
    }

    /**
     * Every rate of up to 1000 counts is the exact share rounded half up to
     * two decimals, computed in whole numbers: 10000 * part / whole
     * hundredths, half up.
     */
    public function testEveryRateIsTheExactShareRoundedHalfUp(): void
    {
        $wrong = [];
        for ($whole = 1; $whole <= 1000; $whole++) {
            for ($part = 0; $part <= $whole; $part++) {
                $exact = intdiv(20000 * $part + $whole, 2 * $whole) / 100.0;
                if (Percent::rate($part, $whole) !== $exact) {
                    $wrong[] = $part . ' of ' . $whole;
                }
            }
        }

        $this->assertSame([], $wrong);
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    public static function quotaProvider(): array
    {
        $megabyte = 1000000;
        $mebibyte = 1048576;

        return [
            '575 MB of 1000 MB, an exact half' => [575 * $megabyte, 1000 * $megabyte, 58],
            '575 MiB of 1000 MiB' => [575 * $mebibyte, 1000 * $mebibyte, 58],
            '145 MB of 1000 MB' => [145 * $megabyte, 1000 * $megabyte, 15],
            '285 MB of 1000 MB' => [285 * $megabyte, 1000 * $megabyte, 29],
            '565 MB of 1000 MB' => [565 * $megabyte, 1000 * $megabyte, 57],
            'a third' => [1, 3, 33],
            'empty' => [0, 1000, 0],
            'full' => [1000, 1000, 100],
            'over the quota' => [1500, 1000, 100],
            'far beyond the int range once multiplied' => [PHP_INT_MAX, 1, 100],
            'no quota' => [500, 0, 0],
        ];
    }

    /**
     * @dataProvider quotaProvider
     */
    public function testTheUseOfAQuotaIsAWholePercentOfAtMost100(int $used, int $quota, int $percent): void
    {
        $this->assertSame($percent, Percent::ofQuota($used, $quota));
    }

    /**
     * Every use of a quota of up to 400 bytes is the exact share rounded
     * half up to a whole percent: 100 * used / quota, half up.
     */
    public function testEveryUseOfAQuotaIsTheExactShareRoundedHalfUp(): void
    {
        $wrong = [];
        for ($quota = 1; $quota <= 400; $quota++) {
            for ($used = 0; $used <= $quota; $used++) {
                if (Percent::ofQuota($used, $quota) !== intdiv(200 * $used + $quota, 2 * $quota)) {
                    $wrong[] = $used . ' of ' . $quota;
                }
            }
        }

        $this->assertSame([], $wrong);
    }
}
