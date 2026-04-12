<?php

declare(strict_types=1);

namespace app\helpers;

use yii\helpers\Html;

/**
 * Human-friendly timestamp rendering.
 */
class TimeHelper
{
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
