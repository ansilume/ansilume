<?php

declare(strict_types=1);

namespace app\tests\unit\controllers;

use app\controllers\RunnerGroupController;
use PHPUnit\Framework\TestCase;

/**
 * The texts of the runner group pages live in constants of
 * RunnerGroupController, and the E2E specs quote them through the
 * constants of the same name in tests/e2e/lib/runner-groups.ts: a change of
 * one without the other fails here, not only in a browser run.
 *
 * Regression: the list of runner groups said that runners register
 * "using the group token", but a runner group has no token; each runner has
 * one of its own (RUNNER_TOKENS_NOTE). The texts of a group without runners
 * were literals of the view.
 */
class RunnerGroupPageTextsTest extends TestCase
{
    /** The constants of RunnerGroupController that the E2E specs quote. */
    private const QUOTED = ['RUNNER_MANAGEMENT_ADMIN_ONLY', 'RUNNER_TOKENS_NOTE', 'NO_RUNNERS', 'NO_RUNNERS_ADD_ONE'];

    public function testTheE2eSpecsQuoteTheTextsAsTheControllerHasThem(): void
    {
        $quoted = $this->typeScriptConstants('tests/e2e/lib/runner-groups.ts');
        foreach (self::QUOTED as $name) {
            $this->assertArrayHasKey($name, $quoted, "tests/e2e/lib/runner-groups.ts quotes {$name}.");
            $this->assertSame(constant(RunnerGroupController::class . '::' . $name), $quoted[$name], $name);
        }
    }

    public function testTheTokenNoteSaysEachRunnerHasATokenOfItsOwn(): void
    {
        $this->assertSame(
            'Each runner authenticates with a token of its own: an admin hands it out with Add Runner on the '
                . 'runner group\'s page, or the runner registers itself with the bootstrap secret.',
            RunnerGroupController::RUNNER_TOKENS_NOTE
        );
        $this->assertStringNotContainsString('group token', RunnerGroupController::RUNNER_TOKENS_NOTE);
    }

    public function testAGroupWithoutRunnersPointsAtTheFormOnlyBelowIt(): void
    {
        $this->assertSame('No runners yet.', RunnerGroupController::NO_RUNNERS);
        $this->assertSame(RunnerGroupController::NO_RUNNERS . ' Add one above.', RunnerGroupController::NO_RUNNERS_ADD_ONE);
    }

    /**
     * The string constants a TypeScript file exports in single quotes, by
     * name, unescaped.
     *
     * @return array<string, string>
     */
    private function typeScriptConstants(string $file): array
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/' . $file);
        $this->assertIsString($source, "{$file} cannot be read.");
        preg_match_all("/^export const ([A-Z_]+) = '((?:[^'\\\\]|\\\\.)*)';$/m", $source, $matches, PREG_SET_ORDER);
        $constants = [];
        foreach ($matches as $match) {
            $constants[$match[1]] = stripcslashes($match[2]);
        }

        return $constants;
    }
}
