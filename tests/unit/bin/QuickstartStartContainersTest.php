<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use app\helpers\FileHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real start of the containers from bin/quickstart (step 9, from
 * COMPOSE_EXIT=0 to "Containers started") in bash under the quickstart's
 * set -euo pipefail and with its own ok, fail and info, with a stand-in for
 * docker on PATH. A failed `docker compose up` is reported with compose's
 * exit code and the hints to the logs and the diagnostics, and ends the
 * quickstart with its own exit 1, with -vv as without it.
 *
 * Regression: with -vv the quickstart streamed the output of
 * `docker compose up` through a while loop and read the exit code with
 * `COMPOSE_EXIT=${PIPESTATUS[0]:-$?}` after the pipeline. Under pipefail the
 * pipeline fails with compose, so set -e ended the quickstart at the
 * pipeline, with compose's exit code: it never said "Failed to start
 * containers" and gave neither hint, in the mode it recommends for finding
 * out why. ErrexitExitStatusTest keeps PIPESTATUS out of every script.
 */
class QuickstartStartContainersTest extends TestCase
{
    /** The start, from COMPOSE_EXIT=0 to the line that says it worked. */
    private const START = '/^COMPOSE_EXIT=0\n.*?^ok "Containers started"\n/ms';

    /** What the harness prints once the start has let the quickstart go on. */
    private const GOES_ON = 'The quickstart goes on.';

    /** The exit code of the failed start: not 1, the quickstart's own. */
    private const COMPOSE_FAILED = '17';

    /**
     * docker for this test: `docker compose up` printing its progress on
     * stderr, as compose does, and failing with STUB_UP_EXIT unless it is 0.
     */
    private const STUB_DOCKER = <<<'BASH'
        #!/usr/bin/env bash
        if [ "$*" != "compose -f docker-compose.prebuilt.yml up -d --pull always" ]; then
            echo "unexpected: docker $*" >&2
            exit 1
        fi
        echo " Container ansilume-db-1  Started" >&2
        if [ "$STUB_UP_EXIT" != "0" ]; then
            echo "Error response from daemon: Bind for 0.0.0.0:8080 failed: port is already allocated" >&2
        fi
        exit "$STUB_UP_EXIT"
        BASH;

    /** The progress compose printed, as the quickstart shows it. */
    private const PROGRESS = "     Container ansilume-db-1  Started\n";

    /** The error compose printed, as the quickstart shows it. */
    private const ERROR = "    Error response from daemon: Bind for 0.0.0.0:8080 failed: port is already allocated\n";

    /** How the quickstart reports the failed start. */
    private const FAILED = '  ✗ Failed to start containers (exit code: ' . self::COMPOSE_FAILED . ")\n";

    /** The diagnostics the hints name. */
    private const DIAGNOSE = 'curl -fsSL https://raw.githubusercontent.com/ansilume/ansilume/main/bin/diagnose | bash';

    /** The hints behind the report. */
    private const HINTS = "  \n"
        . "  Check logs: docker compose -f docker-compose.prebuilt.yml logs\n"
        . '  Run diagnostics: ' . self::DIAGNOSE . "\n";

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/quickstart-start-' . uniqid('', true);
        mkdir($this->directory, 0o700, true);
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->directory);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: int}>
     */
    public static function startProvider(): array
    {
        $streamedThenReported = self::PROGRESS . self::ERROR . self::FAILED . self::HINTS;
        $reportedWithOutput = self::FAILED . self::PROGRESS . self::ERROR . self::HINTS;
        $started = "  ✓ Containers started\n" . self::GOES_ON . "\n";

        return [
            'regression: a failed start with -vv' => ['2', self::COMPOSE_FAILED, $streamedThenReported, 1],
            'a failed start with -v' => ['1', self::COMPOSE_FAILED, $reportedWithOutput, 1],
            'a failed start' => ['0', self::COMPOSE_FAILED, $reportedWithOutput, 1],
            'a start with -vv' => ['2', '0', self::PROGRESS . $started, 0],
            'a start' => ['0', '0', $started, 0],
        ];
    }

    #[DataProvider('startProvider')]
    public function testTheStartIsReportedInEveryVerbosity(
        string $verbose,
        string $composeExit,
        string $output,
        int $status
    ): void {
        [$exitCode, $printed] = $this->runStart($verbose, $composeExit);

        $this->assertSame($output, $printed);
        $this->assertSame($status, $exitCode, $printed);
    }

    /**
     * Runs the start of bin/quickstart as the quickstart runs it: under its
     * options and with its output helpers, docker being the stand-in, and
     * then the line that shows the quickstart goes on.
     *
     * @return array{0: int, 1: string} exit status, stdout and stderr without colours
     */
    private function runStart(string $verbose, string $composeExit): array
    {
        file_put_contents($this->directory . '/docker', self::STUB_DOCKER . "\n");
        chmod($this->directory . '/docker', 0o755);
        $quickstart = (string)file_get_contents(dirname(__DIR__, 3) . '/bin/quickstart');
        $this->assertStringContainsString(
            "\nset -euo pipefail\n",
            $quickstart,
            'The harness runs the start with the options of the quickstart.'
        );
        $this->assertSame(
            1,
            preg_match('/^RED=.*?^die\(\)[^\n]*\n/ms', $quickstart, $helpers),
            'Could not extract the output helpers from bin/quickstart'
        );
        $this->assertSame(
            1,
            preg_match(self::START, $quickstart, $start),
            'Could not extract the start of the containers from bin/quickstart'
        );

        $process = proc_open(
            ['bash', '-c', "set -euo pipefail\n" . $helpers[0] . $start[0] . 'echo "' . self::GOES_ON . "\"\n"],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $this->directory,
            [
                'PATH' => $this->directory . ':' . (string)getenv('PATH'),
                'VERBOSE' => $verbose,
                'COMPOSE_CMD' => 'docker compose',
                'COMPOSE_ARGS' => '-f docker-compose.prebuilt.yml',
                'STUB_UP_EXIT' => $composeExit,
            ]
        );
        $this->assertIsResource($process);
        $output = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), (string)preg_replace('/\e\[[0-9;]*m/', '', $output)];
    }
}
