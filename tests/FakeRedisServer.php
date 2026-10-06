<?php

declare(strict_types=1);

namespace app\tests;

/**
 * One-shot fake Redis server in a child process, for tests that need the real
 * yii2-redis socket code to hit a specific reply without a Redis server.
 *
 * It accepts a single connection and answers the first command with the given
 * RESP line, or closes the connection right away when the reply is null.
 * With $everyCommand it answers every command until the client disconnects,
 * then accepts the next connection.
 * With $everyCommand, $authReply (when given) answers AUTH commands instead,
 * so the server can reject a password like a real one: WRONGPASS for AUTH,
 * NOAUTH for everything after it.
 */
final class FakeRedisServer
{
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

    public static function start(?string $reply, bool $everyCommand = false, ?string $authReply = null): self
    {
        $line = var_export((string)$reply, true);
        $auth = var_export($authReply ?? (string)$reply, true);
        $answer = match (true) {
            $reply === null => 'fclose($c);',
            $everyCommand => 'do { stream_set_timeout($c, 5);'
                . ' while (($in = fread($c, 65536)) !== false && $in !== "") {'
                . ' $out = ""; foreach (preg_split("/(?=\*\d+\r\n)/", $in, -1, PREG_SPLIT_NO_EMPTY) as $cmd) {'
                . ' $out .= stripos($cmd, "\r\nAUTH\r\n") !== false ? ' . $auth . ' : ' . $line . '; }'
                . ' fwrite($c, $out); } fclose($c); } while (($c = stream_socket_accept($s, 10)) !== false);',
            default => 'fread($c, 65536); fwrite($c, ' . $line . '); usleep(300000); fclose($c);',
        };
        $code = '$s = stream_socket_server("tcp://127.0.0.1:0", $no, $err);'
            . ' fwrite(STDOUT, stream_socket_get_name($s, false) . "\n"); fflush(STDOUT);'
            . ' $c = stream_socket_accept($s, 10); ' . $answer;

        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('could not start the fake Redis server');
        }
        $address = trim((string)fgets($pipes[1]));
        if (preg_match('/^127\.0\.0\.1:(\d+)$/', $address, $m) !== 1) {
            throw new \RuntimeException("fake Redis server did not report its address: {$address}");
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
}
