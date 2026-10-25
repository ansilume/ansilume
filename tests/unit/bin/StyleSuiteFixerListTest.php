<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use app\helpers\FileHelper;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real bin/tests-style.sh without a Docker stack, with a stand-in
 * for php whose PHP-CS-Fixer counts files to fix without listing them.
 *
 * Regression: after "PHP-CS-Fixer: N file(s) need fixing" the script showed
 * the listed files with a plain `grep -m 20 … | sed`, which fails when grep
 * finds no line. set -e then ended the suite there, before PHPMD, PHPCPD and
 * the summary. The same shape ended the lint suite at the first ShellCheck
 * warning (LintSuiteFindingsTest).
 */
class StyleSuiteFixerListTest extends TestCase
{
    /** docker for the test: no stack, so the suite runs php itself. */
    private const STUB_DOCKER = "#!/usr/bin/env bash\n# docker for StyleSuiteFixerListTest: no stack.\nexit 1\n";

    private const STUB_PHP = <<<'BASH'
        #!/usr/bin/env bash
        # php for StyleSuiteFixerListTest: PHP-CS-Fixer counts two files without listing them.
        case "$*" in
            *" --version") echo "stub 1.0" ;;
            "vendor/bin/php-cs-fixer fix "*)
                printf 'Found 2 of 1552 files that can be fixed in 1.234 seconds, 20.00 MB memory used\n' ;;
            "vendor/bin/phpcpd "*) echo "No code clones found." ;;
        esac
        BASH;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/style-suite-' . uniqid('', true);
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

    public function testRegressionFilesToFixWithoutAListStillRunTheLaterChecks(): void
    {
        $process = proc_open(
            ['bash', dirname(__DIR__, 3) . '/bin/tests-style.sh'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $this->directory,
            ['PATH' => $this->directory . ':' . (string)getenv('PATH'), 'HOME' => $this->directory]
        );
        $this->assertIsResource($process);
        $output = (string)preg_replace('/\e\[[0-9;]*m/', '', (string)stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        $status = proc_close($process);

        $this->assertStringContainsString(
            "✘  PHP-CS-Fixer: 2 file(s) need fixing — run: php vendor/bin/php-cs-fixer fix\n",
            $output
        );
        $this->assertStringContainsString("✔  PHPMD passed (no violations)\n", $output);
        $this->assertStringContainsString("✔  PHPCPD passed (no duplications)\n", $output);
        $this->assertStringContainsString('Results: 3 passed  1 failed  0 skipped', $output);
        $this->assertSame(1, $status, $output);
    }
}
