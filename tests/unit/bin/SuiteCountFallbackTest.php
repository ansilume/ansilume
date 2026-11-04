<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rule for the suites, bin/tests-*.sh: a check never takes a count it could
 * not read from a tool's output for none. No command substitution ends in a
 * fallback to 0, `$(… || echo "0")`, `$(… || echo 0)` or `$(… || printf '0')`:
 * a tool that crashed, did not start its run or could not reach its service
 * printed no count, and the check read its 0 as "nothing found" and passed.
 * A check judges its tool by the exit code (bin/tests-common.sh); a fallback
 * that only fills the message, such as `|| echo "?"`, decides nothing.
 *
 * The rule reads one line at a time and leaves comments out. It cannot see
 * a count that is empty and defaulted later, `${COUNT:-0}`, nor a pass that
 * a check reads from a marker in the output; StyleSuiteExitStatusTest,
 * PhpunitSuiteExitStatusTest and LintSuiteComposerAuditTest run the suites
 * with tools that did not complete. bin/diagnose and bin/quickstart are no
 * suites and stay out of it.
 *
 * Regression: a phpcs or php-cs-fixer that crashed counted as passed.
 * bin/tests-style.sh read the number of findings of phpcs and PHP-CS-Fixer
 * from their reports with `|| echo "0"`, so a ruleset or config the tool
 * could not read, a PHP newer than it supports, an exception or a fatal
 * error passed the check (StyleSuiteExitStatusTest).
 */
class SuiteCountFallbackTest extends TestCase
{
    /** `|| echo 0`, `|| echo "0"`, `|| printf '0'` and the like, closing a command substitution. */
    private const FALLBACK_TO_ZERO = '/\|\|\s*(?:echo|printf)\s+(?:-[a-zA-Z]+\s+)*(["\']?)0\1\s*\)/';

    public function testRegressionNoSuiteTakesACountItCouldNotReadForNone(): void
    {
        $files = glob(self::root() . '/bin/tests-*.sh') ?: [];
        $this->assertContains(self::root() . '/bin/tests-style.sh', $files);
        $this->assertContains(self::root() . '/bin/tests-phpunit.sh', $files);
        $this->assertContains(self::root() . '/bin/tests-lint.sh', $files);

        $found = [];
        foreach ($files as $file) {
            foreach (explode("\n", (string)file_get_contents($file)) as $number => $line) {
                if (self::fallsBackToZero($line)) {
                    $found[] = substr($file, strlen(self::root()) + 1) . ':' . ($number + 1) . ': ' . trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $found,
            "Judge the tool by its exit code (rc=0; out=\$(cmd) || rc=\$?) and read a count only from a run that"
            . " reported one, with a fallback that only fills the message (|| echo \"?\"):\n" . implode("\n", $found)
        );
    }

    #[DataProvider('shapeProvider')]
    public function testTheRuleKnowsTheShapes(string $line, bool $fallsBackToZero): void
    {
        $this->assertSame($fallsBackToZero, self::fallsBackToZero($line), $line);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function shapeProvider(): array
    {
        return [
            'the phpcs count of the style suite' => [
                '    PHPCS_ERROR_COUNT=$(echo "$PHPCS_OUT" | grep -oP \'A TOTAL OF \K\d+(?= ERROR)\' || echo "0")',
                true,
            ],
            'the PHP-CS-Fixer count of the style suite' => [
                '    CSFIXER_COUNT=$(echo "$CSFIXER_OUT" | grep -oP \'Found \K\d+(?= of)\' || echo "0")',
                true,
            ],
            'an unquoted 0' => ['COUNT=$(grep -c . <<< "$OUT" || echo 0)', true],
            'a 0 in single quotes' => ["COUNT=\$(grep -oP 'N=\\K\\d+' <<< \"\$OUT\" || echo '0')", true],
            'printf' => ["COUNT=\$(grep -oP 'N=\\K\\d+' <<< \"\$OUT\" || printf '0')", true],
            'echo with a flag' => ['COUNT=$(grep -oP "N=\K\d+" <<< "$OUT" || echo -n 0)', true],
            'a blank before the parenthesis' => ['COUNT=$(grep -oP "N=\K\d+" <<< "$OUT" || echo "0" )', true],
            'the end of a substitution over two lines' => ['        || echo "0")', true],
            'a fallback that only fills the message' => [
                '        PHPCS_ERROR_COUNT=$(grep -m1 -oP \'A TOTAL OF \K\d+(?= ERROR)\' <<< "$PHPCS_OUT" || echo "?")',
                false,
            ],
            'the status kept after ||' => ['PHPCS_OUT=$(dc php vendor/bin/phpcs . 2>&1) || PHPCS_RC=$?', false],
            'grep -c prints its own 0' => ['MIGRATION_COUNT=$(grep -cP \'^\*\*\* applied\' <<< "$MIGRATE_OUT" || true)', false],
            'another number' => ['COUNT=$(grep -c . <<< "$OUT" || echo 10)', false],
            'a 0 that does not end the substitution' => ['echo "$(cmd || echo 0 > /dev/null)"', false],
            'no command substitution' => ['grep -q OK <<< "$OUT" || echo 0', false],
            'a comment' => ['    # never: COUNT=$(grep -oP "N=\K\d+" <<< "$OUT" || echo "0")', false],
        ];
    }

    /**
     * Whether the line ends a command substitution in a fallback to 0. A
     * comment ends nothing.
     */
    private static function fallsBackToZero(string $line): bool
    {
        if (preg_match('/^\s*#/', $line) === 1) {
            return false;
        }

        return preg_match(self::FALLBACK_TO_ZERO, $line) === 1;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
