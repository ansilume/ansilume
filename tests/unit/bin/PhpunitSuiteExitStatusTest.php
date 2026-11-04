<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use app\helpers\FileHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real bin/tests-phpunit.sh with a stand-in for docker whose
 * migrations and PHPUnit runs print what the case gives them and exit with
 * the case's code, as the real ones do: yii migrate 2.0.55 exits 0 and
 * prints "Migrated up successfully." or "No new migrations found." when
 * every migration it ran was applied, and exits 1 after "0 from 1 migration
 * was applied." when one failed or with the database's error when it cannot
 * connect; PHPUnit 10.5 exits 0 with "OK…" or "No tests executed!" when no
 * test failed and it raised no PHPUnit warning, 1 for failures and for a
 * PHPUnit warning (failOnPhpunitWarning is on by default: a test class
 * without tests, no code coverage driver), which it lists above "OK, but
 * there were issues!", 2 for errors and 255 on a fatal error.
 *
 * Regression: a failing integration run counted as a skip. The suite read
 * only PHPUnit's output, and when that named "Access denied", "Connection
 * refused" or "SQLSTATE[HY000] [1044]" anywhere, it reported "test database
 * not available" and exited 0: DbErrorSummaryPdoTest asserts the text
 * "Connection refused", so its failure printed that marker. Whether the test
 * database can be reached is now decided before the run, from its
 * migration, and the run is judged by its exit code.
 *
 * Regression: a failed migration of the test database passed. The check
 * took any output with "applied" for a pass, and Yii prints "0 from 1
 * migration was applied." when a migration fails. The migration run of the
 * fresh schema read "Migrated up successfully" from the output whatever the
 * exit code, and took "No new migrations found" on a schema without tables,
 * which means it migrated another database, for 0 migrations applied
 * cleanly.
 *
 * Regression: a run with a PHPUnit warning passed. The suite took any line
 * that starts with "OK" for a pass, and PHPUnit exits 1 under "OK, but
 * there were issues!" for a test class whose tests never run, or for a
 * coverage run without a coverage driver.
 */
class PhpunitSuiteExitStatusTest extends TestCase
{
    private const STUB_DOCKER = <<<'BASH'
        #!/usr/bin/env bash
        # docker for PhpunitSuiteExitStatusTest: each exec in the app container
        # prints $STUBS/<call>.out and exits with $STUBS/<call>.rc; every call
        # is noted in $STUBS/calls.
        printf '%s\n' "$*" >> "$STUBS/calls"
        stub() { cat "$STUBS/$1.out"; exit "$(cat "$STUBS/$1.rc")"; }
        case "$*" in
            "compose ps app --quiet") echo stubbed-app ;;
            "compose exec -T db mariadb "*) ;;
            *"DB_NAME=ansilume_migrate_test php yii migrate "*) stub fresh-schema ;;
            *"DB_NAME=ansilume_test php yii migrate "*) stub test-database ;;
            *"--testsuite=Unit,Integration "*) stub integration ;;
            *"--testsuite=Unit "*) stub unit ;;
            *) echo "unexpected: docker $*" >&2; exit 99 ;;
        esac
        BASH;

    /** What each call prints and exits with when the case does not name it: a good run. */
    private const GOOD = [
        'fresh-schema' => [0, "*** applying m000001_000000_init\n*** applied m000001_000000_init (time: 0.012s)\n\n"
            . "1 migration was applied.\n\nMigrated up successfully.\n"],
        'test-database' => [0, "Yii Migration Tool (based on Yii v2.0.55)\n\nNo new migrations found. Your system is up-to-date.\n"],
        'unit' => [0, "OK (4201 tests, 23447 assertions)\n"],
        'integration' => [0, "OK (12909 tests, 125760 assertions)\n" . self::COVERAGE_REPORT],
    ];

    private const COVERAGE_REPORT = "\nCode Coverage Report:\n  2026-10-30 06:20:02\n\n Summary:\n"
        . "  Classes: 75.43% (436/578)\n  Methods: 90.54% (3456/3817)\n  Lines:   95.18% (23456/24644)\n";

    /** A migration that failed, as yii migrate 2.0.55 reports it. */
    private const MIGRATION_FAILED = "*** applying m000091_000000_example\n"
        . "    > create table {{%example}} ...Exception: SQLSTATE[42S01]: Base table or view already exists\n"
        . "*** failed to apply m000091_000000_example (time: 0.012s)\n\n\n"
        . "0 from 1 migration was applied.\n\nMigration failed. The rest of the migrations are canceled.\n";

    /** What the skip of the integration run says. */
    private const NOT_AVAILABLE = '–  Integration tests (test database not available — create it:';

    /** What PHPUnit 10.5.63 prints for a coverage run without a coverage driver, and exits 1 for. */
    private const NO_COVERAGE_DRIVER = "PHPUnit 10.5.63 by Sebastian Bergmann and contributors.\n\n"
        . "Runtime:       PHP 8.5.12\nConfiguration: /var/www/phpunit.xml\n\n"
        . "Time: 07:07.683, Memory: 321.01 MB\n\n"
        . "There was 1 PHPUnit test runner warning:\n\n1) No code coverage driver available\n\n"
        . "OK, but there were issues!\nTests: 12909, Assertions: 125760, PHPUnit Warnings: 1.\n";

    /** What PHPUnit 10.5.63 prints for a test class without tests, and exits 1 for. */
    private const NO_TESTS_IN_CLASS = "There was 1 PHPUnit test runner warning:\n\n"
        . "1) No tests found in class \"app\\tests\\%s\\ExampleTest\".\n\n"
        . "OK, but there were issues!\nTests: %d, Assertions: %d, PHPUnit Warnings: 1.\n";

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/phpunit-suite-exit-' . uniqid('', true);
        mkdir($this->directory, 0o700, true);
        file_put_contents($this->directory . '/docker', self::STUB_DOCKER . "\n");
        chmod($this->directory . '/docker', 0o755);
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->directory);
    }

    #[DataProvider('failingRunNamingTheDatabaseProvider')]
    public function testRegressionAFailingIntegrationRunFailsWhateverItsOutputNames(string $failure): void
    {
        [$status, $output] = $this->runSuite(['integration' => [1, $failure . self::COVERAGE_REPORT]]);

        $this->assertStringContainsString("✘  Integration tests failed (exit 1)\n", $output);
        $this->assertStringContainsString('1) app\\tests\\integration\\components\\DbErrorSummaryPdoTest::testRefusedConnection', $output, 'the failure is shown');
        $this->assertStringNotContainsString('test database not available', $output);
        $this->assertStringContainsString("✔  Coverage — Lines: 95.18%  Methods: 90.54%  Classes: 75.43%\n", $output);
        $this->assertStringContainsString("Failed checks:\n  ✘  Integration tests failed (exit 1)\n", $output);
        $this->assertSame(1, $status, $output);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function failingRunNamingTheDatabaseProvider(): array
    {
        $failure = static fn (string $expected, string $actual): string => "PHPUnit 10.5.63 by Sebastian Bergmann and contributors.\n\n"
            . "There was 1 failure:\n\n1) app\\tests\\integration\\components\\DbErrorSummaryPdoTest::testRefusedConnection\n"
            . "Failed asserting that two strings are identical.\n--- Expected\n+++ Actual\n@@ @@\n"
            . "-'{$expected}'\n+'{$actual}'\n\nFAILURES!\nTests: 12909, Assertions: 125760, Failures: 1.\n";

        return [
            'Connection refused' => [$failure('Connection refused', 'Connection failed')],
            'Access denied' => [$failure("Access denied for user 'ansilume'@'%'", 'Access refused')],
            'SQLSTATE[HY000] [1044]' => [$failure('SQLSTATE[HY000] [1044]', 'SQLSTATE[HY000] [1045]')],
        ];
    }

    #[DataProvider('failedRunProvider')]
    public function testRegressionARunThatDidNotPassFailsByItsExitCode(string $call, int $exitCode, string $output, string $reported): void
    {
        [$status, $suite] = $this->runSuite([$call => [$exitCode, $output]]);

        $this->assertStringContainsString($reported, $suite);
        $this->assertStringContainsString('Results: 4 passed  1 failed  0 skipped', $suite, 'the other checks still run');
        $this->assertStringNotContainsString('ended early', $suite);
        $this->assertSame(1, $status, $suite);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string, 3: string}>
     */
    public static function failedRunProvider(): array
    {
        $errorAfterSuccess = "*** applied m000001_000000_init (time: 0.012s)\n\n1 migration was applied.\n\nMigrated up successfully.\n"
            . "Error: Undefined constant app\\components\\LogTarget::LEVELS\n";
        $fatalAtShutdown = "PHP Fatal error:  Allowed memory size of 1073741824 bytes exhausted (tried to allocate 20480 bytes)\n";

        return [
            'test database: a migration that failed' => [
                'test-database', 1, self::MIGRATION_FAILED,
                "✘  Test database migration failed (exit 1)\n     *** applying m000091_000000_example\n",
            ],
            'test database: an error after the migrations were applied' => [
                'test-database', 1, $errorAfterSuccess,
                "✘  Test database migration failed (exit 1)\n",
            ],
            'fresh schema: an error after the migrations were applied' => [
                'fresh-schema', 1, $errorAfterSuccess,
                "✘  Migrations failed on a fresh schema (exit 1)\n",
            ],
            'fresh schema: no migration applied, another database migrated' => [
                'fresh-schema', 0, "No new migrations found. Your system is up-to-date.\n",
                "✘  Migrations failed on a fresh schema (exit 0)\n     No new migrations found. Your system is up-to-date.\n",
            ],
            'unit: a run that failed' => [
                'unit', 1, "There was 1 failure:\n\n1) app\\tests\\unit\\ExampleTest::testExample\n\nFAILURES!\nTests: 4201, Assertions: 23447, Failures: 1.\n",
                "✘  Unit tests failed (exit 1)\n",
            ],
            'unit: an error' => [
                'unit', 2, "There was 1 error:\n\n1) app\\tests\\unit\\ExampleTest::testExample\nError: Call to undefined method\n\nERRORS!\nTests: 4201, Assertions: 23440, Errors: 1.\n",
                "✘  Unit tests failed (exit 2)\n",
            ],
            'unit: a fatal error after the OK line' => [
                'unit', 255, "OK (4201 tests, 23447 assertions)\n\n" . $fatalAtShutdown,
                "✘  Unit tests failed (exit 255)\n",
            ],
            'integration: a fatal error after the OK line' => [
                'integration', 255, "OK (12909 tests, 125760 assertions)\n" . self::COVERAGE_REPORT . "\n" . $fatalAtShutdown,
                "✘  Integration tests failed (exit 255)\n",
            ],
            'unit: a PHPUnit warning under "OK, but there were issues!"' => [
                'unit', 1, sprintf(self::NO_TESTS_IN_CLASS, 'unit', 4201, 23447),
                "✘  Unit tests failed (exit 1)\n     There was 1 PHPUnit test runner warning:\n     \n"
                . "     1) No tests found in class \"app\\tests\\unit\\ExampleTest\".\n",
            ],
            'integration: a PHPUnit warning under "OK, but there were issues!"' => [
                'integration', 1, sprintf(self::NO_TESTS_IN_CLASS, 'integration', 12909, 125760) . self::COVERAGE_REPORT,
                "✘  Integration tests failed (exit 1)\n     There was 1 PHPUnit test runner warning:\n     \n"
                . "     1) No tests found in class \"app\\tests\\integration\\ExampleTest\".\n",
            ],
        ];
    }

    /**
     * Regression: no code coverage driver is a PHPUnit warning, for which
     * PHPUnit fails the coverage run. The integration check fails with the
     * warning under it, and the coverage line, which has no figures, says
     * that this is why, so the two lines tell one story.
     */
    public function testRegressionARunWithoutACoverageDriverFailsAndTheCoverageLineSaysWhy(): void
    {
        [$status, $output] = $this->runSuite(['integration' => [1, self::NO_COVERAGE_DRIVER]]);

        $this->assertStringContainsString("✘  Integration tests failed (exit 1)\n", $output);
        $this->assertStringContainsString("     1) No code coverage driver available\n", $output, 'the warning is shown');
        $this->assertStringContainsString(
            "–  Code coverage (no coverage driver, which fails the integration run — install pcov or xdebug) (skipped)\n",
            $output
        );
        $this->assertStringContainsString('Results: 3 passed  1 failed  1 skipped', $output, 'one failure for one cause');
        $this->assertStringContainsString("Failed checks:\n  ✘  Integration tests failed (exit 1)\n", $output);
        $this->assertSame(1, $status, $output);
    }

    #[DataProvider('unreachableTestDatabaseProvider')]
    public function testRegressionAnUnreachableTestDatabaseSkipsTheIntegrationRunBeforeIt(string $error): void
    {
        [$status, $output] = $this->runSuite(['test-database' => [1, "Yii Migration Tool (based on Yii v2.0.55)\n\n{$error}\n"]]);

        $this->assertStringContainsString("✘  Test database migration failed (exit 1)\n", $output);
        $this->assertStringContainsString(self::NOT_AVAILABLE, $output);
        $this->assertStringContainsString("–  Code coverage (test database not available) (skipped)\n", $output);
        $this->assertNotContains(
            'compose exec -T app php -d pcov.enabled=1 -d memory_limit=1G vendor/bin/phpunit --testsuite=Unit,Integration --coverage-text --colors=never',
            $this->calls(),
            'no integration run against a database it cannot reach'
        );
        $this->assertStringContainsString('Results: 2 passed  1 failed  2 skipped', $output);
        $this->assertSame(1, $status, 'the suite fails at the migration all the same: ' . $output);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unreachableTestDatabaseProvider(): array
    {
        return [
            'access denied (without YII_DEBUG)' => [
                "Error: yii\\db\\Exception (SQLSTATE HY000, driver error 1044): Access denied for user 'ansilume'@'%' to database 'ansilume_test'",
            ],
            'access denied (with YII_DEBUG)' => [
                "Exception 'yii\\db\\Exception' with message 'SQLSTATE[HY000] [1044] Access denied for user 'ansilume'@'%' to database 'ansilume_test''",
            ],
            'connection refused' => ['Error: yii\\db\\Exception (SQLSTATE HY000, driver error 2002): Connection refused'],
            'unknown database' => ["Error: yii\\db\\Exception (SQLSTATE HY000, driver error 1049): Unknown database 'ansilume_test'"],
        ];
    }

    public function testAGoodRunPasses(): void
    {
        [$status, $output] = $this->runSuite([]);

        $this->assertStringContainsString("✔  All migrations applied cleanly on a fresh schema (1 migrations)\n", $output);
        $this->assertStringContainsString("✔  Test database migrations up to date\n", $output);
        $this->assertStringContainsString("✔  Unit tests passed (OK (4201 tests, 23447 assertions))\n", $output);
        $this->assertStringContainsString("✔  Integration tests passed (OK (12909 tests, 125760 assertions))\n", $output);
        $this->assertStringContainsString("✔  Coverage — Lines: 95.18%  Methods: 90.54%  Classes: 75.43%\n", $output);
        $this->assertStringContainsString('Results: 5 passed  0 failed  0 skipped', $output);
        $this->assertSame(0, $status, $output);
    }

    public function testATestDatabaseThatMigratedNowPasses(): void
    {
        [$status, $output] = $this->runSuite(['test-database' => [0, self::GOOD['fresh-schema'][1]]]);

        $this->assertStringContainsString("✔  Test database migrations up to date\n", $output);
        $this->assertSame(0, $status, $output);
    }

    public function testARunThatExecutedNoTestIsStillASkip(): void
    {
        [$status, $output] = $this->runSuite(['integration' => [0, "PHPUnit 10.5.63 by Sebastian Bergmann and contributors.\n\nNo tests executed!\n"]]);

        $this->assertStringContainsString("–  Integration tests (no tests executed) (skipped)\n", $output);
        $this->assertSame(0, $status, $output);
    }

    /**
     * Runs bin/tests-phpunit.sh with the calls of $cases printing their text
     * and exiting with their code, the others as GOOD has them.
     *
     * @param array<string, array{0: int, 1: string}> $cases
     * @return array{0: int, 1: string} exit code, output without colours
     */
    private function runSuite(array $cases): array
    {
        foreach (array_replace(self::GOOD, $cases) as $name => [$code, $text]) {
            file_put_contents("{$this->directory}/{$name}.rc", (string)$code);
            file_put_contents("{$this->directory}/{$name}.out", $text);
        }
        $process = proc_open(
            ['bash', dirname(__DIR__, 3) . '/bin/tests-phpunit.sh'],
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
