<?php

declare(strict_types=1);

namespace app\tests\unit\docker;

use app\helpers\FileHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real "waiting for database" loop of docker/php/entrypoint.sh (the
 * dev image) and docker/php/entrypoint-prod.sh (the prebuilt image
 * ghcr.io/ansilume/ansilume) under /bin/sh with set -e, as the app container
 * runs it (/bin/sh is dash in php:8.5-fpm), with a stand-in php that gives
 * the answers of the database check and a sleep that returns at once.
 *
 * Regression: the loop kept the answer of the check with
 * `db_result=$(php -r "…" 2>/dev/null)` outside a condition, so when php died
 * instead of answering (an Error that is not a PDOException, a crash, an OOM
 * kill), set -e ended the entrypoint at that line with php's status, right
 * after "[entrypoint] waiting for database...". The loop never said what
 * failed, php's error was only in PHP's error log /var/log/php_errors.log
 * (php.ini sets display_errors = Off), the migrations never ran, and
 * restart: unless-stopped restarted the app container in a loop without a
 * word in its log.
 */
class EntrypointDatabaseWaitTest extends TestCase
{
    private const ENTRYPOINTS = [
        'dev image' => 'docker/php/entrypoint.sh',
        'prebuilt image' => 'docker/php/entrypoint-prod.sh',
    ];

    /** The loop, from "waiting for database..." to "database is reachable". */
    private const LOOP = '/^[ \t]*echo "\[entrypoint\] waiting for database\.\.\."\n'
        . '.*?^[ \t]*echo "\[entrypoint\] database is reachable"$/ms';

    /**
     * php for the database check: call N answers with line N of answers, or
     * with the last line once they run out; "exit N" dies with status N
     * without a word, as php does with display_errors = Off.
     */
    private const STUB_PHP = <<<'SH'
        #!/bin/sh
        # php for EntrypointDatabaseWaitTest: the database check of the entrypoints.
        printf '%s\n' "$1" >> "$STUB_DIR/checks"
        answer=$(sed -n "$(wc -l < "$STUB_DIR/checks")p" "$STUB_DIR/answers")
        if [ -z "$answer" ]; then
            answer=$(tail -n 1 "$STUB_DIR/answers")
        fi
        case "$answer" in
            "exit "*) exit "${answer#exit }" ;;
        esac
        printf '%s' "$answer"
        SH;

    /** sleep for the loop: notes how long it would wait, and returns at once. */
    private const STUB_SLEEP = <<<'SH'
        #!/bin/sh
        # sleep for EntrypointDatabaseWaitTest: notes the wait, returns at once.
        printf '%s\n' "$*" >> "$STUB_DIR/sleeps"
        SH;

    /** What the harness prints once the loop is behind it. */
    private const GOES_ON = 'harness: the entrypoint goes on to the migrations';

    private const WAITING = "[entrypoint] waiting for database...\n";

    /** What the entrypoints print when php dies in the database check. */
    private const PHP_FAILED = "[entrypoint] ERROR: the database check could not run (php failed)\n"
        . "[entrypoint] Check PHP's error log /var/log/php_errors.log in this container for details.\n";

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/entrypoint-db-wait-' . uniqid('', true);
        mkdir($this->directory, 0o700, true);
        file_put_contents($this->directory . '/php', self::STUB_PHP . "\n");
        file_put_contents($this->directory . '/sleep', self::STUB_SLEEP . "\n");
        chmod($this->directory . '/php', 0o755);
        chmod($this->directory . '/sleep', 0o755);
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->directory);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function entrypointProvider(): array
    {
        $cases = [];
        foreach (self::ENTRYPOINTS as $image => $entrypoint) {
            $cases[$image] = [$entrypoint];
        }

        return $cases;
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function dyingPhpProvider(): array
    {
        $deaths = ['a fatal error' => 255, 'a crash' => 139, 'an OOM kill' => 137];
        $cases = [];
        foreach (self::ENTRYPOINTS as $image => $entrypoint) {
            foreach ($deaths as $death => $status) {
                $cases[$image . ', ' . $death] = [$entrypoint, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('dyingPhpProvider')]
    public function testRegressionAPhpThatDiesEndsTheEntrypointWithItsOwnError(string $entrypoint, int $phpStatus): void
    {
        $run = $this->runLoop($entrypoint, ['exit ' . $phpStatus]);

        $this->assertSame(self::PHP_FAILED, $run['err'], 'the entrypoint says that php failed, and where its error is');
        $this->assertSame(self::WAITING, $run['out'], 'the entrypoint ends before the migrations');
        $this->assertSame("-r\n", $run['checks'], 'a php that dies is not retried as an unreachable database');
        $this->assertSame('', $run['sleeps']);
        $this->assertSame(1, $run['status']);
    }

    #[DataProvider('entrypointProvider')]
    public function testADatabaseThatStaysUnreachableIsReportedAfterSixtySeconds(string $entrypoint): void
    {
        $run = $this->runLoop($entrypoint, ['unavailable']);

        $this->assertSame("[entrypoint] ERROR: database not reachable after 60s\n", $run['err']);
        $this->assertSame(self::WAITING, $run['out']);
        $this->assertSame(str_repeat("-r\n", 30), $run['checks'], 'a check every 2 seconds for 60 seconds');
        $this->assertSame(str_repeat("2\n", 29), $run['sleeps']);
        $this->assertSame(1, $run['status']);
    }

    #[DataProvider('entrypointProvider')]
    public function testAReachableDatabaseEndsTheLoop(string $entrypoint): void
    {
        $run = $this->runLoop($entrypoint, ['ok']);

        $this->assertSame(self::WAITING . "[entrypoint] database is reachable\n" . self::GOES_ON . "\n", $run['out']);
        $this->assertSame('', $run['err']);
        $this->assertSame("-r\n", $run['checks']);
        $this->assertSame('', $run['sleeps']);
        $this->assertSame(0, $run['status']);
    }

    #[DataProvider('entrypointProvider')]
    public function testADatabaseThatComesUpLateEndsTheLoopAfterTheRetries(string $entrypoint): void
    {
        $run = $this->runLoop($entrypoint, ['unavailable', 'unavailable', 'ok']);

        $this->assertSame(self::WAITING . "[entrypoint] database is reachable\n" . self::GOES_ON . "\n", $run['out']);
        $this->assertSame('', $run['err']);
        $this->assertSame(str_repeat("-r\n", 3), $run['checks']);
        $this->assertSame("2\n2\n", $run['sleeps']);
        $this->assertSame(0, $run['status']);
    }

    #[DataProvider('entrypointProvider')]
    public function testRejectedCredentialsKeepTheirMessage(string $entrypoint): void
    {
        $run = $this->runLoop($entrypoint, ['auth_error']);

        $this->assertSame(
            "[entrypoint] ERROR: database credentials rejected (Access denied)\n"
            . "[entrypoint] If you re-ran quickstart, choose 'Fresh install' to reset the database.\n",
            $run['err']
        );
        $this->assertSame(self::WAITING, $run['out']);
        $this->assertSame("-r\n", $run['checks']);
        $this->assertSame('', $run['sleeps']);
        $this->assertSame(1, $run['status']);
    }

    public function testBothImagesWaitForTheDatabaseWithTheSameLoop(): void
    {
        $this->assertSame(
            $this->loopOf(self::ENTRYPOINTS['dev image']),
            $this->loopOf(self::ENTRYPOINTS['prebuilt image']),
            'docker/php/entrypoint.sh and docker/php/entrypoint-prod.sh wait for the database alike'
        );
    }

    /**
     * An operator who searches for the error finds how to read PHP's error
     * of a container that restarts.
     */
    public function testTheTroubleshootingGuideQuotesTheError(): void
    {
        $guide = (string)file_get_contents(dirname(__DIR__, 3) . '/docs/troubleshooting.md');

        $this->assertStringContainsString("```\n" . self::PHP_FAILED . "```\n", $guide);
        $this->assertStringContainsString('docker compose cp app:/var/log/php_errors.log', $guide);
    }

    #[DataProvider('entrypointProvider')]
    public function testTheEntrypointRunsUnderShWithSetE(string $entrypoint): void
    {
        $this->assertStringStartsWith(
            "#!/bin/sh\nset -e\n",
            (string)file_get_contents(dirname(__DIR__, 3) . '/' . $entrypoint),
            'the harness runs the loop as the entrypoint does'
        );
    }

    /**
     * Runs the loop of $entrypoint under /bin/sh with set -e, php answering
     * the database check with $answers in turn, and then the line GOES_ON.
     *
     * @param list<string> $answers
     * @return array{status: int, out: string, err: string, checks: string, sleeps: string}
     */
    private function runLoop(string $entrypoint, array $answers): array
    {
        file_put_contents($this->directory . '/answers', implode("\n", $answers) . "\n");
        $harness = "set -e\n" . $this->loopOf($entrypoint) . "\necho '" . self::GOES_ON . "'\n";

        $process = proc_open(
            ['/bin/sh', '-c', $harness],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->directory,
            ['PATH' => $this->directory . ':' . (string)getenv('PATH'), 'STUB_DIR' => $this->directory]
        );
        $this->assertIsResource($process);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'status' => proc_close($process),
            'out' => $out,
            'err' => $err,
            'checks' => $this->noted('checks'),
            'sleeps' => $this->noted('sleeps'),
        ];
    }

    private function loopOf(string $entrypoint): string
    {
        $script = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $entrypoint);
        $this->assertSame(
            1,
            preg_match(self::LOOP, $script, $m),
            "Could not find the database wait loop in {$entrypoint}"
        );

        return $m[0];
    }

    /**
     * What a stand-in noted in $file of the scratch directory, one call a line.
     */
    private function noted(string $file): string
    {
        $path = $this->directory . '/' . $file;

        return is_file($path) ? (string)file_get_contents($path) : '';
    }
}
