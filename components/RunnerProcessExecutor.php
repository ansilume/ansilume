<?php

declare(strict_types=1);

namespace app\components;

use yii\console\Controller;

/**
 * Spawns an ansible-playbook process and streams its output to the server.
 */
class RunnerProcessExecutor
{
    private const LOG_CHUNK_BYTES = 8192;

    private RunnerHttpClient $http;
    /** @phpstan-ignore-next-line Yii2 Controller is generic but type param is irrelevant here */
    private Controller $controller;

    /**
     * @phpstan-ignore-next-line
     */
    public function __construct(RunnerHttpClient $http, Controller $controller)
    {
        $this->http = $http;
        $this->controller = $controller;
    }

    /**
     * Run a process and stream output to the server.
     *
     * @param string[] $cmd
     * @param array<string, mixed> $payload
     * @param array<string, string> $env
     * @return array{0: int, 1: int, 2: bool}  [exit code, final sequence number, timed-out flag]
     */
    public function run(int $jobId, array $cmd, array $payload, array $env, int $timeoutMinutes): array
    {
        $process = $this->startProcess($jobId, $cmd, $payload, $env);
        if ($process === null) {
            return [-1, 0, false];
        }

        [$pipes, $proc] = $process;

        return $this->streamProcessOutput($jobId, $proc, $pipes, $timeoutMinutes);
    }

    /**
     * @param string[] $cmd
     * @param array<string, mixed> $payload
     * @param array<string, string> $env
     * @return array{0: array<int, resource>, 1: resource}|null  [pipes, process] or null on failure
     */
    private function startProcess(int $jobId, array $cmd, array $payload, array $env): ?array
    {
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $projectCwd = isset($payload['project_path']) ? (string)$payload['project_path'] : '';
        $cwd = ($projectCwd !== '' && is_dir($projectCwd)) ? $projectCwd : null;
        $process = proc_open($cmd, $descriptorspec, $pipes, $cwd, $env);

        if (!is_resource($process)) {
            $this->http->post("/api/runner/v1/jobs/{$jobId}/logs", [
                'stream' => 'stderr',
                'content' => "proc_open failed — cannot execute ansible-playbook\n",
                'sequence' => 0,
            ]);
            $this->http->post("/api/runner/v1/jobs/{$jobId}/complete", ['exit_code' => -1]);
            return null;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$pipes, $process];
    }

    /**
     * Read stdout/stderr from the process and stream to the server.
     *
     * @param resource $process
     * @param array<int, resource> $pipes
     * @return array{0: int, 1: int, 2: bool}  [exit code, final sequence number, timed-out flag]
     */
    private function streamProcessOutput(int $jobId, $process, array $pipes, int $timeoutMinutes): array
    {
        $deadline = time() + ($timeoutMinutes * 60);
        $sequence = 0;
        $timedOut = false;

        while (!feof($pipes[1]) || !feof($pipes[2])) {
            $remaining = $deadline - time();
            if ($remaining <= 0) {
                $this->killTimedOutProcess($jobId, $process, $timeoutMinutes);
                $timedOut = true;
                break;
            }

            $sequence = $this->drainAndStreamLogs($jobId, $pipes, $sequence, $remaining);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($timedOut) {
            $this->sendTimeoutLog($jobId, $sequence++, $timeoutMinutes);
            $exitCode = -1;
        }

        return [$exitCode, $sequence, $timedOut];
    }

    /**
     * Kill a process that exceeded its timeout.
     *
     * @param resource $process
     */
    private function killTimedOutProcess(int $jobId, $process, int $timeoutMinutes): void
    {
        $this->controller->stdout("Job #{$jobId} exceeded timeout of {$timeoutMinutes}m — killing process.\n");
        proc_terminate($process, 15);
        sleep(3);
        proc_terminate($process, 9);
    }

    /**
     * Read available output from process pipes and stream to the server.
     *
     * @param array<int, resource> $pipes
     * @return int Updated sequence number.
     */
    private function drainAndStreamLogs(int $jobId, array $pipes, int $sequence, int $remaining): int
    {
        $read = array_filter([$pipes[1], $pipes[2]], fn ($p) => is_resource($p) && !feof($p));
        $write = null;
        $except = null;
        // stream_select emits E_WARNING on signal interruption (SIGCHLD) — not actionable
        $changed = @stream_select($read, $write, $except, min($remaining, 5)); // @phpcs:ignore

        if ($changed === false || $changed === 0) {
            return $sequence;
        }

        foreach ($read as $stream) {
            $sequence = $this->readAndPostChunk($jobId, $stream, $pipes[1], $sequence);
        }

        return $sequence;
    }

    /**
     * Read a chunk from a stream and POST it to the server.
     *
     * @param resource $stream     The pipe to read from.
     * @param resource $stdoutPipe Reference pipe to distinguish stdout from stderr.
     * @return int Updated sequence number.
     */
    private function readAndPostChunk(int $jobId, $stream, $stdoutPipe, int $sequence): int
    {
        $chunk = fread($stream, self::LOG_CHUNK_BYTES);
        if ($chunk === false || $chunk === '') {
            return $sequence;
        }

        $streamName = ($stream === $stdoutPipe) ? 'stdout' : 'stderr';
        $this->http->post("/api/runner/v1/jobs/{$jobId}/logs", [
            'stream' => $streamName,
            'content' => $chunk,
            'sequence' => $sequence,
        ]);

        return $sequence + 1;
    }

    private function sendTimeoutLog(int $jobId, int $sequence, int $timeoutMinutes): void
    {
        $this->http->post("/api/runner/v1/jobs/{$jobId}/logs", [
            'stream' => 'stderr',
            'content' => "\n[ansilume] Job killed: exceeded timeout of {$timeoutMinutes} minutes.\n",
            'sequence' => $sequence,
        ]);
    }
}
