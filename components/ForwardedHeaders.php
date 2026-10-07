<?php

declare(strict_types=1);

namespace app\components;

/**
 * Reads the forwarding headers of a request, without deciding whom to
 * trust ({@see RunnerTransportClassifier} does that).
 *
 * Each header family becomes one chain of hops in header order, client side
 * first: X-Forwarded-For with X-Forwarded-Proto, and Forwarded (RFC 7239).
 * A proxy appends the address it got the request from, so the right-most
 * hops are the ones closest to the server.
 */
final class ForwardedHeaders
{
    /**
     * The chains of the families the request carries. A hop's "for" is the
     * node without quotes, brackets and port, or null when the element names
     * none; its "proto" is in lower case, or null when unknown.
     *
     * - X-Forwarded-Proto entries pair with X-Forwarded-For entries counted
     *   from the right; hops further left get the left-most entry. Without
     *   X-Forwarded-For, the chain is one hop with the right-most entry.
     * - Each Forwarded element is a hop with its own for= and proto=.
     *
     * @param array<array-key, mixed> $server
     * @return list<list<array{for: string|null, proto: string|null}>>
     */
    public static function chains(array $server): array
    {
        $chains = [];
        $forwardedFor = self::header($server, 'HTTP_X_FORWARDED_FOR');
        $forwardedProto = self::header($server, 'HTTP_X_FORWARDED_PROTO');
        if ($forwardedFor !== '' || $forwardedProto !== '') {
            $chains[] = self::xForwardedChain($forwardedFor, $forwardedProto);
        }
        $forwarded = self::header($server, 'HTTP_FORWARDED');
        if ($forwarded !== '') {
            $chains[] = array_map(
                static fn (string $element): array => [
                    'for' => self::node(self::parameter($element, 'for')),
                    'proto' => self::lower(self::parameter($element, 'proto')),
                ],
                explode(',', $forwarded)
            );
        }

        return $chains;
    }

    /**
     * True when X-Forwarded-Ssl or Front-End-Https says "on": proxies that
     * set neither X-Forwarded-Proto nor Forwarded use these.
     *
     * @param array<array-key, mixed> $server
     */
    public static function sslFlagged(array $server): bool
    {
        return strtolower(self::header($server, 'HTTP_X_FORWARDED_SSL')) === 'on'
            || strtolower(self::header($server, 'HTTP_FRONT_END_HTTPS')) === 'on';
    }

    /**
     * @return list<array{for: string|null, proto: string|null}>
     */
    private static function xForwardedChain(string $forwardedFor, string $forwardedProto): array
    {
        $protos = $forwardedProto === '' ? [] : array_map(static fn (string $proto): ?string => self::lower($proto), explode(',', $forwardedProto));
        if ($forwardedFor === '') {
            return [['for' => null, 'proto' => $protos === [] ? null : $protos[count($protos) - 1]]];
        }
        $nodes = explode(',', $forwardedFor);
        $offset = count($protos) - count($nodes);
        $chain = [];
        foreach ($nodes as $index => $node) {
            $chain[] = ['for' => self::node($node), 'proto' => $protos[max(0, $index + $offset)] ?? null];
        }

        return $chain;
    }

    /**
     * One parameter of a Forwarded element, null when it is not there.
     */
    private static function parameter(string $element, string $name): ?string
    {
        return preg_match('/(?:^|;)\s*' . $name . '\s*=\s*([^;]*)/i', $element, $match) === 1 ? $match[1] : null;
    }

    /**
     * A node without quotes, IPv6 brackets and port: "198.51.100.7:8080"
     * gives 198.51.100.7 and "[2001:db8::7]:4711" gives 2001:db8::7.
     */
    private static function node(?string $value): ?string
    {
        $node = trim(trim((string)$value), '"');
        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $node, $match) === 1) {
            return $match[1];
        }
        $node = substr_count($node, ':') === 1 ? explode(':', $node)[0] : $node;

        return $node === '' ? null : $node;
    }

    private static function lower(?string $value): ?string
    {
        $lower = strtolower(trim(trim((string)$value), '"'));

        return $lower === '' ? null : $lower;
    }

    /**
     * @param array<array-key, mixed> $server
     */
    private static function header(array $server, string $key): string
    {
        return is_string($server[$key] ?? null) ? $server[$key] : '';
    }
}
