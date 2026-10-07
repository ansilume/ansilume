<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\ForwardedHeaders;
use PHPUnit\Framework\TestCase;

/**
 * ForwardedHeaders turns X-Forwarded-For / X-Forwarded-Proto and Forwarded
 * (RFC 7239) into hop chains, client side first, without deciding whom to
 * trust. RunnerTransportClassifierTest covers the decisions built on them.
 */
class ForwardedHeadersTest extends TestCase
{
    public function testARequestWithoutForwardingHeadersHasNoChains(): void
    {
        $this->assertSame([], ForwardedHeaders::chains(['REMOTE_ADDR' => '172.18.0.1']));
    }

    public function testXForwardedForWithoutProtoGivesHopsWithoutProto(): void
    {
        $this->assertSame(
            [[self::hop('198.51.100.7', null), self::hop('10.0.0.2', null)]],
            ForwardedHeaders::chains(['HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.2'])
        );
    }

    /**
     * Entries pair from the right; hops further left get the left-most
     * entry, the one for the client by convention.
     *
     * @return array<string, array{0: string, 1: string, 2: list<array{for: string|null, proto: string|null}>}>
     */
    public static function protoPairingProvider(): array
    {
        return [
            'one each' => ['198.51.100.7', 'HTTPS', [self::hop('198.51.100.7', 'https')]],
            'as many protos as addresses' => ['198.51.100.7, 10.0.0.2', 'https, http', [self::hop('198.51.100.7', 'https'), self::hop('10.0.0.2', 'http')]],
            'fewer protos than addresses' => ['198.51.100.7, 10.0.0.2, 10.0.0.3', 'https, http', [
                self::hop('198.51.100.7', 'https'),
                self::hop('10.0.0.2', 'https'),
                self::hop('10.0.0.3', 'http'),
            ]],
            'more protos than addresses' => ['198.51.100.7', 'https, http', [self::hop('198.51.100.7', 'http')]],
        ];
    }

    /**
     * @dataProvider protoPairingProvider
     * @param list<array{for: string|null, proto: string|null}> $expected
     */
    public function testXForwardedProtoEntriesPairWithTheAddressesFromTheRight(string $for, string $proto, array $expected): void
    {
        $this->assertSame([$expected], ForwardedHeaders::chains(['HTTP_X_FORWARDED_FOR' => $for, 'HTTP_X_FORWARDED_PROTO' => $proto]));
    }

    public function testXForwardedProtoAloneIsOneHopWithTheRightMostEntry(): void
    {
        $this->assertSame([[self::hop(null, 'https')]], ForwardedHeaders::chains(['HTTP_X_FORWARDED_PROTO' => 'http, https']));
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function nodeProvider(): array
    {
        return [
            'IPv4' => ['198.51.100.7', '198.51.100.7'],
            'IPv4 with port' => ['198.51.100.7:8080', '198.51.100.7'],
            'bracketed IPv6' => ['[2001:db8::7]', '2001:db8::7'],
            'bracketed IPv6 with port' => ['[2001:db8::7]:4711', '2001:db8::7'],
            'bare IPv6' => ['2001:db8::7', '2001:db8::7'],
            'padded' => ['  198.51.100.7 ', '198.51.100.7'],
            'empty' => [' ', null],
        ];
    }

    /**
     * @dataProvider nodeProvider
     */
    public function testXForwardedForNodesLoseTheirPortAndBrackets(string $entry, ?string $expected): void
    {
        $this->assertSame([[self::hop($expected, null)]], ForwardedHeaders::chains(['HTTP_X_FORWARDED_FOR' => $entry]));
    }

    public function testEachForwardedElementIsAHopWithItsOwnForAndProto(): void
    {
        $this->assertSame(
            [[
                self::hop('2001:db8::7', 'https'),
                self::hop('198.51.100.7', null),
                self::hop(null, 'http'),
            ]],
            ForwardedHeaders::chains([
                'HTTP_FORWARDED' => 'for="[2001:db8::7]:4711";proto=HTTPS, By=172.18.0.1;For=198.51.100.7:8080;host=x, proto="http"',
            ])
        );
    }

    public function testBothFamiliesGiveOneChainEachXForwardedFirst(): void
    {
        $this->assertSame(
            [[self::hop('203.0.113.9', 'http')], [self::hop('198.51.100.7', 'https')]],
            ForwardedHeaders::chains([
                'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
                'HTTP_X_FORWARDED_PROTO' => 'http',
                'HTTP_FORWARDED' => 'for=198.51.100.7;proto=https',
            ])
        );
    }

    public function testNonStringHeadersAreIgnored(): void
    {
        $this->assertSame([], ForwardedHeaders::chains(['HTTP_X_FORWARDED_FOR' => ['198.51.100.7'], 'HTTP_FORWARDED' => 42]));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function sslFlagProvider(): array
    {
        return [
            'X-Forwarded-Ssl on' => [['HTTP_X_FORWARDED_SSL' => 'on'], true],
            'X-Forwarded-Ssl upper case' => [['HTTP_X_FORWARDED_SSL' => 'ON'], true],
            'Front-End-Https on' => [['HTTP_FRONT_END_HTTPS' => 'on'], true],
            'X-Forwarded-Ssl off' => [['HTTP_X_FORWARDED_SSL' => 'off'], false],
            'Front-End-Https off' => [['HTTP_FRONT_END_HTTPS' => 'off'], false],
            'neither' => [[], false],
        ];
    }

    /**
     * @dataProvider sslFlagProvider
     * @param array<string, mixed> $server
     */
    public function testSslFlagged(array $server, bool $expected): void
    {
        $this->assertSame($expected, ForwardedHeaders::sslFlagged($server));
    }

    /**
     * @return array{for: string|null, proto: string|null}
     */
    private static function hop(?string $for, ?string $proto): array
    {
        return ['for' => $for, 'proto' => $proto];
    }
}
