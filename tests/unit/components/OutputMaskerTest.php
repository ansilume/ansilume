<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\OutputMasker;
use PHPUnit\Framework\TestCase;

/**
 * The runner masks the job's password answers in its output before posting
 * it. Output arrives in chunks per stream, so a value can be cut anywhere;
 * the result must be the same as masking the whole stream at once.
 */
class OutputMaskerTest extends TestCase
{
    private const ANSWER = 'example-db-answer-4711';

    public function testWithoutAnswersOutputPassesUnchangedAndNothingIsHeldBack(): void
    {
        $masker = new OutputMasker([]);

        $this->assertSame('exam', $masker->feed('stdout', 'exam'));
        $this->assertSame('', $masker->flush('stdout'));
        $this->assertSame('any text', $masker->mask('any text'));
    }

    public function testAnAnswerInOneChunkIsMasked(): void
    {
        $masker = new OutputMasker(['db_password' => self::ANSWER]);

        $out = $masker->feed('stdout', 'ok: [localhost] => {"msg": "' . self::ANSWER . "\"}\n");

        $this->assertSame('ok: [localhost] => {"msg": "********"}' . "\n", $out);
        $this->assertSame('', $masker->flush('stdout'));
    }

    /**
     * Every needle, cut at every offset between two feed() calls, is masked
     * and leaves no fragment. The texts of a float answer begin alike
     * (12345678901234 is PHP's, 12345678901234.5 Python's), and the longest
     * wins wherever the chunks were cut.
     */
    public function testEveryNeedleCutAtEveryOffsetIsMasked(): void
    {
        $answers = ['a' => self::ANSWER, 'b' => 'pa"ss\\word/é', 'c' => 987654321, 'd' => 12345678901234.5, 'e' => 1.5e-7];
        $needles = ['example-db-answer-4711', 'pa"ss\\word/é', 'pa\\"ss\\\\word\\/\\u00e9', 'pa\\"ss\\\\word/é', '987654321'];
        foreach ([...$needles, '12345678901234.5', '12345678901234', '1.5e-07', '1.5e-7', '1.5E-7'] as $needle) {
            for ($cut = 1; $cut < strlen($needle); $cut++) {
                $masker = new OutputMasker($answers);
                $out = $masker->feed('stdout', 'before ' . substr($needle, 0, $cut));
                $out .= $masker->feed('stdout', substr($needle, $cut) . ' after');
                $out .= $masker->flush('stdout');

                $this->assertSame('before ******** after', $out, "needle {$needle} cut at {$cut}");
            }
        }
    }

    /**
     * Fed byte by byte, or cut at any one or two places, the stream comes
     * out as strtr() over the whole text gives it.
     */
    public function testAnyChunkingGivesTheResultOfMaskingTheWholeStream(): void
    {
        $answers = ['short' => 'pass', 'long' => 'password', 'other' => 'cdef', 'more' => 'abcd'];
        $text = "x password\npass passw abcdef pas\nxabcdzz password";
        $expected = strtr($text, array_fill_keys(['password', 'pass', 'cdef', 'abcd'], OutputMasker::MASK));

        $masker = new OutputMasker($answers);
        $out = '';
        foreach (str_split($text) as $byte) {
            $out .= $masker->feed('stdout', $byte);
        }
        $this->assertSame($expected, $out . $masker->flush('stdout'), 'byte by byte');

        for ($first = 1; $first < strlen($text); $first++) {
            for ($second = $first; $second < strlen($text); $second += 3) {
                $masker = new OutputMasker($answers);
                $out = $masker->feed('stdout', substr($text, 0, $first))
                    . $masker->feed('stdout', substr($text, $first, $second - $first))
                    . $masker->feed('stdout', substr($text, $second))
                    . $masker->flush('stdout');
                $this->assertSame($expected, $out, "cut at {$first} and {$second}");
            }
        }
    }

    /**
     * Random answers over a small alphabet overlap and nest a lot; random
     * chunks must still give strtr() over the whole stream.
     */
    public function testRandomOverlappingAnswersAndChunksGiveTheWholeStreamResult(): void
    {
        mt_srand(4711);
        for ($round = 0; $round < 300; $round++) {
            $answers = [];
            for ($i = 0; $i < mt_rand(1, 4); $i++) {
                $answers['k' . $i] = $this->randomText(mt_rand(4, 7));
            }
            $text = $this->randomText(mt_rand(0, 60));
            $replacements = array_fill_keys(array_values($answers), OutputMasker::MASK);

            $masker = new OutputMasker($answers);
            $out = '';
            $position = 0;
            while ($position < strlen($text)) {
                $size = mt_rand(1, 9);
                $out .= $masker->feed('stdout', substr($text, $position, $size));
                $position += $size;
            }
            $out .= $masker->flush('stdout');

            $this->assertSame(strtr($text, $replacements), $out, "round {$round}: " . json_encode([$answers, $text]));
        }
    }

    /**
     * strtr(): the longest answer wins, so an answer that contains another
     * leaves no fragment of the longer one.
     */
    public function testAnAnswerContainingAnotherLeavesNoFragment(): void
    {
        $masker = new OutputMasker(['a' => 'pass', 'b' => 'password']);

        $this->assertSame('******** and ********', $masker->mask('password and pass'));
        $this->assertSame('******** and ', $masker->feed('stdout', 'password and '));
        $this->assertSame('', $masker->feed('stdout', 'pass'), 'pass may still become password');
        $this->assertSame('********.', $masker->feed('stdout', '.'));
    }

    /**
     * Live output is not delayed: only a tail that may still become an
     * answer is held back, and it is released as soon as it cannot.
     */
    public function testOnlyATailThatMayBecomeAnAnswerIsHeldBack(): void
    {
        $masker = new OutputMasker(['db_password' => self::ANSWER]);

        $this->assertSame("TASK [deploy] ***\n", $masker->feed('stdout', "TASK [deploy] ***\n"));
        $this->assertSame('value: ', $masker->feed('stdout', 'value: exam'));
        $this->assertSame('example ', $masker->feed('stdout', 'ple '), 'released once it cannot be the answer');
        $this->assertSame('', $masker->feed('stdout', 'e'));
        $this->assertSame('e', $masker->flush('stdout'), 'the end of the stream releases the rest');
        $this->assertSame('', $masker->flush('stdout'));
    }

    public function testStreamsAreHeldBackSeparately(): void
    {
        $masker = new OutputMasker(['db_password' => self::ANSWER]);

        $this->assertSame('', $masker->feed('stdout', 'example-db'));
        $this->assertSame('err ', $masker->feed('stderr', 'err example-'));
        $this->assertSame('********', $masker->feed('stdout', '-answer-4711'));
        $this->assertSame('example-', $masker->flush('stderr'));
        $this->assertSame('', $masker->flush('stdout'));
    }

    /**
     * Ansible prints results as JSON, which escapes quotes, backslashes,
     * slashes (PHP) and non-ASCII characters (Python's default).
     */
    public function testTheJsonEscapedFormsOfAnAnswerAreMasked(): void
    {
        $masker = new OutputMasker(['db_password' => 'pa"ss\\word/é']);

        $this->assertSame(
            '******** ******** ******** ********',
            $masker->mask('pa"ss\\word/é pa\\"ss\\\\word\\/\\u00e9 pa\\"ss\\\\word/é pa\\"ss\\\\word\\/é')
        );
    }

    /**
     * @return array<string, array{0: mixed, 1: string, 2: string}>
     */
    public static function needleProvider(): array
    {
        return [
            'string of 4 bytes' => ['abcd', 'x abcd x', 'x ******** x'],
            'string of 3 bytes' => ['abc', 'x abc x', 'x abc x'],
            'multibyte string of 4 bytes' => ['éé', 'x éé x', 'x ******** x'],
            'integer of 4 digits' => [4711, 'port 4711', 'port ********'],
            'integer of 3 digits' => [471, 'port 471', 'port 471'],
            'negative integer' => [-4711, 'n=-4711', 'n=********'],
            'float' => [1234.5, 'f=1234.5', 'f=********'],
            'short float' => [1.5, 'f=1.5', 'f=1.5'],
            // Regression (CLS-1): a float was masked only in PHP's own text,
            // with 14 significant digits (1.0E+20), but Ansible prints it as
            // Python writes it, so these appeared in clear text.
            'float of 17 significant digits' => [0.12345678901234568, 'f=0.12345678901234568', 'f=********'],
            'float of 16 significant digits' => [3.141592653589793, 'f=3.141592653589793', 'f=********'],
            'float beyond the int range' => [1.2345678901234569e+23, 'f=1.2345678901234569e+23', 'f=********'],
            'float with an exponent, as JSON and YAML results write it' => [1e20, 'json=1e+20 yaml=1.0e+20', 'json=******** yaml=********'],
            'exponent with two digits' => [1.5e-7, 'f=1.5e-07', 'f=********'],
            'negative float' => [-1.5e-7, 'f=-1.5e-07', 'f=********'],
            'float below 0.0001, which Python writes with an exponent' => [0.00001, 'json=1e-05 yaml=1.0e-05', 'json=******** yaml=********'],
            'float that was masked up to its 14th digit' => [12345678901234.5, 'f=12345678901234.5', 'f=********'],
            // The answers file holds json_encode()'s text, 1000000000000000 for
            // this float: Python reads it as an int and prints it so.
            'whole float' => [1e15, 'int=1000000000000000 float=1000000000000000.0', 'int=******** float=********'],
            'whole float of 4 digits' => [1234.0, 'int=1234 float=1234.0', 'int=******** float=********'],
            'short whole float' => [2.0, 'f=2.0 n=2', 'f=2.0 n=2'],
            'negative zero' => [-0.0, 'z=-0.0 z=-0 z=0', 'z=******** z=-0 z=0'],
            // JSON has no number for these, so the runner fails such a job
            // before Ansible starts (RunnerSecretVars::prepare()). Their
            // texts are Python's only: PHP 8.5 warns when NAN becomes a
            // string, and the error handler threw for NAN.
            'infinity' => [INF, 'Infinity .inf inf INF', '******** ******** inf INF'],
            'negative infinity' => [-INF, '-Infinity -.inf -inf -INF', '******** ******** ******** -INF'],
            'not a number' => [NAN, 'NaN .nan nan NAN', 'NaN ******** nan NAN'],
            'boolean' => [true, 'true 1 True', 'true 1 True'],
            'null' => [null, 'null None', 'null None'],
            'list' => [['nested-value'], 'nested-value', 'nested-value'],
            'map' => [['k' => 'nested-value'], 'nested-value', 'nested-value'],
        ];
    }

    /**
     * Strings of at least 4 bytes, integers of at least 4 characters and
     * every text of at least 4 characters in which Ansible prints a float
     * are masked; booleans, null, lists and maps are not.
     *
     * @dataProvider needleProvider
     */
    public function testWhichAnswersAreMasked(mixed $answer, string $output, string $expected): void
    {
        $masker = new OutputMasker(['answer' => $answer]);

        $this->assertSame($expected, $masker->mask($output));
        $this->assertSame($expected, $masker->feed('stdout', $output) . $masker->flush('stdout'));
    }

    /**
     * Task records come from the callback plugin with templated names.
     */
    public function testMaskValuesMasksOnlyTheGivenStringFields(): void
    {
        $masker = new OutputMasker(['db_password' => self::ANSWER, 'pin' => 123456]);
        $task = [
            'seq' => 3,
            'name' => 'Use ' . self::ANSWER . ' and 123456',
            'host' => self::ANSWER,
            'action' => self::ANSWER,
            'changed' => true,
        ];

        $masked = $masker->maskValues($task, ['name', 'host', 'missing']);

        $this->assertSame(
            ['seq' => 3, 'name' => 'Use ******** and ********', 'host' => '********', 'action' => self::ANSWER, 'changed' => true],
            $masked
        );
        $this->assertSame(['name' => 7], $masker->maskValues(['name' => 7], ['name']), 'non-strings stay');
    }

    private function randomText(int $length): string
    {
        $text = '';
        for ($i = 0; $i < $length; $i++) {
            $text .= 'abc'[mt_rand(0, 2)];
        }

        return $text;
    }
}
