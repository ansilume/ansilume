<?php

declare(strict_types=1);

namespace app\tests\unit\services\ldap;

use app\services\ldap\LdapEntry;
use PHPUnit\Framework\TestCase;

class LdapEntryTest extends TestCase
{
    public function testFirstReturnsLeadingValue(): void
    {
        $e = new LdapEntry('uid=jdoe,dc=x,dc=y', ['mail' => ['a@x', 'b@x']]);
        $this->assertSame('a@x', $e->first('mail'));
    }

    public function testFirstIsCaseInsensitive(): void
    {
        $e = new LdapEntry('cn=jdoe', ['displayname' => ['John']]);
        $this->assertSame('John', $e->first('displayName'));
        $this->assertSame('John', $e->first('DISPLAYNAME'));
    }

    public function testFirstReturnsNullForMissingAttribute(): void
    {
        $e = new LdapEntry('uid=jdoe', []);
        $this->assertNull($e->first('mail'));
    }

    public function testAllReturnsAllValues(): void
    {
        $e = new LdapEntry('uid=jdoe', ['memberof' => ['g1', 'g2']]);
        $this->assertSame(['g1', 'g2'], $e->all('memberof'));
    }

    public function testAllReturnsEmptyListForMissingAttribute(): void
    {
        $e = new LdapEntry('uid=jdoe', []);
        $this->assertSame([], $e->all('memberof'));
    }

    public function testHasReportsPresence(): void
    {
        $e = new LdapEntry('uid=jdoe', ['mail' => ['a@x']]);
        $this->assertTrue($e->has('mail'));
        $this->assertTrue($e->has('MAIL'));
        $this->assertFalse($e->has('foo'));
    }
}
