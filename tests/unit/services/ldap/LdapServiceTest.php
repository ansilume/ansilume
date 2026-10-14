<?php

declare(strict_types=1);

namespace app\tests\unit\services\ldap;

use app\services\ldap\FakeLdapClient;
use app\services\ldap\LdapConfig;
use app\services\ldap\LdapLookupResult;
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
        $this->assertSame(LdapService::USER_NOT_FOUND, $svc->getLastError());
    }

    // ── lookup(): a missing user is not a failed lookup ──────────────────────

    /**
     * @return array<string, mixed>
     */
    private function usableParams(): array
    {
        return [
            'enabled' => true,
            'host' => 'fake',
            'baseDn' => 'dc=test',
            'attrUsername' => 'uid',
            'attrEmail' => 'mail',
            'attrDisplayName' => 'displayName',
            'attrUid' => 'entryUUID',
            'roleMapping' => ['Admins' => 'admin'],
            'defaultRole' => 'viewer',
        ];
    }

    private function fakeWithJdoe(): FakeLdapClient
    {
        $client = $this->fake();
        $client->addUser('jdoe', 'uid=jdoe,dc=test', 'pw', [
            'uid' => 'jdoe',
            'mail' => 'jdoe@example.com',
            'displayName' => 'John Doe',
            'entryUUID' => 'guid-123',
        ]);
        $client->addGroupMembership('uid=jdoe,dc=test', 'Admins');
        return $client;
    }

    public function testLookupAnswersTheUserTheDirectoryHas(): void
    {
        $svc = $this->makeService($this->usableParams(), $this->fakeWithJdoe());

        $lookup = $svc->lookup('jdoe');

        $this->assertSame(LdapLookupResult::FOUND, $lookup->outcome);
        $this->assertNull($lookup->error);
        $this->assertNotNull($lookup->user);
        $this->assertSame('uid=jdoe,dc=test', $lookup->user->dn);
        $this->assertSame('guid-123', $lookup->user->uid);
        $this->assertSame('jdoe@example.com', $lookup->user->email);
        $this->assertSame('John Doe', $lookup->user->displayName);
        $this->assertSame(['Admins'], $lookup->user->groups);
        $this->assertSame(['admin'], $lookup->user->roles);
        $this->assertNull($svc->getLastError());
    }

    public function testLookupOfAUserTheDirectoryDoesNotHaveIsMissing(): void
    {
        $svc = $this->makeService($this->usableParams(), $this->fakeWithJdoe());

        $lookup = $svc->lookup('ghost');

        $this->assertSame(LdapLookupResult::MISSING, $lookup->outcome);
        $this->assertNull($lookup->user);
        $this->assertNull($lookup->error, 'A missing user is no error.');
        $this->assertSame(LdapService::USER_NOT_FOUND, $svc->getLastError());
    }

    /**
     * A configuration that is not usable is no missing user: lookupByUsername()
     * answered null for both, so ldap/sync disabled every LDAP account
     * (regression test: LdapControllerTest).
     */
    public function testALookupWhileLdapIsNotUsableIsAFailure(): void
    {
        $svc = $this->makeService(['enabled' => true, 'baseDn' => 'dc=test'], $this->fakeWithJdoe());

        $lookup = $svc->lookup('jdoe');

        $this->assertSame(LdapLookupResult::NOT_USABLE, $lookup->outcome);
        $this->assertSame(LdapService::NOT_USABLE, $lookup->error);
        $this->assertSame(LdapService::NOT_USABLE, $svc->getLastError());
    }

    /**
     * A failed service bind is no missing user: lookupByUsername() answered
     * null for both, so ldap/sync disabled every LDAP account while the
     * directory could not be reached (regression test: LdapControllerTest).
     */
    public function testALookupWhoseServiceBindFailsIsABindFailure(): void
    {
        $client = $this->fakeWithJdoe();
        $client->failServiceBind(true);
        $svc = $this->makeService($this->usableParams(), $client);

        $lookup = $svc->lookup('jdoe');

        $this->assertSame(LdapLookupResult::BIND_FAILED, $lookup->outcome);
        $this->assertNull($lookup->user);
        $this->assertSame(FakeLdapClient::SERVICE_BIND_FAILURE, $lookup->error);
        $this->assertSame(FakeLdapClient::SERVICE_BIND_FAILURE, $svc->getLastError());
    }

    public function testALookupWhoseServiceBindFailsWithoutAReasonSaysSo(): void
    {
        $client = new class (LdapConfig::fromArray($this->usableParams())) extends FakeLdapClient {
            public function bindServiceAccount(): bool
            {
                return false;
            }
        };
        $svc = $this->makeService($this->usableParams(), $client);

        $lookup = $svc->lookup('jdoe');

        $this->assertSame(LdapLookupResult::BIND_FAILED, $lookup->outcome);
        $this->assertSame(LdapService::SERVICE_BIND_FAILED, $lookup->error);
    }

    /**
     * regression: a failed user search answered null like a user the
     * directory does not have, so ldap/sync disabled the account.
     */
    public function testRegressionALookupWhoseSearchFailsIsAFailureNotAMissingUser(): void
    {
        $client = $this->fakeWithJdoe();
        $client->failSearch(true);
        $svc = $this->makeService($this->usableParams(), $client);

        $this->assertNull($svc->lookupByUsername('jdoe'));
        $this->assertSame('Simulated user search failure.', $svc->getLastError(), 'The diagnostics name the failed search, not a missing user.');

        $lookup = $svc->lookup('jdoe');
        $this->assertSame(LdapLookupResult::SEARCH_FAILED, $lookup->outcome);
        $this->assertNull($lookup->user);
        $this->assertSame(FakeLdapClient::SEARCH_FAILURE, $lookup->error);
    }

    /**
     * regression: a failed group search answered the user with no groups, so
     * ldap/sync revoked the roles their groups map to.
     */
    public function testRegressionALookupWhoseGroupSearchFailsIsAFailure(): void
    {
        $client = $this->fakeWithJdoe();
        $client->failGroupSearch(true);
        $svc = $this->makeService($this->usableParams(), $client);

        $this->assertNull($svc->lookupByUsername('jdoe'), 'No user with the default role instead of their groups.');
        $this->assertSame('Simulated group search failure.', $svc->getLastError());

        $lookup = $svc->lookup('jdoe');
        $this->assertSame(LdapLookupResult::GROUPS_FAILED, $lookup->outcome);
        $this->assertNull($lookup->user);
        $this->assertSame(FakeLdapClient::GROUP_SEARCH_FAILURE, $lookup->error);
    }

    public function testALookupAfterAFailedOneReportsOnlyItsOwnOutcome(): void
    {
        $client = $this->fakeWithJdoe();
        $svc = $this->makeService($this->usableParams(), $client);
        $client->failSearch(true);
        $this->assertSame(LdapLookupResult::SEARCH_FAILED, $svc->lookup('ghost')->outcome);

        $client->failSearch(false);
        $this->assertSame(LdapLookupResult::MISSING, $svc->lookup('ghost')->outcome);
        $this->assertSame(LdapLookupResult::FOUND, $svc->lookup('jdoe')->outcome);
        $this->assertNull($svc->getLastError());
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
