<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use app\helpers\FileHelper;
use PHPUnit\Framework\TestCase;

/**
 * Runs the PHPMD check of the real bin/tests-style.sh with the real
 * phpmd.xml and PHPMD, in a checkout of its own whose source directories
 * hold code at and one below every limit of phpmd.xml.
 *
 * Regression: PHPMD 3 deprecates the positional form the suite used
 * (`phpmd <dir> text phpmd.xml`) and prints that deprecation on every run,
 * as it prints its progress bar on stderr without --no-progress; the suite
 * counts every line PHPMD prints as a violation, so it failed for clean
 * code. PHPMD 3 no longer splits `--exclude vendor,tests` at the comma
 * either, so the directories it names would have been analysed.
 *
 * Regression: PHPMD 3 reports a value only above a rule's limit, where
 * PHPMD 2 reported a value equal to most limits as well, and it changed
 * two defaults that phpmd.xml relied on: the parameter limit and the
 * method names TooManyPublicMethods leaves out (PHPMD 3 adds test*). With
 * phpmd.xml unchanged, a cyclomatic complexity of 10, an NPath complexity
 * of 200, a method of 100 lines, a class of 600 lines, a function with 10
 * parameters and a class with 15 public methods and a public test*()
 * method all passed. The limits keep PHPMD 2's: each is reported at the
 * limit and passes one below it.
 *
 * Regression: each directory's output was appended without its last
 * newline, which $(…) drops, so the last violation of one directory was
 * glued to the first of the next and the count was one short.
 */
class StyleSuitePhpmdTest extends TestCase
{
    /** The directories the suite analyses. */
    private const DIRECTORIES = ['controllers', 'services', 'commands', 'jobs', 'components', 'helpers', 'models'];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/style_phpmd_' . uniqid('', true);
        mkdir($this->dir, 0o700, true);
        symlink(dirname(__DIR__, 3) . '/vendor', $this->dir . '/vendor');
        copy(dirname(__DIR__, 3) . '/phpmd.xml', $this->dir . '/phpmd.xml');
        foreach (self::DIRECTORIES as $directory) {
            $class = ucfirst($directory) . 'Clean';
            $this->file("{$directory}/{$class}.php", "final class {$class}\n{\n    public function run(int \$value): int\n    {\n        return \$value > 0 ? \$value : 0;\n    }\n}\n");
        }
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->dir);
    }

    public function testRegressionCleanCodePassesAndTheExcludedDirectoriesStayOut(): void
    {
        $this->file('services/tests/TooComplexTest.php', self::complexFunction('inTests', 12));
        $this->file('models/vendor/TooComplex.php', self::complexFunction('inVendor', 12));

        [$status, $output] = $this->runCheck();

        $this->assertSame(0, $status, $output);
        $this->assertSame("  ✔  PHPMD passed (no violations)\n", $output, 'no deprecation, no progress bar, nothing from tests/ or vendor/');
    }

    public function testRegressionEveryLimitIsReportedAtTheLimitAndPassesOneBelow(): void
    {
        $this->file(
            'services/AtTheLimits.php',
            self::complexFunction('ccnAt10', 10)
            . self::npathFunction('npathAt200', 200)
            . self::parameterFunction('paramsAt10', 10)
            . self::classWithMethodOf('MethodAt100Lines', 'linesAt100', 100)
            . self::classOfLines('ClassAt600Lines', 600)
        );
        $this->file(
            'models/AtTheMethodLimit.php',
            self::publicMethods('SixteenPublicMethods', 16, '')
            . self::publicMethods('FifteenAndATest', 15, self::method('testConnection'))
        );
        $this->file(
            'helpers/BelowTheLimits.php',
            self::complexFunction('ccnAt9', 9)
            . self::npathFunction('npathAt199', 199)
            . self::parameterFunction('paramsAt9', 9)
            . self::classWithMethodOf('MethodAt99Lines', 'linesAt99', 99)
            . self::classOfLines('ClassAt599Lines', 599)
            . self::publicMethods('FifteenPublicMethods', 15, '')
            . self::publicMethods('FifteenAndAGetter', 15, self::method('getName'))
        );

        [$status, $output] = $this->runCheck();

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString("  ✘  PHPMD: 9 violation(s) found\n", $output);
        $reported = [
            ['AtTheLimits', 'CyclomaticComplexity', 'The function ccnAt10() has a Cyclomatic Complexity of 10.'],
            ['AtTheLimits', 'CyclomaticComplexity', 'The function npathAt200() has a Cyclomatic Complexity of 201.'],
            ['AtTheLimits', 'NPathComplexity', 'The function npathAt200() has an NPath complexity of 200.'],
            ['AtTheLimits', 'ExcessiveParameterList', 'The function paramsAt10 has 10 parameters.'],
            ['AtTheLimits', 'ExcessiveMethodLength', 'The method linesAt100() has 100 lines of code.'],
            ['AtTheLimits', 'ExcessiveClassLength', 'The class ClassAt600Lines has 600 lines of code.'],
            ['AtTheMethodLimit', 'TooManyPublicMethods', 'The class SixteenPublicMethods has 16 public methods.'],
            ['AtTheMethodLimit', 'TooManyPublicMethods', 'The class FifteenAndATest has 16 public methods.'],
            ['BelowTheLimits', 'CyclomaticComplexity', 'The function npathAt199() has a Cyclomatic Complexity of 200.'],
        ];
        foreach ($reported as [$file, $rule, $message]) {
            $this->assertMatchesRegularExpression(
                '~^     \S+/' . $file . '\.php:\d+\s+' . $rule . '\s+' . preg_quote($message, '~') . ' ~m',
                $output,
                'one violation per line'
            );
        }
        foreach (['ccnAt9', 'NPath complexity of 199', 'paramsAt9', 'linesAt99', 'ClassAt599Lines', 'FifteenPublicMethods', 'FifteenAndAGetter'] as $passes) {
            $this->assertStringNotContainsString($passes, $output);
        }
    }

    /** The commands of the section "PHPMD (complexity + unused code)" of bin/tests-style.sh. */
    private function phpmdSection(): string
    {
        $script = (string)file_get_contents(dirname(__DIR__, 3) . '/bin/tests-style.sh');
        $this->assertSame(1, preg_match('/^section "PHPMD [^"]*"\n(.*?)^section /ms', $script, $m));

        return $m[1] ?? '';
    }

    /**
     * Runs the section in the test's checkout, with PHP and PHPMD of this
     * container, as the suite does without a Docker stack.
     *
     * @return array{0: int, 1: string} exit code, output
     */
    private function runCheck(): array
    {
        $harness = "RED=''\nNC=''\n"
            . 'ok() { echo "  ✔  $1"; }' . "\n"
            . 'fail() { echo "  ✘  $1"; }' . "\n"
            . 'skip() { echo "  –  $1 (skipped)"; }' . "\n"
            . 'dc() { "$@"; }' . "\n"
            . $this->phpmdSection();
        $process = proc_open(
            ['bash', '-c', 'set -euo pipefail' . "\n" . $harness],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $this->dir,
            ['PATH' => (string)getenv('PATH'), 'HOME' => $this->dir]
        );
        $this->assertIsResource($process);
        $output = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }

    /** A function whose cyclomatic complexity is $ccn: the if and one || for each further condition. */
    private static function complexFunction(string $name, int $ccn): string
    {
        $conditions = implode(' || ', array_map(static fn (int $i): string => "\$a[{$i}]", range(0, $ccn - 2)));

        return "function {$name}(array \$a): int\n{\n    if ({$conditions}) {\n        return 1;\n    }\n\n    return 0;\n}\n\n";
    }

    /** A function whose NPath complexity is $labels: a switch with that many labels. */
    private static function npathFunction(string $name, int $labels): string
    {
        $cases = implode(' ', array_map(static fn (int $i): string => "case {$i}:", range(1, $labels)));

        return "function {$name}(int \$x): int\n{\n    switch (\$x) {\n        {$cases}\n            return 1;\n    }\n\n    return 0;\n}\n\n";
    }

    private static function parameterFunction(string $name, int $count): string
    {
        $parameters = implode(', ', array_map(static fn (int $i): string => "int \$p{$i}", range(1, $count)));

        return "function {$name}({$parameters}): int\n{\n    return \$p1;\n}\n\n";
    }

    /** A class with one method of exactly $lines lines, from its signature to its closing brace. */
    private static function classWithMethodOf(string $class, string $method, int $lines): string
    {
        $body = '';
        for ($i = 1; $i <= $lines - 4; $i++) {
            $body .= "        \$v = {$i};\n";
        }

        return "final class {$class}\n{\n    public function {$method}(): int\n    {\n{$body}        return \$v;\n    }\n}\n\n";
    }

    /** A class of exactly $lines lines, from `final class` to its closing brace. */
    private static function classOfLines(string $class, int $lines): string
    {
        $fields = '';
        for ($i = 1; $i <= $lines - 3; $i++) {
            $fields .= "    public int \$f{$i} = {$i};\n";
        }

        return "final class {$class}\n{\n{$fields}}\n\n";
    }

    private static function publicMethods(string $class, int $count, string $extra): string
    {
        $methods = '';
        for ($i = 1; $i <= $count; $i++) {
            $methods .= self::method("run{$i}");
        }

        return "final class {$class}\n{\n{$methods}{$extra}}\n\n";
    }

    private static function method(string $name): string
    {
        return "    public function {$name}(): int\n    {\n        return 1;\n    }\n\n";
    }

    private function file(string $path, string $content): void
    {
        $file = $this->dir . '/' . $path;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o700, true);
        }
        file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\nnamespace fixtures;\n\n" . $content);
    }
}
