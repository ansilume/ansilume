<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\RunnerTransportClassifier;
use PHPUnit\Framework\TestCase;

/**
 * RunnerTransportClassifier decides from $_SERVER alone whether a runner
 * request came over HTTPS, over plain HTTP from a trusted network (the
 * bundled runners' Docker network) or over plain HTTP from outside, where
 * claim responses carry decrypted credentials in clear. Forwarded headers
 * count only when a trusted peer sends them, so a remote runner cannot hide
 * the warning by sending them itself.
 */
class RunnerTransportClassifierTest extends TestCase
{
    private const NOW = 1760000000;

    // -- networks() -----------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsetConfigurationProvider(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
            'only separators' => [' , ,, '],
        ];
    }

    /**
     * @dataProvider unsetConfigurationProvider
     */
    public function testAnUnsetConfigurationMeansTheDefaultNetworks(string $configured): void
    {
        $this->assertSame(
            RunnerTransportClassifier::DEFAULT_TRUSTED_NETWORKS,
            RunnerTransportClassifier::networks($configured)
        );
    }

    public function testACustomListIsTrimmedAndKeepsAddressesAndRangesOfBothFamilies(): void
    {
        $this->assertSame(
            ['10.20.0.0/16', '203.0.113.7', '2001:db8:42::/48', '2001:db8::1'],
            RunnerTransportClassifier::networks(' 10.20.0.0/16 ,203.0.113.7,  2001:db8:42::/48 , 2001:db8::1 ')
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidEntryProvider(): array
    {
        return [
            'hostname' => ['proxy.example.com'],
            'word' => ['not-an-ip'],
            'octet out of range' => ['300.1.1.1'],
            'truncated address' => ['10.0.0'],
            'address with port' => ['203.0.113.10:51234'],
            'non-numeric prefix' => ['10.0.0.0/abc'],
            'empty prefix' => ['10.0.0.0/'],
            'negative prefix' => ['10.0.0.0/-8'],
            'two prefixes' => ['10.0.0.0/8/16'],
            'netmask notation' => ['10.0.0.0/255.0.0.0'],
            'prefix without address' => ['/24'],
            'ipv6 zone id' => ['fe80::1%eth0'],
            // Regression: kept, so the defaults stopped applying, yet never matched.
            'ipv4 prefix over 32' => ['10.0.0.0/33'],
            'ipv4 prefix over 32 with a leading zero' => ['10.0.0.0/033'],
            'ipv6 prefix over 128' => ['2001:db8::/129'],
        ];
    }

    /**
     * @dataProvider invalidEntryProvider
     */
    public function testInvalidEntriesAreDropped(string $invalid): void
    {
        $this->assertSame(
            ['10.20.0.0/16', '2001:db8::/32'],
            RunnerTransportClassifier::networks('10.20.0.0/16, ' . $invalid . ', 2001:db8::/32')
        );
    }

    public function testTheWidestAndNarrowestPrefixesOfBothFamiliesAreKept(): void
    {
        $this->assertSame(
            ['10.0.0.5/32', '2001:db8::1/128', '0.0.0.0/0', '::/0'],
            RunnerTransportClassifier::networks('10.0.0.5/32, 2001:db8::1/128, 0.0.0.0/0, ::/0')
        );
    }

    public function testIgnoredEntriesNamesWhatNetworksDrops(): void
    {
        $this->assertSame(['nonsense', '10.0.0.0/33'], RunnerTransportClassifier::ignoredEntries('10.0.0.0/8, nonsense, , 10.0.0.0/33'));
        $this->assertSame([], RunnerTransportClassifier::ignoredEntries(''));
        $this->assertSame([], RunnerTransportClassifier::ignoredEntries(' 10.0.0.0/8 ,::1 '));
    }

    /**
     * A list with the wrong separator is one invalid entry: the defaults
     * apply, and bin/diagnose names the entry.
     */
    public function testAListWithTheWrongSeparatorIsOneIgnoredEntry(): void
    {
        $this->assertSame(RunnerTransportClassifier::DEFAULT_TRUSTED_NETWORKS, RunnerTransportClassifier::networks('172.19.0.0/16 172.20.0.0/16'));
        $this->assertSame(['172.19.0.0/16 172.20.0.0/16'], RunnerTransportClassifier::ignoredEntries('172.19.0.0/16 172.20.0.0/16'));
    }

    public function testOnlyInvalidEntriesFallBackToTheDefaults(): void
    {
        $this->assertSame(
            RunnerTransportClassifier::DEFAULT_TRUSTED_NETWORKS,
            RunnerTransportClassifier::networks('proxy.example.com, 300.1.1.1, 10.0.0.0/abc')
        );
    }

    // -- classify(): peer address ---------------------------------------------

    public function testWithoutAPeerAddressTheTransportIsUnknown(): void
    {
        $this->assertSame(self::seen(null, null), $this->classify([]));
    }

    public function testHeadersCannotStandInForAMissingPeer(): void
    {
        $this->assertSame(self::seen(null, null), $this->classify([
            'HTTPS' => 'on',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
        ]));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidPeerProvider(): array
    {
        return [
            'empty' => [''],
            'garbage' => ['not-an-address'],
            'hostname' => ['runner.example.com'],
            'address with port' => ['203.0.113.10:51234'],
            'integer' => [3405803786],
            'array' => [['203.0.113.10']],
            'null' => [null],
        ];
    }

    /**
     * @dataProvider invalidPeerProvider
     */
    public function testAnInvalidPeerAddressIsIgnored(mixed $remoteAddr): void
    {
        $this->assertSame(self::seen(null, null), $this->classify(['REMOTE_ADDR' => $remoteAddr]));
    }

    public function testThePeerAddressIsTrimmed(): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_INTERNAL, '172.18.0.5'),
            $this->classify(['REMOTE_ADDR' => ' 172.18.0.5 '])
        );
    }

    // -- classify(): direct connections ---------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function httpsOnProvider(): array
    {
        return ['on' => ['on'], 'upper case' => ['ON'], 'one' => ['1']];
    }

    /**
     * @dataProvider httpsOnProvider
     */
    public function testATlsConnectionToTheServerIsHttps(string $https): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTPS, '203.0.113.10'),
            $this->classify(['REMOTE_ADDR' => '203.0.113.10', 'HTTPS' => $https])
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function httpsOffProvider(): array
    {
        return ['off' => ['off'], 'empty' => [''], 'zero' => ['0']];
    }

    /**
     * @dataProvider httpsOffProvider
     */
    public function testHttpsOffIsPlainHttp(string $https): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, '203.0.113.10'),
            $this->classify(['REMOTE_ADDR' => '203.0.113.10', 'HTTPS' => $https])
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function trustedPeerProvider(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'ipv6 loopback' => ['::1'],
            '10/8' => ['10.1.2.3'],
            'bundled runner on the docker network' => ['172.18.0.5'],
            'start of 172.16/12' => ['172.16.0.1'],
            'end of 172.16/12' => ['172.31.255.254'],
            '192.168/16' => ['192.168.1.20'],
            'ipv6 unique local' => ['fd12:3456:789a::1'],
            'start of fc00::/7' => ['fc00::1'],
        ];
    }

    /**
     * @dataProvider trustedPeerProvider
     */
    public function testPlainHttpFromATrustedNetworkIsInternal(string $peer): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_INTERNAL, $peer),
            $this->classify(['REMOTE_ADDR' => $peer])
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function untrustedPeerProvider(): array
    {
        return [
            'public ipv4' => ['203.0.113.10'],
            'just below 172.16/12' => ['172.15.255.255'],
            'just above 172.16/12' => ['172.32.0.1'],
            'just above 10/8' => ['11.0.0.1'],
            'next to 192.168/16' => ['192.169.0.1'],
            'public ipv6' => ['2001:db8::7'],
            'ipv6 link local' => ['fe80::1'],
            'just above fc00::/7' => ['fe00::1'],
        ];
    }

    /**
     * @dataProvider untrustedPeerProvider
     */
    public function testPlainHttpFromOutsideTheTrustedNetworksIsExternal(string $peer): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, $peer),
            $this->classify(['REMOTE_ADDR' => $peer])
        );
    }

    /**
     * Security: forwarded headers are client-controlled unless a trusted
     * proxy set them. A remote runner sending them itself is still flagged,
     * with its real address.
     */
    public function testAPublicPeerCannotClaimHttpsOrAPrivateAddressThroughHeaders(): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, '203.0.113.10'),
            $this->classify([
                'REMOTE_ADDR' => '203.0.113.10',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_FORWARDED' => 'for=10.0.0.4;proto=https',
                'HTTP_X_FORWARDED_SSL' => 'on',
                'HTTP_FRONT_END_HTTPS' => 'on',
                'HTTP_X_FORWARDED_FOR' => '10.0.0.4',
            ])
        );
    }

    // -- classify(): trusted proxies -------------------------------------------

    public function testATrustedProxyTerminatingTlsMakesItHttpsForTheForwardedClient(): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTPS, '198.51.100.7'),
            $this->classify([
                'REMOTE_ADDR' => '172.18.0.1',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
            ])
        );
    }

    public function testATrustedProxyForwardingPlainHttpForAPublicClientIsExternal(): void
    {
        $expected = self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, '198.51.100.7');

        $this->assertSame($expected, $this->classify([
            'REMOTE_ADDR' => '172.18.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'http',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
        ]));
        $this->assertSame($expected, $this->classify([
            'REMOTE_ADDR' => '172.18.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
        ]), 'a proxy that sends no protocol header at all');
    }

    public function testATrustedProxyForwardingPlainHttpForAPrivateClientIsInternal(): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_INTERNAL, '10.0.0.9'),
            $this->classify([
                'REMOTE_ADDR' => '172.18.0.1',
                'HTTP_X_FORWARDED_PROTO' => 'http',
                'HTTP_X_FORWARDED_FOR' => '10.0.0.9',
            ])
        );
    }

    /**
     * Each proxy appends the address it got the request from, so only the
     * right-most address outside the trusted networks is reliable; anything
     * left of it came from the client.
     */
    public function testTheRightMostUntrustedForwardedAddressIsTheClient(): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, '203.0.113.99'),
            $this->classify([
                'REMOTE_ADDR' => '172.18.0.1',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 203.0.113.99, 10.0.0.2',
            ])
        );
    }

    /**
     * Security: a public client behind a trusted proxy cannot pass for an
     * internal one by sending its own X-Forwarded-For with a private
     * address; the proxy keeps that in front of the address it appends.
     */
    public function testAClientCannotHideBehindAPrivateAddressItPrependedItself(): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, '198.51.100.7'),
            $this->classify([
                'REMOTE_ADDR' => '172.18.0.1',
                'HTTP_X_FORWARDED_FOR' => '10.0.0.4, 198.51.100.7',
            ])
        );
    }

    public function testAnAllTrustedChainYieldsTheLeftMostAddress(): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_INTERNAL, '10.0.0.5'),
            $this->classify([
                'REMOTE_ADDR' => '172.18.0.1',
                'HTTP_X_FORWARDED_FOR' => '10.0.0.5, 192.168.1.4, 172.18.0.3',
            ])
        );
    }

    public function testInvalidForwardedAddressesAreSkipped(): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, '198.51.100.7'),
            $this->classify([
                'REMOTE_ADDR' => '172.18.0.1',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.7, unknown, , 300.1.1.1',
            ])
        );
    }

    /**
     * Regression: the client address came from X-Forwarded-For only. Behind
     * a trusted proxy that sends just "Forwarded" (RFC 7239), a public
     * plain-HTTP runner showed as the proxy's private address, and the
     * warning was hidden.
     */
    public function testATrustedProxySendingOnlyForwardedNamesTheClient(): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, '198.51.100.7'),
            $this->classify(['REMOTE_ADDR' => '172.18.0.1', 'HTTP_FORWARDED' => 'for=198.51.100.7;proto=http'])
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function forwardedForProvider(): array
    {
        return [
            'right-most untrusted element' => ['for=10.0.0.4, for=198.51.100.7', RunnerTransportClassifier::HTTP_EXTERNAL, '198.51.100.7'],
            'quoted IPv4 with port' => ['for="198.51.100.7:8080"', RunnerTransportClassifier::HTTP_EXTERNAL, '198.51.100.7'],
            'bracketed IPv6 with port' => ['for="[2001:db8:cafe::17]:4711";proto=https', RunnerTransportClassifier::HTTPS, '2001:db8:cafe::17'],
            'upper case, with by= and host=' => ['By=172.18.0.1;For=198.51.100.7;Host=ansilume.example.com', RunnerTransportClassifier::HTTP_EXTERNAL, '198.51.100.7'],
            'all trusted' => ['for=10.0.0.5, for=192.168.1.4', RunnerTransportClassifier::HTTP_INTERNAL, '10.0.0.5'],
            'unknown and obfuscated nodes' => ['for=unknown, for=_hidden', RunnerTransportClassifier::HTTP_INTERNAL, '172.18.0.1'],
        ];
    }

    /**
     * @dataProvider forwardedForProvider
     */
    public function testTheForwardedHeaderNamesTheClient(string $forwarded, string $transport, string $client): void
    {
        $this->assertSame(
            self::seen($transport, $client),
            $this->classify(['REMOTE_ADDR' => '172.18.0.1', 'HTTP_FORWARDED' => $forwarded])
        );
    }

    /**
     * Regression: entries with a port (Azure Application Gateway, IIS ARR)
     * counted as invalid, so the gateway's private address stood in for the
     * client and plain HTTP from the internet showed as internal.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function forwardedForWithPortProvider(): array
    {
        return [
            'IPv4 with port' => ['203.0.113.7:51234', '203.0.113.7'],
            'bracketed IPv6 with port' => ['[2001:db8::7]:443', '2001:db8::7'],
        ];
    }

    /**
     * @dataProvider forwardedForWithPortProvider
     */
    public function testXForwardedForEntriesWithAPortNameTheClient(string $entry, string $client): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, $client),
            $this->classify(['REMOTE_ADDR' => '10.1.0.4', 'HTTP_X_FORWARDED_PROTO' => 'http', 'HTTP_X_FORWARDED_FOR' => $entry])
        );
    }

    /**
     * Regression: a client sent its own "X-Forwarded-Proto: https" and the
     * proxy appended "http"; the left-most entry won. Entries pair with the
     * X-Forwarded-For entries counted from the right.
     */
    public function testAProtoTheClientPrependedDoesNotCount(): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, '198.51.100.7'),
            $this->classify(['REMOTE_ADDR' => '172.18.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7', 'HTTP_X_FORWARDED_PROTO' => 'https, http'])
        );
    }

    /**
     * Regression: proxies that send only Forwarded lost to headers the
     * client added, and the proto came from the first element, which the
     * client controls. Each family now counts on its own and the least
     * secure answer wins.
     *
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function spoofedBehindAForwardedProxyProvider(): array
    {
        return [
            'client X-Forwarded-Proto https' => [['HTTP_X_FORWARDED_PROTO' => 'https'], '203.0.113.7'],
            'client X-Forwarded-For with a private address' => [['HTTP_X_FORWARDED_FOR' => '10.0.0.5'], '203.0.113.7'],
            'transparent proxy upstream adds a private X-Forwarded-For' => [['HTTP_X_FORWARDED_FOR' => '192.168.10.25'], '203.0.113.7'],
            'client X-Forwarded-Ssl on' => [['HTTP_X_FORWARDED_SSL' => 'on'], '203.0.113.7'],
        ];
    }

    /**
     * @dataProvider spoofedBehindAForwardedProxyProvider
     * @param array<string, string> $clientHeaders
     */
    public function testHeadersTheClientAddsCannotHideTheWarningBehindAForwardedProxy(array $clientHeaders, string $client): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, $client),
            $this->classify(['REMOTE_ADDR' => '172.18.0.1', 'HTTP_FORWARDED' => 'for=203.0.113.7;proto=http'] + $clientHeaders)
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function forwardedChainProtoProvider(): array
    {
        return [
            // An appending proxy keeps the element the client sent in front of its own.
            'client-prepended element' => ['for=10.0.0.4;proto=https, for=203.0.113.7;proto=http', '203.0.113.7'],
            // TLS at an edge on the internet, then plain HTTP to the trusted proxy: credentials in clear.
            'plain HTTP after a TLS edge' => ['for=203.0.113.50;proto=https, for=198.51.100.1;proto=http', '198.51.100.1'],
        ];
    }

    /**
     * Regression: the client came from the right-most untrusted element but
     * the proto from the first element.
     *
     * @dataProvider forwardedChainProtoProvider
     */
    public function testTheProtoComesFromTheClientsForwardedElement(string $forwarded, string $client): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, $client),
            $this->classify(['REMOTE_ADDR' => '172.18.0.1', 'HTTP_FORWARDED' => $forwarded])
        );
    }

    public function testAForwardedProxyStatingOnlyTheProtoMakesItHttps(): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTPS, '198.51.100.7'),
            $this->classify([
                'REMOTE_ADDR' => '172.18.0.1',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
                'HTTP_FORWARDED' => 'proto=https;host=ansilume.example.com',
            ])
        );
    }

    public function testXForwardedForWinsOverForwarded(): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, '203.0.113.9'),
            $this->classify([
                'REMOTE_ADDR' => '172.18.0.1',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
                'HTTP_FORWARDED' => 'for=198.51.100.7',
            ])
        );
    }

    public function testAPublicPeerCannotClaimAPrivateAddressThroughForwarded(): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, '203.0.113.10'),
            $this->classify(['REMOTE_ADDR' => '203.0.113.10', 'HTTP_FORWARDED' => 'for=10.0.0.4;proto=https'])
        );
    }

    public function testWithoutAUsableForwardedAddressThePeerIsTheClient(): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_INTERNAL, '172.18.0.1'),
            $this->classify(['REMOTE_ADDR' => '172.18.0.1', 'HTTP_X_FORWARDED_FOR' => 'unknown, _hidden'])
        );
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function forwardedHttpsProvider(): array
    {
        return [
            'X-Forwarded-Proto' => [['HTTP_X_FORWARDED_PROTO' => 'https']],
            'X-Forwarded-Proto, upper case and padded' => [['HTTP_X_FORWARDED_PROTO' => ' HTTPS ']],
            'X-Forwarded-Proto list paired with the X-Forwarded-For entries' => [
                ['HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.2', 'HTTP_X_FORWARDED_PROTO' => 'https, http'],
            ],
            'Forwarded' => [['HTTP_FORWARDED' => 'for=198.51.100.7;proto=https;by=172.18.0.1']],
            'Forwarded, quoted' => [['HTTP_FORWARDED' => 'for="198.51.100.7";proto="https"']],
            'Forwarded, upper case' => [['HTTP_FORWARDED' => 'For=198.51.100.7;Proto=HTTPS']],
            'X-Forwarded-Ssl' => [['HTTP_X_FORWARDED_SSL' => 'on']],
            'X-Forwarded-Ssl, upper case' => [['HTTP_X_FORWARDED_SSL' => 'ON']],
            'Front-End-Https' => [['HTTP_FRONT_END_HTTPS' => 'on']],
        ];
    }

    /**
     * @dataProvider forwardedHttpsProvider
     * @param array<string, string> $headers
     */
    public function testEveryCommonProxyHttpsSignalIsHonouredFromATrustedPeer(array $headers): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTPS, '198.51.100.7'),
            $this->classify($headers + ['REMOTE_ADDR' => '172.18.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'])
        );
    }

    /**
     * A proxy usually sets one of these headers and passes the others through
     * from the client, so the first header that says anything wins: a client
     * cannot add "X-Forwarded-Ssl: on" to a proxy that sets X-Forwarded-Proto.
     *
     * @return array<string, array{0: array<string, string>}>
     */
    public static function forwardedPlainProvider(): array
    {
        return [
            'X-Forwarded-Proto http' => [['HTTP_X_FORWARDED_PROTO' => 'http']],
            'Forwarded proto=http' => [['HTTP_FORWARDED' => 'for=198.51.100.7;proto=http']],
            'X-Forwarded-Ssl off' => [['HTTP_X_FORWARDED_SSL' => 'off']],
            'Front-End-Https off' => [['HTTP_FRONT_END_HTTPS' => 'off']],
            'X-Forwarded-Proto http beats X-Forwarded-Ssl on' => [
                ['HTTP_X_FORWARDED_PROTO' => 'http', 'HTTP_X_FORWARDED_SSL' => 'on'],
            ],
            'X-Forwarded-Proto http beats Forwarded proto=https' => [
                ['HTTP_X_FORWARDED_PROTO' => 'http', 'HTTP_FORWARDED' => 'proto=https'],
            ],
            'Forwarded proto=http beats Front-End-Https on' => [
                ['HTTP_FORWARDED' => 'proto=http', 'HTTP_FRONT_END_HTTPS' => 'on'],
            ],
        ];
    }

    /**
     * @dataProvider forwardedPlainProvider
     * @param array<string, string> $headers
     */
    public function testPlainHttpSignalsFromATrustedProxyKeepAPublicClientExternal(array $headers): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, '198.51.100.7'),
            $this->classify(['REMOTE_ADDR' => '172.18.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'] + $headers)
        );
    }

    // -- classify(): address families ------------------------------------------

    /**
     * A dual-stack listener reports IPv4 clients as ::ffff:a.b.c.d. Without
     * unwrapping, the bundled runners would match no IPv4 range and be
     * flagged as external.
     */
    public function testIpv4MappedPeerAddressesAreClassifiedAsIpv4(): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_INTERNAL, '10.0.0.7'),
            $this->classify(['REMOTE_ADDR' => '::ffff:10.0.0.7'])
        );
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_INTERNAL, '192.168.1.5'),
            $this->classify(['REMOTE_ADDR' => '::FFFF:192.168.1.5'])
        );
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, '203.0.113.10'),
            $this->classify(['REMOTE_ADDR' => '::ffff:203.0.113.10'])
        );
    }

    /**
     * Regression: only the compressed "::ffff:a.b.c.d" form was unwrapped;
     * the same address written out in full or in hex counted as external.
     */
    public function testIpv4MappedAddressesInEveryNotationAreClassifiedAsIpv4(): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_INTERNAL, '10.0.0.1'),
            $this->classify(['REMOTE_ADDR' => '0:0:0:0:0:ffff:10.0.0.1'])
        );
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_INTERNAL, '10.0.0.1'),
            $this->classify(['REMOTE_ADDR' => '::ffff:a00:1'])
        );
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, '2001:db8::ffff:a00:1'),
            $this->classify(['REMOTE_ADDR' => '2001:db8::ffff:a00:1']),
            'an IPv6 address that only ends like a mapped one stays IPv6'
        );
    }

    public function testIpv4MappedForwardedAddressesAreClassifiedAsIpv4(): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, '198.51.100.7'),
            $this->classify([
                'REMOTE_ADDR' => '::ffff:172.18.0.1',
                'HTTP_X_FORWARDED_FOR' => '::ffff:198.51.100.7, ::ffff:10.0.0.2',
            ])
        );
    }

    public function testAnIpv6UniqueLocalProxyIsTrusted(): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTPS, '2001:db8::7'),
            $this->classify([
                'REMOTE_ADDR' => 'fd00:1::2',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_FOR' => '2001:db8::7',
            ])
        );
    }

    public function testRangesNeverMatchAddressesOfTheOtherFamily(): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, '10.0.0.1'),
            $this->classify(['REMOTE_ADDR' => '10.0.0.1'], RunnerTransportClassifier::networks('::/0'))
        );
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, '2001:db8::1'),
            $this->classify(['REMOTE_ADDR' => '2001:db8::1'], RunnerTransportClassifier::networks('0.0.0.0/0'))
        );
    }

    // -- classify(): custom trusted networks -----------------------------------

    public function testCustomTrustedNetworksReplaceTheDefaults(): void
    {
        $trusted = RunnerTransportClassifier::networks('172.16.0.0/12');

        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, '192.168.1.20'),
            $this->classify(['REMOTE_ADDR' => '192.168.1.20'], $trusted),
            'a 192.168 runner is outside the narrowed list'
        );
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, '127.0.0.1'),
            $this->classify(['REMOTE_ADDR' => '127.0.0.1'], $trusted),
            'loopback is no longer implied'
        );
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_INTERNAL, '172.18.0.5'),
            $this->classify(['REMOTE_ADDR' => '172.18.0.5'], $trusted)
        );
    }

    public function testAProxyOutsideTheCustomNetworksIsNotBelieved(): void
    {
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, '10.0.0.1'),
            $this->classify([
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_FOR' => '172.18.0.9',
            ], RunnerTransportClassifier::networks('172.18.0.0/16'))
        );
    }

    public function testASingleProxyAddressCanBeTrusted(): void
    {
        $trusted = RunnerTransportClassifier::networks('203.0.113.5');

        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTPS, '198.51.100.7'),
            $this->classify([
                'REMOTE_ADDR' => '203.0.113.5',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
            ], $trusted)
        );
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, '203.0.113.6'),
            $this->classify(['REMOTE_ADDR' => '203.0.113.6', 'HTTP_X_FORWARDED_PROTO' => 'https'], $trusted),
            'its neighbour is not trusted'
        );
    }

    public function testCustomIpv6RangesAreHonoured(): void
    {
        $trusted = RunnerTransportClassifier::networks('2001:db8:42::/48');

        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_INTERNAL, '2001:db8:42::9'),
            $this->classify(['REMOTE_ADDR' => '2001:db8:42::9'], $trusted)
        );
        $this->assertSame(
            self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, '2001:db8:43::1'),
            $this->classify(['REMOTE_ADDR' => '2001:db8:43::1'], $trusted)
        );
    }

    // -- changes() --------------------------------------------------------------

    public function testNothingChangesWhenTransportAndAddressAreUnchanged(): void
    {
        $this->assertSame([], $this->changes(
            self::seen(RunnerTransportClassifier::HTTP_INTERNAL, '172.18.0.5'),
            ['REMOTE_ADDR' => '172.18.0.5']
        ));
        $this->assertSame([], $this->changes(
            self::seen(RunnerTransportClassifier::HTTPS, '203.0.113.10'),
            ['REMOTE_ADDR' => '203.0.113.10', 'HTTPS' => 'on']
        ));
    }

    public function testTheFirstRequestRecordsTransportAndAddress(): void
    {
        $this->assertSame(
            ['transport' => RunnerTransportClassifier::HTTP_INTERNAL, 'remote_addr' => '172.18.0.5'],
            $this->changes(self::seen(null, null), ['REMOTE_ADDR' => '172.18.0.5'])
        );
    }

    public function testOnlyTheColumnThatChangedIsReturned(): void
    {
        $this->assertSame(
            ['remote_addr' => '172.18.0.9'],
            $this->changes(
                self::seen(RunnerTransportClassifier::HTTP_INTERNAL, '172.18.0.5'),
                ['REMOTE_ADDR' => '172.18.0.9']
            ),
            'new address, same transport'
        );
        $this->assertSame(
            ['transport' => RunnerTransportClassifier::HTTPS],
            $this->changes(
                self::seen(RunnerTransportClassifier::HTTP_INTERNAL, '172.18.0.1'),
                ['REMOTE_ADDR' => '172.18.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https']
            ),
            'same address, new transport'
        );
    }

    public function testEveryPlainHttpRequestFromOutsideStampsPlaintextSeenAt(): void
    {
        $this->assertSame(
            [
                'transport' => RunnerTransportClassifier::HTTP_EXTERNAL,
                'remote_addr' => '203.0.113.10',
                'plaintext_seen_at' => self::NOW,
            ],
            $this->changes(self::seen(null, null), ['REMOTE_ADDR' => '203.0.113.10'])
        );
        $this->assertSame(
            ['plaintext_seen_at' => self::NOW + 30],
            $this->changes(
                self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, '203.0.113.10'),
                ['REMOTE_ADDR' => '203.0.113.10'],
                null,
                self::NOW + 30
            ),
            'stamped again although transport and address are unchanged'
        );
    }

    /**
     * plaintext_seen_at is history: operators need it after the fix to know
     * which credentials to rotate, so moving to HTTPS must not clear it.
     */
    public function testMovingToHttpsLeavesThePlaintextHistoryAlone(): void
    {
        $this->assertSame(
            ['transport' => RunnerTransportClassifier::HTTPS],
            $this->changes(
                self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, '203.0.113.10'),
                ['REMOTE_ADDR' => '203.0.113.10', 'HTTPS' => 'on']
            )
        );
    }

    public function testAnUnknownPeerChangesNothing(): void
    {
        $this->assertSame([], $this->changes(
            self::seen(RunnerTransportClassifier::HTTP_EXTERNAL, '203.0.113.10'),
            []
        ), 'the last known values are kept, not overwritten with null');
        $this->assertSame([], $this->changes(
            self::seen(null, null),
            ['REMOTE_ADDR' => 'garbage', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7']
        ));
    }

    public function testChangesUseTheTrustedNetworksPassedIn(): void
    {
        $this->assertSame(
            [
                'transport' => RunnerTransportClassifier::HTTP_EXTERNAL,
                'remote_addr' => '192.168.1.20',
                'plaintext_seen_at' => self::NOW,
            ],
            $this->changes(
                self::seen(null, null),
                ['REMOTE_ADDR' => '192.168.1.20'],
                RunnerTransportClassifier::networks('172.16.0.0/12')
            )
        );
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * @param array<string, mixed> $server
     * @param list<string>|null $trusted the defaults when null
     * @return array{transport: string|null, remote_addr: string|null}
     */
    private function classify(array $server, ?array $trusted = null): array
    {
        return RunnerTransportClassifier::classify($server, $trusted ?? RunnerTransportClassifier::DEFAULT_TRUSTED_NETWORKS);
    }

    /**
     * @param array{transport: string|null, remote_addr: string|null} $current
     * @param array<string, mixed> $server
     * @param list<string>|null $trusted the defaults when null
     * @return array<string, string|int>
     */
    private function changes(array $current, array $server, ?array $trusted = null, int $now = self::NOW): array
    {
        return RunnerTransportClassifier::changes(
            $current,
            $server,
            $trusted ?? RunnerTransportClassifier::DEFAULT_TRUSTED_NETWORKS,
            $now
        );
    }

    /**
     * @return array{transport: string|null, remote_addr: string|null}
     */
    private static function seen(?string $transport, ?string $remoteAddr): array
    {
        return ['transport' => $transport, 'remote_addr' => $remoteAddr];
    }
}
