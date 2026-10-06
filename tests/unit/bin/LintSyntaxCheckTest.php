<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use app\helpers\FileHelper;
use PHPUnit\Framework\TestCase;

/**
 * Regression: the PHP syntax check in bin/tests-lint.sh ran `docker compose
 * exec` inside a `while read` loop. docker compose exec reads stdin, so it
 * swallowed the rest of the file list and only the first file was ever
 * linted; a syntax error anywhere else passed the suite.
 */
class LintSyntaxCheckTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/lint_syntax_' . uniqid('', true);
        mkdir($this->dir, 0o700, true);
        file_put_contents($this->dir . '/a_valid.php', "<?php\necho 'a';\n");
        file_put_contents($this->dir . '/b_broken.php', "<?php\necho 'b'\n");
        file_put_contents($this->dir . '/c_valid.php', "<?php\necho 'c';\n");
        file_put_contents($this->dir . '/d_broken.php', "<?php\nfunction (\n");
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->dir);
    }

    private function syntaxCheckFunction(): string
    {
        $script = (string)file_get_contents(dirname(__DIR__, 3) . '/bin/tests-lint.sh');
        $this->assertSame(1, preg_match('/^php_syntax_check\(\) \{.*?\n\}/ms', $script, $m));

        return $m[0];
    }

    /**
     * @return array{out: string, rc: int}
     */
    private function runCheck(): array
    {
        // Fake dc that, like `docker compose exec`, consumes all of stdin.
        $harness = "RED=''\nNC=''\n"
            . 'dc() { cat > /dev/null; shift; "$TEST_PHP" "$@"; }' . "\n"
            . $this->syntaxCheckFunction() . "\n"
            . 'php_syntax_check < <(find "$TEST_DIR" -name "*.php" -print0 | sort -z)' . "\n"
            . 'echo "errors=$SYNTAX_ERRORS"';
        $process = proc_open(
            ['bash', '-c', 'set -euo pipefail' . "\n" . $harness],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['TEST_PHP' => PHP_BINARY, 'TEST_DIR' => $this->dir, 'PATH' => (string)getenv('PATH')]
        );
        $this->assertIsResource($process);
        $out = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['out' => $out, 'rc' => proc_close($process)];
    }

    public function testEveryFileIsCheckedAndEveryErrorIsCounted(): void
    {
        $result = $this->runCheck();

        $this->assertSame(0, $result['rc'], 'set -e must not abort at the first broken file');
        $this->assertStringContainsString('errors=2', $result['out']);
        $this->assertStringContainsString($this->dir . '/b_broken.php', $result['out']);
        $this->assertStringContainsString($this->dir . '/d_broken.php', $result['out']);
        $this->assertStringNotContainsString('a_valid.php', $result['out']);
        $this->assertStringNotContainsString('c_valid.php', $result['out']);
    }
}
