<?php

declare(strict_types=1);

namespace app\components;

use yii\helpers\IpHelper;

/**
 * Classifies how a runner request reached the server, from $_SERVER only.
 * The claim response carries decrypted credentials, so plain HTTP from
 * outside the trusted networks deserves a warning.
 *
 * - The TCP peer is the client unless it sits in a trusted network; only
 *   then do forwarding headers count, so a remote runner that connects
 *   directly cannot hide the warning by sending them itself.
 * - Bundled runners talk to nginx over the internal Docker network: their
 *   peer address is private, so they count as http_internal.
 * - Behind a trusted proxy, X-Forwarded-For with X-Forwarded-Proto and
 *   Forwarded (RFC 7239) name the client and its protocol. Each family is
 *   read on its own and the least secure answer wins, so a header that the
 *   proxy passes through from the client can add a warning but not remove
 *   one, as long as the proxy sets the family it uses.
 *
 * Visibility only: a client inside a trusted network, or behind a proxy that
 * passes forwarding headers through unchanged, can claim https, so this must
 * never decide whether credentials are sent.
 */
final class RunnerTransportClassifier
{
    public const HTTPS = 'https';
    public const HTTP_INTERNAL = 'http_internal';
    public const HTTP_EXTERNAL = 'http_external';
    public const TRANSPORTS = [self::HTTPS, self::HTTP_INTERNAL, self::HTTP_EXTERNAL];

    /** Loopback, RFC 1918 and IPv6 unique local: Docker networks and host-side proxies. */
    public const DEFAULT_TRUSTED_NETWORKS = ['127.0.0.0/8', '::1/128', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7'];

    /**
     * The trusted networks from RUNNER_TRUSTED_NETWORKS (comma-separated IP
     * addresses or CIDR ranges); the defaults when unset. A value replaces
     * the defaults. Invalid entries are ignored, so the defaults also apply
     * when no entry is valid.
     *
     * @return list<string>
     */
    public static function networks(string $configured): array
    {
        $networks = array_values(array_filter(
            array_map('trim', explode(',', $configured)),
            static fn (string $entry): bool => self::isNetwork($entry)
        ));

        return $networks === [] ? self::DEFAULT_TRUSTED_NETWORKS : $networks;
    }

    /**
     * The entries of RUNNER_TRUSTED_NETWORKS that networks() ignores, so
     * bin/diagnose can name them.
     *
     * @return list<string>
     */
    public static function ignoredEntries(string $configured): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $configured)),
            static fn (string $entry): bool => $entry !== '' && !self::isNetwork($entry)
        ));
    }

    /**
     * @param array<array-key, mixed> $server $_SERVER
     * @param list<string> $trusted
     * @return array{transport: string|null, remote_addr: string|null} nulls when the peer is unknown
     */
    public static function classify(array $server, array $trusted): array
    {
        $peer = self::ip($server['REMOTE_ADDR'] ?? null);
        if ($peer === null) {
            return ['transport' => null, 'remote_addr' => null];
        }
        if (in_array(strtolower(self::header($server, 'HTTPS')), ['on', '1'], true)) {
            return ['transport' => self::HTTPS, 'remote_addr' => $peer];
        }
        if (!self::inNetworks($peer, $trusted)) {
            return ['transport' => self::HTTP_EXTERNAL, 'remote_addr' => $peer];
        }
        $claims = array_map(static fn (array $chain): array => self::claim($chain, $trusted), ForwardedHeaders::chains($server));
        $client = self::client($claims, $trusted) ?? $peer;
        if (self::proto($claims, ForwardedHeaders::sslFlagged($server)) === 'https') {
            return ['transport' => self::HTTPS, 'remote_addr' => $client];
        }

        return ['transport' => self::inNetworks($client, $trusted) ? self::HTTP_INTERNAL : self::HTTP_EXTERNAL, 'remote_addr' => $client];
    }

    /**
     * The runner columns to update for this request: transport and
     * remote_addr when they changed, plaintext_seen_at on every plain HTTP
     * request from outside the trusted networks.
     *
     * @param array{transport: string|null, remote_addr: string|null} $current
     * @param array<array-key, mixed> $server
     * @param list<string> $trusted
     * @return array<string, string|int>
     */
    public static function changes(array $current, array $server, array $trusted, int $now): array
    {
        $seen = self::classify($server, $trusted);
        $changes = [];
        foreach ($seen as $column => $value) {
            if ($value !== null && $value !== $current[$column]) {
                $changes[$column] = $value;
            }
        }
        if ($seen['transport'] === self::HTTP_EXTERNAL) {
            $changes['plaintext_seen_at'] = $now;
        }

        return $changes;
    }

    /**
     * What one header chain says: its client's hop, the right-most one whose
     * address lies outside the trusted networks, else the left-most one with
     * an address. Each proxy appends the address it got the request from, so
     * anything left of the right-most untrusted address came from the client.
     * Without any address, the protocol of the hop closest to the server.
     *
     * @param list<array{for: string|null, proto: string|null}> $chain
     * @param list<string> $trusted
     * @return array{for: string|null, proto: string|null}
     */
    private static function claim(array $chain, array $trusted): array
    {
        $hops = [];
        foreach ($chain as $hop) {
            $address = self::ip($hop['for']);
            if ($address !== null) {
                $hops[] = ['for' => $address, 'proto' => $hop['proto']];
            }
        }
        foreach (array_reverse($hops) as $hop) {
            if (!self::inNetworks($hop['for'], $trusted)) {
                return $hop;
            }
        }

        return $hops[0] ?? ['for' => null, 'proto' => $chain[count($chain) - 1]['proto'] ?? null];
    }

    /**
     * The client the chains name: the first one outside the trusted
     * networks, else the first one; null when none names a client.
     *
     * @param list<array{for: string|null, proto: string|null}> $claims
     * @param list<string> $trusted
     */
    private static function client(array $claims, array $trusted): ?string
    {
        $clients = array_values(array_filter(array_column($claims, 'for'), static fn (?string $ip): bool => $ip !== null));
        foreach ($clients as $client) {
            if (!self::inNetworks($client, $trusted)) {
                return $client;
            }
        }

        return $clients[0] ?? null;
    }

    /**
     * The least secure protocol the chains state; X-Forwarded-Ssl or
     * Front-End-Https count only when no chain states one.
     *
     * @param list<array{for: string|null, proto: string|null}> $claims
     */
    private static function proto(array $claims, bool $sslFlagged): ?string
    {
        $stated = array_values(array_filter(array_column($claims, 'proto'), static fn (?string $proto): bool => $proto !== null));
        if ($stated !== []) {
            return array_diff($stated, ['https']) === [] ? 'https' : 'http';
        }

        return $sslFlagged ? 'https' : null;
    }

    /**
     * An IP address, optionally with a prefix length that fits its family.
     */
    private static function isNetwork(string $entry): bool
    {
        $parts = explode('/', $entry, 2);
        if (filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $maxPrefix = str_contains($parts[0], ':') ? 128 : 32;

        return !isset($parts[1]) || (ctype_digit($parts[1]) && (int)$parts[1] <= $maxPrefix);
    }

    /**
     * @param list<string> $networks
     */
    private static function inNetworks(string $ip, array $networks): bool
    {
        foreach ($networks as $network) {
            if (IpHelper::getIpVersion($ip) === IpHelper::getIpVersion(explode('/', $network, 2)[0]) && IpHelper::inRange($ip, $network)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A valid IP address; IPv4-mapped IPv6 addresses (::ffff:10.0.0.7, in any
     * notation) as plain IPv4.
     */
    private static function ip(mixed $value): ?string
    {
        $ip = is_string($value) ? trim($value) : '';
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = (string)inet_pton($ip);
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return (string)inet_ntop(substr($packed, 12));
        }

        return substr($ip, 0, 45);
    }

    /**
     * @param array<array-key, mixed> $server
     */
    private static function header(array $server, string $key): string
    {
        return is_string($server[$key] ?? null) ? $server[$key] : '';
    }
}
