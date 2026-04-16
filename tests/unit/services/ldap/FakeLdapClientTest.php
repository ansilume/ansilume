<?php

declare(strict_types=1);

namespace app\tests\unit\services\ldap;

use app\services\ldap\FakeLdapClient;
use app\services\ldap\LdapConfig;
use PHPUnit\Framework\TestCase;

class FakeLdapClientTest extends TestCase
{
    private function makeConfig(bool $usable = true): LdapConfig
    {
        return LdapConfig::fromArray($usable ? [
            'enabled' => true,
            'host' => 'fake',
            'baseDn' => 'dc=test',
        ] : []);
    }

    public function testServiceBindFailsWhenConfigUnusable(): void
    {
        $client = new FakeLdapClient($this->makeConfig(false));
        $this->assertFalse($client->bindServiceAccount());
        $this->assertNotNull($client->getLastError());
    }

    public function testServiceBindSucceedsWithUsableConfig(): void
    {
        $client = new FakeLdapClient($this->makeConfig());
        $this->assertTrue($client->bindServiceAccount());
        $this->assertNull($client->getLastError());
    }

    public function testFailServiceBindCanBeForced(): void
    {
        $client = new FakeLdapClient($this->makeConfig());
        $client->failServiceBind(true);
        $this->assertFalse($client->bindServiceAccount());
        $this->assertSame('Simulated service bind failure.', $client->getLastError());
    }

    public function testFindUserReturnsRegisteredEntry(): void
    {
        $client = new FakeLdapClient($this->makeConfig());
        $client->addUser('jdoe', 'uid=jdoe,dc=test', 'pw', [
            'mail' => 'j@x',
            'displayName' => 'John Doe',
        ]);
        $entry = $client->findUser('jdoe');
        $this->assertNotNull($entry);
        $this->assertSame('uid=jdoe,dc=test', $entry->dn);
        $this->assertSame('j@x', $entry->first('mail'));
    }

    public function testFindUserIsCaseInsensitive(): void
    {
        $client = new FakeLdapClient($this->makeConfig());
        $client->addUser('JDoe', 'uid=jdoe,dc=test', 'pw');
        $this->assertNotNull($client->findUser('jdoe'));
        $this->assertNotNull($client->findUser('JDOE'));
    }

    public function testFindUserReturnsNullWhenAbsent(): void
    {
        $client = new FakeLdapClient($this->makeConfig());
        $this->assertNull($client->findUser('ghost'));
    }

    public function testFindUserReturnsNullWhenServiceBindFails(): void
    {
        $client = new FakeLdapClient($this->makeConfig());
        $client->failServiceBind(true);
        $client->addUser('jdoe', 'uid=jdoe,dc=test', 'pw');
        $this->assertNull($client->findUser('jdoe'));
    }

    public function testBindAsUserAcceptsCorrectPassword(): void
    {
        $client = new FakeLdapClient($this->makeConfig());
        $client->addUser('jdoe', 'uid=jdoe,dc=test', 'secret');
        $this->assertTrue($client->bindAsUser('uid=jdoe,dc=test', 'secret'));
    }

    public function testBindAsUserRejectsWrongPassword(): void
    {
        $client = new FakeLdapClient($this->makeConfig());
        $client->addUser('jdoe', 'uid=jdoe,dc=test', 'secret');
        $this->assertFalse($client->bindAsUser('uid=jdoe,dc=test', 'wrong'));
        $this->assertSame('Invalid credentials.', $client->getLastError());
    }

    public function testBindAsUserRejectsEmptyDnOrPassword(): void
    {
        $client = new FakeLdapClient($this->makeConfig());
        $this->assertFalse($client->bindAsUser('', 'pw'));
        $this->assertFalse($client->bindAsUser('uid=jdoe,dc=test', ''));
    }

    public function testBindAsUserReturnsFalseForUnknownDn(): void
    {
        $client = new FakeLdapClient($this->makeConfig());
        $this->assertFalse($client->bindAsUser('uid=ghost,dc=test', 'pw'));
        $this->assertSame('No such DN.', $client->getLastError());
    }

    public function testFindUserGroupsReturnsRegisteredMemberships(): void
    {
        $client = new FakeLdapClient($this->makeConfig());
        $client->addUser('jdoe', 'uid=jdoe,dc=test', 'pw');
        $client->addGroupMembership('uid=jdoe,dc=test', 'Admins');
        $client->addGroupMembership('uid=jdoe,dc=test', 'Ops');
        $this->assertSame(['Admins', 'Ops'], $client->findUserGroups('uid=jdoe,dc=test'));
    }

    public function testFindUserGroupsReturnsEmptyForUnknownDn(): void
    {
        $client = new FakeLdapClient($this->makeConfig());
        $this->assertSame([], $client->findUserGroups('uid=ghost,dc=test'));
    }

    public function testRemoveUserDeletesEntry(): void
    {
        $client = new FakeLdapClient($this->makeConfig());
        $client->addUser('jdoe', 'uid=jdoe,dc=test', 'pw');
        $client->removeUser('jdoe');
        $this->assertNull($client->findUser('jdoe'));
    }

    public function testCloseResetsServiceBound(): void
    {
        $client = new FakeLdapClient($this->makeConfig());
        $client->bindServiceAccount();
        $client->close();
        // Calling findUser should re-bind via bindServiceAccount(). Confirm
        // by failing the bind and checking the result.
        $client->failServiceBind(true);
        $this->assertNull($client->findUser('anyone'));
    }

    public function testGetConfigReturnsTheConfig(): void
    {
        $cfg = $this->makeConfig();
        $client = new FakeLdapClient($cfg);
        $this->assertSame($cfg, $client->getConfig());
    }
}
