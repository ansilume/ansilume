<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use app\helpers\FileHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real start of the containers from bin/quickstart, start_containers()
 * where the install (step 9) and the update call it, in bash under the
 * quickstart's set -euo pipefail and with its own ok, warn, fail and info,
 * with a stand-in for docker on PATH. A failed `docker compose up` is
 * reported with compose's exit code, the last ten lines of its output and
 * the hints to the logs and the diagnostics, and ends the quickstart with its
 * own exit 1, with -vv as without it, in the install and in the update.
 *
 * Regression: with -vv the install streamed the output of
 * `docker compose up` through a while loop and read the exit code with
 * `COMPOSE_EXIT=${PIPESTATUS[0]:-$?}` after the pipeline. Under pipefail the
 * pipeline fails with compose, so set -e ended the quickstart at the
 * pipeline, with compose's exit code: it never said "Failed to start
 * containers" and gave neither hint, in the mode it recommends for finding
 * out why. ErrexitExitStatusTest keeps PIPESTATUS out of every script.
 *
 * Regression: the update ran `docker compose up -d … || true` and said
 * "Containers updated" whatever compose answered, with its output sent to
 * /dev/null. While the old containers still answered, it went on to
 * "Update complete" and exit 0, so a cron job that runs the update
 * (docs/updating.md, Automated updates) never learned that it had failed.
 */
class QuickstartStartContainersTest extends TestCase
{
    /** start_containers() and show_compose_output(), each from its first line to its closing brace. */
    private const FUNCTIONS = [
        'start_containers' => '/^start_containers\(\) \{\n.*?^\}\n/ms',
        'show_compose_output' => '/^show_compose_output\(\) \{\n.*?^\}\n/ms',
    ];

    /** Where the install and the update start the containers, up to the line that says it worked. */
    private const CALLERS = [
        'install' => '/^info "Starting services\.\.\."\n.*?^ok "Containers started"\n/ms',
        'update' => '/^    info "Pulling latest images and restarting\.\.\."\n.*?^    ok "Containers updated"\n/ms',
    ];

    /** The `docker compose up` each of them runs. */
    private const UP = [
        'install' => 'compose -f docker-compose.prebuilt.yml up -d --pull always',
        'update' => 'compose -f docker-compose.prebuilt.yml up -d',
    ];

    /** What the harness prints once the start has let the quickstart go on. */
    private const GOES_ON = 'The quickstart goes on.';

    /** The exit code of the failed start: not 1, the quickstart's own. */
    private const COMPOSE_FAILED = '17';

    /** How many containers `docker compose up` reports: more than the ten lines a failed start shows. */
    private const CONTAINERS = 11;

    /**
     * docker for this test: the pull of the update, and STUB_UP, the
     * `docker compose up` of the caller, printing a line per container on
     * stderr, as compose does, and failing with STUB_UP_EXIT unless it is 0.
     * With STUB_ENV_ERROR both fail with that error, as compose fails on a
     * line of .env it cannot read.
     */
    private const STUB_DOCKER = <<<'BASH'
        #!/usr/bin/env bash
        case "$*" in
            "compose -f docker-compose.prebuilt.yml pull")
                if [ -n "${STUB_ENV_ERROR:-}" ]; then
                    echo "$STUB_ENV_ERROR" >&2
                    exit 1
                fi
                echo " Image ghcr.io/ansilume/ansilume:latest  Pulled" >&2
                exit 0 ;;
            "$STUB_UP")
                if [ -n "${STUB_ENV_ERROR:-}" ]; then
                    echo "$STUB_ENV_ERROR" >&2
                    exit "$STUB_UP_EXIT"
                fi
                for n in $(seq 1 "$STUB_CONTAINERS"); do
                    echo " Container ansilume-c${n}-1  Started" >&2
                done
                if [ "$STUB_UP_EXIT" != "0" ]; then
                    echo "Error response from daemon: Bind for 0.0.0.0:8080 failed: port is already allocated" >&2
                fi
                exit "$STUB_UP_EXIT" ;;
        esac
        echo "unexpected: docker $*" >&2
        exit 1
        BASH;

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

    /** The line before the install starts the containers. */
    private const INSTALL = "  Starting services...\n";

    /** The line before the update pulls the images and starts the containers. */
    private const UPDATE = "  Pulling latest images and restarting...\n";

    /** The pull of the update, as the quickstart streams it with -vv. */
    private const PULLED = "     Image ghcr.io/ansilume/ansilume:latest  Pulled\n";

    /** Parts of values of .env that the quickstart must never print. */
    private const SECRETS = ['not-a-real-secret-3f2a', 'not-a-real-secret-4b7c', 'not-a-real-secret-5d9e', 'notarealsecret6f1a'];

    /**
     * The ways compose quotes a value of .env, as compose v5 prints them:
     * the rest of a line it cannot read, a ${ that is no interpolation, the
     * text of ${VAR:?…}, and the name after a $ in a value.
     */
    private const ENV_ERROR = 'time="2026-10-25T21:00:00Z" level=warning msg="The \"notarealsecret6f1a\" variable is not set.'
        . " Defaulting to a blank string.\"\n"
        . 'failed to read /opt/ansilume/.env: line 5: unterminated quoted value "not-a-real-secret-3f2a' . "\n"
        . 'failed to read /opt/ansilume/.env: Invalid template: "not-a-real-secret-4b7c${x"' . "\n"
        . 'error while interpolating services.app.environment.[]: required variable UNSET_X is missing a value:'
        . ' not-a-real-secret-5d9e';

    /** What the quickstart shows instead of them: once, and where to see them. */
    private const ENV_ERROR_SHOWN = '    (lines about .env are left out, as they can hold a secret: see them with'
        . " docker compose -f docker-compose.prebuilt.yml config --quiet)\n";

    /** The update's warning about the failed pull. */
    private const PULL_FAILED = '  ⚠ Pulling the images failed, so the containers keep the images they have.'
        . " Run the update with -vv to see why.\n";

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
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: int}>
     */
    public static function startProvider(): array
    {
        $all = self::progress(1);
        $streamedThenReported = $all . self::ERROR . self::FAILED . self::HINTS;
        $lastTenReported = self::FAILED . self::progress(3) . self::ERROR . self::HINTS;
        $started = "  ✓ Containers started\n" . self::GOES_ON . "\n";
        $updated = "  ✓ Containers updated\n" . self::GOES_ON . "\n";

        return [
            'regression: a failed start of the install with -vv' => [
                'install', '2', self::COMPOSE_FAILED, self::INSTALL . $streamedThenReported, 1,
            ],
            'a failed start of the install with -v' => [
                'install', '1', self::COMPOSE_FAILED, self::INSTALL . $lastTenReported, 1,
            ],
            'a failed start of the install' => [
                'install', '0', self::COMPOSE_FAILED, self::INSTALL . $lastTenReported, 1,
            ],
            'a start of the install with -vv' => ['install', '2', '0', self::INSTALL . $all . $started, 0],
            'a start of the install' => ['install', '0', '0', self::INSTALL . $started, 0],
            'regression: a failed start of the update' => [
                'update', '0', self::COMPOSE_FAILED, self::UPDATE . $lastTenReported, 1,
            ],
            'regression: a failed start of the update with -vv' => [
                'update', '2', self::COMPOSE_FAILED, self::UPDATE . self::PULLED . $streamedThenReported, 1,
            ],
            'a start of the update with -vv' => [
                'update', '2', '0', self::UPDATE . self::PULLED . $all . $updated, 0,
            ],
            'a start of the update' => ['update', '0', '0', self::UPDATE . $updated, 0],
        ];
    }

    #[DataProvider('startProvider')]
    public function testTheStartIsReportedInEveryVerbosity(
        string $caller,
        string $verbose,
        string $composeExit,
        string $output,
        int $status
    ): void {
        [$exitCode, $printed] = $this->runStart($caller, $verbose, ['STUB_UP_EXIT' => $composeExit]);

        $this->assertSame($output, $printed);
        $this->assertSame($status, $exitCode, $printed);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function envErrorProvider(): array
    {
        $reported = self::FAILED . self::ENV_ERROR_SHOWN . self::HINTS;
        $streamedThenReported = self::ENV_ERROR_SHOWN . self::FAILED . self::HINTS;

        return [
            'the install' => ['install', '0', self::INSTALL . $reported],
            'the install with -vv' => ['install', '2', self::INSTALL . $streamedThenReported],
            'regression: the update' => ['update', '0', self::UPDATE . self::PULL_FAILED . $reported],
            'the update with -vv, its pull too' => [
                'update', '2', self::UPDATE . self::ENV_ERROR_SHOWN . self::PULL_FAILED . $streamedThenReported,
            ],
        ];
    }

    /**
     * Regression, review finding: compose quotes values of .env, which can
     * be a password, and the update printed the end of compose's output
     * where it had sent it to /dev/null before, also into the log of a cron
     * job that runs it. The quickstart leaves every line about .env out, in
     * every verbosity (final-check finding: cutting off only the "line N:"
     * errors at their first quote let "Invalid template", ${VAR:?…} and
     * "variable is not set" through).
     */
    #[DataProvider('envErrorProvider')]
    public function testRegressionTheRestOfAnEnvLineComposeCannotReadIsHidden(
        string $caller,
        string $verbose,
        string $output
    ): void {
        [$exitCode, $printed] = $this->runStart(
            $caller,
            $verbose,
            ['STUB_UP_EXIT' => self::COMPOSE_FAILED, 'STUB_ENV_ERROR' => self::ENV_ERROR]
        );

        $this->assertSame($output, $printed);
        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $printed);
        }
        $this->assertSame(1, $exitCode, $printed);
    }

    /**
     * Review finding: start_containers() showed the end of compose's output
     * through a here-string, which needs a temp file (bash 5.0 and older
     * always, newer ones past 64 KiB). A full disk, a common reason for the
     * failed start itself, denies it, and set -e then ended the quickstart
     * before its hints. HEAD's install had used a pipe. A test run as root
     * cannot be denied the temp file, so this pins the shape.
     */
    public function testRegressionTheEndOfComposesOutputGoesThroughAPipe(): void
    {
        $function = $this->extract(self::FUNCTIONS['start_containers'], 'start_containers()');

        $this->assertStringNotContainsString('<<<', $function);
        $this->assertStringContainsString("printf '%s\\n' \"\$output\" | tail -10 | show_compose_output\n", $function);
    }

    /**
     * The lines `docker compose up` prints for the containers from $first
     * on, as the quickstart shows them.
     */
    private static function progress(int $first): string
    {
        $lines = '';
        for ($n = $first; $n <= self::CONTAINERS; $n++) {
            $lines .= "     Container ansilume-c{$n}-1  Started\n";
        }

        return $lines;
    }

    private function extract(string $pattern, string $what): string
    {
        $quickstart = (string)file_get_contents(dirname(__DIR__, 3) . '/bin/quickstart');
        $this->assertSame(1, preg_match($pattern, $quickstart, $match), "Could not extract {$what} from bin/quickstart");

        return $match[0];
    }

    /**
     * Runs the start of the containers as $caller runs it: under the options
     * of the quickstart and with its output helpers, show_compose_output()
     * and start_containers(), docker being the stand-in and Redis
     * authentication left as it is, and then the line that shows the
     * quickstart goes on.
     *
     * @param array<string, string> $env
     * @return array{0: int, 1: string} exit status, stdout and stderr without colours
     */
    private function runStart(string $caller, string $verbose, array $env): array
    {
        file_put_contents($this->directory . '/docker', self::STUB_DOCKER . "\n");
        chmod($this->directory . '/docker', 0o755);
        $quickstart = (string)file_get_contents(dirname(__DIR__, 3) . '/bin/quickstart');
        $this->assertStringContainsString(
            "\nset -euo pipefail\n",
            $quickstart,
            'The harness runs the start with the options of the quickstart.'
        );
        $script = "set -euo pipefail\n" . $this->extract('/^RED=.*?^die\(\)[^\n]*\n/ms', 'the output helpers')
            . $this->extract(self::FUNCTIONS['show_compose_output'], 'show_compose_output()')
            . $this->extract(self::FUNCTIONS['start_containers'], 'start_containers()')
            . "ensure_redis_password() { :; }\n"
            . $this->extract(self::CALLERS[$caller], "the start of the containers of the {$caller}")
            . 'echo "' . self::GOES_ON . "\"\n";

        $process = proc_open(
            ['bash', '-c', $script],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $this->directory,
            [
                'PATH' => $this->directory . ':' . (string)getenv('PATH'),
                'VERBOSE' => $verbose,
                'COMPOSE_CMD' => 'docker compose',
                'COMPOSE_ARGS' => '-f docker-compose.prebuilt.yml',
                'STUB_UP' => self::UP[$caller],
                'STUB_CONTAINERS' => (string)self::CONTAINERS,
            ] + $env
        );
        $this->assertIsResource($process);
        $output = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), (string)preg_replace('/\e\[[0-9;]*m/', '', $output)];
    }
}
