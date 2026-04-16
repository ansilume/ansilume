<?php

declare(strict_types=1);

namespace app\tests\unit\services\ldap;

use app\services\ldap\FakeLdapClient;
use app\services\ldap\LdapConfig;
use app\services\ldap\LdapService;
use PHPUnit\Framework\TestCase;

class LdapServiceTest extends TestCase
{
    private array $originalLdapParams = [];

    protected function setUp(): void
    {
        $this->originalLdapParams = is_array(\Yii::$app->params['ldap'] ?? null)
            ? \Yii::$app->params['ldap']
            : [];
    }

    protected function tearDown(): void
    {
        \Yii::$app->params['ldap'] = $this->originalLdapParams;
    }

    private function makeService(array $params, ?FakeLdapClient $client = null): LdapService
    {
        \Yii::$app->params['ldap'] = $params;
        $svc = new LdapService();
        if ($client !== null) {
            $svc->client = $client;
        }
        return $svc;
    }

    private function fake(): FakeLdapClient
    {
        return new FakeLdapClient(LdapConfig::fromArray([
            'enabled' => true,
            'host' => 'fake',
            'baseDn' => 'dc=test',
            'attrUsername' => 'uid',
            'attrEmail' => 'mail',
            'attrDisplayName' => 'displayName',
            'attrUid' => 'entryUUID',
        ]));
    }

    public function testIsEnabledReflectsConfig(): void
    {
        $disabled = $this->makeService(['enabled' => false]);
        $this->assertFalse($disabled->isEnabled());

        $enabled = $this->makeService(['enabled' => true]);
        $this->assertTrue($enabled->isEnabled());
    }

    public function testAuthenticateReturnsNullWhenLdapNotUsable(): void
    {
        $svc = $this->makeService(['enabled' => false]);
        $this->assertNull($svc->authenticate('jdoe', 'pw'));
        $this->assertNotNull($svc->getLastError());
    }

    public function testAuthenticateRejectsEmptyCredentials(): void
    {
        $svc = $this->makeService([
            'enabled' => true,
            'host' => 'fake',
            'baseDn' => 'dc=test',
        ]);
        $this->assertNull($svc->authenticate('', 'pw'));
        $this->assertNull($svc->authenticate('jdoe', ''));
    }

    public function testAuthenticateFailsOnServiceBindFailure(): void
    {
        $client = $this->fake();
        $client->failServiceBind(true);
        $svc = $this->makeService([
            'enabled' => true,
            'host' => 'fake',
            'baseDn' => 'dc=test',
        ], $client);
        $this->assertNull($svc->authenticate('jdoe', 'pw'));
        $this->assertNotNull($svc->getLastError());
    }

    public function testAuthenticateFailsWhenUserMissing(): void
    {
        $client = $this->fake();
        $svc = $this->makeService([
            'enabled' => true,
            'host' => 'fake',
            'baseDn' => 'dc=test',
        ], $client);
        $this->assertNull($svc->authenticate('ghost', 'pw'));
        $this->assertSame('User not found in directory.', $svc->getLastError());
    }

    public function testAuthenticateFailsOnWrongPassword(): void
    {
        $client = $this->fake();
        $client->addUser('jdoe', 'uid=jdoe,dc=test', 'right', [
            'uid' => 'jdoe',
            'mail' => 'jdoe@example.com',
        ]);
        $svc = $this->makeService([
            'enabled' => true,
            'host' => 'fake',
            'baseDn' => 'dc=test',
            'attrUsername' => 'uid',
            'attrEmail' => 'mail',
        ], $client);
        $this->assertNull($svc->authenticate('jdoe', 'wrong'));
    }

    public function testAuthenticateReturnsResultOnSuccess(): void
    {
        $client = $this->fake();
        $client->addUser('jdoe', 'uid=jdoe,dc=test', 'right', [
            'uid' => 'jdoe',
            'mail' => 'jdoe@example.com',
            'displayName' => 'John Doe',
            'entryUUID' => 'guid-123',
        ]);
        $client->addGroupMembership('uid=jdoe,dc=test', 'Admins');
        $svc = $this->makeService([
            'enabled' => true,
            'host' => 'fake',
            'baseDn' => 'dc=test',
            'attrUsername' => 'uid',
            'attrEmail' => 'mail',
            'attrDisplayName' => 'displayName',
            'attrUid' => 'entryUUID',
            'roleMapping' => ['Admins' => 'admin'],
        ], $client);

        $result = $svc->authenticate('jdoe', 'right');
        $this->assertNotNull($result);
        $this->assertSame('uid=jdoe,dc=test', $result->dn);
        $this->assertSame('guid-123', $result->uid);
        $this->assertSame('jdoe', $result->username);
        $this->assertSame('jdoe@example.com', $result->email);
        $this->assertSame('John Doe', $result->displayName);
        $this->assertSame(['Admins'], $result->groups);
        $this->assertSame(['admin'], $result->roles);
    }

    public function testLookupByUsernameDoesNotRequirePassword(): void
    {
        $client = $this->fake();
        $client->addUser('jdoe', 'uid=jdoe,dc=test', 'pw', [
            'uid' => 'jdoe',
            'mail' => 'jdoe@x',
            'entryUUID' => 'guid-123',
        ]);
        $svc = $this->makeService([
            'enabled' => true,
            'host' => 'fake',
            'baseDn' => 'dc=test',
            'attrUsername' => 'uid',
            'attrEmail' => 'mail',
            'attrUid' => 'entryUUID',
        ], $client);
        $result = $svc->lookupByUsername('jdoe');
        $this->assertNotNull($result);
        $this->assertSame('guid-123', $result->uid);
    }

    public function testLookupByUsernameReturnsNullForMissingUser(): void
    {
        $svc = $this->makeService([
            'enabled' => true,
            'host' => 'fake',
            'baseDn' => 'dc=test',
        ], $this->fake());
        $this->assertNull($svc->lookupByUsername('ghost'));
    }

    public function testMapGroupsToRolesIsCaseInsensitive(): void
    {
        $svc = $this->makeService([
            'roleMapping' => ['Admins' => 'admin', 'Ops' => 'operator'],
        ]);
        $this->assertSame(['admin'], $svc->mapGroupsToRoles(['ADMINS']));
    }

    public function testMapGroupsToRolesFallsBackToDefault(): void
    {
        $svc = $this->makeService([
            'roleMapping' => ['Admins' => 'admin'],
            'defaultRole' => 'viewer',
        ]);
        $this->assertSame(['viewer'], $svc->mapGroupsToRoles(['Random']));
    }

    public function testMapGroupsToRolesReturnsEmptyWhenNoMappingAndNoDefault(): void
    {
        $svc = $this->makeService([]);
        $this->assertSame([], $svc->mapGroupsToRoles(['anything']));
    }

    public function testMapGroupsToRolesDeduplicatesMatches(): void
    {
        $svc = $this->makeService([
            'roleMapping' => ['Admins' => 'admin', 'admins' => 'admin'],
        ]);
        $this->assertSame(['admin'], $svc->mapGroupsToRoles(['Admins']));
    }

    public function testDiagnoseReportsConnectionState(): void
    {
        $client = $this->fake();
        $svc = $this->makeService([
            'enabled' => true,
            'host' => 'fake',
            'baseDn' => 'dc=test',
            'bindDn' => 'cn=svc',
        ], $client);
        $diag = $svc->diagnose();
        $this->assertTrue($diag['enabled']);
        $this->assertSame('fake', $diag['host']);
        $this->assertSame('dc=test', $diag['base_dn']);
        $this->assertTrue($diag['bind_dn_configured']);
        $this->assertTrue($diag['service_bind']);
        $this->assertNull($diag['error']);
    }

    public function testDiagnoseReportsBindFailure(): void
    {
        $client = $this->fake();
        $client->failServiceBind(true);
        $svc = $this->makeService([
            'enabled' => true,
            'host' => 'fake',
            'baseDn' => 'dc=test',
        ], $client);
        $diag = $svc->diagnose();
        $this->assertFalse($diag['service_bind']);
        $this->assertNotNull($diag['error']);
    }

    public function testDiagnoseReportsDisabled(): void
    {
        $svc = $this->makeService([]);
        $diag = $svc->diagnose();
        $this->assertFalse($diag['enabled']);
        $this->assertFalse($diag['service_bind']);
        $this->assertSame('LDAP not enabled or not fully configured.', $diag['error']);
    }

    public function testGetClientUsesConfigArrayForm(): void
    {
        // Cover the array-form `client` config branch.
        $svc = $this->makeService([
            'enabled' => true,
            'host' => 'fake',
            'baseDn' => 'dc=test',
        ]);
        $svc->client = ['class' => FakeLdapClient::class];
        // Authenticate to force the client to be resolved.
        $this->assertNull($svc->authenticate('ghost', 'pw'));
    }
}
