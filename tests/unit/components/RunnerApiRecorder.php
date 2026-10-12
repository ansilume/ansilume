<?php

declare(strict_types=1);

namespace app\tests\unit\components;

/**
 * A loopback HTTP server (`php -S`) that answers every request with
 * {"ok":true} and records its path and raw body. Tests point the real
 * RunnerHttpClient at it and see exactly the JSON the server would get, or
 * that a request never came.
 */
final class RunnerApiRecorder
{
    private const ROUTER = <<<'PHP'
        <?php
        $record = [
            'path' => (string)($_SERVER['REQUEST_URI'] ?? ''),
            'body' => base64_encode((string)file_get_contents('php://input')),
        ];
        file_put_contents(__DIR__ . '/requests.ndjson', json_encode($record) . "\n", FILE_APPEND | LOCK_EX);
        header('Content-Type: application/json');
        echo '{"ok":true}';
        PHP;

    /** @var resource|null */
    private $process = null;

    private string $directory = '';

    /**
     * @return string the base URL
     */
    public function start(): string
    {
        $this->directory = sys_get_temp_dir() . '/runner_api_recorder_' . uniqid('', true);
        mkdir($this->directory, 0o700, true);
        file_put_contents($this->directory . '/router.php', self::ROUTER);
        $port = $this->freePort();
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $this->directory . '/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $this->directory
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('RunnerApiRecorder: proc_open failed');
        }
        $this->process = $process;
        $this->waitUntilReachable($port);

        return 'http://127.0.0.1:' . $port;
    }

    /**
     * The requests so far, in order.
     *
     * @return list<array{path: string, body: string}>
     */
    public function requests(): array
    {
        $log = $this->directory . '/requests.ndjson';
        if (!is_file($log)) {
            return [];
        }
        $requests = [];
        $lines = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines === false ? [] : $lines as $line) {
            $record = json_decode($line, true);
            if (is_array($record)) {
                $requests[] = ['path' => (string)$record['path'], 'body' => (string)base64_decode((string)$record['body'], true)];
            }
        }

        return $requests;
    }

    /**
     * Forgets the requests so far.
     */
    public function reset(): void
    {
        $log = $this->directory . '/requests.ndjson';
        if (is_file($log)) {
            unlink($log);
        }
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 9);
            proc_close($this->process);
        }
        $this->process = null;
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if ($this->directory !== '' && is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    private function freePort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            throw new \RuntimeException('RunnerApiRecorder: could not bind a probe socket');
        }
        $name = (string)stream_socket_get_name($probe, false);
        fclose($probe);

        return (int)substr($name, (int)strrpos($name, ':') + 1);
    }

    private function waitUntilReachable(int $port): void
    {
        $deadline = microtime(true) + 5.0;
        set_error_handler(static fn (): bool => true);
        try {
            while (microtime(true) < $deadline) {
                $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.2);
                if ($socket !== false) {
                    fclose($socket);
                    return;
                }
                usleep(50000);
            }
        } finally {
            restore_error_handler();
        }
        $this->stop();
        throw new \RuntimeException('RunnerApiRecorder: the server did not come up in time');
    }
}
