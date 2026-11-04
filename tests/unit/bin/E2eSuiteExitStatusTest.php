<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use app\helpers\FileHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real bin/tests-e2e.sh with a stand-in for docker whose E2E seed,
 * Playwright build and run and teardown print what the case gives them and
 * exit with the case's code, as the real ones do: yii exits 0 when the seed
 * completed, also when items already existed, and 1 when it failed;
 * Playwright exits 0 when no test failed, also when every test was skipped,
 * 1 when one failed or an error came outside the tests, and 130 when the
 * run was interrupted.
 *
 * Regression: a Playwright run that ended with an error passed. The suite
 * dropped Playwright's exit code and passed every run that printed
 * "N passed" and no "N failed", such as one whose global teardown failed
 * after the tests, or one that was interrupted. A run passes now only with
 * exit 0, a "N passed" line and no "N failed" with N > 0; the counts fill
 * the message too.
 *
 * Regression: a seed that failed passed. The suite took any output with
 * "already exists" for a seed, and the seed prints that for each item it
 * finds before a later step can fail.
 */
class E2eSuiteExitStatusTest extends TestCase
{
    private const STUB_DOCKER = <<<'BASH'
        #!/usr/bin/env bash
        # docker for E2eSuiteExitStatusTest: each call of the E2E steps prints
        # $STUBS/<call>.out and exits with $STUBS/<call>.rc; every call is
        # noted in $STUBS/calls.
        printf '%s\n' "$*" >> "$STUBS/calls"
        stub() { cat "$STUBS/$1.out"; exit "$(cat "$STUBS/$1.rc")"; }
        case "$*" in
            "compose ps app --quiet") echo stubbed-app ;;
            "compose exec -T queue-worker sh -c "*|"compose exec -T notification-worker sh -c "*|"compose exec -T schedule-runner sh -c "*) echo "1000 1000" ;;
            "compose exec -T app php yii e2e/seed") stub seed ;;
            "compose --profile e2e build playwright") stub build ;;
            "compose --profile e2e run --rm playwright") stub run ;;
            "compose exec -T app php yii e2e/teardown") stub teardown ;;
            *) echo "unexpected: docker $*" >&2; exit 99 ;;
        esac
        BASH;

    /** A summary of Playwright's list reporter. */
    private const SUMMARY = "\n  322 skipped\n  910 passed (14.1m)\n";

    /** What each call prints and exits with when the case does not name it: a good run. */
    private const GOOD = [
        'seed' => [0, "E2E seed complete.\n"],
        'build' => [0, "#1 [playwright] building\n"],
        'run' => [0, 'Running 1232 tests using 1 worker' . "\n" . self::SUMMARY],
        'teardown' => [0, "E2E teardown complete.\n"],
    ];

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/e2e-suite-exit-' . uniqid('', true);
        mkdir($this->directory, 0o700, true);
        file_put_contents($this->directory . '/docker', self::STUB_DOCKER . "\n");
        chmod($this->directory . '/docker', 0o755);
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->directory);
    }

    public function testAGoodRunPasses(): void
    {
        [$status, $output] = $this->runSuite([]);

        $this->assertStringContainsString("✔  Playwright E2E passed (910 tests, 322 skipped)\n", $output);
        $this->assertStringContainsString("✔  E2E teardown complete\n", $output);
        $this->assertStringContainsString('Results: 5 passed  0 failed  0 skipped', $output);
        $this->assertSame(0, $status, $output);
    }

    #[DataProvider('runThatDidNotPassProvider')]
    public function testRegressionARunThatEndedWithAnErrorFailsByItsExitCode(int $exitCode, string $output, string $reported): void
    {
        [$status, $suite] = $this->runSuite(['run' => [$exitCode, $output]]);

        $this->assertStringContainsString($reported, $suite);
        $this->assertStringContainsString("✔  E2E teardown complete\n", $suite, 'the teardown still runs');
        $this->assertStringContainsString('Results: 4 passed  1 failed  0 skipped', $suite);
        $this->assertSame(1, $status, $suite);
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: string}>
     */
    public static function runThatDidNotPassProvider(): array
    {
        return [
            'an error in the global teardown after the tests' => [
                1,
                "Error in global teardown\n\n  Error: ENOENT: no such file or directory\n" . self::SUMMARY,
                "✘  Playwright E2E: 910 passed, 0 failed, 322 skipped (exit 1)\n",
            ],
            'an error outside the tests' => [
                1,
                "  1 error was not a part of any test, see above for details\n" . self::SUMMARY,
                "✘  Playwright E2E: 910 passed, 0 failed, 322 skipped (exit 1)\n",
            ],
            'an interrupted run' => [
                130,
                "  1 interrupted\n" . self::SUMMARY,
                "✘  Playwright E2E: 910 passed, 0 failed, 322 skipped (exit 130)\n",
            ],
            'failed tests' => [
                1,
                "  2 failed\n    [admin] › tests/projects/sync.spec.ts:12:5 › sync\n" . self::SUMMARY,
                "✘  Playwright E2E: 910 passed, 2 failed, 322 skipped (exit 1)\n",
            ],
            'no test ran' => [
                1,
                "Error: No tests found\n",
                "✘  Playwright E2E tests did not run (exit 1)\n",
            ],
        ];
    }

    /**
     * Playwright exits 0 when every test was skipped: the exit code alone
     * would pass a run that tested nothing, so a pass needs "N passed".
     */
    public function testARunWhoseEveryTestWasSkippedDidNotRun(): void
    {
        [$status, $output] = $this->runSuite(['run' => [0, "Running 1232 tests using 1 worker\n\n  1232 skipped\n"]]);

        $this->assertStringContainsString("✘  Playwright E2E tests did not run (exit 0)\n", $output);
        $this->assertStringContainsString('1232 skipped', $output, 'the reason is shown');
        $this->assertStringNotContainsString('Playwright E2E passed', $output);
        $this->assertStringContainsString("✔  E2E teardown complete\n", $output, 'the teardown still runs');
        $this->assertStringContainsString('Results: 4 passed  1 failed  0 skipped', $output);
        $this->assertSame(1, $status, $output);
    }

    public function testABuildThatFailedRunsNoTests(): void
    {
        [$status, $output] = $this->runSuite(['build' => [1, "ERROR: failed to solve: process did not complete\n"]]);

        $this->assertStringContainsString("✘  Playwright E2E tests did not run (exit 1)\n", $output);
        $this->assertStringContainsString('failed to solve', $output, 'the reason is shown');
        $this->assertNotContains('compose --profile e2e run --rm playwright', $this->calls());
        $this->assertSame(1, $status, $output);
    }

    public function testRegressionASeedThatFailedAfterFindingItemsFails(): void
    {
        $seed = "User e2e-admin already exists, skipping.\nUser e2e-operator already exists, skipping.\n"
            . "Error: Could not save user e2e-viewer: Username has already been taken.\n";
        [$status, $output] = $this->runSuite(['seed' => [1, $seed]]);

        $this->assertStringContainsString("✘  E2E seed failed (exit 1)\n", $output);
        $this->assertStringContainsString('Could not save user e2e-viewer', $output, 'the reason is shown');
        $this->assertNotContains('compose --profile e2e run --rm playwright', $this->calls(), 'no run on a seed that failed');
        $this->assertStringContainsString('Results: 3 passed  1 failed  0 skipped', $output);
        $this->assertSame(1, $status, $output);
    }

    public function testASeedThatFoundEveryItemPasses(): void
    {
        [$status, $output] = $this->runSuite(['seed' => [0, "User e2e-admin already exists, skipping.\nE2E seed complete.\n"]]);

        $this->assertStringContainsString("✔  Playwright E2E passed (910 tests, 322 skipped)\n", $output);
        $this->assertSame(0, $status, $output);
    }

    /**
     * Runs bin/tests-e2e.sh with the stand-in, each call as GOOD gives it
     * unless $cases names it.
     *
     * @param array<string, array{0: int, 1: string}> $cases
     * @return array{0: int, 1: string} the exit code and the output, colours removed
     */
    private function runSuite(array $cases): array
    {
        foreach (array_replace(self::GOOD, $cases) as $name => [$code, $text]) {
            file_put_contents("{$this->directory}/{$name}.rc", (string)$code);
            file_put_contents("{$this->directory}/{$name}.out", $text);
        }
        $process = proc_open(
            ['bash', dirname(__DIR__, 3) . '/bin/tests-e2e.sh'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $this->directory,
            [
                'PATH' => $this->directory . ':' . (string)getenv('PATH'),
                'HOME' => $this->directory,
                'STUBS' => $this->directory,
            ]
        );
        $this->assertIsResource($process);
        $output = (string)preg_replace('/\e\[[0-9;]*m/', '', (string)stream_get_contents($pipes[1]));
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }

    /**
     * The calls of docker that the last run made.
     *
     * @return list<string>
     */
    private function calls(): array
    {
        return file($this->directory . '/calls', FILE_IGNORE_NEW_LINES) ?: [];
    }
}
