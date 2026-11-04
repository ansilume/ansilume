<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\StoredLintOutput;
use PHPUnit\Framework\TestCase;

/**
 * The output of a lint run as it is stored (StoredLintOutput): whole when it
 * fits the TEXT column lint_output (65,535 bytes), else its start and its
 * end, where ansible-lint prints its summary, with a note between them that
 * says how many bytes were left out. Measured in bytes, cut at a line break,
 * or at a character boundary where the part has none: never inside a
 * multibyte character, which the column would refuse.
 *
 * Regression (review of the lint queue): ansible-lint wrote 71,194 bytes for
 * a playbook of 60 legacy tasks, the UPDATE that stores the result and ends
 * the requests of the run failed with "Data too long for column
 * 'lint_output'", and the lint card showed "Lint queued" for good.
 */
final class StoredLintOutputTest extends TestCase
{
    /** ansible-lint's summary, which it writes last (stderr, after the findings on stdout). */
    private const SUMMARY = "\e[2mWARNING  Listing 302 violation(s) that are fatal\e[0m\n\n# Rule Violation Summary\n\n"
        . " 60 \e[34mname\e[0m \e[2mprofile:basic tags:idiom\e[0m\n"
        . "\n\e[31m\e[1mFailed\e[0m\e[0m: 302 failure(s), 0 warning(s) in 1 files processed of 1 encountered.";

    public function testAnOutputThatFitsIsStoredAsItIs(): void
    {
        foreach (['', 'Passed: 0 failure(s)', str_repeat('x', StoredLintOutput::MAX_BYTES), str_repeat("ä\n", intdiv(StoredLintOutput::MAX_BYTES, 3))] as $output) {
            $this->assertSame($output, StoredLintOutput::of($output), strlen($output) . ' bytes');
        }
        $this->assertSame(65535, StoredLintOutput::MAX_BYTES, 'what a TEXT column holds');
    }

    /**
     * One byte more than the column holds: the start and the end in whole
     * lines, the note between them, and no more than the column holds.
     */
    public function testRegressionAnOutputOneByteOverTheColumnIsCut(): void
    {
        $findings = $this->outputOf(StoredLintOutput::MAX_BYTES - 1000);
        $output = str_repeat('-', StoredLintOutput::MAX_BYTES - strlen($findings)) . "\n" . $findings;
        $this->assertSame(StoredLintOutput::MAX_BYTES + 1, strlen($output));

        $stored = StoredLintOutput::of($output);

        $this->assertLessThanOrEqual(StoredLintOutput::MAX_BYTES, strlen($stored));
        $this->assertCutAtLines($output, $stored);
    }

    /**
     * An output far over the column, as ansible-lint writes for a large
     * project: the start of the findings, the note, and the end with the
     * summary; the note says exactly how many bytes are missing.
     */
    public function testAnOutputFarOverTheColumnKeepsItsStartAndItsSummary(): void
    {
        $output = $this->outputOf(353978);

        $stored = StoredLintOutput::of($output);

        $this->assertLessThanOrEqual(StoredLintOutput::MAX_BYTES, strlen($stored));
        $this->assertGreaterThan(StoredLintOutput::MAX_BYTES - 300, strlen($stored), 'the room is used, up to a line at either end');
        [$start, $end] = $this->assertCutAtLines($output, $stored);
        $this->assertStringStartsWith("\e[31m\e[34mname[missing]", $start, 'the first finding');
        $this->assertStringEndsWith(self::SUMMARY, $end, 'the summary');
        $this->assertLessThanOrEqual(StoredLintOutput::END_BYTES, strlen($end));
        $this->assertGreaterThan(StoredLintOutput::END_BYTES - 300, strlen($end));
        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'));
    }

    /**
     * An output without line breaks is cut at a character boundary, never
     * inside a character of two, three or four bytes, which the column
     * (utf8mb4) refuses.
     *
     * @dataProvider characterProvider
     */
    public function testAnOutputWithoutLineBreaksIsCutAtACharacterBoundary(string $character): void
    {
        // An odd byte count before the characters moves every boundary.
        $output = 'x' . str_repeat($character, intdiv(StoredLintOutput::MAX_BYTES * 2, strlen($character)));

        $stored = StoredLintOutput::of($output);

        $this->assertLessThanOrEqual(StoredLintOutput::MAX_BYTES, strlen($stored));
        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'), 'whole characters only');
        [$start, $note, $end] = $this->partsOf($stored);
        $this->assertStringStartsWith($start, $output);
        $this->assertStringEndsWith($end, $output);
        $this->assertSame(sprintf(StoredLintOutput::LEFT_OUT, strlen($output) - strlen($start) - strlen($end), StoredLintOutput::MAX_BYTES), $note);
        $this->assertGreaterThan(StoredLintOutput::END_BYTES - 4, strlen($end), 'at most one character less than the room of the end');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function characterProvider(): array
    {
        return [
            'one byte' => ['a'],
            'two bytes' => ['ä'],
            'three bytes' => ['✓'],
            'four bytes' => ['😀'],
        ];
    }

    /**
     * The note says how many bytes were left out and how many a result
     * keeps, the most digits included.
     */
    public function testTheNoteSaysHowMuchWasLeftOut(): void
    {
        $output = str_repeat("0123456789abcdef\n", 70000);

        [$start, $note, $end] = $this->partsOf(StoredLintOutput::of($output));

        $leftOut = strlen($output) - strlen($start) - strlen($end);
        $this->assertGreaterThan(1000000, $leftOut);
        $this->assertSame(sprintf(StoredLintOutput::LEFT_OUT, $leftOut, StoredLintOutput::MAX_BYTES), $note);
        $this->assertStringContainsString((string)$leftOut, $note);
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * $stored is the start of $output in whole lines, the note on its own
     * line, and the end of $output in whole lines; the note's count is the
     * bytes between them.
     *
     * @return array{0: string, 1: string} the start and the end
     */
    private function assertCutAtLines(string $output, string $stored): array
    {
        [$start, $note, $end] = $this->partsOf($stored);
        $this->assertStringStartsWith($start . "\n", $output, 'the start, up to a line break');
        $this->assertStringEndsWith("\n" . $end, $output, 'the end, from a line break');
        $this->assertSame(sprintf(StoredLintOutput::LEFT_OUT, strlen($output) - strlen($start) - strlen($end), StoredLintOutput::MAX_BYTES), $note);

        return [$start, $end];
    }

    /**
     * The start, the note and the end of a stored output that was cut.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function partsOf(string $stored): array
    {
        $note = str_replace('%d', '\d+', preg_quote(StoredLintOutput::LEFT_OUT, '/'));
        $this->assertSame(1, preg_match('/^(.*)\n(' . $note . ')\n(.*)$/s', $stored, $parts), 'the note on a line of its own');

        return [$parts[1], $parts[2], $parts[3]];
    }

    /**
     * An output as ansible-lint writes it, of $bytes bytes or a few more:
     * findings of three lines each, on stdout, and the summary at the end
     * (stderr), joined as LintService joins them.
     */
    private function outputOf(int $bytes): string
    {
        $findings = '';
        for ($i = 1; strlen($findings) + strlen(self::SUMMARY) + 1 < $bytes; $i++) {
            $findings .= "\e[31m\e[34mname[missing]\e[0m\e[2m:\e[0m \e[31mAll tasks should be named.\e[0m\n"
                . "\e[35msite.yml\e[0m:" . ($i * 2 + 3) . " \e[2mTask/Handler: Prüfe Dienst ✓ {$i}\e[0m\n\e[0m\n";
        }
        $output = rtrim($findings, "\n") . "\n" . self::SUMMARY;
        $this->assertGreaterThanOrEqual($bytes, strlen($output));

        return $output;
    }
}
