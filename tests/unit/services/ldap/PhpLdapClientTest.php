<?php

declare(strict_types=1);

namespace app\tests\unit\services\ldap;

use app\services\ldap\LdapConfig;
use app\services\ldap\LdapLookupResult;
use app\services\ldap\LdapService;
use app\services\ldap\PhpLdapClient;
use PHPUnit\Framework\TestCase;

/**
 * The production client, PHP's ldap extension and libldap, against a fake
 * directory server ({@see FakeLdapServer}).
 *
 * A null from findUser() and an empty list from findUserGroups() say nothing
 * about the user unless the last error is null: ldap/sync disables an
 * account only when the search ran and found no entry. Up to 2.10.1 the
 * client answered a search that matched two entries, or that the directory
 * answered with a referral, like one that found no entry, and kept the error
 * of an earlier operation, so a caller could not tell a failed search from
 * an empty one.
 */
class PhpLdapClientTest extends TestCase
{
    private const USER_DN = 'uid=jdoe,dc=test';

    private ?FakeLdapServer $server = null;

    /** @var array<string, mixed> */
    private array $originalLdapParams = [];

    protected function setUp(): void
    {
        $params = \Yii::$app->params['ldap'] ?? null;
        $this->originalLdapParams = is_array($params) ? $params : [];
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        \Yii::$app->params['ldap'] = $this->originalLdapParams;
    }

    /**
     * @param array{binds?: list<int>, searches?: list<array{code?: int, message?: string, entries?: list<array{dn: string, attrs: array<string, list<string>>}>}>} $script
     * @return array<string, mixed>
     */
    private function directory(array $script): array
    {
        $this->server = FakeLdapServer::start($script);
        return [
            'enabled' => true,
            'host' => '127.0.0.1',
            'port' => $this->server->port,
            'timeout' => 3,
            'baseDn' => 'dc=test',
            'userFilter' => '(uid=%s)',
            'groupFilter' => '(member=%s)',
            'attrUsername' => 'uid',
            'attrEmail' => 'mail',
            'attrUid' => 'entryUUID',
            'roleMapping' => ['Admins' => 'admin'],
        ];
    }

    /**
     * @param array{binds?: list<int>, searches?: list<array{code?: int, message?: string, entries?: list<array{dn: string, attrs: array<string, list<string>>}>}>} $script
     */
    private function client(array $script): PhpLdapClient
    {
        return new PhpLdapClient(LdapConfig::fromArray($this->directory($script)));
    }

    /**
     * @return array{dn: string, attrs: array<string, list<string>>}
     */
    private function jdoe(): array
    {
        return ['dn' => self::USER_DN, 'attrs' => ['uid' => ['jdoe'], 'mail' => ['jdoe@example.com'], 'entryUUID' => ['guid-jdoe']]];
    }

    // ── findUser ──────────────────────────────────────────────────────────────

    public function testASearchThatFindsNoEntryAnswersNullWithoutAnError(): void
    {
        $client = $this->client(['searches' => [['entries' => []]]]);

        $this->assertNull($client->findUser('ghost'));
        $this->assertNull($client->getLastError(), 'No error: the directory has no such user.');
    }

    public function testASearchThatFindsOneEntryAnswersIt(): void
    {
        $client = $this->client(['searches' => [['entries' => [$this->jdoe()]]]]);

        $entry = $client->findUser('jdoe');

        $this->assertNotNull($entry);
        $this->assertSame(self::USER_DN, $entry->dn);
        $this->assertSame('jdoe@example.com', $entry->first('mail'));
        $this->assertNull($client->getLastError());
    }

    /**
     * regression: a user search that matched two entries answered null
     * without an error, like a user the directory does not have, so
     * ldap/sync disabled the account.
     */
    public function testRegressionASearchThatMatchesTwoEntriesIsAnError(): void
    {
        $client = $this->client(['searches' => [[
            'entries' => [$this->jdoe(), ['dn' => 'uid=jdoe,ou=other,dc=test', 'attrs' => ['uid' => ['jdoe']]]],
        ]]]);

        $this->assertNull($client->findUser('jdoe'));
        $this->assertNotNull($client->getLastError(), 'Two entries name no user: an error, not a missing user.');
        $this->assertSame(PhpLdapClient::USER_SEARCH_NOT_ONE, $client->getLastError());
    }

    /**
     * regression: a user search that the directory answered with a referral
     * to another server (its base DN is held elsewhere) found no entry and
     * answered null without an error, so ldap/sync disabled every account.
     */
    public function testRegressionASearchAnsweredWithAReferralIsAnError(): void
    {
        $client = $this->client(['searches' => [['code' => 10]]]);

        $this->assertNull($client->findUser('jdoe'));
        $this->assertNotNull($client->getLastError(), 'A referral says nothing about the user.');
        $this->assertSame(PhpLdapClient::USER_SEARCH_FAILED . ': Referral', $client->getLastError());
    }

    public function testASearchThatExceedsTheSizeLimitIsAnError(): void
    {
        // More than two entries match: the directory answers two and the size limit.
        $client = $this->client(['searches' => [[
            'entries' => [$this->jdoe(), ['dn' => 'uid=jdoe,ou=other,dc=test', 'attrs' => ['uid' => ['jdoe']]]],
            'code' => 4,
        ]]]);

        $this->assertNull($client->findUser('jdoe'));
        $this->assertSame(PhpLdapClient::USER_SEARCH_FAILED . ': Size limit exceeded', $client->getLastError());
    }

    public function testASearchThatFailsAnswersNullWithTheDirectoryError(): void
    {
        $client = $this->client(['searches' => [['code' => 32]]]);

        $this->assertNull($client->findUser('jdoe'));
        $this->assertSame(PhpLdapClient::USER_SEARCH_FAILED . ': No such object', $client->getLastError());
    }

    /**
     * regression: the client kept the error of an earlier operation, so a
     * search that found no entry looked like a failed one, and a failed one
     * like the earlier failure.
     */
    public function testRegressionASearchReportsOnlyItsOwnOutcome(): void
    {
        $client = $this->client(['searches' => [['code' => 1], ['entries' => []]]]);
        $this->assertNull($client->findUser('jdoe'));
        $this->assertNotNull($client->getLastError());

        $this->assertNull($client->findUser('ghost'));
        $this->assertNull($client->getLastError(), 'The search ran and found no entry: no error, whatever failed before.');
    }

    public function testASearchWhoseServiceBindFailsAnswersNullWithTheBindError(): void
    {
        $client = $this->client(['binds' => [49]]);

        $this->assertNull($client->findUser('jdoe'));
        $this->assertSame('Service account bind failed: Invalid credentials', $client->getLastError());
    }

    // ── findUserGroups ────────────────────────────────────────────────────────

    public function testAGroupSearchAnswersTheGroupNames(): void
    {
        $client = $this->client(['searches' => [['entries' => [
            ['dn' => 'cn=Admins,dc=test', 'attrs' => ['cn' => ['Admins']]],
            ['dn' => 'cn=Ops,dc=test', 'attrs' => ['cn' => ['Ops']]],
            ['dn' => 'cn=nameless,dc=test', 'attrs' => []],
        ]]]]);

        $this->assertSame(['Admins', 'Ops'], $client->findUserGroups(self::USER_DN));
        $this->assertNull($client->getLastError());
    }

    public function testAGroupSearchThatFailsIsAnError(): void
    {
        $client = $this->client(['searches' => [['code' => 1]]]);

        $this->assertSame([], $client->findUserGroups(self::USER_DN));
        $this->assertSame(PhpLdapClient::GROUP_SEARCH_FAILED . ': Operations error', $client->getLastError());
    }

    /**
     * regression: a group search answered with a referral to another server
     * answered no group without an error, so ldap/sync revoked the roles
     * the user's groups map to.
     */
    public function testRegressionAGroupSearchAnsweredWithAReferralIsAnError(): void
    {
        $client = $this->client(['searches' => [['code' => 10]]]);

        $this->assertSame([], $client->findUserGroups(self::USER_DN));
        $this->assertNotNull($client->getLastError(), 'A referral says nothing about the user\'s groups.');
        $this->assertSame(PhpLdapClient::GROUP_SEARCH_FAILED . ': Referral', $client->getLastError());
    }

    public function testAGroupSearchThatStopsAtALimitAnswersNoPartialList(): void
    {
        $client = $this->client(['searches' => [[
            'entries' => [['dn' => 'cn=Admins,dc=test', 'attrs' => ['cn' => ['Admins']]]],
            'code' => 4,
        ]]]);

        $this->assertSame([], $client->findUserGroups(self::USER_DN), 'Not a part of the groups as if it were all of them.');
        $this->assertSame(PhpLdapClient::GROUP_SEARCH_FAILED . ': Size limit exceeded', $client->getLastError());
    }

    /**
     * regression: the client kept the error of an earlier group search, so
     * a user in no group looked like a failed group search.
     */
    public function testRegressionAGroupSearchReportsOnlyItsOwnOutcome(): void
    {
        $client = $this->client(['searches' => [['code' => 1], ['entries' => []]]]);
        $this->assertSame([], $client->findUserGroups(self::USER_DN));
        $this->assertNotNull($client->getLastError());

        $this->assertSame([], $client->findUserGroups(self::USER_DN));
        $this->assertNull($client->getLastError(), 'The search ran: the user is in no group.');
    }

    public function testAGroupSearchWhoseServiceBindFailsAnswersTheBindError(): void
    {
        $client = $this->client(['binds' => [49]]);

        $this->assertSame([], $client->findUserGroups(self::USER_DN));
        $this->assertSame('Service account bind failed: Invalid credentials', $client->getLastError());
    }

    // ── LdapService::lookup() with the production client ─────────────────────

    /**
     * @param array{binds?: list<int>, searches?: list<array{code?: int, message?: string, entries?: list<array{dn: string, attrs: array<string, list<string>>}>}>} $script
     */
    private function lookup(array $script, string $username): LdapLookupResult
    {
        \Yii::$app->params['ldap'] = $this->directory($script);
        $service = new LdapService();
        $service->client = ['class' => PhpLdapClient::class];
        return $service->lookup($username);
    }

    public function testALookupOfAUserTheDirectoryHasAnswersTheUserWithTheirRoles(): void
    {
        $lookup = $this->lookup(['searches' => [
            ['entries' => [$this->jdoe()]],
            ['entries' => [['dn' => 'cn=Admins,dc=test', 'attrs' => ['cn' => ['Admins']]]]],
        ]], 'jdoe');

        $this->assertSame(LdapLookupResult::FOUND, $lookup->outcome);
        $this->assertNotNull($lookup->user);
        $this->assertSame('guid-jdoe', $lookup->user->uid);
        $this->assertSame(['admin'], $lookup->user->roles);
    }

    public function testALookupOfAUserTheDirectoryDoesNotHaveIsMissing(): void
    {
        $lookup = $this->lookup(['searches' => [['entries' => []]]], 'ghost');

        $this->assertSame(LdapLookupResult::MISSING, $lookup->outcome);
        $this->assertNull($lookup->error);
    }

    /**
     * regression: with the directory up but the user search failing (here
     * its base DN does not exist), every lookup answered "not found", and
     * ldap/sync disabled every LDAP account.
     */
    public function testRegressionALookupWhoseSearchFailsIsAFailureNotAMissingUser(): void
    {
        \Yii::$app->params['ldap'] = $this->directory(['searches' => [['code' => 32], ['code' => 32]]]);
        $service = new LdapService();
        $service->client = ['class' => PhpLdapClient::class];

        $this->assertNull($service->lookupByUsername('jdoe'));
        $this->assertSame('User search failed: No such object', $service->getLastError(), 'Not "User not found in directory."');

        $lookup = $service->lookup('jdoe');
        $this->assertSame(LdapLookupResult::SEARCH_FAILED, $lookup->outcome);
        $this->assertSame(PhpLdapClient::USER_SEARCH_FAILED . ': No such object', $lookup->error);
    }

    public function testALookupWhoseSearchIsAnsweredWithAReferralIsAFailure(): void
    {
        $lookup = $this->lookup(['searches' => [['code' => 10]]], 'jdoe');

        $this->assertSame(LdapLookupResult::SEARCH_FAILED, $lookup->outcome);
        $this->assertSame(PhpLdapClient::USER_SEARCH_FAILED . ': Referral', $lookup->error);
    }

    public function testALookupWhoseServiceBindFailsIsABindFailure(): void
    {
        $lookup = $this->lookup(['binds' => [49]], 'jdoe');

        $this->assertSame(LdapLookupResult::BIND_FAILED, $lookup->outcome);
        $this->assertSame('Service account bind failed: Invalid credentials', $lookup->error);
    }

    public function testALookupWhoseGroupSearchFailsIsAFailure(): void
    {
        $lookup = $this->lookup(['searches' => [['entries' => [$this->jdoe()]], ['code' => 1]]], 'jdoe');

        $this->assertSame(LdapLookupResult::GROUPS_FAILED, $lookup->outcome);
        $this->assertNull($lookup->user);
        $this->assertSame(PhpLdapClient::GROUP_SEARCH_FAILED . ': Operations error', $lookup->error);
    }
}
