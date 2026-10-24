<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\GitSyncDiagnostics;
use app\helpers\FileHelper;
use app\tests\unit\ProcessStartFailure;
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

    /**
     * regression: the userinfo ended at its first "@", so a password with a
     * raw "@" showed its rest: https://u:p@ss@host/r became
     * https://***@ss@host/r. Everything up to the last "@" before the path
     * is hidden now, on the runner's job log, the project page and in the
     * API alike. Git splits such a URL at the first "@" too, so its own
     * messages name the rest of the password as the user
     * (https://ss@host/r/): hidden as well.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rawAtProvider(): array
    {
        return [
            'password with an @' => ['https://deploy:p@ss-w5c-tail@git.example.com/org/repo.git', 'https://***@git.example.com/org/repo.git'],
            'several @' => ['https://u:a@b@c-w5c-tail@git.example.com/r.git', 'https://***@git.example.com/r.git'],
            'without a path' => ['https://u:p@ss-w5c-tail@git.example.com', 'https://***@git.example.com'],
            'with a port' => ['https://u:p@ss-w5c-tail@git.example.com:8443/r.git', 'https://***@git.example.com:8443/r.git'],
            'in a command line' => ['git clone -- https://u:p@ss-w5c-tail@git.example.com/r.git /dest', 'git clone -- https://***@git.example.com/r.git /dest'],
            'in git remote -v' => ["origin\thttps://u:p@ss-w5c-tail@git.example.com/r.git (fetch)", "origin\thttps://***@git.example.com/r.git (fetch)"],
        ];
    }

    /**
     * @dataProvider rawAtProvider
     */
    public function testRegressionAPasswordWithARawAtShowedItsRest(string $text, string $expected): void
    {
        $redacted = GitSyncDiagnostics::redactUrl($text);

        $this->assertSame($expected, $redacted);
        $this->assertStringNotContainsString('w5c-tail', $redacted);
    }

    /**
     * The rest of such a password as git names it (from the first "@" on),
     * and a password with a query or fragment mark, are hidden too.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function namedByGitProvider(): array
    {
        return [
            'as git names it' => ["fatal: unable to access 'https://ss-w5c-tail@git.example.com/r.git/': URL rejected: Bad hostname", "fatal: unable to access 'https://***@git.example.com/r.git/': URL rejected: Bad hostname"],
            'a query or fragment mark in the password' => ['https://u:p?s#s-w5c-tail@git.example.com/r.git', 'https://***@git.example.com/r.git'],
        ];
    }

    /**
     * @dataProvider namedByGitProvider
     */
    public function testTheRestOfAPasswordAsGitNamesItIsHidden(string $text, string $expected): void
    {
        $redacted = GitSyncDiagnostics::redactUrl($text);

        $this->assertSame($expected, $redacted);
        $this->assertStringNotContainsString('w5c-tail', $redacted);
    }

    /**
     * Only the userinfo goes: what follows the host keeps its shape, an "@"
     * in the path included, and so does text without "://".
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function shapeProvider(): array
    {
        return [
            'an @ in the path of a URL without credentials' => ['https://git.example.com/org/repo@v2.git', 'https://git.example.com/org/repo@v2.git'],
            'an @ in the path of a URL with credentials' => ['ssh://git@git.example.com/org/repo@v2.git', 'ssh://***@git.example.com/org/repo@v2.git'],
            'scp-like with an @ in the path' => ['git@git.example.com:org/repo@v2.git', 'git@git.example.com:org/repo@v2.git'],
            'a file URL' => ['file:///srv/repos/team.git', 'file:///srv/repos/team.git'],
            'an email after the URL' => ['see https://git.example.com/help or mail ops@example.com', 'see https://git.example.com/help or mail ops@example.com'],
            'empty userinfo' => ['https://@git.example.com/r.git', 'https://@git.example.com/r.git'],
            'redacted already' => ['https://***@git.example.com/r.git', 'https://***@git.example.com/r.git'],
            'nothing after the scheme' => ['https://', 'https://'],
            'empty' => ['', ''],
        ];
    }

    /**
     * @dataProvider shapeProvider
     */
    public function testRedactUrlKeepsTheShapeOfWhatIsNoCredential(string $text, string $expected): void
    {
        $this->assertSame($expected, GitSyncDiagnostics::redactUrl($text));
    }

    /**
     * Every URL of a text is redacted, on every line, also two URLs that
     * only a comma or a quote separates.
     */
    public function testEveryUrlOfATextIsRedacted(): void
    {
        $text = "Cloning https://u:one@a.example.com/r.git\n"
            . "fatal: 'https://u:two@b.example.com','https://u:three@c.example.com/r'\ttab https://four@d.example.com\r\n";

        $this->assertSame(
            "Cloning https://***@a.example.com/r.git\n"
            . "fatal: 'https://***@b.example.com','https://***@c.example.com/r'\ttab https://***@d.example.com\r\n",
            GitSyncDiagnostics::redactUrl($text)
        );
    }

    /**
     * A long text whatever its content is redacted, not dropped: a regular
     * expression that backtracks gives up on it and returns nothing.
     */
    public function testALongTextWithoutAPathIsRedactedWhole(): void
    {
        $text = 'https://u:p@' . str_repeat('h', 2000000) . ' and https://' . str_repeat('x', 2000000);

        $redacted = GitSyncDiagnostics::redactUrl($text);

        $this->assertSame('https://***@' . str_repeat('h', 2000000) . ' and https://' . str_repeat('x', 2000000), $redacted);
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
     * Regression (PHP 8.3): diagnostics must never throw, but a git that is
     * not on the PATH made proc_open() warn (posix_spawn), and the warning
     * threw, so the report of a sync whose git did not start was lost with
     * the runner. Each git command of the report now shows nothing.
     */
    public function testAGitThatCannotStartIsReportedAsUnavailable(): void
    {
        $checkout = $this->dir . '/checkout';
        $this->git(['init', '-q', '-b', 'main', $checkout]);

        $path = ProcessStartFailure::emptyPath();
        try {
            $report = (new GitSyncDiagnostics())->collect($checkout, false);
        } finally {
            $path->restore();
        }

        $this->assertStringContainsString("git: unavailable\n", $report);
        $this->assertStringContainsString("git repo state (from {$checkout}):\n----------------------------\n", $report);
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
