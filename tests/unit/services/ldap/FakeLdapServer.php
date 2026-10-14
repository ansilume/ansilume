<?php

declare(strict_types=1);

namespace app\tests\unit\services\ldap;

/**
 * A minimal LDAP server in a child process, so that tests drive the real
 * PhpLdapClient (PHP's ldap extension, libldap) without a directory.
 *
 * It speaks just enough LDAPv3 (RFC 4511, BER encoded) for a simple bind and
 * a search: every bind request takes the next result code of `binds`
 * (default 0, success), every search request the next answer of `searches`
 * (default: no entry), an unbind request ends the connection. Other requests
 * get no answer. It serves one connection after another until stopped.
 *
 * A search answer is `['code' => int, 'message' => string, 'entries' => list<array{dn: string, attrs: array<string, list<string>>}>]`,
 * each key optional: the entries come first, then the result with the code
 * (0 success, 4 size limit exceeded, 32 no such object, ...).
 */
final class FakeLdapServer
{
    private const TAG_SEQUENCE = 0x30;
    private const TAG_SET = 0x31;
    private const TAG_INTEGER = 0x02;
    private const TAG_ENUMERATED = 0x0A;
    private const TAG_OCTET_STRING = 0x04;
    private const OP_BIND_REQUEST = 0x60;
    private const OP_BIND_RESPONSE = 0x61;
    private const OP_UNBIND_REQUEST = 0x42;
    private const OP_SEARCH_REQUEST = 0x63;
    private const OP_SEARCH_ENTRY = 0x64;
    private const OP_SEARCH_DONE = 0x65;

    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    public readonly int $port;

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function __construct($process, array $pipes, int $port)
    {
        $this->process = $process;
        $this->pipes = $pipes;
        $this->port = $port;
    }

    /**
     * @param array{binds?: list<int>, searches?: list<array{code?: int, message?: string, entries?: list<array{dn: string, attrs: array<string, list<string>>}>}>} $script
     */
    public static function start(array $script): self
    {
        $code = 'require ' . var_export(dirname(__DIR__, 4) . '/vendor/autoload.php', true) . ';'
            . ' ' . self::class . '::serve(' . var_export($script, true) . ');';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('could not start the fake LDAP server');
        }
        $address = trim((string)fgets($pipes[1]));
        if (preg_match('/^127\.0\.0\.1:(\d+)$/', $address, $m) !== 1) {
            throw new \RuntimeException("fake LDAP server did not report its address: {$address}");
        }

        return new self($process, $pipes, (int)$m[1]);
    }

    public function stop(): void
    {
        if (!is_resource($this->process)) {
            return;
        }
        proc_terminate($this->process);
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($this->process);
    }

    /**
     * The server loop, run in the child process by start().
     *
     * @param array{binds?: list<int>, searches?: list<array{code?: int, message?: string, entries?: list<array{dn: string, attrs: array<string, list<string>>}>}>} $script
     */
    public static function serve(array $script): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            fwrite(STDOUT, "error: {$errstr}\n");
            return;
        }
        fwrite(STDOUT, stream_socket_get_name($server, false) . "\n");
        fflush(STDOUT);
        $binds = $script['binds'] ?? [];
        $searches = $script['searches'] ?? [];
        while (($conn = stream_socket_accept($server, 30)) !== false) {
            while (($request = self::readRequest($conn)) !== null) {
                [$id, $op] = $request;
                if ($op === self::OP_UNBIND_REQUEST) {
                    break;
                }
                if ($op === self::OP_BIND_REQUEST) {
                    fwrite($conn, self::message($id, self::result(self::OP_BIND_RESPONSE, array_shift($binds) ?? 0, '')));
                } elseif ($op === self::OP_SEARCH_REQUEST) {
                    fwrite($conn, self::searchAnswer($id, array_shift($searches) ?? []));
                }
            }
            fclose($conn);
        }
    }

    /**
     * @param array{code?: int, message?: string, entries?: list<array{dn: string, attrs: array<string, list<string>>}>} $answer
     */
    private static function searchAnswer(int $id, array $answer): string
    {
        $out = '';
        foreach ($answer['entries'] ?? [] as $entry) {
            $out .= self::message($id, self::entry($entry['dn'], $entry['attrs']));
        }
        return $out . self::message($id, self::result(self::OP_SEARCH_DONE, $answer['code'] ?? 0, $answer['message'] ?? ''));
    }

    /**
     * @param array<string, list<string>> $attrs
     */
    private static function entry(string $dn, array $attrs): string
    {
        $list = '';
        foreach ($attrs as $type => $values) {
            $set = '';
            foreach ($values as $value) {
                $set .= self::tlv(self::TAG_OCTET_STRING, $value);
            }
            $list .= self::tlv(self::TAG_SEQUENCE, self::tlv(self::TAG_OCTET_STRING, $type) . self::tlv(self::TAG_SET, $set));
        }
        return self::tlv(self::OP_SEARCH_ENTRY, self::tlv(self::TAG_OCTET_STRING, $dn) . self::tlv(self::TAG_SEQUENCE, $list));
    }

    private static function result(int $op, int $code, string $message): string
    {
        return self::tlv($op, self::integer(self::TAG_ENUMERATED, $code)
            . self::tlv(self::TAG_OCTET_STRING, '') . self::tlv(self::TAG_OCTET_STRING, $message));
    }

    private static function message(int $id, string $op): string
    {
        return self::tlv(self::TAG_SEQUENCE, self::integer(self::TAG_INTEGER, $id) . $op);
    }

    private static function integer(int $tag, int $value): string
    {
        $bytes = '';
        do {
            $bytes = chr($value & 0xFF) . $bytes;
            $value >>= 8;
        } while ($value > 0);
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\0" . $bytes;
        }
        return self::tlv($tag, $bytes);
    }

    private static function tlv(int $tag, string $content): string
    {
        $length = strlen($content);
        if ($length < 0x80) {
            return chr($tag) . chr($length) . $content;
        }
        $bytes = ltrim(pack('N', $length), "\0");
        return chr($tag) . chr(0x80 | strlen($bytes)) . $bytes . $content;
    }

    /**
     * The message ID and the operation tag of the next request, or null when
     * the client closed the connection.
     *
     * @param resource $conn
     * @return array{0: int, 1: int}|null
     */
    private static function readRequest($conn): ?array
    {
        $head = self::read($conn, 2);
        if ($head === null) {
            return null;
        }
        $length = ord($head[1]);
        if (($length & 0x80) !== 0) {
            $lengthBytes = self::read($conn, $length & 0x7F);
            if ($lengthBytes === null) {
                return null;
            }
            $length = (int)hexdec(bin2hex($lengthBytes));
        }
        $body = self::read($conn, $length);
        if ($body === null) {
            return null;
        }
        $idLength = ord($body[1]);
        $id = (int)hexdec(bin2hex(substr($body, 2, $idLength)));
        return [$id, ord($body[2 + $idLength])];
    }

    /**
     * @param resource $conn
     */
    private static function read($conn, int $bytes): ?string
    {
        $buffer = '';
        while (strlen($buffer) < $bytes) {
            $chunk = fread($conn, $bytes - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }
}
