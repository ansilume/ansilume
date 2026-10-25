<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rule for the scripts in bin/, the docker entrypoints and the run steps of
 * the workflows, which run with set -e (GitHub runs a bash step with -e;
 * bin/tests.sh does not, and reads no status either): a script tests a
 * command it expects to fail in a condition, `if ! out=$(cmd); then`, or
 * keeps its status right after ||, `rc=0; out=$(cmd) || rc=$?`, or passes
 * it on there, `|| exit $?`, and reads $? and PIPESTATUS nowhere else. A
 * test of $? or PIPESTATUS after a plain statement never runs when the
 * statement failed: set -e has ended the script at the statement, before
 * the script says what failed.
 *
 * The rule reads one line at a time, so it also refuses a read of the right
 * status that is not on the line of the ||: `rc=$?` on the line after
 * `|| {`, `else rc=$?` after `if out=$(cmd); then`, and `local status=$?`
 * at the top of the function of a trap. Write `|| { rc=$?; …` on one line,
 * `rc=0; out=$(cmd) || rc=$?` instead of the if, and pass "$?" in the trap
 * (`trap 'report_early_end "$?" …' EXIT`, as bin/tests-common.sh does).
 * It finds a test of the status only: a plain capture whose empty result is
 * tested after it fails the same way, and no rule finds that.
 *
 * Regression: bin/tests-lint.sh kept the output of ShellCheck with
 * `sc_output=$(shellcheck -S warning "$script" 2>&1)` and tested
 * `[[ $? -ne 0 ]]` after it, and kept that of `bash -n` the same way, so the
 * lint suite ended without a word at the first finding
 * (LintSuiteFindingsTest). bin/quickstart read `${PIPESTATUS[0]:-$?}` after
 * the pipeline of `docker compose up` with -vv, so a failed start ended it
 * before it said so.
 */
class ErrexitExitStatusTest extends TestCase
{
    /** $? or ${?}. */
    private const STATUS = '\$(?:\?|\{\?\})';

    /**
     * The status kept or passed on right after ||: `|| rc=$?`,
     * `|| { rc=$?; …; }`, `|| local rc="$?"`, `|| exit $?`, `|| return $?`.
     */
    private const KEPT_AFTER_OR = '/\|\|\s*(?:\{\s*)?'
        . '(?:(?:exit|return)\s+|(?:(?:local|declare|export|readonly)\s+)?[A-Za-z_][A-Za-z0-9_]*=)'
        . '"?' . self::STATUS . '"?/';

    public function testRegressionNoScriptTestsAStatusThatSetEHasAlreadyActedOn(): void
    {
        $files = array_merge(
            glob(self::root() . '/bin/*') ?: [],
            glob(self::root() . '/docker/*/*.sh') ?: [],
            glob(self::root() . '/.github/workflows/*.y*ml') ?: []
        );
        $this->assertContains(self::root() . '/bin/tests-lint.sh', $files);
        $this->assertContains(self::root() . '/docker/runner/entrypoint.sh', $files);
        $this->assertContains(self::root() . '/.github/workflows/ci.yaml', $files);

        $found = [];
        foreach ($files as $file) {
            foreach (explode("\n", (string)file_get_contents($file)) as $number => $line) {
                if (self::readsAStatusAfterAStatement($line)) {
                    $found[] = substr($file, strlen(self::root()) + 1) . ':' . ($number + 1) . ': ' . trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $found,
            "Test the command in a condition (if ! out=\$(cmd); then) or keep its status on the line of || "
            . "(rc=0; out=\$(cmd) || rc=\$?, or || { rc=\$?; …); in the function of a trap, pass \"\$?\" in the trap:\n"
            . implode("\n", $found)
        );
    }

    #[DataProvider('shapeProvider')]
    public function testTheRuleKnowsTheShapes(string $line, bool $readsAStatusAfterAStatement): void
    {
        $this->assertSame($readsAStatusAfterAStatement, self::readsAStatusAfterAStatement($line), $line);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function shapeProvider(): array
    {
        return [
            'the ShellCheck check of the lint suite' => ['        if [[ $? -ne 0 ]]; then', true],
            'a test with [' => ['if [ $? -ne 0 ]; then', true],
            'the status kept after the statement' => ['rc=$?', true],
            'the status printed' => ['echo "exit code: $?"', true],
            'the braced status' => ['if [ "${?}" -ne 0 ]; then', true],
            'the braced status kept after the statement' => ['rc=${?}', true],
            'PIPESTATUS after a pipeline, the quickstart start' => ['    COMPOSE_EXIT=${PIPESTATUS[0]:-$?}', true],
            'PIPESTATUS alone' => ['status=("${PIPESTATUS[@]}")', true],
            'refused: the status on the line after || {' => ['        rc=$?', true],
            'refused: the status after else' => ['else rc=$?', true],
            'refused: the status at the top of the function of a trap' => ['    local status=$?', true],
            'the status kept after ||' => ['ALINT_OUT=$(cd deploy && ansible-lint 2>&1) || ALINT_RC=$?', false],
            'the status kept after || in braces' => ['out=$(cmd) || { rc=$?; echo "failed"; }', false],
            'the status kept after || without a space' => ['docker compose pull ||COMPOSE_EXIT=$?', false],
            'the braced status kept after ||' => ['out=$(cmd) || rc=${?}', false],
            'the quoted status kept after ||' => ['out=$(cmd) || rc="$?"', false],
            'a local kept after ||' => ['out=$(cmd) || local rc=$?', false],
            'exit with the status after ||' => ['cmd || exit $?', false],
            'return with the status after ||' => ['cmd || return $?', false],
            'a trap, which runs at the end' => ["trap 'report_early_end \"\$?\" \"\$BASH_COMMAND\"' EXIT", false],
            'an escaped dollar' => ['echo "\$? is the status of the last command"', false],
            'an escaped braced dollar' => ['echo "\${?} is the status of the last command"', false],
            'a comment' => ['# set -e never reaches if [[ $? -ne 0 ]] after a failed statement', false],
            'no status' => ['if ! sc_output=$(shellcheck -S warning "$script" 2>&1); then', false],
        ];
    }

    /**
     * Whether the line reads $? or PIPESTATUS other than right after || or
     * in a trap. A comment, and $? behind a backslash, read nothing.
     */
    private static function readsAStatusAfterAStatement(string $line): bool
    {
        if (preg_match('/^\s*(?:#|trap\s)/', $line) === 1) {
            return false;
        }
        $line = (string)preg_replace(self::KEPT_AFTER_OR, '', $line);

        return preg_match('/(?<!\\\\)' . self::STATUS . '|PIPESTATUS/', $line) === 1;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
