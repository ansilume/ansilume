<?php

declare(strict_types=1);

namespace app\services;

use app\components\GitSyncDiagnostics;
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
 *   - **Streamed log capture.** What either stream writes is appended to
 *     the `project_sync_log` table in real time so the UI can poll for
 *     progress while the sync runs. The accumulated stdout/stderr is also
 *     returned so the caller can fold them into RuntimeException messages
 *     on non-zero exit.
 *
 * Every row it appends, and the output it returns, names URLs without their
 * credentials (GitSyncDiagnostics::redactUrl): git's messages can name the
 * repository URL, and the timeout names the command. A row ends at
 * whitespace (or where the stream ends), which no URL holds, so that no URL
 * is split across two rows where neither shows its credentials whole.
 *
 * Lives outside ProjectService so the proc_open + stream_select + signal
 * dance has its own home and ProjectService stays focused on credential
 * resolution and status transitions.
 */
class ProjectSyncProcessRunner
{
    /**
     * The row of the sync output, and the error, when git runs longer than
     * the timeout; sprintf(): the timeout in seconds, then the command with
     * its URL without credentials.
     */
    public const TIMED_OUT = 'git command timed out after %d seconds: %s';

    /**
     * What ends a row: whitespace. The output after the last whitespace is
     * held back until more comes or the stream ends.
     */
    private const ROW_END = " \t\n\r\v\f";

    /**
     * A run without whitespace longer than this is written as it came, so
     * that held output stays bounded. Only a URL across the end of such a
     * run could be split across two rows; git writes no line that long.
     */
    private const MAX_HELD_BYTES = 8192;

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
     * Per stream (ProjectSyncLog::STREAM_STDOUT, STREAM_STDERR), the output
     * after its last whitespace, not written yet.
     *
     * @var array<string, string>
     */
    private array $held = [];

    /**
     * @param string[]              $cmd The command, the repository URL with its credentials included.
     * @param array<string, string> $env
     * @return array{0: string, 1: string, 2: int} stdout and stderr without the credentials of URLs, the exit code.
     * @throws \RuntimeException on proc_open failure or timeout.
     */
    public function run(
        Project $project,
        #[\SensitiveParameter] array $cmd,
        #[\SensitiveParameter] array $env,
        int $timeoutSeconds
    ): array {
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
        $this->held = [];
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
        $this->writeHeld($project);

        if ($timedOut) {
            $this->terminate($process);
            $message = sprintf(self::TIMED_OUT, $timeoutSeconds, GitSyncDiagnostics::redactUrl(implode(' ', $cmd)));
            $this->appendLog($project, ProjectSyncLog::STREAM_SYSTEM, $message . "\n");
            throw new \RuntimeException($message);
        }

        return [$this->stdout, $this->stderr, proc_close($process)];
    }

    /**
     * Appends a row to the sync output's system stream, without the
     * credentials of URLs.
     */
    public function appendSystem(Project $project, #[\SensitiveParameter] string $message): void
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
        $waitMicros = (int)(($remaining - $waitSeconds) * 1000000);

        try {
            $ready = stream_select($read, $write, $except, $waitSeconds, $waitMicros);
        } catch (\ErrorException $e) {
            // The queue worker's SIGALRM heartbeat interrupts select(), and
            // Yii turns the "Interrupted system call" warning into an
            // exception. Nothing is lost: the next pass selects again.
            if (!str_contains($e->getMessage(), 'Interrupted system call')) {
                throw $e;
            }
            return;
        }
        if ($ready === false || $ready === 0) {
            return;
        }

        foreach ($read as $stream) {
            $this->absorbChunk($project, $stream, $stream === $stdoutPipe);
        }
    }

    /**
     * Writes what a read brought, up to its last whitespace, with what was
     * held back before it; holds back the rest.
     *
     * @param resource $stream
     */
    private function absorbChunk(Project $project, $stream, bool $isStdout): void
    {
        $chunk = fread($stream, self::READ_CHUNK_BYTES);
        if ($chunk === false || $chunk === '') {
            return;
        }

        $name = $isStdout ? ProjectSyncLog::STREAM_STDOUT : ProjectSyncLog::STREAM_STDERR;
        $text = ($this->held[$name] ?? '') . $chunk;
        $tail = strcspn(strrev($text), self::ROW_END);
        if ($tail > self::MAX_HELD_BYTES) {
            $tail = 0;
        }
        $this->held[$name] = substr($text, strlen($text) - $tail);
        $this->write($project, $name, substr($text, 0, strlen($text) - $tail));
    }

    /**
     * Writes what is held back: the streams ended, or the run timed out.
     */
    private function writeHeld(Project $project): void
    {
        foreach ($this->held as $name => $text) {
            $this->write($project, $name, $text);
        }
        $this->held = [];
    }

    /**
     * Adds output of a stream to what run() returns and to the sync output,
     * without the credentials of URLs.
     */
    private function write(Project $project, string $name, #[\SensitiveParameter] string $text): void
    {
        if ($text === '') {
            return;
        }
        $text = $this->appendLog($project, $name, $text);
        if ($name === ProjectSyncLog::STREAM_STDOUT && strlen($this->stdout) < self::MAX_BUFFER_BYTES) {
            $this->stdout .= $text;
        } elseif ($name === ProjectSyncLog::STREAM_STDERR && strlen($this->stderr) < self::MAX_BUFFER_BYTES) {
            $this->stderr .= $text;
        }
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
            usleep(100000);
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

    /**
     * Appends a row to the sync output; the content it stored: $content
     * without the credentials of URLs.
     */
    private function appendLog(Project $project, string $stream, #[\SensitiveParameter] string $content): string
    {
        $shown = GitSyncDiagnostics::redactUrl($content);
        $log = new ProjectSyncLog();
        $log->project_id = $project->id;
        $log->stream = $stream;
        $log->content = $shown;
        $log->sequence = $this->sequence++;
        $log->created_at = time();
        $log->save(false);

        return $shown;
    }
}
