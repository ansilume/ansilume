<?php

declare(strict_types=1);

namespace app\tests\unit\helpers;

use app\helpers\TimeHelper;
use PHPUnit\Framework\TestCase;

class TimeHelperTest extends TestCase
{
    public function testRelativeReturnsEmDashForNull(): void
    {
        $this->assertSame('—', TimeHelper::relative(null));
    }

    public function testRelativeReturnsTimeElement(): void
    {
        $ts = time() - 120;
        $result = TimeHelper::relative($ts);
        $this->assertStringContainsString('<time', $result);
        $this->assertStringContainsString('</time>', $result);
        $this->assertStringContainsString('datetime="', $result);
        $this->assertStringContainsString('title="', $result);
    }

    public function testRelativeShowsAbsoluteDateInTitle(): void
    {
        $ts = time() - 3600;
        $result = TimeHelper::relative($ts);
        $expected = date('Y-m-d H:i:s', $ts);
        $this->assertStringContainsString($expected, $result);
    }

    public function testAgoJustNow(): void
    {
        $this->assertSame('just now', TimeHelper::ago(time()));
        $this->assertSame('just now', TimeHelper::ago(time() - 30));
    }

    public function testAgoMinutes(): void
    {
        $this->assertSame('1 min ago', TimeHelper::ago(time() - 60));
        $this->assertSame('5 min ago', TimeHelper::ago(time() - 300));
        $this->assertSame('59 min ago', TimeHelper::ago(time() - 3540));
    }

    public function testAgoHours(): void
    {
        $this->assertSame('1 hour ago', TimeHelper::ago(time() - 3600));
        $this->assertSame('3 hours ago', TimeHelper::ago(time() - 10800));
        $this->assertSame('23 hours ago', TimeHelper::ago(time() - 82800));
    }

    public function testAgoDays(): void
    {
        $this->assertSame('1 day ago', TimeHelper::ago(time() - 86400));
        $this->assertSame('7 days ago', TimeHelper::ago(time() - 604800));
    }

    public function testAgoOlderThanThirtyDays(): void
    {
        $ts = time() - (31 * 86400);
        $this->assertSame(date('Y-m-d', $ts), TimeHelper::ago($ts));
    }

    public function testAgoFutureTimestamp(): void
    {
        $result = TimeHelper::ago(time() + 300);
        $this->assertSame('in 5 min', $result);
    }

    public function testAgoFutureHours(): void
    {
        $result = TimeHelper::ago(time() + 7200);
        $this->assertSame('in 2 hours', $result);
    }

    public function testAgoFutureDays(): void
    {
        $result = TimeHelper::ago(time() + 172800);
        $this->assertSame('in 2 days', $result);
    }

    public function testAgoFutureMoment(): void
    {
        $result = TimeHelper::ago(time() + 10);
        $this->assertSame('in a moment', $result);
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function durationData(): array
    {
        return [
            'nothing' => [0, '0s'],
            'seconds' => [45, '45s'],
            'last second of a minute' => [59, '59s'],
            'one minute' => [60, '1m 00s'],
            'minutes and seconds' => [75, '1m 15s'],
            'seconds padded' => [125, '2m 05s'],
            'last second of an hour' => [3599, '59m 59s'],
            'one hour' => [3600, '1h 00m'],
            'hours and minutes' => [7980, '2h 13m'],
            'minutes padded' => [3900, '1h 05m'],
            'last second of a day' => [86399, '23h 59m'],
            'one day' => [86400, '1d 0h'],
            'days and hours' => [273600, '3d 4h'],
            'many days' => [3 * 86400 * 10 + 7200, '30d 2h'],
            'a clock that went back' => [-5, '0s'],
        ];
    }

    /**
     * Hours and days, not minutes only ("133m 00s" for 7980 s), and no
     * wrap after 24 hours as gmdate('H:i:s') wraps.
     *
     * @dataProvider durationData
     */
    public function testDurationShowsItsTwoLargestUnits(int $seconds, string $expected): void
    {
        $this->assertSame($expected, TimeHelper::duration($seconds));
    }

    public function testStampShowsOnlyTheTimeOnTheSameDay(): void
    {
        $now = (int)mktime(18, 30, 0, 10, 13, 2026);
        $morning = (int)mktime(0, 0, 5, 10, 13, 2026);

        $this->assertSame('00:00:05', TimeHelper::stamp($morning, $now));
        $this->assertSame('18:30:00', TimeHelper::stamp($now, $now));
    }

    public function testStampShowsTheDateForAnotherDay(): void
    {
        $now = (int)mktime(0, 0, 1, 10, 13, 2026);

        $this->assertSame('2026-10-12 23:59:59', TimeHelper::stamp((int)mktime(23, 59, 59, 10, 12, 2026), $now));
        $this->assertSame('2026-10-14 00:00:00', TimeHelper::stamp((int)mktime(0, 0, 0, 10, 14, 2026), $now));
        $this->assertSame('2025-10-13 00:00:01', TimeHelper::stamp((int)mktime(0, 0, 1, 10, 13, 2025), $now));
    }

    public function testStampComparesWithTheCurrentTimeByDefault(): void
    {
        $twoDaysAgo = time() - 2 * 86400;

        $this->assertSame(date('Y-m-d H:i:s', $twoDaysAgo), TimeHelper::stamp($twoDaysAgo));
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', TimeHelper::stamp(time()));
    }
}
