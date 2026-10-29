<?php

declare(strict_types=1);

namespace app\components;

/**
 * The texts in which Python writes a float, and so Ansible, which prints a
 * job's values with Python:
 * - repr(): str() too, so templated text and task names ("{{ answer }}"),
 *   JSON results and the to_json and to_nice_json filters;
 * - json(): json.dumps(), repr() but Infinity, -Infinity and NaN, for which
 *   JSON has no number;
 * - yaml(): PyYAML, the results of the YAML result format
 *   (ANSIBLE_CALLBACK_RESULT_FORMAT=yaml, callback_result_format = yaml in
 *   ansible.cfg) and the to_yaml and to_nice_yaml filters.
 *
 * repr() writes the fewest significant digits that read back as the same
 * float, and of those the nearest to it (Python's float_repr_style 'short',
 * David Gay's dtoa in mode 0). It writes them in fixed notation from 0.0001
 * up to below 1e16, a whole number with ".0" (1000000000000000.0), and with
 * an exponent of a sign and at least two digits otherwise (1e-05, 1e+16,
 * 1.5e-07). The digits do not depend on PHP's precision or
 * serialize_precision settings.
 */
final class PythonFloatText
{
    /** Every float reads back from this many significant digits. */
    private const ROUND_TRIP_DIGITS = 17;

    /**
     * repr() writes fixed notation while the point of $value =
     * 0.<digits> x 10^point is from -3 (0.0001 = 0.1 x 10^-3) to 16
     * (9999999999999998.0).
     */
    private const FIXED_FROM_POINT = -3;
    private const FIXED_TO_POINT = 16;

    public static function repr(#[\SensitiveParameter] float $value): string
    {
        return self::nonFinite($value, 'nan', 'inf') ?? self::finite($value);
    }

    public static function json(#[\SensitiveParameter] float $value): string
    {
        return self::nonFinite($value, 'NaN', 'Infinity') ?? self::finite($value);
    }

    public static function yaml(#[\SensitiveParameter] float $value): string
    {
        $text = self::nonFinite($value, '.nan', '.inf');
        if ($text !== null) {
            return $text;
        }
        $repr = self::finite($value);

        // YAML 1.1 reads a float only with a ".", so PyYAML writes 1e+20 as 1.0e+20.
        return str_contains($repr, '.') ? $repr : str_replace('e', '.0e', $repr);
    }

    /**
     * $nan for NaN, $infinity for INF and with "-" before it for -INF; null
     * for a finite $value.
     */
    private static function nonFinite(#[\SensitiveParameter] float $value, string $nan, string $infinity): ?string
    {
        if (is_nan($value)) {
            return $nan;
        }

        return is_infinite($value) ? ($value < 0 ? '-' : '') . $infinity : null;
    }

    /**
     * repr() of a finite $value.
     */
    private static function finite(#[\SensitiveParameter] float $value): string
    {
        // The sign bit, so -0.0 too: 1 / -0.0 is -INF.
        $sign = fdiv(1.0, $value) < 0 ? '-' : '';
        [$digits, $point] = self::shortest(abs($value));
        $fixed = $point >= self::FIXED_FROM_POINT && $point <= self::FIXED_TO_POINT;

        return $sign . ($fixed ? self::fixed($digits, $point) : self::scientific($digits, $point));
    }

    /**
     * 0.<digits> x 10^point in fixed notation: 0.0001, 12.5, 100.0.
     */
    private static function fixed(#[\SensitiveParameter] string $digits, int $point): string
    {
        if ($point <= 0) {
            return '0.' . str_repeat('0', -$point) . $digits;
        }
        if ($point >= strlen($digits)) {
            return str_pad($digits, $point, '0') . '.0';
        }

        return substr($digits, 0, $point) . '.' . substr($digits, $point);
    }

    /**
     * 0.<digits> x 10^point with an exponent: 1e-05, 1.5e+20, 5e-324.
     */
    private static function scientific(#[\SensitiveParameter] string $digits, int $point): string
    {
        $mantissa = strlen($digits) > 1 ? $digits[0] . '.' . substr($digits, 1) : $digits;

        return $mantissa . sprintf('e%+03d', $point - 1);
    }

    /**
     * The fewest significant digits that read back as $value (finite, not
     * negative), the nearest of them to it, and the position of the decimal
     * point: $value = 0.<digits> x 10^point. For each length, sprintf()
     * gives $value correctly rounded. At a power of two the next float below
     * lies half as far away as the next one above, so where that nearest
     * decimal lies below and reads back as another float, the next one up
     * can still read back as $value: Python writes 2 ** -24 as
     * 5.960464477539063e-08, not with the 17 digits of
     * 5.9604644775390625e-08.
     *
     * @return array{0: string, 1: int} the digits without trailing zeros ('' for 0.0), and the point
     */
    private static function shortest(#[\SensitiveParameter] float $value): array
    {
        $mantissa = 0;
        $exponent = 0;
        for ($length = 1; $length <= self::ROUND_TRIP_DIGITS; $length++) {
            // sprintf() writes d.ddde+x or d.ddde-x: the digits as an integer, and the exponent of the last one.
            $text = sprintf('%.' . ($length - 1) . 'e', $value);
            $e = (int)strpos($text, 'e');
            $mantissa = (int)str_replace('.', '', substr($text, 0, $e));
            $exponent = (int)substr($text, $e + 1) - $length + 1;
            $read = (float)"{$mantissa}e{$exponent}";
            if ($read < $value && (float)(($mantissa + 1) . "e{$exponent}") === $value) {
                $mantissa++;
                $read = $value;
            }
            if ($read === $value) {
                break;
            }
        }
        $digits = (string)$mantissa;

        return [rtrim($digits, '0'), strlen($digits) + $exponent];
    }
}
