<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use app\helpers\FileHelper;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real bin/tests-lint.sh from a copy in a directory of its own, so
 * that it checks the scripts and migrations the test puts there: a script
 * with a ShellCheck warning, one with a syntax error, a dangling symlink, and
 * a migration file without a class, with a stand-in for ShellCheck on PATH,
 * as GitHub's runners have it.
 *
 * Regression: the checks kept the output of `bash -n` and of ShellCheck with
 * a plain statement and tested $? after it,
 * `sc_output=$(shellcheck -S warning "$script" 2>&1)` and then
 * `if [[ $? -ne 0 ]]`. set -e ended the suite at that statement on the first
 * finding, before the check showed it: the lint suite failed without a word
 * right after "Shell script checks", and none of its later checks ran.
 * GitHub's runners have ShellCheck, so the first warning in a script would
 * have failed the lint run of CI that way. The first line of a script that
 * head cannot read, the class of a migration file without one and the
 * migration timestamps of a checkout without migrations/ ended it the same
 * way, at `head -1`, `grep | head -1` and `find | … | uniq -d`.
 * ErrexitExitStatusTest keeps the test of $? after a statement out of every
 * script, and a suite that set -e ends early names the line and the command
 * it ended at (SuiteEndedEarlyTest).
 */
class LintSuiteFindingsTest extends TestCase
{
    private const STUB_DOCKER = "#!/usr/bin/env bash\n# docker for LintSuiteFindingsTest: no stack.\nexit 1\n";

    /** ShellCheck for the test: a warning for every script named *warns*. */
    private const STUB_SHELLCHECK = <<<'BASH'
        #!/usr/bin/env bash
        # shellcheck for LintSuiteFindingsTest: a warning for every script named *warns*.
        script="${*: -1}"
        case "$script" in
            *warns*)
                printf 'In %s line 2:\nunused=1\n^----^ SC2034 (warning): unused appears unused.\n' "$script"
                exit 1
                ;;
        esac
        BASH;

    /** composer and ansible-lint for the test: nothing to report. */
    private const STUB_QUIET = "#!/usr/bin/env bash\nexit 0\n";

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/lint-suite-' . uniqid('', true);
        mkdir($this->directory . '/repo/bin', 0o700, true);
        mkdir($this->directory . '/stubs', 0o700, true);
        foreach (['tests-lint.sh', 'tests-common.sh'] as $script) {
            $source = (string)file_get_contents(dirname(__DIR__, 3) . '/bin/' . $script);
            $this->executable('repo/bin/' . $script, $source);
        }
        $this->executable('stubs/docker', self::STUB_DOCKER);
        $this->executable('stubs/shellcheck', self::STUB_SHELLCHECK . "\n");
        $this->executable('stubs/composer', self::STUB_QUIET);
        $this->executable('stubs/ansible-lint', self::STUB_QUIET);
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->directory);
    }

    public function testRegressionEveryFindingIsShownAndTheSuiteRunsToItsSummary(): void
    {
        $this->executable('repo/bin/a-warns.sh', "#!/usr/bin/env bash\nunused=1\n");
        $this->executable('repo/bin/b-broken.sh', "#!/usr/bin/env bash\nif true; then\n");
        $this->executable('repo/bin/c-fine.sh', "#!/usr/bin/env bash\necho fine\n");
        symlink('nowhere', $this->directory . '/repo/bin/d-gone.sh');
        mkdir($this->directory . '/repo/migrations');
        file_put_contents(
            $this->directory . '/repo/migrations/m000001_000000_helpers.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n"
        );

        [$status, $output] = $this->runSuite();

        $this->assertStringContainsString(
            "✘  Missing shebang: bin/d-gone.sh\n",
            $output,
            'a first line head cannot read'
        );
        $this->assertStringContainsString("✘  Syntax error (bash): bin/b-broken.sh\n", $output);
        $this->assertStringContainsString('bin/b-broken.sh: line 3: syntax error', $output, "bash's own message");
        $this->assertStringContainsString("✘  Not executable: bin/d-gone.sh\n", $output);
        $this->assertStringContainsString(
            "✘  ShellCheck warnings: bin/a-warns.sh\n     In bin/a-warns.sh line 2:\n     unused=1\n"
            . "     ^----^ SC2034 (warning): unused appears unused.\n",
            $output
        );
        $this->assertStringNotContainsString('ShellCheck passed', $output);
        $this->assertStringContainsString("✘  4 shell script issue(s) found\n", $output);
        $this->assertStringNotContainsString('c-fine.sh', $output);
        $this->assertStringContainsString(
            "✔  All migration class names match their filenames\n",
            $output,
            'a migration file without a class'
        );
        $this->assertSummaryReached($output);
        $this->assertSame(1, $status, $output);
    }

    public function testRegressionACheckoutWithoutMigrationsRunsToItsSummary(): void
    {
        $this->executable('repo/bin/c-fine.sh', "#!/usr/bin/env bash\necho fine\n");

        [$status, $output] = $this->runSuite();

        $this->assertStringContainsString("✔  ShellCheck passed\n", $output);
        $this->assertStringContainsString("✔  All shell scripts pass checks (3 scripts)\n", $output);
        $this->assertStringContainsString("✔  No duplicate migration timestamps\n", $output);
        $this->assertSummaryReached($output);
        $this->assertSame(1, $status, 'the checkout has no OpenAPI spec and no Dockerfiles: ' . $output);
    }

    private function assertSummaryReached(string $output): void
    {
        $this->assertMatchesRegularExpression('/^Results: \d+ passed  \d+ failed  \d+ skipped/m', $output);
        $this->assertStringNotContainsString('ended early', $output);
    }

    /**
     * Runs the copy of bin/tests-lint.sh with the stand-ins on PATH.
     *
     * @return array{0: int, 1: string} exit code, output without colours
     */
    private function runSuite(): array
    {
        $process = proc_open(
            ['bash', $this->directory . '/repo/bin/tests-lint.sh'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $this->directory . '/repo',
            ['PATH' => $this->directory . '/stubs:' . (string)getenv('PATH'), 'HOME' => $this->directory]
        );
        $this->assertIsResource($process);
        $output = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), (string)preg_replace('/\e\[[0-9;]*m/', '', $output)];
    }

    private function executable(string $path, string $content): void
    {
        file_put_contents($this->directory . '/' . $path, $content);
        chmod($this->directory . '/' . $path, 0o755);
    }
}
