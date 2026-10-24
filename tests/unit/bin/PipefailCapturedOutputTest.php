<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rule for the scripts in bin/ and the run steps of the workflows, which run
 * with `set -o pipefail` (GitHub runs a bash step with -eo pipefail): a
 * captured output reaches a command that stops reading early (grep with -q,
 * -m or -l, head, awk with exit) as a here-string, `grep -q … <<< "$OUT"` or
 * `head -20 <<< "$OUT"`, never through `echo "$OUT" |` or
 * `printf '%s' "$OUT" |`. Once that command has stopped reading, echo fails
 * to write the rest ("echo: write error: Broken pipe" where SIGPIPE is
 * ignored, as on GitHub's runners; killed by SIGPIPE elsewhere), so the
 * pipeline fails although the check matched, and outside a condition set -e
 * ends the script there.
 *
 * Regression: the CI run of v2.12.2 (2026-10-23) reported "Integration tests
 * failed" and "Code coverage (could not parse output)" for a PHPUnit run that
 * printed OK: bin/tests-phpunit.sh tested its output with
 * `echo "$COMBINED_OUT" | grep -q`, while echo still wrote the coverage
 * report behind the result. It passed locally, as the race went the other
 * way. PhpunitSuiteLargeOutputTest runs that script with such an output.
 */
class PipefailCapturedOutputTest extends TestCase
{
    /** A stretch of one pipeline stage: no pipe, no ; or &&, escapes kept. */
    private const STAGE = '(?:(?!&&)[^|;\n\\\\]|\\\\.)*';

    /** A pipe, not ||. */
    private const PIPE = '\|(?!\|)';

    public function testRegressionNoScriptPipesACapturedOutputIntoACommandThatStopsReadingEarly(): void
    {
        $files = array_merge(
            glob(self::root() . '/bin/*') ?: [],
            glob(self::root() . '/.github/workflows/*.yml') ?: []
        );
        $this->assertContains(self::root() . '/bin/tests-phpunit.sh', $files);

        $found = [];
        foreach ($files as $file) {
            foreach (explode("\n", (string)file_get_contents($file)) as $number => $line) {
                if (self::feedsAnEarlyReader($line)) {
                    $found[] = substr($file, strlen(self::root()) + 1) . ':' . ($number + 1) . ': ' . trim($line);
                }
            }
        }

        $this->assertSame([], $found, "Pass a captured output as a here-string (grep -q … <<< \"\$OUT\", head -20 <<< \"\$OUT\"):\n" . implode("\n", $found));
    }

    #[DataProvider('shapeProvider')]
    public function testTheRuleKnowsTheShapes(string $line, bool $feedsAnEarlyReader): void
    {
        $this->assertSame($feedsAnEarlyReader, self::feedsAnEarlyReader($line), $line);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function shapeProvider(): array
    {
        return [
            'grep -q, the CI failure' => ['    if echo "$COMBINED_OUT" | grep -qP "^OK|Tests: .* Failures: 0"; then', true],
            'grep -qi with an escaped pipe in the pattern' => ['if echo "$db_err" | grep -qi "ok\|access denied"; then', true],
            'printf of a parameter expansion' => ["if [ \"\$code\" = \"200\" ] && printf '%s' \"\${raw%\$'\\n'*}\" | grep -q '\"status\":\"ok\"'; then", true],
            'grep -m' => ['X=$(echo "$OUT" | grep -m1 -oP "Lines: \K\d+")', true],
            'grep --quiet' => ['echo "$OUT" | grep --quiet OK', true],
            'head behind another stage' => ['echo "$CSFIXER_OUT" | grep \'^\s*[0-9]\+)\' | head -20 | sed \'s/^/     /\'', true],
            'head behind an escaped pipe' => ['echo "$OUT" | grep "a\|b" | head -1', true],
            'head in a command substitution' => ['info "    Last output: $(echo "$last_output" | head -3 | redact)"', true],
            'awk with exit' => ['echo "$COMBINED_OUT" | awk \'/Code Coverage Report/{exit} {print}\' | tail -40', true],
            'a here-string' => ['if grep -qP "^OK" <<< "$COMBINED_OUT"; then', false],
            'head of a here-string' => ['head -20 <<< "$AUDIT" | sed \'s/^/     /\'', false],
            'tail reads everything' => ['echo "$OUT" | tail -30 | sed \'s/^/     /\'', false],
            'grep without a stop flag' => ['X=$(echo "$PHPCS_OUT" | grep -oP \'A TOTAL OF \K\d+\' || echo "0")', false],
            'awk without exit' => ['pid=$(echo "$uids" | awk \'{print $1}\')', false],
            'no variable' => ['echo "plain" | grep -q plain', false],
            'an escaped dollar' => ['echo "\$OUT" | grep -q OUT', false],
            'a command, not a captured output' => ['if docker compose ps app --quiet 2>/dev/null | grep -q .; then', false],
            'a pipe in a command substitution of the argument' => ['echo "REDIS_PASSWORD=$(gen_secret | head -c 32)" >> "$env_file"', false],
            'another pipeline after ;' => ['echo "$OUT" > out.txt; cat out.txt | head -1', false],
            'another command after &&' => ['echo "$OUT" && cat out.txt | head -1', false],
            'a comment' => ['# never echo "$OUT" | grep -q OK', false],
        ];
    }

    /**
     * Whether the line pipes echo or printf of a variable into grep with -q,
     * -m or -l (or their long names), head, or awk with exit, in the same
     * pipeline. A command substitution in the argument of echo ends the
     * search: the pipe after it is the substitution's.
     */
    private static function feedsAnEarlyReader(string $line): bool
    {
        if (preg_match('/^\s*#/', $line) === 1) {
            return false;
        }
        $stage = self::STAGE;
        $pipe = self::PIPE;
        $argument = '(?:(?!&&|\$\()[^|;\n\\\\]|\\\\.)*';
        $captured = '\b(?:echo|printf)\b(?:(?!&&)[^|;\n\\\\$]|\\\\.)*\$(?!\()' . $argument;
        $earlyReader = '(?:grep\b' . $stage . '?\s(?:-[a-zA-Z]*[qmlL]|--(?:quiet|silent|max-count|files-with(?:out)?-match(?:es)?))|head\b|awk\b' . $stage . '?\bexit\b)';

        return preg_match('/' . $captured . '(?:' . $pipe . $stage . ')*?' . $pipe . '\s*' . $earlyReader . '/', $line) === 1;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
