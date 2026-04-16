<?php

declare(strict_types=1);

namespace app\tests\unit\services\ldap;

use app\services\ldap\LdapConfig;
use PHPUnit\Framework\TestCase;

class LdapConfigTest extends TestCase
{
    public function testFromArrayUsesDefaultsForMissingKeys(): void
    {
        $cfg = LdapConfig::fromArray([]);
        $this->assertFalse($cfg->enabled);
        $this->assertSame('', $cfg->host);
        $this->assertSame(389, $cfg->port);
        $this->assertSame(LdapConfig::ENCRYPTION_NONE, $cfg->encryption);
        $this->assertTrue($cfg->verifyPeer);
        $this->assertSame(5, $cfg->timeout);
        $this->assertSame('sAMAccountName', $cfg->attrUsername);
        $this->assertSame('mail', $cfg->attrEmail);
        $this->assertSame('displayName', $cfg->attrDisplayName);
        $this->assertSame('objectGUID', $cfg->attrUid);
        $this->assertSame('cn', $cfg->groupNameAttr);
        $this->assertSame([], $cfg->roleMapping);
        $this->assertSame('', $cfg->defaultRole);
        $this->assertTrue($cfg->autoProvision);
        $this->assertSame(['objectguid'], $cfg->binaryAttributes);
    }

    public function testFromArrayPopulatesAllFields(): void
    {
        $cfg = LdapConfig::fromArray([
            'enabled' => true,
            'host' => 'ldap.example.com',
            'port' => 636,
            'encryption' => 'LDAPS',
            'verifyPeer' => false,
            'timeout' => 10,
            'baseDn' => 'dc=example,dc=com',
            'bindDn' => 'cn=svc,dc=example,dc=com',
            'bindPassword' => 'secret',
            'userFilter' => '(uid=%s)',
            'attrUsername' => 'uid',
            'attrEmail' => 'mail',
            'attrDisplayName' => 'cn',
            'attrUid' => 'entryUUID',
            'groupFilter' => '(member=%s)',
            'groupNameAttr' => 'cn',
            'roleMapping' => ['Admins' => 'admin'],
            'defaultRole' => 'viewer',
            'autoProvision' => false,
        ]);
        $this->assertTrue($cfg->enabled);
        $this->assertSame('ldap.example.com', $cfg->host);
        $this->assertSame(636, $cfg->port);
        $this->assertSame('ldaps', $cfg->encryption);
        $this->assertFalse($cfg->verifyPeer);
        $this->assertSame(10, $cfg->timeout);
        $this->assertSame('dc=example,dc=com', $cfg->baseDn);
        $this->assertSame('secret', $cfg->bindPassword);
        $this->assertSame('(uid=%s)', $cfg->userFilter);
        $this->assertSame(['Admins' => 'admin'], $cfg->roleMapping);
        $this->assertSame('viewer', $cfg->defaultRole);
        $this->assertFalse($cfg->autoProvision);
    }

    public function testFromArrayCoercesInvalidRoleMappingToEmpty(): void
    {
        $cfg = LdapConfig::fromArray(['roleMapping' => 'not-an-array']);
        $this->assertSame([], $cfg->roleMapping);
    }

    public function testUriBuildsLdapsUrl(): void
    {
        $cfg = LdapConfig::fromArray([
            'host' => 'dc.example.com',
            'port' => 636,
            'encryption' => 'ldaps',
        ]);
        $this->assertSame('ldaps://dc.example.com:636', $cfg->uri());
    }

    public function testUriBuildsLdapUrlForStartTls(): void
    {
        $cfg = LdapConfig::fromArray([
            'host' => 'dc.example.com',
            'port' => 389,
            'encryption' => 'starttls',
        ]);
        // STARTTLS uses ldap:// then upgrades — scheme stays ldap.
        $this->assertSame('ldap://dc.example.com:389', $cfg->uri());
    }

    public function testIsUsableRequiresEnabledHostAndBaseDn(): void
    {
        $this->assertFalse(LdapConfig::fromArray([])->isUsable());
        $this->assertFalse(LdapConfig::fromArray(['enabled' => true])->isUsable());
        $this->assertFalse(LdapConfig::fromArray([
            'enabled' => true,
            'host' => 'ldap.example.com',
        ])->isUsable());
        $this->assertTrue(LdapConfig::fromArray([
            'enabled' => true,
            'host' => 'ldap.example.com',
            'baseDn' => 'dc=example,dc=com',
        ])->isUsable());
    }
}
