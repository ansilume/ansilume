<?php

declare(strict_types=1);

namespace app\components;

use yii\console\Controller;

/**
 * Spawns an ansible-playbook process and streams its output to the server.
 *
 * Output is read in chunks that can end inside a UTF-8 character. Those
 * bytes wait for the next chunk of their stream, so every posted chunk is
 * whole characters: the server takes JSON, and a chunk cut inside a
 * character could not be encoded. The bytes still waiting when a stream
 * ends are posted with its last chunk.
 *
 * Output then goes through an {@see OutputMasker} per stream, which masks
 * the job's password answers; by default it masks nothing. A chunk the
 * masker holds back completely is not posted, and what it still holds when
 * the process ends is posted before the timeout line and before the runner
 * reports completion.
 */
class RunnerProcessExecutor
{
    private const LOG_CHUNK_BYTES = 8192;

    private RunnerHttpClient $http;
    private Controller $controller;
    private OutputMasker $masker;

    /** @var array<string, string> per stream, the first bytes of a character the next chunk completes */
    private array $incomplete = [];

    public function __construct(RunnerHttpClient $http, Controller $controller, ?OutputMasker $masker = null)
    {
        $this->http = $http;
        $this->controller = $controller;
        $this->masker = $masker ?? new OutputMasker([]);
    }

    /**
     * Run a process and stream output to the server.
     *
     * @param string[] $cmd
     * @param array<string, mixed> $payload
     * @param array<string, string> $env
     * @return array{0: int, 1: int, 2: bool}  [exit code, final sequence number, timed-out flag]
     */
    public function run(
        int $jobId,
        #[\SensitiveParameter] array $cmd,
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] array $env,
        int $timeoutMinutes
    ): array {
        $process = $this->startProcess($jobId, $cmd, $payload, $env);
        if ($process === null) {
            return [-1, 0, false];
        }

        [$pipes, $proc] = $process;

        return $this->streamProcessOutput($jobId, $proc, $pipes, $timeoutMinutes);
    }

    /**
     * How long the process may run.
     */
    protected function timeoutSeconds(int $timeoutMinutes): int
    {
        return $timeoutMinutes * 60;
    }

    /**
     * @param string[] $cmd
     * @param array<string, mixed> $payload
     * @param array<string, string> $env
     * @return array{0: array<int, resource>, 1: resource}|null  [pipes, process] or null on failure
     */
    private function startProcess(
        int $jobId,
        #[\SensitiveParameter] array $cmd,
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] array $env
    ): ?array {
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
        $deadline = time() + $this->timeoutSeconds($timeoutMinutes);
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

        $sequence = $this->postLog($jobId, 'stdout', $this->finishStream('stdout'), $sequence);
        $sequence = $this->postLog($jobId, 'stderr', $this->finishStream('stderr'), $sequence);

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

        return $this->postLog($jobId, $streamName, $this->maskWholeCharacters($streamName, $chunk), $sequence);
    }

    /**
     * The masked output of $chunk, the next chunk of $stream, up to its last
     * whole UTF-8 character. The bytes of a character it ends inside wait
     * for the next chunk, so the masker and the server see whole characters.
     */
    private function maskWholeCharacters(string $stream, #[\SensitiveParameter] string $chunk): string
    {
        $bytes = ($this->incomplete[$stream] ?? '') . $chunk;
        $whole = strlen($bytes) - self::incompleteTail($bytes);
        $this->incomplete[$stream] = substr($bytes, $whole);

        return $this->masker->feed($stream, substr($bytes, 0, $whole));
    }

    /**
     * The masked rest of $stream after it ended: the bytes of a character
     * it never completed, then what the masker held back.
     */
    private function finishStream(string $stream): string
    {
        $rest = $this->incomplete[$stream] ?? '';
        unset($this->incomplete[$stream]);

        return $this->masker->feed($stream, $rest) . $this->masker->flush($stream);
    }

    /**
     * How many bytes at the end of $bytes begin a UTF-8 character that is
     * not complete yet: 0 to 3. Bytes that cannot be UTF-8 count as complete,
     * so they pass on at once.
     */
    private static function incompleteTail(#[\SensitiveParameter] string $bytes): int
    {
        $length = strlen($bytes);
        for ($back = 1; $back <= min(3, $length); $back++) {
            $byte = ord($bytes[$length - $back]);
            if (($byte & 0xC0) !== 0x80) {
                // Not a continuation byte: the character starts here.
                return self::characterLength($byte) > $back ? $back : 0;
            }
        }

        return 0;
    }

    /**
     * The length in bytes of the UTF-8 character that starts with $byte; 1
     * for ASCII and for a byte no character starts with.
     */
    private static function characterLength(int $byte): int
    {
        return match (true) {
            $byte >= 0xF8 => 1,
            $byte >= 0xF0 => 4,
            $byte >= 0xE0 => 3,
            $byte >= 0xC0 => 2,
            default => 1,
        };
    }

    /**
     * POST one log chunk; nothing when it is empty (the masker may hold back
     * a whole chunk, and the server rejects empty content).
     *
     * @return int Updated sequence number.
     */
    private function postLog(int $jobId, string $stream, #[\SensitiveParameter] string $content, int $sequence): int
    {
        if ($content === '') {
            return $sequence;
        }
        $this->http->post("/api/runner/v1/jobs/{$jobId}/logs", [
            'stream' => $stream,
            'content' => $content,
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
