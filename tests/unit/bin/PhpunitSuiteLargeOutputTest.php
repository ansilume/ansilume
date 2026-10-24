<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use app\helpers\FileHelper;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real bin/tests-phpunit.sh with a stand-in for docker whose
 * coverage run prints a large coverage report behind PHPUnit's result, with
 * SIGPIPE ignored, as on GitHub's runners.
 *
 * Regression: the CI run of v2.12.2 (2026-10-23) reported "Integration tests
 * failed" and "Code coverage (could not parse output)" for a coverage run that
 * printed "OK, but some tests were skipped!". The script tested the output
 * with `echo "$COMBINED_OUT" | grep -q`: grep stopped reading at the result,
 * echo then failed to write the coverage report behind it ("echo: write
 * error: Broken pipe"), and with pipefail the check failed. On a failed run
 * the same happened to the awk that cuts the report off, and set -e ended
 * the script before its summary. PipefailCapturedOutputTest keeps the shape
 * out of every script.
 */
class PhpunitSuiteLargeOutputTest extends TestCase
{
    /** Classes in the coverage report: about 3 MB, far above a pipe's buffer. */
    private const REPORTED_CLASSES = 40000;

    private const STUB_DOCKER = <<<'BASH'
        #!/usr/bin/env bash
        # docker for PhpunitSuiteLargeOutputTest: the calls of bin/tests-phpunit.sh.
        case "$*" in
            "compose ps app --quiet") echo stubbed-app ;;
            "compose exec -T db mariadb "*) ;;
            *"--testsuite=Unit,Integration "*) cat "$STUB_COVERAGE_RUN" ;;
            *"--testsuite=Unit "*) echo "OK (4201 tests, 23447 assertions)" ;;
            *"php yii migrate "*) printf '*** applied m000001_000000_init\nMigrated up successfully.\n' ;;
            *) echo "unexpected: docker $*" >&2; exit 1 ;;
        esac
        BASH;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/phpunit-suite-' . uniqid('', true);
        mkdir($this->directory, 0o700, true);
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->directory);
    }

    public function testRegressionAPassingRunWithALargeCoverageReportPasses(): void
    {
        [$status, $output] = $this->runSuite("OK, but some tests were skipped!\nTests: 12909, Assertions: 125760, Skipped: 2.\n");

        $this->assertStringContainsString('✔  Integration tests passed (OK, but some tests were skipped!' . "\n" . 'Tests: 12909, Assertions: 125760, Skipped: 2.)', $output);
        $this->assertStringContainsString('✔  Coverage — Lines: 95.18%  Methods: 90.54%  Classes: 75.43%', $output);
        $this->assertStringContainsString('Results: 5 passed  0 failed  0 skipped', $output);
        $this->assertStringNotContainsString('Broken pipe', $output);
        $this->assertSame(0, $status, $output);
    }

    public function testRegressionAFailedRunWithALargeCoverageReportIsReportedToTheEnd(): void
    {
        [$status, $output] = $this->runSuite("There was 1 failure:\n\n1) app\\tests\\integration\\ExampleTest::testExample\nFailed asserting that false is true.\n\nFAILURES!\nTests: 12909, Assertions: 125760, Failures: 1.\n");

        $this->assertStringContainsString('✘  Integration tests failed', $output);
        $this->assertStringContainsString('1) app\\tests\\integration\\ExampleTest::testExample', $output, 'the failure, without the coverage report');
        $this->assertStringNotContainsString('Methods: 100.00%', $output, 'the coverage report is cut off');
        $this->assertStringContainsString('✔  Coverage — Lines: 95.18%  Methods: 90.54%  Classes: 75.43%', $output);
        $this->assertStringContainsString('Results: 4 passed  1 failed  0 skipped', $output);
        $this->assertStringNotContainsString('Broken pipe', $output);
        $this->assertSame(1, $status, $output);
    }

    /**
     * Runs bin/tests-phpunit.sh with SIGPIPE ignored, its coverage run
     * printing $result and then the coverage report.
     *
     * @return array{0: int, 1: string} exit code, output without colours
     */
    private function runSuite(string $result): array
    {
        file_put_contents($this->directory . '/docker', self::STUB_DOCKER . "\n");
        chmod($this->directory . '/docker', 0o755);
        file_put_contents($this->directory . '/coverage-run.txt', $this->coverageRun($result));

        $process = proc_open(
            ['bash', '-c', 'trap "" PIPE; exec bash "$0" 2>&1', dirname(__DIR__, 3) . '/bin/tests-phpunit.sh'],
            [1 => ['pipe', 'w']],
            $pipes,
            $this->directory,
            [
                'PATH' => $this->directory . ':' . (string)getenv('PATH'),
                'HOME' => $this->directory,
                'STUB_COVERAGE_RUN' => $this->directory . '/coverage-run.txt',
            ]
        );
        $this->assertIsResource($process);
        $output = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), (string)preg_replace('/\e\[[0-9;]*m/', '', $output)];
    }

    /**
     * What a coverage run prints: progress, the result, the coverage report.
     */
    private function coverageRun(string $result): string
    {
        $output = "PHPUnit 10.5.63 by Sebastian Bergmann and contributors.\n\n"
            . str_repeat(str_repeat('.', 59) . "\n", 219)
            . "\nTime: 07:07.683, Memory: 321.01 MB\n\n"
            . $result
            . "\n\nCode Coverage Report:\n  2026-10-23 06:20:02\n\n Summary:\n"
            . "  Classes: 75.43% (436/578)\n  Methods: 90.54% (3456/3817)\n  Lines:   95.18% (23456/24644)\n\n";
        for ($class = 1; $class <= self::REPORTED_CLASSES; $class++) {
            $output .= "app\\services\\ExampleService{$class}\n  Methods: 100.00% ( 7/ 7)   Lines: 100.00% ( 52/ 52)\n";
        }

        return $output;
    }
}
