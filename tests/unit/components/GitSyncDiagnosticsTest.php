<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\GitSyncDiagnostics;
use app\helpers\FileHelper;
use PHPUnit\Framework\TestCase;

/**
 * What a failed runner git sync tells the operator in the job log.
 */
class GitSyncDiagnosticsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/git_sync_diagnostics_' . uniqid('', true);
        mkdir($this->dir . '/home', 0o700, true);
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->dir);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function urlProvider(): array
    {
        return [
            'user and token' => ['https://deploy:token@github.com/team/repo.git', 'https://***@github.com/team/repo.git'],
            'token only' => ['https://ghp_abc@github.com/team/repo.git', 'https://***@github.com/team/repo.git'],
            'ssh scheme' => ['ssh://git@host:2222/repo.git', 'ssh://***@host:2222/repo.git'],
            'inside a config line' => ['remote.origin.url=https://u:p@example.com/r.git', 'remote.origin.url=https://***@example.com/r.git'],
            'no credentials' => ['https://github.com/team/repo.git', 'https://github.com/team/repo.git'],
            'scp-style address' => ['git@github.com:team/repo.git', 'git@github.com:team/repo.git'],
        ];
    }

    /**
     * @dataProvider urlProvider
     */
    public function testRedactUrlHidesCredentials(string $url, string $expected): void
    {
        $this->assertSame($expected, GitSyncDiagnostics::redactUrl($url));
    }

    public function testAMissingTargetAndParentAreDescribed(): void
    {
        $target = $this->dir . '/missing/checkout';

        $report = (new GitSyncDiagnostics())->collect($target, true);

        $lines = explode("\n", $report);
        $this->assertSame('--- Git sync diagnostics ---', $lines[0]);
        $this->assertMatchesRegularExpression('/^git: git version \S+/', $lines[1]);
        $this->assertMatchesRegularExpression('/^runner user: \S+ \(uid=\d+, gid=\d+\)$/', $lines[2]);
        $this->assertSame('git env: GIT_TERMINAL_PROMPT=0, safe.directory=* (via GIT_CONFIG_*)', $lines[3]);
        $this->assertSame([
            'target path: ' . $target,
            '  exists=no',
            'parent dir: ' . $this->dir . '/missing',
            '  exists=no',
            'disk free: (parent dir missing)',
            '----------------------------',
            '',
        ], array_slice($lines, 4));
    }

    public function testAnExistingDirectoryAndFileAreDescribed(): void
    {
        $target = $this->dir . '/README.md';
        file_put_contents($target, "x\n");
        chmod($target, 0o640);

        $report = (new GitSyncDiagnostics())->collect($target, false);

        $this->assertStringContainsString("target path: {$target}\n  exists=yes is_dir=no writable=yes mode=0640\n  owner: ", $report);
        $this->assertMatchesRegularExpression('/parent dir: ' . preg_quote($this->dir, '/') . '\n  exists=yes is_dir=yes writable=yes mode=0\d{3}\n  owner: \S+/', $report);
        $this->assertMatchesRegularExpression('/disk free on ' . preg_quote($this->dir, '/') . ': [\d.]+ MB/', $report);
        $this->assertStringNotContainsString('git repo state', $report, 'not a checkout');
    }

    /**
     * For a failed pull the state of the checkout is shown, with credentials
     * in remote URLs and config lines redacted.
     */
    public function testTheCheckoutStateIsShownForAPullWithoutSecrets(): void
    {
        $checkout = $this->dir . '/checkout';
        $this->git(['init', '-q', '-b', 'main', $checkout]);
        $this->git(['-C', $checkout, 'remote', 'add', 'origin', 'https://deploy:s3cr3t@example.com/team/repo.git']);
        file_put_contents($checkout . '/README.md', "x\n");
        $this->git(['-C', $checkout, 'add', 'README.md']);
        $this->git(['-C', $checkout, 'commit', '-q', '-m', 'init']);

        $report = (new GitSyncDiagnostics())->collect($checkout, false);

        $this->assertStringContainsString("git repo state (from {$checkout}):\n  remote:\n    origin\thttps://***@example.com/team/repo.git (fetch)\n", $report);
        $this->assertStringContainsString("  current branch: main\n", $report);
        $this->assertStringContainsString('  effective config (first 20 lines):', $report);
        $this->assertStringContainsString('remote.origin.url=https://***@example.com/team/repo.git', $report);
        $this->assertStringNotContainsString('s3cr3t', $report);
    }

    public function testAFreshCloneSkipsTheCheckoutState(): void
    {
        $checkout = $this->dir . '/checkout';
        $this->git(['init', '-q', '-b', 'main', $checkout]);

        $this->assertStringNotContainsString('git repo state', (new GitSyncDiagnostics())->collect($checkout, true));
    }

    public function testACheckoutWithoutARemoteShowsNoRemoteBlock(): void
    {
        $checkout = $this->dir . '/checkout';
        $this->git(['init', '-q', '-b', 'main', $checkout]);

        $report = (new GitSyncDiagnostics())->collect($checkout, false);

        $this->assertStringContainsString("git repo state (from {$checkout}):\n  current branch: HEAD\n  effective config (first 20 lines):\n", $report);
        $this->assertStringNotContainsString('  remote:', $report);
    }

    /**
     * @param list<string> $args
     */
    private function git(array $args): void
    {
        $process = proc_open(['git', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir, [
            'PATH' => (string)getenv('PATH'),
            'HOME' => $this->dir . '/home',
            'GIT_AUTHOR_NAME' => 'Test',
            'GIT_AUTHOR_EMAIL' => 'test@example.com',
            'GIT_COMMITTER_NAME' => 'Test',
            'GIT_COMMITTER_EMAIL' => 'test@example.com',
        ]);
        $this->assertIsResource($process);
        stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $err);
    }
}
