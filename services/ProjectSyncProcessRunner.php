<?php

declare(strict_types=1);

namespace app\services;

use app\models\Project;
use app\models\ProjectSyncLog;

/**
 * Runs a single git subprocess on behalf of ProjectService with two
 * properties the previous blocking implementation didn't have:
 *
 *   - **Hard timeout.** stdout/stderr are read non-blocking and the
 *     `stream_select` loop tracks a deadline. When the deadline trips the
 *     child gets SIGTERM, then SIGKILL after a short grace, and we throw
 *     a RuntimeException with a clear "git timed out after Ns" message.
 *     Without this, an unreachable SCM host blocks the queue worker on
 *     `proc_close()` indefinitely (the original "stuck on syncing" bug).
 *
 *   - **Streamed log capture.** Every chunk read from either stream is
 *     appended to the `project_sync_log` table in real time so the UI can
 *     poll for progress while the sync runs. The accumulated stdout/stderr
 *     is also returned so the caller can fold them into RuntimeException
 *     messages on non-zero exit.
 *
 * Lives outside ProjectService so the proc_open + stream_select + signal
 * dance has its own home and ProjectService stays focused on credential
 * resolution and status transitions.
 */
class ProjectSyncProcessRunner
{
    /**
     * Read buffer size for each stream chunk. 8 KiB matches the ansible
     * callback log chunking and is large enough that we rarely loop on
     * partial lines while staying small enough to keep memory bounded.
     */
    private const READ_CHUNK_BYTES = 8192;

    /** Grace period between SIGTERM and SIGKILL when reclaiming a hung child. */
    private const TERM_GRACE_SECONDS = 2;

    /** Max bytes to buffer in-memory per stream — cheap guardrail against runaway output. */
    private const MAX_BUFFER_BYTES = 4 * 1024 * 1024;

    private string $stdout = '';
    private string $stderr = '';
    private int $sequence = 1;

    /**
     * @param string[]              $cmd
     * @param array<string, string> $env
     * @return array{0: string, 1: string, 2: int}
     * @throws \RuntimeException on proc_open failure or timeout.
     */
    public function run(Project $project, array $cmd, array $env, int $timeoutSeconds): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, null, $env ?: null);
        if (!is_resource($process)) {
            throw new \RuntimeException('proc_open failed for git command.');
        }

        // Stdin not used; close immediately so the child never blocks waiting
        // for an interactive password prompt that GIT_TERMINAL_PROMPT=0 should
        // already have suppressed.
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $this->stdout = '';
        $this->stderr = '';
        $this->sequence = $this->nextSequence($project);
        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;

        while (!feof($pipes[1]) || !feof($pipes[2])) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $timedOut = true;
                break;
            }
            $this->pumpOnce($project, $pipes[1], $pipes[2], $remaining);
        }

        if (is_resource($pipes[1])) {
            fclose($pipes[1]);
        }
        if (is_resource($pipes[2])) {
            fclose($pipes[2]);
        }

        if ($timedOut) {
            $this->terminate($process);
            $message = sprintf('git command timed out after %d seconds: %s', $timeoutSeconds, implode(' ', $cmd));
            $this->appendLog($project, ProjectSyncLog::STREAM_SYSTEM, $message . "\n");
            throw new \RuntimeException($message);
        }

        return [$this->stdout, $this->stderr, proc_close($process)];
    }

    public function appendSystem(Project $project, string $message): void
    {
        $this->sequence = $this->nextSequence($project);
        $this->appendLog($project, ProjectSyncLog::STREAM_SYSTEM, $message);
    }

    /**
     * One iteration of the read loop: select on whichever pipes are still
     * open, drain anything that's ready, append to project_sync_log.
     *
     * Pulled out of run() to keep both methods under PHPMD's complexity cap;
     * the stream_select pass-by-reference dance lives in one place and the
     * outer loop only deals with the deadline.
     *
     * @param resource $stdoutPipe
     * @param resource $stderrPipe
     */
    private function pumpOnce(Project $project, $stdoutPipe, $stderrPipe, float $remaining): void
    {
        $read = [];
        if (!feof($stdoutPipe)) {
            $read[] = $stdoutPipe;
        }
        if (!feof($stderrPipe)) {
            $read[] = $stderrPipe;
        }
        if ($read === []) {
            return;
        }

        $write = null;
        $except = null;
        $waitSeconds = (int)min(1, max(0, $remaining));
        $waitMicros = (int)(($remaining - $waitSeconds) * 1_000_000);

        $ready = stream_select($read, $write, $except, $waitSeconds, $waitMicros);
        if ($ready === false || $ready === 0) {
            return;
        }

        foreach ($read as $stream) {
            $this->absorbChunk($project, $stream, $stream === $stdoutPipe);
        }
    }

    /** @param resource $stream */
    private function absorbChunk(Project $project, $stream, bool $isStdout): void
    {
        $chunk = fread($stream, self::READ_CHUNK_BYTES);
        if ($chunk === false || $chunk === '') {
            return;
        }

        if ($isStdout && strlen($this->stdout) < self::MAX_BUFFER_BYTES) {
            $this->stdout .= $chunk;
        } elseif (!$isStdout && strlen($this->stderr) < self::MAX_BUFFER_BYTES) {
            $this->stderr .= $chunk;
        }

        $this->appendLog(
            $project,
            $isStdout ? ProjectSyncLog::STREAM_STDOUT : ProjectSyncLog::STREAM_STDERR,
            $chunk,
        );
    }

    /**
     * Send SIGTERM, give the child a moment, then SIGKILL if it's still
     * alive. proc_close after either gets us the exit slot back.
     *
     * @param resource $process
     */
    private function terminate($process): void
    {
        if (is_resource($process)) {
            proc_terminate($process, 15); // SIGTERM
        }

        $stillRunning = true;
        $deadline = microtime(true) + self::TERM_GRACE_SECONDS;
        while (microtime(true) < $deadline) {
            if (!is_resource($process)) {
                $stillRunning = false;
                break;
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $stillRunning = false;
                break;
            }
            usleep(100_000);
        }

        if ($stillRunning && is_resource($process)) {
            proc_terminate($process, 9); // SIGKILL
        }
        if (is_resource($process)) {
            proc_close($process);
        }
    }

    private function nextSequence(Project $project): int
    {
        return (int)ProjectSyncLog::find()
            ->where(['project_id' => $project->id])
            ->max('sequence') + 1;
    }

    private function appendLog(Project $project, string $stream, string $content): void
    {
        $log = new ProjectSyncLog();
        $log->project_id = $project->id;
        $log->stream = $stream;
        $log->content = $content;
        $log->sequence = $this->sequence++;
        $log->created_at = time();
        $log->save(false);
    }
}
