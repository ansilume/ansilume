<?php

declare(strict_types=1);

namespace app\tests\unit\services\notification;

/**
 * Tiny loopback HTTP server used by notification-channel unit tests.
 *
 * Spawns `php -S 127.0.0.1:<port>` as a child process pointed at a
 * throwaway docroot whose index.php answers as the test says and records
 * every request it answers (requests()).
 * The caller can then point curl at the returned base URL to exercise
 * real HTTP code paths without touching the network.
 */
final class HttpLoopbackServer
{
    /**
     * The docroot's index.php, which answers every path: records the
     * request before it answers, so the record is there once the caller has
     * the answer; then answers as answers.json says for the request's path
     * (without its query), else as it says for '*', else 404.
     */
    private const INDEX = <<<'PHP'
        <?php
        file_put_contents(__DIR__ . '/requests.ndjson', json_encode([
            'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
            'path' => (string)($_SERVER['REQUEST_URI'] ?? ''),
            'headers' => getallheaders(),
            'body' => base64_encode((string)file_get_contents('php://input')),
        ]) . "\n", FILE_APPEND | LOCK_EX);
        $answers = json_decode((string)file_get_contents(__DIR__ . '/answers.json'), true);
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $answer = $answers[$path] ?? $answers['*'] ?? ['status' => 404];
        sleep((int)($answer['sleep'] ?? 0));
        http_response_code((int)($answer['status'] ?? 200));
        foreach ((array)($answer['headers'] ?? []) as $name => $value) {
            header($name . ': ' . $value);
        }
        echo (string)($answer['body'] ?? '');
        PHP;

    /** @var resource|null */
    private $process;
    private string $docroot = '';
    public int $port = 0;

    /**
     * Starts the server; it answers every path with $statusCode and $body.
     */
    public function start(int $statusCode = 200, string $body = 'ok'): string
    {
        return $this->startWith(['*' => ['status' => $statusCode, 'body' => $body]]);
    }

    /**
     * Starts the server with an answer per path: the key is the path without
     * the query, or '*' for every other path. An answer names its status
     * (200 when left out), its body (''), its headers, and the seconds the
     * server sleeps before it answers. A redirect is a 3xx status with a
     * Location header.
     *
     * @param array<string, array{status?: int, body?: string, headers?: array<string, string>, sleep?: int}> $answers
     */
    public function startWith(array $answers): string
    {
        // Pick a free loopback port by letting the kernel allocate one.
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            throw new \RuntimeException('HttpLoopbackServer: could not bind probe socket');
        }
        $name = stream_socket_get_name($probe, false);
        fclose($probe);
        if ($name === false) {
            throw new \RuntimeException('HttpLoopbackServer: could not resolve probe port');
        }
        $this->port = (int)substr($name, strrpos($name, ':') + 1);

        $this->docroot = sys_get_temp_dir() . '/ansilume_loopback_' . uniqid('', true);
        mkdir($this->docroot, 0755, true);
        file_put_contents($this->docroot . '/index.php', self::INDEX);
        file_put_contents($this->docroot . '/answers.json', json_encode($answers, JSON_THROW_ON_ERROR));

        $cmd = ['php', '-S', '127.0.0.1:' . $this->port, '-t', $this->docroot];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('HttpLoopbackServer: proc_open failed');
        }
        $this->process = $process;
        // Detach pipes so the child isn't blocked on stdout/stderr buffers.
        foreach ($pipes as $p) {
            if (is_resource($p)) {
                stream_set_blocking($p, false);
            }
        }

        // Wait for the server to become reachable. Install a temporary error
        // handler so the inevitable "connection refused" notices during the
        // retry loop don't leak into test output — avoids the `@` suppression
        // operator while still providing proper error handling.
        $deadline = microtime(true) + 3.0;
        set_error_handler(static fn (): bool => true);
        try {
            while (microtime(true) < $deadline) {
                $sock = stream_socket_client(
                    'tcp://127.0.0.1:' . $this->port,
                    $errno,
                    $errstr,
                    0.2,
                    STREAM_CLIENT_CONNECT
                );
                if ($sock !== false) {
                    fclose($sock);
                    return 'http://127.0.0.1:' . $this->port;
                }
                usleep(50000);
            }
        } finally {
            restore_error_handler();
        }
        $this->stop();
        throw new \RuntimeException('HttpLoopbackServer: server did not come up in time');
    }

    /**
     * The requests the server answered so far, in order; headers by the
     * names the client sent.
     *
     * @return list<array{method: string, path: string, headers: array<string, string>, body: string}>
     */
    public function requests(): array
    {
        $log = $this->docroot . '/requests.ndjson';
        if ($this->docroot === '' || !is_file($log)) {
            return [];
        }
        $requests = [];
        foreach (file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $record = json_decode($line, true);
            if (is_array($record)) {
                $requests[] = [
                    'method' => (string)$record['method'],
                    'path' => (string)$record['path'],
                    'headers' => array_map('strval', (array)$record['headers']),
                    'body' => (string)base64_decode((string)$record['body'], true),
                ];
            }
        }

        return $requests;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 9);
            proc_close($this->process);
            $this->process = null;
        }
        if ($this->docroot !== '' && is_dir($this->docroot)) {
            foreach (glob($this->docroot . '/*') ?: [] as $f) {
                unlink($f);
            }
            rmdir($this->docroot);
            $this->docroot = '';
        }
    }
}
