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
}
