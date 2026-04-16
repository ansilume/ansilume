<?php

declare(strict_types=1);

namespace app\tests\unit\services\ldap;

use app\services\ldap\LdapAuthResult;
use PHPUnit\Framework\TestCase;

class LdapAuthResultTest extends TestCase
{
    public function testReadonlyPropertiesAreSet(): void
    {
        $r = new LdapAuthResult(
            dn: 'uid=jdoe,dc=x,dc=y',
            uid: 'abc-123',
            username: 'jdoe',
            email: 'jdoe@example.com',
            displayName: 'John Doe',
            groups: ['Admins', 'Ops'],
            roles: ['admin', 'operator'],
        );
        $this->assertSame('uid=jdoe,dc=x,dc=y', $r->dn);
        $this->assertSame('abc-123', $r->uid);
        $this->assertSame('jdoe', $r->username);
        $this->assertSame('jdoe@example.com', $r->email);
        $this->assertSame('John Doe', $r->displayName);
        $this->assertSame(['Admins', 'Ops'], $r->groups);
        $this->assertSame(['admin', 'operator'], $r->roles);
    }
}
