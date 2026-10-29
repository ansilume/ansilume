<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use app\helpers\FileHelper;
use PHPUnit\Framework\TestCase;

/**
 * Runs the PHP syntax check section of the real bin/tests-lint.sh in a
 * directory of its own, with a stand-in for dc that runs this PHP with the
 * app container's php.ini, which writes errors to the log instead of
 * showing them.
 *
 * Regression: the check ran `docker compose exec` inside a `while read`
 * loop. docker compose exec reads stdin, so it swallowed the rest of the
 * file list and only the first file was ever linted; a syntax error
 * anywhere else passed the suite.
 *
 * Regression: php -l exits 0 for a compile-time deprecation, and that
 * php.ini sent the message to the error log, so the check passed every
 * file that PHP deprecates when it compiles it: an implicitly nullable
 * parameter (PHP 8.4), a non-canonical cast such as (integer) (PHP 8.5).
 * Yii's error handler turns such a deprecation into an exception when the
 * file loads: the implicitly nullable parameter of
 * tests/integration/models/PasswordResetRequestFormTest.php aborted the
 * whole Integration suite on PHP 8.5, while the lint suite stayed green.
 * PHP now shows every message, and a file passes only when its output is
 * the one line of a clean file. The check also never linted the yii entry
 * script, which every console command, queue worker, runner and schedule
 * run starts and which has no .php extension.
 */
class LintSyntaxCheckTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/lint_syntax_' . uniqid('', true);
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->dir);
    }

    public function testEveryFileIsCheckedAndEveryErrorIsCounted(): void
    {
        $this->file('a_valid.php', "<?php\necho 'a';\n");
        $this->file('b_broken.php', "<?php\necho 'b'\n");
        $this->file('c_valid.php', "<?php\necho 'c';\n");
        $this->file('d_broken.php', "<?php\nfunction (\n");

        $result = $this->runCheck();

        $this->assertSame(0, $result['rc'], 'set -e must not abort at the first broken file: ' . $result['out']);
        $this->assertStringContainsString('errors=2', $result['out']);
        $this->assertMatchesRegularExpression(
            '~^  ✘  \./b_broken\.php\n     Parse error: syntax error, .+ in \./b_broken\.php on line 3\n     Errors parsing \./b_broken\.php$~m',
            $result['out'],
            "PHP's own message, not only the error log's"
        );
        $this->assertMatchesRegularExpression(
            '~^  ✘  \./d_broken\.php\n     Parse error: .+ in \./d_broken\.php on line 3\n     Errors parsing \./d_broken\.php$~m',
            $result['out']
        );
        $this->assertStringContainsString("  ✘  2 PHP file(s) fail the syntax check\n", $result['out']);
        $this->assertStringNotContainsString('a_valid.php', $result['out']);
        $this->assertStringNotContainsString('c_valid.php', $result['out']);
    }

    public function testRegressionACompileTimeDeprecationFailsTheFileWithPhpsMessage(): void
    {
        $this->file('nullable.php', "<?php\n\nfunction lookUp(int \$id = null): ?int\n{\n    return \$id;\n}\n");
        $this->file('cast.php', "<?php\n\n\$count = (integer)'3';\n");
        $this->file('clean.php', "<?php\n\nfunction lookUp(?int \$id = null): int\n{\n    return (int)\$id;\n}\n");

        $result = $this->runCheck();

        $this->assertSame(0, $result['rc'], $result['out']);
        $this->assertStringContainsString(
            "  ✘  ./nullable.php\n"
            . "     Deprecated: lookUp(): Implicitly marking parameter \$id as nullable is deprecated, the explicit nullable type must be used instead in ./nullable.php on line 3\n"
            . "     No syntax errors detected in ./nullable.php\n",
            $result['out']
        );
        $this->assertStringContainsString(
            "  ✘  ./cast.php\n"
            . "     Deprecated: Non-canonical cast (integer) is deprecated, use the (int) cast instead in ./cast.php on line 3\n"
            . "     No syntax errors detected in ./cast.php\n",
            $result['out']
        );
        $this->assertStringContainsString("  ✘  2 PHP file(s) fail the syntax check\n", $result['out']);
        $this->assertStringContainsString('errors=2', $result['out']);
        $this->assertStringNotContainsString('clean.php', $result['out']);
    }

    public function testACheckoutWithCleanFilesOnlyPasses(): void
    {
        $this->file('clean.php', "<?php\n\ndeclare(strict_types=1);\n\necho (int)'1';\n");
        $this->file('views/page.php', "<p><?= 'page' ?></p>\n");
        $this->file('yii', "#!/usr/bin/env php\n<?php\n\ndeclare(strict_types=1);\n\nexit(0);\n");

        $result = $this->runCheck();

        $this->assertSame(0, $result['rc'], $result['out']);
        $this->assertSame("  ✔  All PHP files pass syntax check\nerrors=0\n", $result['out']);
    }

    public function testRegressionTheYiiEntryScriptIsChecked(): void
    {
        $this->file('yii', "#!/usr/bin/env php\n<?php\n\n\$exitCode = 0\n");
        foreach (['vendor', 'runtime', 'docker', '.composer', '@runtime'] as $skipped) {
            $this->file($skipped . '/broken.php', "<?php\necho 'skipped'\n");
        }

        $result = $this->runCheck();

        $this->assertSame(0, $result['rc'], $result['out']);
        $this->assertMatchesRegularExpression(
            '~^  ✘  \./yii\n     Parse error: syntax error, .+ in \./yii on line 5\n     Errors parsing \./yii$~m',
            $result['out']
        );
        $this->assertStringContainsString("  ✘  1 PHP file(s) fail the syntax check\n", $result['out']);
        $this->assertStringNotContainsString('broken.php', $result['out'], 'dependencies, runtime/ and docker/ stay out of the check');
    }

    /** The commands of the section "PHP syntax check" of bin/tests-lint.sh. */
    private function syntaxCheckSection(): string
    {
        $script = (string)file_get_contents(dirname(__DIR__, 3) . '/bin/tests-lint.sh');
        $this->assertSame(1, preg_match('/^section "PHP syntax check"\n(.*?)^section /ms', $script, $m));

        return $m[1] ?? '';
    }

    /**
     * Runs the section in the test's directory.
     *
     * @return array{out: string, rc: int}
     */
    private function runCheck(): array
    {
        // A stand-in for dc that, like `docker compose exec`, consumes all of stdin.
        $harness = "RED=''\nNC=''\n"
            . 'ok() { echo "  ✔  $1"; }' . "\n"
            . 'fail() { echo "  ✘  $1"; }' . "\n"
            . 'dc() { cat > /dev/null; shift; "$TEST_PHP" "$@"; }' . "\n"
            . $this->syntaxCheckSection() . "\n"
            . 'echo "errors=$SYNTAX_ERRORS"';
        $process = proc_open(
            ['bash', '-c', 'set -euo pipefail' . "\n" . $harness],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $this->dir,
            ['TEST_PHP' => PHP_BINARY, 'PATH' => (string)getenv('PATH')]
        );
        $this->assertIsResource($process);
        $out = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return ['out' => $out, 'rc' => proc_close($process)];
    }

    private function file(string $path, string $content): void
    {
        $file = $this->dir . '/' . $path;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o700, true);
        }
        file_put_contents($file, $content);
    }
}
