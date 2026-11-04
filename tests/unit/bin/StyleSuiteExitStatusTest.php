<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use app\helpers\FileHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real bin/tests-style.sh without a Docker stack, with a stand-in
 * for php whose phpcs, PHP-CS-Fixer, PHPMD or PHPCPD prints what the case
 * gives it and exits with the case's code, as the real tool does: phpcs
 * 3.13 exits 0 without errors, 1 or 2 with them, 3 when it cannot run and
 * 255 on a fatal error; a dry run of PHP-CS-Fixer 3.94 exits 0 without
 * files to fix, 8 with them, 4, 16, 32 or 64 for a file it cannot lint, a
 * config it cannot use, an invalid rule option or an exception while
 * fixing, 1 on a PHP version newer than it supports and 255 on a fatal
 * error; PHPMD 3 exits 0 without violations, 2 with them, and 1, 3 or 255
 * when it did not complete; phpcpd 7.0.1 exits 0 without clones, 1 with
 * them ("Found N code clones"), 1 for an argument error or "No files found
 * to scan" too, and 255 on a fatal error. The outputs of phpcpd are those
 * of 7.0.1.
 *
 * Regression: a phpcs or php-cs-fixer that crashed counted as passed. The
 * suite read the number of findings of phpcs ("A TOTAL OF N ERRORS") and of
 * PHP-CS-Fixer ("Found N of M files") from their reports and took a missing
 * number for none (`|| echo "0"`), so a tool that ended before its report
 * passed: "PSR-12 check passed" for a ruleset phpcs could not read and for a
 * fatal error, "PHP-CS-Fixer passed (0 fixable issues)" for a config it
 * could not read, for an exception while fixing, and on a PHP version newer
 * than it supports, where it exits 1 before it checks a file, as 3.94 will
 * on PHP 8.6. The app container's php.ini logs errors without showing them,
 * so a fatal error left the output empty, and PHPMD, which fails on every
 * line it prints, passed that too. SuiteCountFallbackTest keeps the
 * fallback to 0 out of every suite.
 *
 * Regression: a phpcpd that crashed was reported as clones, "PHPCPD: ?
 * clone(s) found (exit 255)", with nothing under it but its version: the
 * check took every code but 0 for clones, and phpcpd ran without
 * -d display_errors=stderr, so its fatal error went to the log only.
 */
class StyleSuiteExitStatusTest extends TestCase
{
    /** docker for the test: no stack, so the suite runs php itself. */
    private const STUB_DOCKER = "#!/usr/bin/env bash\n# docker for StyleSuiteExitStatusTest: no stack.\nexit 1\n";

    private const STUB_PHP = <<<'BASH'
        #!/usr/bin/env bash
        # php for StyleSuiteExitStatusTest: phpcs, PHP-CS-Fixer, PHPMD and PHPCPD
        # print $STUBS/<tool>.out and exit with $STUBS/<tool>.rc; every call is
        # noted in $STUBS/calls.
        printf '%s\n' "$*" >> "$STUBS/calls"
        stub() { cat "$STUBS/$1.out"; exit "$(cat "$STUBS/$1.rc")"; }
        case "$*" in
            *" --version") echo "stub 1.0" ;;
            *"vendor/bin/phpcs "*) stub phpcs ;;
            *"vendor/bin/php-cs-fixer fix "*) stub fixer ;;
            *"vendor/bin/phpmd "*) stub phpmd ;;
            *"vendor/bin/phpcpd "*) stub phpcpd ;;
            *) echo "unexpected: php $*" >&2; exit 99 ;;
        esac
        BASH;

    /** What each tool prints and exits with when the case does not name it. */
    private const CLEAN = [
        'phpcs' => [0, ''],
        'fixer' => [0, "Found 0 of 543 files that can be fixed in 2.091 seconds, 324.00 MB memory used\n"],
        'phpmd' => [0, ''],
        'phpcpd' => [0, "7.0.1\nNo code clones found.\n\nTime: 00:00.249, Memory: 53.00 MB\n"],
    ];

    /** The runs of the tools, after the --version probes, which need no -d display_errors=stderr. */
    private const TOOL_RUNS = [
        'vendor/bin/phpcs ',
        'vendor/bin/php-cs-fixer fix ',
        'vendor/bin/phpmd analyze ',
        'vendor/bin/phpcpd ',
    ];

    /** What phpcpd 7.0.1 prints, after its version, when PHP shows the fatal error it ended with. */
    private const PHPCPD_FATAL = "Fatal error: Allowed memory size of 20971520 bytes exhausted (tried to allocate 2097152 bytes)"
        . " in /var/www/vendor/systemsdk/phpcpd/src/Detector/Strategy/DefaultStrategy.php on line 64\n"
        . "Stack trace:\n#0 /var/www/vendor/systemsdk/phpcpd/src/Detector/Strategy/DefaultStrategy.php(64): array_keys()\n#1 {main}\n";

    /** The summary report of phpcs up to its total. */
    private const PHPCS_SUMMARY = "\nPHP CODE SNIFFER REPORT SUMMARY\n"
        . "----------------------------------------------------------------------\n"
        . "FILE                                                  ERRORS  WARNINGS\n"
        . "----------------------------------------------------------------------\n"
        . "/var/www/services/ExampleService.php                  3       0\n";

    private const FIXER_HEAD = "PHP CS Fixer 3.94.2 7th Gear by Fabien Potencier, Dariusz Ruminski and contributors.\n"
        . "PHP runtime: 8.5.12\nLoaded config default from \".php-cs-fixer.dist.php\".\n"
        . "Running analysis on 4 cores with 10 files per process.\n\n";

    /** What PHP-CS-Fixer 3.94 prints on a PHP newer than it supports. */
    private const UNSUPPORTED_PHP = 'PHP CS Fixer currently supports PHP syntax only up to PHP 8.5, current PHP version: 8.6.0.'
        . ' Add `Config::setUnsupportedPhpVersionAllowed(true)` to allow executions on unsupported PHP versions.'
        . ' Such execution may be unstable and you may experience code modified in a wrong way.';

    /** The summary when only the case's tool failed its check. */
    private const ONE_FAILED = 'Results: 3 passed  1 failed  0 skipped';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/style-suite-exit-' . uniqid('', true);
        mkdir($this->directory, 0o700, true);
        file_put_contents($this->directory . '/docker', self::STUB_DOCKER);
        file_put_contents($this->directory . '/php', self::STUB_PHP . "\n");
        chmod($this->directory . '/docker', 0o755);
        chmod($this->directory . '/php', 0o755);
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->directory);
    }

    #[DataProvider('incompleteRunProvider')]
    public function testRegressionAToolThatDidNotCompleteFailsItsCheck(
        string $tool,
        int $exitCode,
        string $output,
        string $reported,
        string $notReported
    ): void {
        [$status, $suite] = $this->runSuite($tool, $exitCode, $output);

        $this->assertStringContainsString($reported, $suite);
        $this->assertStringNotContainsString($notReported, $suite);
        $this->assertStringContainsString(self::ONE_FAILED, $suite, 'the other checks still run');
        $this->assertStringNotContainsString('ended early', $suite);
        $this->assertSame(1, $status, $suite);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string, 3: string, 4: string}>
     */
    public static function incompleteRunProvider(): array
    {
        return [
            'phpcs: a fatal error without output' => [
                'phpcs', 255, '',
                "✘  PSR-12: phpcs did not complete (exit 255)\n     (no output)\n",
                'PSR-12 check passed',
            ],
            'phpcs: a ruleset it cannot read' => [
                'phpcs', 3,
                "ERROR: Ruleset /var/www/phpcs.xml is not valid\n- On line 3, column 1: Couldn't find end of Start Tag ruleset line 2\n\nRun \"phpcs --help\" for usage information\n",
                "✘  PSR-12: phpcs did not complete (exit 3)\n     ERROR: Ruleset /var/www/phpcs.xml is not valid\n",
                'PSR-12 check passed',
            ],
            'phpcs: a standard that is not installed' => [
                'phpcs', 3,
                "ERROR: the \"PSR12\" coding standard is not installed. The installed coding standards are MySource, PEAR, PSR1, PSR2, Squiz and Zend\n",
                "✘  PSR-12: phpcs did not complete (exit 3)\n     ERROR: the \"PSR12\" coding standard is not installed.",
                'PSR-12 check passed',
            ],
            'phpcs: a report that a fatal error cut off' => [
                'phpcs', 255,
                self::PHPCS_SUMMARY . "Fatal error: Allowed memory size of 268435456 bytes exhausted (tried to allocate 20480 bytes)\n",
                "✘  PSR-12: phpcs did not complete (exit 255)\n",
                'PSR-12 check passed',
            ],
            'fixer: a fatal error without output' => [
                'fixer', 255, '',
                "✘  PHP-CS-Fixer did not complete (exit 255)\n     (no output)\n",
                'PHP-CS-Fixer passed',
            ],
            'fixer: a PHP version newer than it supports' => [
                'fixer', 1,
                "PHP CS Fixer 3.94.2 7th Gear by Fabien Potencier, Dariusz Ruminski and contributors.\nPHP runtime: 8.6.0\n" . self::UNSUPPORTED_PHP . "\n",
                "✘  PHP-CS-Fixer did not complete (exit 1)\n",
                'PHP-CS-Fixer passed',
            ],
            'fixer: a file it cannot lint' => [
                'fixer', 4,
                self::FIXER_HEAD . "Files that were not fixed due to errors reported during linting before fixing:\n   1) /var/www/services/Broken.php\n",
                "✘  PHP-CS-Fixer did not complete (exit 4)\n",
                'PHP-CS-Fixer passed',
            ],
            'fixer: a config it cannot read' => [
                'fixer', 16,
                "\nIn ConfigurationResolver.php line 613:\n\n  Cannot read config file \".php-cs-fixer.dist.php\".\n\n",
                "✘  PHP-CS-Fixer did not complete (exit 16)\n",
                'PHP-CS-Fixer passed',
            ],
            'fixer: an invalid rule option' => [
                'fixer', 32,
                "\nIn FixerConfigurationResolver.php line 95:\n\n  [array_syntax] Invalid configuration: The option \"syntax\" with value \"longer\" is invalid.\n\n",
                "✘  PHP-CS-Fixer did not complete (exit 32)\n",
                'PHP-CS-Fixer passed',
            ],
            'fixer: an exception while fixing, after its report' => [
                'fixer', 64,
                self::FIXER_HEAD . "\nFound 0 of 543 files that can be fixed in 2.091 seconds, 324.00 MB memory used\n\n"
                . "Files that were not fixed due to errors reported during fixing:\n   1) services/ExampleService.php\n",
                "✘  PHP-CS-Fixer did not complete (exit 64)\n",
                'PHP-CS-Fixer passed',
            ],
            'phpmd: a fatal error without output' => [
                'phpmd', 255, '',
                "✘  PHPMD did not complete for controllers/ (exit 255)\n     (no output)\n",
                'PHPMD passed',
            ],
            'phpmd: an exception' => [
                'phpmd', 1,
                "The given file \"controllers\" does not exist.\n",
                "✘  PHPMD did not complete for controllers/ (exit 1)\n     The given file \"controllers\" does not exist.\n",
                'PHPMD passed',
            ],
            'phpmd: a file it could not process' => [
                'phpmd', 3,
                "controllers/Broken.php\t-\tUnexpected token: }, line: 12, col: 1, file: controllers/Broken.php.\n",
                "✘  PHPMD did not complete for controllers/ (exit 3)\n",
                'PHPMD passed',
            ],
            'phpcpd: a fatal error the log kept, after its version' => [
                'phpcpd', 255, '7.0.1',
                "✘  PHPCPD did not complete (exit 255)\n     7.0.1\n",
                'clone(s) found',
            ],
            'phpcpd: a fatal error that PHP shows' => [
                'phpcpd', 255, "7.0.1\n" . self::PHPCPD_FATAL,
                "✘  PHPCPD did not complete (exit 255)\n     7.0.1\n     Fatal error: Allowed memory size of 20971520 bytes exhausted",
                'clone(s) found',
            ],
            'phpcpd: no files to scan' => [
                'phpcpd', 1, "7.0.1\nNo files found to scan\n",
                "✘  PHPCPD did not complete (exit 1)\n     7.0.1\n     No files found to scan\n",
                'clone(s) found',
            ],
            'phpcpd: an argument error' => [
                'phpcpd', 1, "7.0.1\nUnknown option \"--frobnicate\"\n",
                "✘  PHPCPD did not complete (exit 1)\n     7.0.1\n     Unknown option \"--frobnicate\"\n",
                'clone(s) found',
            ],
        ];
    }

    public function testPhpmdStopsAtTheFirstDirectoryItDidNotComplete(): void
    {
        [$status, $suite] = $this->runSuite(
            'phpmd',
            255,
            "Fatal error: Uncaught PHPMD\\Exception\\RuleSetNotFoundException: Cannot find specified rule-set \"phpmd.xml\".\n"
        );

        $this->assertStringContainsString(
            "✘  PHPMD did not complete for controllers/ (exit 255)\n"
            . "     Fatal error: Uncaught PHPMD\\Exception\\RuleSetNotFoundException: Cannot find specified rule-set \"phpmd.xml\".\n",
            $suite
        );
        $this->assertStringNotContainsString('PHPMD did not complete for services/', $suite);
        $this->assertStringContainsString(self::ONE_FAILED, $suite, 'PHPMD fails once, PHPCPD still runs');
        $this->assertSame(1, $status, $suite);
    }

    public function testTheMessageOfAToolThatDidNotCompleteIsShown(): void
    {
        [, $fixer] = $this->runSuite('fixer', 1, "PHP runtime: 8.6.0\n" . self::UNSUPPORTED_PHP . "\n");
        [, $phpcs] = $this->runSuite('phpcs', 255, self::PHPCS_SUMMARY . "Fatal error: Allowed memory size exhausted\n");

        $this->assertStringContainsString(
            "✘  PHP-CS-Fixer did not complete (exit 1)\n     PHP runtime: 8.6.0\n     " . self::UNSUPPORTED_PHP . "\n",
            $fixer
        );
        $this->assertStringContainsString("✘  PSR-12: phpcs did not complete (exit 255)\n", $phpcs);
        $this->assertStringNotContainsString('error(s) found', $phpcs, 'a report without its total is no count of errors');
        $this->assertStringContainsString(
            "     /var/www/services/ExampleService.php                  3       0\n     Fatal error: Allowed memory size exhausted\n",
            $phpcs
        );
    }

    public function testFindingsAreCountedAndListed(): void
    {
        $summary = self::PHPCS_SUMMARY . "----------------------------------------------------------------------\n"
            . "A TOTAL OF 3 ERRORS AND 0 WARNINGS WERE FOUND IN 1 FILE\n"
            . "----------------------------------------------------------------------\n";
        [$fixableStatus, $fixable] = $this->runSuite('phpcs', 2, $summary . "PHPCBF CAN FIX 3 OF THESE SNIFF VIOLATIONS AUTOMATICALLY\n");
        [$phpcsStatus, $phpcs] = $this->runSuite('phpcs', 1, $summary);
        [$fixerStatus, $fixer] = $this->runSuite(
            'fixer',
            8,
            self::FIXER_HEAD . "   1) services/ExampleService.php\n   2) models/Example.php\n\n"
            . "Found 2 of 543 files that can be fixed in 2.091 seconds, 324.00 MB memory used\n"
        );

        foreach ([$fixable, $phpcs] as $suite) {
            $this->assertStringContainsString("✘  PSR-12: 3 error(s) found\n", $suite);
            $this->assertStringContainsString("     A TOTAL OF 3 ERRORS AND 0 WARNINGS WERE FOUND IN 1 FILE\n", $suite);
            $this->assertStringContainsString(self::ONE_FAILED, $suite);
        }
        $this->assertSame(1, $fixableStatus, $fixable);
        $this->assertSame(1, $phpcsStatus, $phpcs);
        $this->assertStringContainsString(
            "✘  PHP-CS-Fixer: 2 file(s) need fixing — run: php vendor/bin/php-cs-fixer fix\n"
            . "        1) services/ExampleService.php\n        2) models/Example.php\n",
            $fixer
        );
        $this->assertSame(1, $fixerStatus, $fixer);
    }

    public function testEveryLinePhpmdPrintsStillCountsAsAViolation(): void
    {
        $violation = "/var/www/services/ExampleService.php:12\tCyclomaticComplexity\tThe method run() has a Cyclomatic Complexity of 10.\n";
        [$violations, $suite] = $this->runSuite('phpmd', 2, $violation);
        [$printed, $deprecation] = $this->runSuite('phpmd', 0, "Deprecated: something PHPMD calls is deprecated\n");

        $this->assertStringContainsString("✘  PHPMD: 7 violation(s) found\n", $suite, 'one line in each of the 7 directories');
        $this->assertStringNotContainsString('did not complete', $suite);
        $this->assertSame(1, $violations, $suite);
        $this->assertStringContainsString("✘  PHPMD: 7 violation(s) found\n     Deprecated: something PHPMD calls is deprecated\n", $deprecation);
        $this->assertSame(1, $printed, $deprecation);
    }

    /**
     * An exit 1 with "Found N code clones" is clones: phpcpd writes "clones"
     * for one clone too.
     */
    public function testClonesAreCountedAndListed(): void
    {
        [$status, $suite] = $this->runSuite(
            'phpcpd',
            1,
            "7.0.1\nFound 2 code clones with 34 duplicated lines in 3 files:\n\n"
            . "  - /var/www/services/ExampleService.php:10-27 (17 lines)\n    /var/www/services/OtherService.php:20-37\n\n"
            . "  - /var/www/services/ExampleService.php:60-77 (17 lines)\n    /var/www/models/Example.php:40-57\n\n"
            . "0.14% duplicated lines out of 24644 total lines of code.\n"
            . "Average code clone size is 17 lines, the largest code clone has 17 lines\n\nTime: 00:00.249, Memory: 53.00 MB\n"
        );
        [$oneStatus, $one] = $this->runSuite(
            'phpcpd',
            1,
            "7.0.1\nFound 1 code clones with 23 duplicated lines in 2 files:\n\n"
            . "  - /var/www/services/ExampleService.php:2-25 (23 lines)\n    /var/www/services/OtherService.php:2-25\n\n"
            . "0.09% duplicated lines out of 24644 total lines of code.\n"
            . "Average code clone size is 23 lines, the largest code clone has 23 lines\n\nTime: 00:00.249, Memory: 53.00 MB\n"
        );

        $this->assertStringContainsString(
            "✘  PHPCPD: 2 clone(s) found\n     7.0.1\n     Found 2 code clones with 34 duplicated lines in 3 files:\n     \n"
            . "       - /var/www/services/ExampleService.php:10-27 (17 lines)\n",
            $suite
        );
        $this->assertStringNotContainsString('did not complete', $suite);
        $this->assertStringContainsString(self::ONE_FAILED, $suite);
        $this->assertSame(1, $status, $suite);
        $this->assertStringContainsString("✘  PHPCPD: 1 clone(s) found\n", $one);
        $this->assertStringNotContainsString('did not complete', $one);
        $this->assertSame(1, $oneStatus, $one);
    }

    /**
     * The app container's php.ini only logs errors, so a tool that ends with
     * a fatal error prints nothing of it unless PHP runs with
     * -d display_errors=stderr: every run of a tool has it (StyleSuitePhpmdTest
     * shows the difference with the real PHPMD). The --version probes need
     * none: a probe that fails skips its tool.
     */
    public function testEveryToolRunsWithPhpShowingItsErrors(): void
    {
        [$status, $suite] = $this->runSuite('phpcs', 0, '');
        $this->assertSame(0, $status, $suite);

        $runs = array_values(array_filter($this->calls(), static fn (string $call): bool => !str_ends_with($call, ' --version')));
        foreach (self::TOOL_RUNS as $tool) {
            $this->assertNotEmpty(
                array_filter($runs, static fn (string $call): bool => str_contains($call, $tool)),
                "a run of {$tool}"
            );
        }
        $showsItsErrors = '~^-d display_errors=stderr (?:'
            . implode('|', array_map(static fn (string $tool): string => preg_quote($tool, '~'), self::TOOL_RUNS))
            . ')~';
        foreach ($runs as $call) {
            $this->assertMatchesRegularExpression($showsItsErrors, $call, 'php runs the tool with -d display_errors=stderr');
        }
    }

    public function testACleanRunPasses(): void
    {
        [$status, $suite] = $this->runSuite('phpcs', 0, '');

        $this->assertStringContainsString("✔  PSR-12 check passed\n", $suite);
        $this->assertStringContainsString("✔  PHP-CS-Fixer passed (0 fixable issues)\n", $suite);
        $this->assertStringContainsString("✔  PHPMD passed (no violations)\n", $suite);
        $this->assertStringContainsString("✔  PHPCPD passed (no duplications)\n", $suite);
        $this->assertStringContainsString('Results: 4 passed  0 failed  0 skipped', $suite);
        $this->assertSame(0, $status, $suite);
    }

    /**
     * Runs bin/tests-style.sh with $tool printing $output and exiting with
     * $exitCode, the other tools clean.
     *
     * @return array{0: int, 1: string} exit code, output without colours
     */
    private function runSuite(string $tool, int $exitCode, string $output): array
    {
        foreach (array_replace(self::CLEAN, [$tool => [$exitCode, $output]]) as $name => [$code, $text]) {
            file_put_contents("{$this->directory}/{$name}.rc", (string)$code);
            file_put_contents("{$this->directory}/{$name}.out", $text);
        }
        file_put_contents($this->directory . '/calls', '');
        $process = proc_open(
            ['bash', dirname(__DIR__, 3) . '/bin/tests-style.sh'],
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
        $suite = (string)preg_replace('/\e\[[0-9;]*m/', '', (string)stream_get_contents($pipes[1]));
        fclose($pipes[1]);

        return [proc_close($process), $suite];
    }

    /**
     * The arguments of each call of php that the last run made.
     *
     * @return list<string>
     */
    private function calls(): array
    {
        return file($this->directory . '/calls', FILE_IGNORE_NEW_LINES) ?: [];
    }
}
