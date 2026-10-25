<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use app\helpers\FileHelper;
use PHPUnit\Framework\TestCase;

/**
 * bin/tests-common.sh names the line and the command that set -e ended a
 * suite at, when it ended before print_summary, and keeps the suite's exit
 * status.
 *
 * Regression: the lint suite ended without a word right after "Shell script
 * checks" when ShellCheck found something: set -e ended it at
 * `sc_output=$(shellcheck -S warning "$script" 2>&1)`, before the check
 * showed the finding (LintSuiteFindingsTest). A suite that set -e ends
 * early now says where. For a pipeline the command is the pipeline's last
 * one, so the line is what points at it; review finding: the report named
 * only the command, `sed 's/^/     /'` for the failed
 * `grep -m 20 … | sed 's/^/     /'` of the style suite.
 */
class SuiteEndedEarlyTest extends TestCase
{
    /** docker for the test: no stack, so tests-common.sh runs everything locally. */
    private const STUB_DOCKER = "#!/usr/bin/env bash\nexit 1\n";

    /** The suite's first lines; its body starts on line 4. */
    private const SUITE_HEAD = "#!/usr/bin/env bash\nset -euo pipefail\nsource \"\$TEST_COMMON\"\n";

    private const ENDED_EARLY_AT = '  ✘  The suite ended early (exit %d), before its summary,'
        . ' at tests-example.sh:%d: %s' . "\n"
        . '     set -e ends a suite where a command fails outside a condition; the checks after it did not run.' . "\n";

    /** Where set -e did not end it: set -u, or an explicit exit. */
    private const ENDED_EARLY = '  ✘  The suite ended early (exit %d), before its summary, at: %s' . "\n"
        . '     The checks after it did not run; the lines above say why.' . "\n";

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/suite-ended-early-' . uniqid('', true);
        mkdir($this->directory, 0o700, true);
        file_put_contents($this->directory . '/docker', self::STUB_DOCKER);
        chmod($this->directory . '/docker', 0o755);
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->directory);
    }

    public function testRegressionASuiteThatSetEEndsNamesTheLineAndTheCommand(): void
    {
        $result = $this->runSuite(<<<'BASH'
            ok "a check before"
            sc_output=$(printf 'SC2034 (warning): unused appears unused.\n'; exit 1)
            if [[ $? -ne 0 ]]; then
                fail "never shown"
            fi
            print_summary
            BASH);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString("✔  a check before\n", $result['out']);
        $command = "sc_output=$(printf 'SC2034 (warning): unused appears unused.\\n'; exit 1)";
        $this->assertStringEndsWith("\n" . sprintf(self::ENDED_EARLY_AT, 1, 5, $command), $result['out']);
        $this->assertStringNotContainsString('never shown', $result['out']);
        $this->assertStringNotContainsString('Results:', $result['out']);
    }

    public function testACommandThatFailsInAFunctionIsNamedWithItsLine(): void
    {
        $result = $this->runSuite(<<<'BASH'
            check() {
                grep -q needle <<< "haystack"
                ok "never shown"
            }
            check
            print_summary
            BASH);

        $this->assertSame(1, $result['rc']);
        $this->assertStringEndsWith(
            "\n" . sprintf(self::ENDED_EARLY_AT, 1, 5, 'grep -q needle <<< "haystack"'),
            $result['out']
        );
        $this->assertStringNotContainsString('never shown', $result['out']);
    }

    /**
     * Review finding: for a pipeline the command is its last one, which did
     * not fail; the line names the pipeline.
     */
    public function testRegressionAPipelineIsNamedByItsLine(): void
    {
        $result = $this->runSuite(<<<'BASH'
            fail "PHP-CS-Fixer: 2 file(s) need fixing"
            grep -m 20 '^\s*[0-9]\+)' <<< "Found 2 of 1552 files" | sed 's/^/     /'
            print_summary
            BASH);

        $this->assertSame(1, $result['rc']);
        $this->assertStringEndsWith("\n" . sprintf(self::ENDED_EARLY_AT, 1, 5, "sed 's/^/     /'"), $result['out']);
    }

    /**
     * Review finding: for a subshell the report named the command before it.
     */
    public function testRegressionASubshellIsNamedWithItsLine(): void
    {
        $result = $this->runSuite(<<<'BASH'
            ok "a check before"
            ( grep -q needle <<< "haystack" )
            print_summary
            BASH);

        $this->assertSame(1, $result['rc']);
        $this->assertStringEndsWith(
            "\n" . sprintf(self::ENDED_EARLY_AT, 1, 5, '( grep -q needle <<< "haystack" )'),
            $result['out']
        );
    }

    /**
     * Review finding: the report went where the loop's output went.
     */
    public function testRegressionALoopWhoseOutputIsDiscardedIsStillReported(): void
    {
        $result = $this->runSuite(<<<'BASH'
            for script in a b; do
                grep -q needle <<< "haystack"
            done > /dev/null
            print_summary
            BASH);

        $this->assertSame(1, $result['rc']);
        $this->assertStringEndsWith(
            "\n" . sprintf(self::ENDED_EARLY_AT, 1, 5, 'grep -q needle <<< "haystack"'),
            $result['out']
        );
    }

    public function testTheSuiteKeepsTheExitStatusOfTheCommand(): void
    {
        $result = $this->runSuite("sh -c 'exit 3'\nprint_summary");

        $this->assertSame(3, $result['rc']);
        $this->assertStringEndsWith("\n" . sprintf(self::ENDED_EARLY_AT, 3, 4, "sh -c 'exit 3'"), $result['out']);
    }

    /**
     * Review finding: set -u ended this suite, not set -e; the report blamed
     * set -e.
     */
    public function testAnUnsetVariableIsNamedWithoutBlamingSetE(): void
    {
        $result = $this->runSuite("echo \"\$NOT_SET\"\nprint_summary");

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('NOT_SET: unbound variable', $result['out'], "bash's own message");
        $this->assertStringEndsWith("\n" . sprintf(self::ENDED_EARLY, 1, 'echo "$NOT_SET"'), $result['out']);
    }

    public function testFailuresThatTheSuiteHandlesSayNothing(): void
    {
        $result = $this->runSuite(<<<'BASH'
            handled=$(false || true)
            kept=$(false; echo "went on")
            if ! false; then ok "a condition"; fi
            rc=0
            out=$(false) || rc=$?
            probe() { false; echo "the probe goes on"; }
            if probe; then ok "a function in a condition"; fi
            print_summary
            BASH);

        $this->assertSame(0, $result['rc'], $result['out']);
        $this->assertStringContainsString('the probe goes on', $result['out']);
        $this->assertStringContainsString('All checks passed.', $result['out']);
        $this->assertStringNotContainsString('ended early', $result['out']);
    }

    public function testASuiteThatReachesItsSummarySaysNothingMore(): void
    {
        $failed = $this->runSuite("fail \"a failed check\"\nprint_summary");
        $passed = $this->runSuite("ok \"a check\"\nprint_summary");

        $this->assertSame(1, $failed['rc']);
        $this->assertStringContainsString("Failed checks:\n  ✘  a failed check\n", $failed['out']);
        $this->assertSame(0, $passed['rc']);
        $this->assertStringContainsString('All checks passed.', $passed['out']);
        $this->assertStringNotContainsString('ended early', $failed['out'] . $passed['out']);
    }

    /**
     * Runs $body as the suite tests-example.sh: with set -euo pipefail, after
     * sourcing bin/tests-common.sh, from line 4 on.
     *
     * @return array{out: string, rc: int} output without colours, exit code
     */
    private function runSuite(string $body): array
    {
        file_put_contents($this->directory . '/tests-example.sh', self::SUITE_HEAD . $body . "\n");
        $process = proc_open(
            ['bash', $this->directory . '/tests-example.sh'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $this->directory,
            [
                'PATH' => $this->directory . ':' . (string)getenv('PATH'),
                'TEST_COMMON' => dirname(__DIR__, 3) . '/bin/tests-common.sh',
            ]
        );
        $this->assertIsResource($process);
        $out = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return ['out' => (string)preg_replace('/\e\[[0-9;]*m/', '', $out), 'rc' => proc_close($process)];
    }
}
