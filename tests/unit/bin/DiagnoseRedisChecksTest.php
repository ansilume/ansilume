<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use PHPUnit\Framework\TestCase;

/**
 * bin/diagnose output is meant to be pasted into bug reports. It must never
 * contain the Redis password, it reports only whether REDIS_PASSWORD is set,
 * and it flags runners that can still reach backend services.
 */
class DiagnoseRedisChecksTest extends TestCase
{
    private function diagnose(): string
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/bin/diagnose');
        $this->assertNotFalse($content);

        return $content;
    }

    private function extractFunction(string $name): string
    {
        $this->assertSame(
            1,
            preg_match('/^' . preg_quote($name, '/') . '\(\) \{.*?\n\}/ms', $this->diagnose(), $m),
            "could not extract {$name}() from bin/diagnose"
        );

        return $m[0];
    }

    /**
     * @return array{out: string, rc: int}
     */
    private function bash(string $script, string $stdin = ''): array
    {
        $process = proc_open(
            ['bash', '-c', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return ['out' => $out, 'rc' => proc_close($process)];
    }

    private function redact(string $input): string
    {
        return $this->bash($this->extractFunction('redact') . "\nredact", $input)['out'];
    }

    /**
     * Regression: health/check output (which diagnose prints) could carry
     * yii2-redis' "Redis command was: AUTH <password>".
     */
    public function testRedactMasksRedisAuthArguments(): void
    {
        $out = $this->redact("Redis command was: AUTH s3cr3t-one\nAUTH default s3cr3t-two\nDB_PASSWORD=s3cr3t-three\n");

        $this->assertStringNotContainsString('s3cr3t', $out);
        $this->assertStringContainsString('AUTH ***REDACTED***', $out);
    }

    public function testRedactKeepsHarmlessLines(): void
    {
        $this->assertSame("[health] redis: ok\n", $this->redact("[health] redis: ok\n"));
    }

    public function testRedisPasswordIsReportedButNeverPrinted(): void
    {
        $diagnose = $this->diagnose();

        $this->assertStringContainsString('ok "REDIS_PASSWORD is set"', $diagnose);
        $this->assertStringContainsString('REDIS_PASSWORD is empty', $diagnose);
        $this->assertSame(1, preg_match("/grep -E '\\^\\((?<list>[A-Z_|]+)\\)=' \\.env/", $diagnose, $m));
        $this->assertStringNotContainsString('REDIS_PASSWORD', $m['list'], 'the non-secret list must not print it');
        $this->assertStringNotContainsString('RUNNER_MODE', $m['list'], 'RUNNER_MODE no longer exists');
    }

    public function testRunnersAreCheckedAgainstEveryBackend(): void
    {
        $diagnose = $this->diagnose();

        $this->assertStringContainsString('for backend in app db redis queue-worker; do', $diagnose);
        $this->assertStringContainsString('share_network "$runner_cid" "$backend_cid"', $diagnose);
    }

    /**
     * Runs share_network() with a fake docker CLI that maps container names
     * to the networks they are attached to.
     */
    private function shareNetwork(string $a, string $b): bool
    {
        $fakeDocker = 'docker() { case "$4" in'
            . ' runner) echo "net-runners " ;;'
            . ' legacy-runner) echo "net-ansilume " ;;'
            . ' app) echo "net-ansilume " ;;'
            . ' nginx) echo "net-ansilume net-runners " ;;'
            . ' esac; }';
        $script = $fakeDocker . "\n" . $this->extractFunction('container_networks') . "\n"
            . $this->extractFunction('share_network') . "\n"
            . 'share_network ' . escapeshellarg($a) . ' ' . escapeshellarg($b);

        return $this->bash($script)['rc'] === 0;
    }

    /**
     * Regression: the probe resolved backend names inside the runner, so a
     * DNS search domain (app.example.com) reported an isolated runner as
     * connected. Network IDs are unambiguous.
     */
    public function testShareNetworkComparesNetworkIds(): void
    {
        $this->assertFalse($this->shareNetwork('runner', 'app'), 'isolated runner');
        $this->assertTrue($this->shareNetwork('legacy-runner', 'app'), 'runner on the shared network');
        $this->assertTrue($this->shareNetwork('runner', 'nginx'), 'nginx bridges both networks');
        $this->assertFalse($this->shareNetwork('runner', 'gone'), 'a container without networks');
    }

    public function testRedisPasswordMismatchesAreReported(): void
    {
        $diagnose = $this->diagnose();

        $this->assertStringContainsString("! grep -q 'REDIS_PASSWORD' \"\$compose_file\"", $diagnose);
        $this->assertStringContainsString('test -f /var/www/components/RedisSettings.php', $diagnose);
        $this->assertFileExists(dirname(__DIR__, 3) . '/components/RedisSettings.php');
    }
}
