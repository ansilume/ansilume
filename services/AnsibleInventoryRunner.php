<?php

declare(strict_types=1);

namespace app\services;

use app\components\SubprocessEnvironment;
use app\components\VaultIsolation;
use yii\base\Component;

/**
 * Executes `ansible-inventory --list` as a subprocess and returns the raw output.
 *
 * Handles process lifecycle: spawning, non-blocking I/O, timeout, and cleanup.
 * Designed to be used by InventoryService but kept separate so process management
 * does not inflate the service's complexity.
 *
 * Every run is vault-isolated ({@see VaultIsolation}): the server never runs
 * a repository's vault password script and never decrypts vault content.
 */
class AnsibleInventoryRunner extends Component
{
    /**
     * Writable HOME for the subprocess. ansible plugins (and tools like
     * ansible-vault helpers) probe `~/.ansible*` and similar paths; the
     * default www-data home `/var/www` is root-owned, so anything that
     * writes there fails with EACCES. Same fix as PlaybookEnvironment and
     * ProjectService — point at a runtime dir prepared by the entrypoints.
     */
    public const ANSIBLE_HOME = '/var/www/runtime/ansible-home';

    /** Exit code of ansible-inventory for parser errors, decryption failures included. */
    public const EXIT_PARSER_ERROR = 4;

    /** ANSIBLE_VARS_ENABLED value that matches no vars plugin: no group_vars/ or host_vars/. */
    public const VARS_PLUGINS_DISABLED = 'ansilume_disabled';

    /** @var int Timeout in seconds for ansible-inventory execution. */
    public int $timeout = 30;

    /**
     * Check whether ansible-inventory is available on this system.
     */
    public function isAvailable(): bool
    {
        exec('which ansible-inventory 2>/dev/null', $lines, $code);
        return $code === 0 && !empty($lines);
    }

    /**
     * Run `ansible-inventory --list` against the given inventory path.
     *
     * @return array{stdout: string, stderr: string, exit_code: int|null, error: string|null}
     */
    public function run(string $inventoryPath, ?string $cwd = null): array
    {
        return $this->execute($inventoryPath, $cwd, []);
    }

    /**
     * Like run(), but loads no vars plugin, so group_vars/ and host_vars/ stay
     * unread. Used when those hold vault-encrypted files: hosts and groups
     * still come out complete.
     *
     * @return array{stdout: string, stderr: string, exit_code: int|null, error: string|null}
     */
    public function runWithoutVarsPlugins(string $inventoryPath, ?string $cwd = null): array
    {
        return $this->execute($inventoryPath, $cwd, ['ANSIBLE_VARS_ENABLED' => self::VARS_PLUGINS_DISABLED]);
    }

    /**
     * @param array<string, string> $extraEnv
     * @return array{stdout: string, stderr: string, exit_code: int|null, error: string|null}
     */
    protected function execute(string $inventoryPath, ?string $cwd, array $extraEnv): array
    {
        $vault = $this->vaultIsolation();
        try {
            $env = $this->buildProcessEnv(array_merge($vault->overrides(), $extraEnv));

            return $this->runProcess(['ansible-inventory', '--list', '-i', $inventoryPath], $cwd, $env);
        } catch (\RuntimeException $e) {
            return self::result('', '', null, $e->getMessage());
        } finally {
            // After proc_close, or after the kill on timeout.
            $vault->cleanup();
        }
    }

    protected function vaultIsolation(): VaultIsolation
    {
        return new VaultIsolation();
    }

    /**
     * @param string[] $cmd
     * @param array<string, string> $env
     * @return array{stdout: string, stderr: string, exit_code: int|null, error: string|null}
     */
    private function runProcess(array $cmd, ?string $cwd, array $env): array
    {
        $process = $this->openProcess($cmd, $pipes, $cwd, $env);
        if ($process === null) {
            return self::result('', '', null, 'Failed to start ansible-inventory process.');
        }

        [$stdout, $stderr, $timedOut] = $this->readProcessOutput($pipes, $process);
        if ($timedOut) {
            return self::result($stdout, $stderr, null, 'ansible-inventory timed out.');
        }

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            $errMsg = trim($stderr ?: $stdout);
            return self::result($stdout, $stderr, $exitCode, "ansible-inventory failed (exit {$exitCode}): {$errMsg}");
        }

        return self::result($stdout, $stderr, 0, null);
    }

    /**
     * @return array{stdout: string, stderr: string, exit_code: int|null, error: string|null}
     */
    private static function result(string $stdout, string $stderr, ?int $exitCode, ?string $error): array
    {
        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit_code' => $exitCode, 'error' => $error];
    }

    /**
     * Open a subprocess and return the process resource + pipes.
     * Returns null if proc_open fails.
     *
     * @param string[] $cmd
     * @param resource[]|null $pipes
     * @param array<string, string>|null $env defaults to buildProcessEnv()
     * @return resource|null
     */
    protected function openProcess(array $cmd, ?array &$pipes, ?string $cwd = null, ?array $env = null)
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, $cwd, $env ?? $this->buildProcessEnv());
        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return $process;
    }

    /**
     * Subprocess environment: the shared allowlist (PATH, proxies, CA and
     * ANSIBLE_* settings) plus a pinned writable HOME and a UTF-8 locale.
     * Inventory scripts and plugins from the repository run in this process,
     * so it never gets the server's secrets. See {@see SubprocessEnvironment}.
     * The overrides (vault isolation) win over forwarded ANSIBLE_* values.
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    protected function buildProcessEnv(array $overrides = []): array
    {
        return SubprocessEnvironment::build(getenv() ?: [], array_merge([
            'HOME' => self::ANSIBLE_HOME,
            'LANG' => 'C.UTF-8',
        ], $overrides));
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
