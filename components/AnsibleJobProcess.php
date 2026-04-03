<?php

declare(strict_types=1);

namespace app\components;

use app\jobs\JobTimeoutException;
use app\models\Job;
use app\models\JobLog;

/**
 * Spawns an ansible-playbook subprocess and streams its output as JobLog records.
 */
class AnsibleJobProcess
{
    /**
     * Run a command as a subprocess, streaming output to the database.
     * Returns the process exit code.
     *
     * @param string[] $cmd
     * @param array<string, mixed> $payload
     * @param array<string, string> $env
     * @throws JobTimeoutException if the process exceeds the timeout.
     */
    public function run(Job $job, array $cmd, array $payload, array $env, int $timeoutMinutes): int
    {
        $process = $this->startProcess($cmd, $payload, $env);
        $pipes = $process['pipes'];

        $timedOut = $this->streamProcessOutput($job, $pipes, $timeoutMinutes, $process['resource']);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process['resource']);
        $job->pid = null;
        $job->save(false);

        if ($timedOut) {
            throw new JobTimeoutException($timeoutMinutes);
        }

        return $exitCode;
    }

    /**
     * Open the ansible-playbook subprocess.
     *
     * @param string[] $cmd
     * @param array<string, mixed> $payload
     * @param array<string, string> $env
     * @return array{resource: resource, pipes: array<int, resource>}
     */
    private function startProcess(array $cmd, array $payload, array $env): array
    {
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $projectCwd = (string)($payload['project_path'] ?? '');
        $process = proc_open($cmd, $descriptorspec, $pipes, is_dir($projectCwd) ? $projectCwd : null, $env);

        if (!is_resource($process)) {
            throw new \RuntimeException('proc_open failed');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return ['resource' => $process, 'pipes' => $pipes];
    }

    /**
     * Read stdout/stderr from the subprocess, writing log chunks.
     * Returns true if the process was killed due to timeout.
     *
     * @param array<int, resource> $pipes
     * @param resource $process
     */
    private function streamProcessOutput(Job $job, array $pipes, int $timeoutMinutes, $process): bool
    {
        $deadline = time() + ($timeoutMinutes * 60);
        $sequence = 0;

        while (!feof($pipes[1]) || !feof($pipes[2])) {
            $remaining = $deadline - time();
            if ($remaining <= 0) {
                $this->killTimedOutProcess($process);
                return true;
            }

            $sequence = $this->drainAndAppendLogs($job, $pipes, $sequence, $remaining);
        }

        return false;
    }

    /**
     * Kill a process that exceeded its timeout.
     *
     * @param resource $process
     */
    private function killTimedOutProcess($process): void
    {
        proc_terminate($process, 15);
        sleep(3);
        proc_terminate($process, 9);
    }

    /**
     * Read available output from process pipes and append as log entries.
     *
     * @param array<int, resource> $pipes
     * @return int Updated sequence number.
     */
    private function drainAndAppendLogs(Job $job, array $pipes, int $sequence, int $remaining): int
    {
        $read = array_filter([$pipes[1], $pipes[2]], fn ($p) => is_resource($p) && !feof($p));
        $write = null;
        $except = null;
        $changed = stream_select($read, $write, $except, min($remaining, 5));

        if ($changed === false || $changed === 0) {
            return $sequence;
        }

        foreach ($read as $stream) {
            $chunk = fread($stream, 4096);
            if ($chunk !== false && $chunk !== '') {
                $streamName = ($stream === $pipes[1]) ? JobLog::STREAM_STDOUT : JobLog::STREAM_STDERR;
                $this->appendLog($job, $streamName, $chunk, $sequence++);
            }
        }

        return $sequence;
    }

    private function appendLog(Job $job, string $stream, string $content, int $sequence = 0): void
    {
        $log = new JobLog();
        $log->job_id = $job->id;
        $log->stream = $stream;
        $log->content = $content;
        $log->sequence = $sequence;
        $log->created_at = time();
        if (!$log->save()) {
            \Yii::error("AnsibleJobProcess: failed to save log for job #{$job->id}: " . json_encode($log->errors), __CLASS__);
        }
    }
}
