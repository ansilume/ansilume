<?php

declare(strict_types=1);

namespace app\helpers;

use yii\helpers\Html;

/**
 * Human-friendly timestamp rendering.
 */
class TimeHelper
{
    /** duration() under a minute; sprintf(): the seconds. */
    public const DURATION_SECONDS = '%ds';

    /** duration() under an hour; sprintf(): the minutes, the seconds. */
    public const DURATION_MINUTES = '%dm %02ds';

    /** duration() under a day; sprintf(): the hours, the minutes. */
    public const DURATION_HOURS = '%dh %02dm';

    /** duration() of a day or more; sprintf(): the days, the hours. */
    public const DURATION_DAYS = '%dd %dh';

    /** stamp() of a time of the same day: date() format. */
    public const STAMP_TODAY = 'H:i:s';

    /** stamp() of a time of another day: date() format. */
    public const STAMP_OTHER_DAY = 'Y-m-d H:i:s';

    /**
     * A duration in its two largest units: '45s', '1m 15s', '2h 13m',
     * '3d 4h'. A negative duration (a clock that went back) reads '0s'.
     */
    public static function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return sprintf(self::DURATION_SECONDS, $seconds);
        }
        if ($seconds < 3600) {
            return sprintf(self::DURATION_MINUTES, intdiv($seconds, 60), $seconds % 60);
        }
        if ($seconds < 86400) {
            return sprintf(self::DURATION_HOURS, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
        }

        return sprintf(self::DURATION_DAYS, intdiv($seconds, 86400), intdiv($seconds % 86400, 3600));
    }

    /**
     * An absolute time: 'H:i:s' when it is on the same day as $now (the
     * current time by default), else 'Y-m-d H:i:s'; the server's timezone,
     * as every time label.
     */
    public static function stamp(int $timestamp, ?int $now = null): string
    {
        $sameDay = date('Y-m-d', $timestamp) === date('Y-m-d', $now ?? time());

        return date($sameDay ? self::STAMP_TODAY : self::STAMP_OTHER_DAY, $timestamp);
    }

    /**
     * Render a unix timestamp as a relative "time ago" string wrapped in a
     * <time> element whose title shows the absolute date/time.
     *
     * Returns '—' for null timestamps.
     */
    public static function relative(?int $timestamp): string
    {
        if ($timestamp === null) {
            return '—';
        }
        $absolute = date('Y-m-d H:i:s', $timestamp);
        $label = self::ago($timestamp);
        return Html::tag('time', Html::encode($label), [
            'datetime' => date('c', $timestamp),
            'title' => $absolute,
        ]);
    }

    /**
     * Return a human-readable "time ago" string.
     */
    public static function ago(int $timestamp): string
    {
        $diff = time() - $timestamp;

        if ($diff < 0) {
            return self::future(-$diff);
        }
        if ($diff < 60) {
            return 'just now';
        }
        if ($diff < 3600) {
            $m = (int)floor($diff / 60);
            return $m === 1 ? '1 min ago' : $m . ' min ago';
        }
        if ($diff < 86400) {
            $h = (int)floor($diff / 3600);
            return $h === 1 ? '1 hour ago' : $h . ' hours ago';
        }
        if ($diff < 2592000) {
            $d = (int)floor($diff / 86400);
            return $d === 1 ? '1 day ago' : $d . ' days ago';
        }
        return date('Y-m-d', $timestamp);
    }

    /**
     * Return a human-readable "in X" string for future timestamps.
     */
    private static function future(int $diff): string
    {
        if ($diff < 60) {
            return 'in a moment';
        }
        if ($diff < 3600) {
            $m = (int)floor($diff / 60);
            return 'in ' . $m . ' min';
        }
        if ($diff < 86400) {
            $h = (int)floor($diff / 3600);
            return $h === 1 ? 'in 1 hour' : 'in ' . $h . ' hours';
        }
        $d = (int)floor($diff / 86400);
        return $d === 1 ? 'in 1 day' : 'in ' . $d . ' days';
    }
}
