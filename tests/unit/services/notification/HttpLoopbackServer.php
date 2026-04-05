<?php

declare(strict_types=1);

namespace app\tests\unit\services\notification;

/**
 * Tiny loopback HTTP server used by notification-channel unit tests.
 *
 * Spawns `php -S 127.0.0.1:<port>` as a child process pointed at a
 * throwaway docroot whose index.php returns a configurable status code.
 * The caller can then point curl at the returned base URL to exercise
 * real HTTP code paths without touching the network.
 */
final class HttpLoopbackServer
{
    /** @var resource|null */
    private $process;
    private string $docroot = '';
    public int $port = 0;

    public function start(int $statusCode = 200, string $body = 'ok'): string
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
        file_put_contents(
            $this->docroot . '/index.php',
            "<?php http_response_code({$statusCode}); echo " . var_export($body, true) . ";\n"
        );

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
                usleep(50_000);
            }
        } finally {
            restore_error_handler();
        }
        $this->stop();
        throw new \RuntimeException('HttpLoopbackServer: server did not come up in time');
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
