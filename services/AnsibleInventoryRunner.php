<?php

declare(strict_types=1);

namespace app\services;

use yii\base\Component;

/**
 * Executes `ansible-inventory --list` as a subprocess and returns the raw output.
 *
 * Handles process lifecycle: spawning, non-blocking I/O, timeout, and cleanup.
 * Designed to be used by InventoryService but kept separate so process management
 * does not inflate the service's complexity.
 */
class AnsibleInventoryRunner extends Component
{
    /** @var int Timeout in seconds for ansible-inventory execution. */
    public int $timeout = 30;

    /**
     * Check whether ansible-inventory is available on this system.
     */
    public function isAvailable(): bool
    {
        exec('which ansible-inventory 2>/dev/null', $out, $code);
        return $code === 0;
    }

    /**
     * Run `ansible-inventory --list` against the given inventory path.
     *
     * @return array{stdout?: string, error: ?string}
     */
    public function run(string $inventoryPath, ?string $cwd = null): array
    {
        $cmd = ['ansible-inventory', '--list', '-i', $inventoryPath];

        $process = $this->openProcess($cmd, $pipes, $cwd);
        if ($process === null) {
            return ['groups' => [], 'hosts' => [], 'error' => 'Failed to start ansible-inventory process.'];
        }

        [$stdout, $stderr, $timedOut] = $this->readProcessOutput($pipes, $process);

        if ($timedOut) {
            return ['groups' => [], 'hosts' => [], 'error' => 'ansible-inventory timed out.'];
        }

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $errMsg = trim($stderr ?: $stdout);
            return ['groups' => [], 'hosts' => [], 'error' => "ansible-inventory failed (exit {$exitCode}): {$errMsg}"];
        }

        return ['stdout' => $stdout, 'error' => null];
    }

    /**
     * Open a subprocess and return the process resource + pipes.
     * Returns null if proc_open fails.
     *
     * @param resource[] &$pipes
     * @return resource|null
     */
    protected function openProcess(array $cmd, ?array &$pipes, ?string $cwd = null)
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return $process;
    }

    /**
     * Read stdout/stderr from a subprocess with timeout handling.
     *
     * @param resource[] $pipes
     * @param resource   $process
     * @return array{0: string, 1: string, 2: bool} [stdout, stderr, timedOut]
     */
    protected function readProcessOutput(array $pipes, $process): array
    {
        $stdout = '';
        $stderr = '';
        $deadline = time() + $this->timeout;

        while (true) {
            $read = array_filter([$pipes[1], $pipes[2]], fn ($p) => is_resource($p));
            if (empty($read)) {
                break;
            }

            if (time() > $deadline) {
                $this->killProcess($process, $read);
                return [$stdout, $stderr, true];
            }

            $write = $except = [];
            $remaining = max(1, $deadline - time());

            // stream_select emits E_WARNING on signal interruption (SIGCHLD) — not actionable
            $changed = @stream_select($read, $write, $except, $remaining); // @phpcs:ignore
            if ($changed === false) {
                break;
            }

            $this->drainPipes($read, $pipes[1], $stdout, $stderr);
        }

        $this->closePipes($pipes);

        return [$stdout, $stderr, false];
    }

    /**
     * Terminate a timed-out process and close its pipes.
     *
     * @param resource   $process
     * @param resource[] $openPipes
     */
    protected function killProcess($process, array $openPipes): void
    {
        proc_terminate($process, 15);
        foreach ($openPipes as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
        proc_close($process);
    }

    /**
     * Read available data from ready pipes into stdout/stderr buffers.
     *
     * @param resource[] $readyPipes  Pipes returned by stream_select
     * @param resource   $stdoutPipe  Reference pipe to distinguish stdout from stderr
     */
    protected function drainPipes(array $readyPipes, $stdoutPipe, string &$stdout, string &$stderr): void
    {
        foreach ($readyPipes as $pipe) {
            $chunk = fread($pipe, 65536);
            if ($chunk === false || $chunk === '') {
                if (feof($pipe)) {
                    fclose($pipe);
                }
                continue;
            }
            if ($pipe === $stdoutPipe) {
                $stdout .= $chunk;
            } else {
                $stderr .= $chunk;
            }
        }
    }

    /**
     * Close any pipes that are still open.
     *
     * @param resource[] $pipes
     */
    protected function closePipes(array $pipes): void
    {
        if (is_resource($pipes[1])) {
            fclose($pipes[1]);
        }
        if (is_resource($pipes[2])) {
            fclose($pipes[2]);
        }
    }
}
