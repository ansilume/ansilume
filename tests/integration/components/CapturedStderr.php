<?php

declare(strict_types=1);

namespace app\tests\integration\components;

/**
 * A stream filter that keeps what is written to STDERR and passes nothing
 * on, so a test can read what the console error handler prints without it
 * reaching the test output. start() resets $text and appends the filter;
 * remove it with stream_filter_remove().
 */
final class CapturedStderr extends \php_user_filter
{
    private const NAME = 'ansilume-test-captured-stderr';

    public static string $text = '';

    /**
     * @return resource|false the appended filter
     */
    public static function start()
    {
        self::$text = '';
        if (!in_array(self::NAME, stream_get_filters(), true)) {
            stream_filter_register(self::NAME, self::class);
        }

        return stream_filter_append(\STDERR, self::NAME, STREAM_FILTER_WRITE);
    }

    /**
     * @param resource $in
     * @param resource $out
     * @param int $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            self::$text .= $bucket->data;
            $consumed += $bucket->datalen;
        }

        return PSFS_FEED_ME;
    }
}
