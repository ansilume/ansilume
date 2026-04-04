<?php

declare(strict_types=1);

namespace app\tests\unit\services\audit;

use app\services\audit\SyslogAuditTarget;
use PHPUnit\Framework\TestCase;

class SyslogAuditTargetTest extends TestCase
{
    public function testConstructorAcceptsDefaults(): void
    {
        $target = new SyslogAuditTarget();
        $this->assertInstanceOf(SyslogAuditTarget::class, $target);
    }

    public function testConstructorAcceptsCustomValues(): void
    {
        $target = new SyslogAuditTarget('myapp', 'LOG_USER');
        $this->assertInstanceOf(SyslogAuditTarget::class, $target);
    }

    public function testConstructorHandlesInvalidFacilityGracefully(): void
    {
        // Invalid facility name should not throw, falls back to LOG_LOCAL0
        $target = new SyslogAuditTarget('ansilume', 'INVALID_FACILITY');
        $this->assertInstanceOf(SyslogAuditTarget::class, $target);
    }

    public function testSendDoesNotThrow(): void
    {
        $target = new SyslogAuditTarget('ansilume-test', 'LOG_LOCAL0');

        $entry = [
            'action'      => 'test.syslog',
            'object_type' => 'test',
            'object_id'   => 1,
            'user_id'     => null,
            'metadata'    => null,
            'ip_address'  => '127.0.0.1',
            'user_agent'  => 'PHPUnit',
            'created_at'  => time(),
        ];

        // Should not throw — we can't easily assert syslog output,
        // but we can verify the method completes without error
        $target->send($entry);
        $this->assertTrue(true);
    }

    public function testSendWithEmptyEntryDoesNotThrow(): void
    {
        $target = new SyslogAuditTarget('ansilume-test', 'LOG_LOCAL0');
        $target->send([]);
        $this->assertTrue(true);
    }

    public function testSendWithUnicodeContent(): void
    {
        $target = new SyslogAuditTarget('ansilume-test', 'LOG_LOCAL0');
        $target->send([
            'action' => 'test.unicode',
            'metadata' => ['note' => 'Ü日本語'],
        ]);
        $this->assertTrue(true);
    }
}
