<?php

declare(strict_types=1);

namespace app\tests\unit\ci;

use PHPUnit\Framework\TestCase;

/**
 * tests/ci/scrutinizer-db-setup.php waits for Scrutinizer's database service.
 *
 * Regression: the old wait loop sat on a service that accepted connections
 * but never sent the MySQL greeting. mysqlnd waits up to its read timeout
 * (one day by default) for that greeting, so each of the 30 attempts took
 * minutes and the build hung for more than an hour.
 */
class ScrutinizerDbSetupTest extends TestCase
{
    private string $envFile;

    protected function setUp(): void
    {
        $this->envFile = sys_get_temp_dir() . '/scrutinizer-db-setup-' . uniqid('', true) . '.env';
        file_put_contents($this->envFile, "DB_HOST=127.0.0.1\n");
    }

    protected function tearDown(): void
    {
        \app\helpers\FileHelper::safeUnlink($this->envFile);
    }

    public function testAServiceThatNeverAnswersFailsWithinTheDeadline(): void
    {
        // The kernel completes the TCP handshake for the backlog, so this
        // socket accepts connections without ever answering them.
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, $errstr);
        $port = $this->portOf($server);

        $started = microtime(true);
        [$exitCode, $output] = $this->run127($port, 2);
        $elapsed = microtime(true) - $started;
        fclose($server);

        $this->assertSame(1, $exitCode, $output);
        $this->assertLessThan(20, $elapsed, 'the wait must be bounded');
        $this->assertStringContainsString("probe 127.0.0.1:{$port}: accepted the connection but sent no greeting within 1 s", $output);
        $this->assertStringContainsString('MySQL server has gone away', $output);
        $this->assertStringContainsString('No database answered before the deadline.', $output);
        $this->assertSame("DB_HOST=127.0.0.1\n", file_get_contents($this->envFile), '.env stays untouched');
    }

    public function testAClosedPortFailsFastAndSaysSo(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, $errstr);
        $port = $this->portOf($server);
        fclose($server);

        [$exitCode, $output] = $this->run127($port, 1);

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString("probe 127.0.0.1:{$port}: no connection: Connection refused", $output);
        $this->assertStringContainsString('No database answered before the deadline.', $output);
    }

    /**
     * @param resource $server
     */
    private function portOf($server): int
    {
        $name = (string)stream_socket_get_name($server, false);

        return (int)substr($name, (int)strrpos($name, ':') + 1);
    }

    /**
     * Runs the script against 127.0.0.1:$port with a one-second attempt timeout.
     *
     * @return array{0: int, 1: string} exit code and combined output
     */
    private function run127(int $port, int $deadline): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/tests/ci/scrutinizer-db-setup.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                'PATH' => (string)getenv('PATH'),
                'SCRUTINIZER_DB_HOSTS' => '127.0.0.1',
                'SCRUTINIZER_DB_PORT' => (string)$port,
                'SCRUTINIZER_DB_DEADLINE' => (string)$deadline,
                'SCRUTINIZER_DB_ATTEMPT_TIMEOUT' => '1',
                'SCRUTINIZER_ENV_FILE' => $this->envFile,
            ]
        );
        $this->assertIsResource($process);
        $output = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }
}
