<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use app\helpers\FileHelper;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real bin/tests-phpunit.sh from a copy in a directory of its own,
 * so that the test decides what its .env holds, without a Docker stack, with
 * stand-ins for docker and php on PATH.
 *
 * Regression: three skips of the script never ran. Without a Docker stack
 * the migration run skips when it cannot connect to the database as root,
 * or when no root password is set; but the script kept the output of its
 * connection helper with a plain statement, which failed with the helper,
 * and read the root password from .env with `grep -m1 … | cut`, which
 * failed without a match. The coverage line skips when the output names
 * "Summary:" without the figures, as a run that died before its report
 * after an error naming DbErrorSummary::of() does; but the figures were read
 * with a plain `grep -oP`, which failed without a match. set -e ended the
 * suite at each of these statements without a word, and the checks after it
 * never ran. The same shape ended the lint suite at the first ShellCheck
 * warning (LintSuiteFindingsTest).
 */
class PhpunitSuiteSkipsTest extends TestCase
{
    /** docker for the test: no stack. */
    private const STUB_DOCKER = "#!/usr/bin/env bash\n# docker for PhpunitSuiteSkipsTest: no stack.\nexit 1\n";

    private const STUB_PHP = <<<'BASH'
        #!/usr/bin/env bash
        # php for PhpunitSuiteSkipsTest: the calls of bin/tests-phpunit.sh without Docker.
        case "$*" in
            */ansilume_migrate_*.php" setup "*) echo "error: SQLSTATE[HY000] [2002] Connection refused"; exit 1 ;;
            "yii migrate --interactive=0") echo "No new migrations found. Your system is up-to-date." ;;
            *"--testsuite=Unit,Integration "*) cat "$STUB_COVERAGE_RUN" ;;
            *"--testsuite=Unit "*) echo "OK (1 test, 1 assertion)" ;;
            *) echo "unexpected: php $*" >&2; exit 1 ;;
        esac
        BASH;

    /** A coverage run that passed, with its report. */
    private const PASSED_WITH_REPORT = "OK (2 tests, 2 assertions)\n\nCode Coverage Report:\n\n Summary:\n"
        . "  Classes: 75.43% (436/578)\n  Methods: 90.54% (3456/3817)\n  Lines:   95.18% (23456/24644)\n";

    /** The summary of a run whose only skip is the migration run. */
    private const GOES_ON = 'Results: 4 passed  0 failed  1 skipped';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/phpunit-suite-skips-' . uniqid('', true);
        mkdir($this->directory . '/repo/bin', 0o700, true);
        mkdir($this->directory . '/stubs', 0o700, true);
        foreach (['tests-phpunit.sh', 'tests-common.sh'] as $script) {
            copy(dirname(__DIR__, 3) . '/bin/' . $script, $this->directory . '/repo/bin/' . $script);
        }
        file_put_contents($this->directory . '/stubs/docker', self::STUB_DOCKER);
        file_put_contents($this->directory . '/stubs/php', self::STUB_PHP . "\n");
        chmod($this->directory . '/stubs/docker', 0o755);
        chmod($this->directory . '/stubs/php', 0o755);
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->directory);
    }

    public function testRegressionNoRootPasswordInTheEnvFileSkipsTheMigrationRun(): void
    {
        file_put_contents($this->directory . '/repo/.env', "APP_ENV=dev\nDB_HOST=db\n");

        [$status, $output] = $this->runSuite([], self::PASSED_WITH_REPORT);

        $this->assertStringContainsString(
            "–  Migration end-to-end test (Docker not available and DB_ROOT_PASSWORD not set) (skipped)\n",
            $output
        );
        $this->assertStringContainsString(self::GOES_ON, $output, 'the suite goes on after the skip');
        $this->assertSame(0, $status, $output);
    }

    public function testRegressionARootConnectionThatFailsSkipsTheMigrationRun(): void
    {
        [$status, $output] = $this->runSuite(['DB_ROOT_PASSWORD' => 'not-a-real-password'], self::PASSED_WITH_REPORT);

        $this->assertStringContainsString(
            "–  Migration end-to-end test (cannot connect to DB as root:"
            . " error: SQLSTATE[HY000] [2002] Connection refused) (skipped)\n",
            $output
        );
        $this->assertStringContainsString(self::GOES_ON, $output, 'the suite goes on after the skip');
        $this->assertSame(0, $status, $output);
    }

    public function testRegressionASummaryWithoutFiguresSkipsTheCoverageLine(): void
    {
        $died = "There was 1 error:\n\n1) app\\tests\\unit\\ExampleTest::testExample\n"
            . "Error: Call to undefined method app\\components\\DbErrorSummary::of()\n\n"
            . "ERRORS!\nTests: 2, Assertions: 1, Errors: 1.\n"
            . "PHP Fatal error:  Allowed memory size of 1073741824 bytes exhausted (tried to allocate 20480 bytes)\n";

        [$status, $output] = $this->runSuite([], $died);

        $this->assertStringContainsString("✘  Integration tests failed\n", $output);
        $this->assertStringContainsString("–  Code coverage (could not parse output) (skipped)\n", $output);
        $this->assertStringContainsString(
            "Failed checks:\n  ✘  Integration tests failed\n",
            $output,
            'the suite reaches its summary'
        );
        $this->assertSame(1, $status, $output);
    }

    public function testACoverageReportStillGivesItsFigures(): void
    {
        [$status, $output] = $this->runSuite([], self::PASSED_WITH_REPORT);

        $this->assertStringContainsString("✔  Coverage — Lines: 95.18%  Methods: 90.54%  Classes: 75.43%\n", $output);
        $this->assertSame(0, $status, $output);
    }

    /**
     * Runs the copy of bin/tests-phpunit.sh with the stand-ins and $env, its
     * coverage run printing $coverageRun.
     *
     * @param array<string, string> $env
     * @return array{0: int, 1: string} exit code, output without colours
     */
    private function runSuite(array $env, string $coverageRun): array
    {
        file_put_contents($this->directory . '/coverage-run.txt', $coverageRun);
        $process = proc_open(
            ['bash', $this->directory . '/repo/bin/tests-phpunit.sh'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $this->directory . '/repo',
            [
                'PATH' => $this->directory . '/stubs:' . (string)getenv('PATH'),
                'HOME' => $this->directory,
                'STUB_COVERAGE_RUN' => $this->directory . '/coverage-run.txt',
            ] + $env
        );
        $this->assertIsResource($process);
        $output = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), (string)preg_replace('/\e\[[0-9;]*m/', '', $output)];
    }
}
