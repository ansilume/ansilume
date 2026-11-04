<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use app\helpers\FileHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real bin/tests-lint.sh from a copy in a directory of its own,
 * without a Docker stack, with a stand-in for composer whose audit prints
 * what the case gives it and exits with the case's code, as Composer 2.10
 * does: 0 when it has nothing to report; 1 for a security advisory, for an
 * abandoned package (its default for audit.abandoned is "fail"), for an
 * installed package on a filter list whose audit is "fail" (its built-in
 * policy.malware audits Packagist's malware list with "fail" by default)
 * and for an error of the command, such as "No installed packages found";
 * 100 when it cannot reach a repository; 255 on a fatal error. The outputs
 * are those of Composer 2.10.3.
 *
 * Regression: an audit that did not complete counted as passed. The check
 * failed only when the output named "CVE-", "GHSA-" or "Found N
 * vulnerab…", and reported "No known security vulnerabilities in
 * dependencies" for anything else: an audit that could not reach the
 * advisories of Packagist (exit 100), one without installed packages, one
 * that crashed, and one whose advisories have no CVE, for which Composer
 * prints NO CVE, an ID of PKSA- and "Found N security vulnerability
 * advisories". A Packagist outage or a rate limit turned the gate green.
 *
 * Regression: a package on Packagist's malware list passed, as "No known
 * security vulnerabilities in dependencies": its output names no "CVE-".
 * Judged by the exit code alone, it failed as "composer audit did not
 * complete (exit 1)", which docs/ci.md explains as a run that did not
 * finish, and beside an advisory as security advisories, whose first 20
 * lines could cut off the filter list's table, which Composer prints last.
 */
class LintSuiteComposerAuditTest extends TestCase
{
    private const STUB_DOCKER = "#!/usr/bin/env bash\n# docker for LintSuiteComposerAuditTest: no stack.\nexit 1\n";

    /** composer for the test: validate passes, audit prints $STUBS/audit.out and exits with $STUBS/audit.rc. */
    private const STUB_COMPOSER = <<<'BASH'
        #!/usr/bin/env bash
        # composer for LintSuiteComposerAuditTest.
        case "$1" in
            validate) ;;
            audit) cat "$STUBS/audit.out"; exit "$(cat "$STUBS/audit.rc")" ;;
            *) echo "unexpected: composer $*" >&2; exit 99 ;;
        esac
        BASH;

    /** ShellCheck and ansible-lint for the test: nothing to report. */
    private const STUB_QUIET = "#!/usr/bin/env bash\nexit 0\n";

    /** One advisory in Composer's table. */
    private const ADVISORY = "+-------------------+----------------------------------------------------------------------------------+\n"
        . "| Package           | silverstripe/framework                                                           |\n"
        . "| Severity          | medium                                                                           |\n"
        . "| Advisory ID       | PKSA-7qg6-pyzm-bc35                                                              |\n"
        . "| CVE               | %s |\n"
        . "| Title             | SS-2025-001 - User enumeration via timing attack                                 |\n"
        . "| URL               | https://www.silverstripe.org/download/security-releases/ss-2025-001              |\n"
        . "| Affected versions | <5.3.23                                                                          |\n"
        . "| Reported at       | 2025-04-10T02:37:11+00:00                                                        |\n"
        . "+-------------------+----------------------------------------------------------------------------------+\n";

    /** A package on Packagist's malware list, as Composer 2.10.3 prints it, last in its output. */
    private const FILTERED = "Found 1 package matching filters:\n"
        . self::FILTER_BORDER
        . "| Package              | Versions | List    | URL                                                                       | Reason  | ID                  | Source |\n"
        . self::FILTER_BORDER
        . "| aikido/endpoint-test | 0.0.1    | malware | https://packagist.org/packages/aikido/endpoint-test/filter-lists/malware/ | malware | PKFE-5wrh-r7dk-4mdq | aikido |\n"
        . self::FILTER_BORDER;

    private const FILTER_BORDER = "+----------------------+----------+---------+------------------------------------------"
        . "---------------------------------+---------+---------------------+--------+\n";

    /** The row of the package on the malware list. */
    private const MALWARE_ROW = '| aikido/endpoint-test | 0.0.1    | malware |';

    /** What the check reports for it. */
    private const MALWARE = 'composer audit found packages on a filter list (malware)';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/lint-suite-audit-' . uniqid('', true);
        mkdir($this->directory . '/repo/bin', 0o700, true);
        mkdir($this->directory . '/stubs', 0o700, true);
        foreach (['tests-lint.sh', 'tests-common.sh'] as $script) {
            $this->executable('repo/bin/' . $script, (string)file_get_contents(dirname(__DIR__, 3) . '/bin/' . $script));
        }
        $this->executable('stubs/docker', self::STUB_DOCKER);
        $this->executable('stubs/composer', self::STUB_COMPOSER . "\n");
        $this->executable('stubs/shellcheck', self::STUB_QUIET);
        $this->executable('stubs/ansible-lint', self::STUB_QUIET);
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->directory);
    }

    #[DataProvider('incompleteAuditProvider')]
    public function testRegressionAnAuditThatDidNotCompleteFails(int $exitCode, string $output, string $reported): void
    {
        [$status, $suite] = $this->runSuite($exitCode, $output);

        $this->assertStringContainsString($reported, $suite);
        $this->assertStringNotContainsString('No known security vulnerabilities', $suite);
        $this->assertListedAsFailed("composer audit did not complete (exit {$exitCode})", $suite);
        $this->assertSame(1, $status, $suite);
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: string}>
     */
    public static function incompleteAuditProvider(): array
    {
        $unreachable = "\nIn HttpDownloader.php line 332:\n\n"
            . "  Network disabled, request canceled: https://repo.packagist.org/packages.json\n\n"
            . "audit [--no-dev] [-f|--format FORMAT] [--locked] [--abandoned ABANDONED] [--ignore-severity IGNORE-SEVERITY] [--ignore-unreachable]\n";

        return [
            'Packagist cannot be reached' => [
                100, $unreachable,
                "✘  composer audit did not complete (exit 100)\n     \n     In HttpDownloader.php line 332:\n",
            ],
            'no installed packages' => [
                1, "No installed packages found. Please run \"composer install\" before running \"audit\" or pass \"--locked\" to audit the lock file.\n",
                "✘  composer audit did not complete (exit 1)\n     No installed packages found.",
            ],
            'a fatal error without output' => [
                255, '',
                "✘  composer audit did not complete (exit 255)\n     (no output)\n",
            ],
        ];
    }

    public function testRegressionAnAdvisoryWithoutACveFails(): void
    {
        [$status, $suite] = $this->runSuite(
            1,
            "Found 1 security vulnerability advisory affecting 1 package:\n" . sprintf(self::ADVISORY, 'NO CVE                                                                          ')
        );

        $this->assertStringContainsString(
            "✘  composer audit found security advisories\n     Found 1 security vulnerability advisory affecting 1 package:\n",
            $suite
        );
        $this->assertStringContainsString('| Advisory ID       | PKSA-7qg6-pyzm-bc35', $suite, 'the advisory is listed');
        $this->assertListedAsFailed('composer audit found security advisories', $suite);
        $this->assertSame(1, $status, $suite);
    }

    public function testAnAdvisoryWithACveStillFails(): void
    {
        [$status, $suite] = $this->runSuite(
            1,
            "Found 1 security vulnerability advisory affecting 1 package:\n" . sprintf(self::ADVISORY, 'CVE-2025-30148                                                                  ')
        );

        $this->assertListedAsFailed('composer audit found security advisories', $suite);
        $this->assertSame(1, $status, $suite);
    }

    /**
     * Composer fails an abandoned package unless composer.json names it in
     * config.audit.ignore-abandoned; the check follows it.
     */
    public function testAnAbandonedPackageFailsAsComposerDecides(): void
    {
        [$status, $suite] = $this->runSuite(
            1,
            "No security vulnerability advisories found.\nFound 1 abandoned package:\n"
            . "+-------------------+----------------------------------------------------------------------------------+\n"
            . "| Abandoned Package | Suggested Replacement                                                            |\n"
            . "+-------------------+----------------------------------------------------------------------------------+\n"
            . "| fzaninotto/faker  | none                                                                             |\n"
            . "+-------------------+----------------------------------------------------------------------------------+\n"
        );

        $this->assertStringContainsString("✘  composer audit found abandoned packages\n", $suite);
        $this->assertStringContainsString('| fzaninotto/faker  | none', $suite, 'the package is listed');
        $this->assertListedAsFailed('composer audit found abandoned packages', $suite);
        $this->assertSame(1, $status, $suite);
    }

    public function testRegressionAPackageOnTheMalwareListFailsAsSuch(): void
    {
        [$status, $suite] = $this->runSuite(1, "No security vulnerability advisories found.\n" . self::FILTERED);

        $this->assertStringContainsString('✘  ' . self::MALWARE . "\n", $suite);
        $this->assertStringContainsString(self::MALWARE_ROW, $suite, 'the package and its list are shown');
        $this->assertStringContainsString('PKFE-5wrh-r7dk-4mdq', $suite);
        $this->assertStringNotContainsString('did not complete', $suite);
        $this->assertListedAsFailed(self::MALWARE, $suite);
        $this->assertSame(1, $status, $suite);
    }

    /**
     * Composer prints the filter list's table after the advisories: the
     * check names the malware and shows the end of the output, where the
     * first 20 lines of two advisories left the table out.
     */
    public function testRegressionAPackageOnTheMalwareListBesideAdvisoriesFailsAsSuch(): void
    {
        [$status, $suite] = $this->runSuite(
            1,
            "Found 2 security vulnerability advisories affecting 1 package:\n"
            . sprintf(self::ADVISORY, 'CVE-2025-30148                                                                  ')
            . sprintf(self::ADVISORY, 'NO CVE                                                                          ')
            . self::FILTERED
        );

        $this->assertStringContainsString('✘  ' . self::MALWARE . "\n", $suite);
        $this->assertStringContainsString(self::MALWARE_ROW, $suite, 'the package and its list are shown');
        $this->assertListedAsFailed(self::MALWARE, $suite);
        $this->assertSame(1, $status, $suite);
    }

    public function testACleanAuditPasses(): void
    {
        [, $suite] = $this->runSuite(0, "No security vulnerability advisories found.\n");

        $this->assertStringContainsString("✔  No known security vulnerabilities in dependencies\n", $suite);
        $this->assertStringNotContainsString('composer audit', $suite);
        $this->assertMatchesRegularExpression('/^Results: \d+ passed  \d+ failed  \d+ skipped/m', $suite);
        $this->assertStringNotContainsString('ended early', $suite);
    }

    /**
     * --ignore-unreachable makes Composer exit 0 for an audit that could
     * not reach a repository, and --abandoned would overrule composer.json.
     */
    public function testTheAuditLeavesTheVerdictToComposer(): void
    {
        $script = (string)file_get_contents(dirname(__DIR__, 3) . '/bin/tests-lint.sh');

        $this->assertSame(1, preg_match_all('/\bdc composer audit\b[^\n]*/', $script, $calls), 'the audit of the lint suite');
        $this->assertStringNotContainsString('--ignore-unreachable', $calls[0][0]);
        $this->assertStringNotContainsString('--abandoned', $calls[0][0]);
    }

    /**
     * $failure fails its check and is listed under "Failed checks:" of the
     * summary, which the suite reached.
     */
    private function assertListedAsFailed(string $failure, string $output): void
    {
        $summary = strstr($output, "\nFailed checks:\n");
        $this->assertIsString($summary, $output);
        $this->assertStringContainsString("\n  ✘  {$failure}\n", $summary);
        $this->assertStringNotContainsString('ended early', $output);
    }

    /**
     * Runs the copy of bin/tests-lint.sh with the stand-ins on PATH, the
     * audit printing $output and exiting with $exitCode.
     *
     * @return array{0: int, 1: string} exit code, output without colours
     */
    private function runSuite(int $exitCode, string $output): array
    {
        file_put_contents($this->directory . '/audit.rc', (string)$exitCode);
        file_put_contents($this->directory . '/audit.out', $output);
        $process = proc_open(
            ['bash', $this->directory . '/repo/bin/tests-lint.sh'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $this->directory . '/repo',
            [
                'PATH' => $this->directory . '/stubs:' . (string)getenv('PATH'),
                'HOME' => $this->directory,
                'STUBS' => $this->directory,
            ]
        );
        $this->assertIsResource($process);
        $suite = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), (string)preg_replace('/\e\[[0-9;]*m/', '', $suite)];
    }

    private function executable(string $path, string $content): void
    {
        file_put_contents($this->directory . '/' . $path, $content);
        chmod($this->directory . '/' . $path, 0o755);
    }
}
