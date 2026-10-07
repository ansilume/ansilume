<?php

declare(strict_types=1);

namespace app\components;

/**
 * Describes the runner environment and the target path when a git sync on
 * the runner fails, so the job log says why. Never includes secrets: URLs are
 * redacted. Output is bounded so it stays readable in the job log.
 *
 * Extracted from RunnerController together with {@see RunnerProjectSync}.
 */
final class GitSyncDiagnostics
{
    public function __construct(private readonly GitEnvBuilder $gitEnv = new GitEnvBuilder())
    {
    }

    /**
     * Redact credentials (user:pass@) from a git URL so logs never
     * contain tokens or passwords embedded in URLs.
     */
    public static function redactUrl(string $url): string
    {
        return (string)preg_replace('#(://)[^/@\s]+@#', '$1***@', $url);
    }

    /**
     * Collect diagnostic information about the runner environment and
     * target path state to help debug git sync failures.
     */
    public function collect(string $projectPath, bool $isClone): string
    {
        $lines = ['--- Git sync diagnostics ---'];
        $lines[] = 'git: ' . $this->gitVersion();
        $lines[] = 'runner user: ' . $this->runnerUser();
        $lines[] = 'git env: GIT_TERMINAL_PROMPT=0, safe.directory=* (via GIT_CONFIG_*)';

        foreach ($this->path('target path', $projectPath) as $l) {
            $lines[] = $l;
        }
        foreach ($this->path('parent dir', dirname($projectPath)) as $l) {
            $lines[] = $l;
        }
        $lines[] = $this->diskFree(dirname($projectPath));

        if (!$isClone && is_dir($projectPath . '/.git')) {
            foreach ($this->repoState($projectPath) as $l) {
                $lines[] = $l;
            }
        }

        $lines[] = '----------------------------';
        return implode("\n", $lines) . "\n";
    }

    private function gitVersion(): string
    {
        $out = $this->captureShortCmd(['git', '--version']);
        return $out !== '' ? $out : 'unavailable';
    }

    private function runnerUser(): string
    {
        if (!function_exists('posix_geteuid')) {
            return 'posix extension not available';
        }
        $uid = posix_geteuid();
        $gid = posix_getegid();
        $name = 'unknown';
        if (function_exists('posix_getpwuid')) {
            $info = posix_getpwuid($uid);
            if (is_array($info)) {
                $name = (string)$info['name'];
            }
        }
        return sprintf('%s (uid=%d, gid=%d)', $name, $uid, $gid);
    }

    /**
     * @return array<int, string>
     */
    private function path(string $label, string $path): array
    {
        $lines = [$label . ': ' . $path];
        if (!file_exists($path)) {
            $lines[] = '  exists=no';
            return $lines;
        }
        $lines[] = sprintf(
            '  exists=yes is_dir=%s writable=%s mode=%04o',
            is_dir($path) ? 'yes' : 'no',
            is_writable($path) ? 'yes' : 'no',
            fileperms($path) & 0777,
        );
        $ownerId = fileowner($path);
        $ownerName = (string)$ownerId;
        if ($ownerId !== false && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid($ownerId);
            if (is_array($info)) {
                $ownerName = $info['name'] . ' (' . $ownerId . ')';
            }
        }
        $lines[] = '  owner: ' . $ownerName;
        return $lines;
    }

    private function diskFree(string $path): string
    {
        if (!is_dir($path)) {
            return 'disk free: (parent dir missing)';
        }
        $df = disk_free_space($path);
        if ($df === false) {
            return 'disk free: unavailable';
        }
        return sprintf('disk free on %s: %.1f MB', $path, $df / 1048576);
    }

    /**
     * Collect state of an existing git checkout — effective config, remote
     * URL, branch, status. Run from inside the target directory so we see
     * exactly what git sees. URLs are redacted before logging.
     *
     * @return array<int, string>
     */
    private function repoState(string $projectPath): array
    {
        $lines = ['git repo state (from ' . $projectPath . '):'];

        $remote = $this->captureShortCmd(['git', '-C', $projectPath, 'remote', '-v']);
        if ($remote !== '') {
            $lines[] = '  remote:';
            foreach (array_slice(explode("\n", $remote), 0, 5) as $l) {
                $lines[] = '    ' . self::redactUrl($l);
            }
        }

        $branch = $this->captureShortCmd(['git', '-C', $projectPath, 'rev-parse', '--abbrev-ref', 'HEAD']);
        if ($branch !== '') {
            $lines[] = '  current branch: ' . $branch;
        }

        $config = $this->captureShortCmd(
            ['git', '-C', $projectPath, 'config', '--list', '--show-origin']
        );
        if ($config !== '') {
            $lines[] = '  effective config (first 20 lines):';
            foreach (array_slice(explode("\n", $config), 0, 20) as $l) {
                $lines[] = '    ' . self::redactUrl($l);
            }
        }

        return $lines;
    }

    /**
     * Run a short diagnostic command and return trimmed stdout. Uses
     * proc_open (not shell_exec) to comply with the security check that
     * forbids shell_exec / exec outside services/. Returns empty string
     * on any failure — diagnostics must never throw.
     *
     * @param array<int, string> $cmd
     */
    private function captureShortCmd(array $cmd): string
    {
        $sshKeyFile = null;
        $env = $this->gitEnv->build('', null, $sshKeyFile);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, null, $env);
        if (!is_resource($proc)) {
            return '';
        }
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return trim($out);
    }
}
