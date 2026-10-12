<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\OutputMasker;
use app\components\RunnerHttpClient;
use app\components\RunnerProcessExecutor;
use PHPUnit\Framework\TestCase;

/**
 * Job output is read in 8192-byte chunks, which can end inside a UTF-8
 * character. A child process writes the output with one write, so the
 * first read takes exactly the first 8192 bytes; the runner posts what it
 * read through the real RunnerHttpClient to a recording loopback server, so
 * these tests see the JSON the server gets.
 *
 * Regression (review RUN-2): a chunk cut inside a character could not be
 * JSON-encoded, the client dropped the request without a word, and both
 * halves of the character went missing with up to 16 KB of output around
 * them. Bytes that are not UTF-8 at all dropped their chunk the same way.
 */
class RunnerProcessExecutorUtf8Test extends TestCase
{
    private const CHUNK = 8192;

    private const WRITE_ONCE = 'fwrite(STDOUT, base64_decode($argv[1]));';

    private static ?RunnerApiRecorder $recorder = null;

    private static string $baseUrl = '';

    public static function setUpBeforeClass(): void
    {
        self::$recorder = new RunnerApiRecorder();
        self::$baseUrl = self::$recorder->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$recorder?->stop();
        self::$recorder = null;
    }

    protected function setUp(): void
    {
        self::recorder()->reset();
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function cutCharacterProvider(): array
    {
        return [
            '2-byte character cut after 1 byte' => ['ü', 1],
            '3-byte character cut after 1 byte' => ['€', 1],
            '3-byte character cut after 2 bytes' => ['€', 2],
            '4-byte character cut after 1 byte' => ['😀', 1],
            '4-byte character cut after 2 bytes' => ['😀', 2],
            '4-byte character cut after 3 bytes' => ['😀', 3],
        ];
    }

    /**
     * Every chunk is posted, the content is intact and the sequence numbers
     * are consecutive.
     *
     * @dataProvider cutCharacterProvider
     */
    public function testAChunkThatEndsInsideACharacterIsPostedWholeAndInOrder(string $character, int $cut): void
    {
        $before = str_repeat('a', self::CHUNK - $cut);

        $logs = $this->postedLogs($before . $character . "\nafter the cut\n");

        $this->assertCount(2, $logs, 'every chunk reaches the server');
        $this->assertSame(
            [
                ['stream' => 'stdout', 'content' => $before, 'sequence' => 0],
                ['stream' => 'stdout', 'content' => $character . "\nafter the cut\n", 'sequence' => 1],
            ],
            $logs
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function wholeCharacterProvider(): array
    {
        return [
            '1-byte character' => ['a'],
            '2-byte character' => ['ü'],
            '3-byte character' => ['€'],
            '4-byte character' => ['😀'],
        ];
    }

    /**
     * A chunk that ends right after a character is posted as it is; nothing
     * is held back.
     *
     * @dataProvider wholeCharacterProvider
     */
    public function testAChunkThatEndsAfterAWholeCharacterIsPostedAtOnce(string $character): void
    {
        $first = str_repeat('a', self::CHUNK - strlen($character)) . $character;

        $logs = $this->postedLogs($first . "\nnext\n");

        $this->assertCount(2, $logs, 'every chunk reaches the server');
        $this->assertSame(
            [
                ['stream' => 'stdout', 'content' => $first, 'sequence' => 0],
                ['stream' => 'stdout', 'content' => "\nnext\n", 'sequence' => 1],
            ],
            $logs
        );
    }

    /**
     * The masker does not repair a cut that is not part of an answer: the
     * character still arrives whole, and the answer is masked.
     */
    public function testACutCharacterNextToAMaskedAnswerArrivesWhole(): void
    {
        $before = str_repeat('a', self::CHUNK - 1);

        $logs = $this->postedLogs($before . "ü geheim-4711\n", new OutputMasker(['db_password' => 'geheim-4711']));

        $this->assertSame(
            [
                ['stream' => 'stdout', 'content' => $before, 'sequence' => 0],
                ['stream' => 'stdout', 'content' => 'ü ' . OutputMasker::MASK . "\n", 'sequence' => 1],
            ],
            $logs
        );
    }

    /**
     * An answer that starts with the cut character is masked across the cut.
     */
    public function testAnAnswerThatStartsWithACutCharacterIsMasked(): void
    {
        $answer = 'über-geheim-4711';
        $before = str_repeat('a', self::CHUNK - 1);

        $logs = $this->postedLogs($before . $answer . " done\n", new OutputMasker(['db_password' => $answer]));

        $this->assertSame(
            [
                ['stream' => 'stdout', 'content' => $before, 'sequence' => 0],
                ['stream' => 'stdout', 'content' => OutputMasker::MASK . " done\n", 'sequence' => 1],
            ],
            $logs
        );
        $this->assertStringNotContainsString('geheim', (string)json_encode(self::recorder()->requests()));
    }

    /**
     * Output that is not UTF-8 at all reaches the server with U+FFFD in
     * place of the bad bytes, instead of not at all.
     */
    public function testBytesThatAreNotUtf8ArePostedAsReplacementCharacters(): void
    {
        $logs = $this->postedLogs("before \xFF\xFE after\n");

        $this->assertSame([['stream' => 'stdout', 'content' => "before \u{FFFD}\u{FFFD} after\n", 'sequence' => 0]], $logs);
    }

    /**
     * A byte no UTF-8 character starts with is not held back for a next
     * chunk: it cannot become part of a character.
     */
    public function testATrailingByteNoCharacterStartsWithIsPostedAtOnce(): void
    {
        $logs = $this->postedLogs("end \xFF");

        $this->assertSame([['stream' => 'stdout', 'content' => "end \u{FFFD}", 'sequence' => 0]], $logs);
    }

    /**
     * The bytes of a character the stream never completes are posted when
     * it ends, after what came before them: they go through the masker
     * before its flush, so what it held back keeps its place.
     */
    public function testTheBytesOfAnUnfinishedCharacterArePostedInOrderWhenTheStreamEnds(): void
    {
        $logs = $this->postedLogs("value: abc\xE2\x82", new OutputMasker(['db_password' => 'abcdefgh']));

        $this->assertSame(
            [
                ['stream' => 'stdout', 'content' => 'value: ', 'sequence' => 0],
                ['stream' => 'stdout', 'content' => "abc\u{FFFD}", 'sequence' => 1],
            ],
            $logs
        );
    }

    /**
     * A killed process: its last bytes come before the timeout line.
     */
    public function testTheEndOfTheOutputComesBeforeTheTimeoutLine(): void
    {
        $posts = [];
        $http = $this->createMock(RunnerHttpClient::class);
        $http->method('post')->willReturnCallback(static function (string $path, array $body) use (&$posts): array {
            $posts[] = $body;

            return ['ok' => true];
        });
        $executor = new class ($http, new SilentController('runner', \Yii::$app), new OutputMasker(['db_password' => 'abcdefgh'])) extends RunnerProcessExecutor {
            protected function timeoutSeconds(int $timeoutMinutes): int
            {
                return 2;
            }
        };
        $cmd = [PHP_BINARY, '-r', self::WRITE_ONCE . ' sleep(30);', base64_encode("value: abc\xE2\x82")];

        [$exitCode, $sequence, $timedOut] = $executor->run(7, $cmd, [], getenv() ?: [], 1);

        $this->assertTrue($timedOut);
        $this->assertSame(-1, $exitCode);
        $this->assertSame(
            [
                ['stream' => 'stdout', 'content' => 'value: ', 'sequence' => 0],
                ['stream' => 'stdout', 'content' => "abc\xE2\x82", 'sequence' => 1],
                ['stream' => 'stderr', 'content' => "\n[ansilume] Job killed: exceeded timeout of 1 minutes.\n", 'sequence' => 2],
            ],
            $posts
        );
        $this->assertSame(3, $sequence);
    }

    // -- Helpers ------------------------------------------------------------------

    /**
     * Runs a process that writes $output with one write, and returns the
     * log chunks the server got for it.
     *
     * @return list<array{stream: mixed, content: mixed, sequence: mixed}>
     */
    private function postedLogs(string $output, ?OutputMasker $masker = null): array
    {
        $executor = new RunnerProcessExecutor(
            new RunnerHttpClient(self::$baseUrl, 'runner-token'),
            new SilentController('runner', \Yii::$app),
            $masker
        );

        [$exitCode] = $executor->run(7, [PHP_BINARY, '-r', self::WRITE_ONCE, base64_encode($output)], [], getenv() ?: [], 1);

        $this->assertSame(0, $exitCode);
        $logs = [];
        foreach (self::recorder()->requests() as $request) {
            $this->assertSame('/api/runner/v1/jobs/7/logs', $request['path']);
            $body = json_decode($request['body'], true);
            $this->assertIsArray($body, 'the server gets valid JSON');
            $logs[] = ['stream' => $body['stream'], 'content' => $body['content'], 'sequence' => $body['sequence']];
        }

        return $logs;
    }

    private static function recorder(): RunnerApiRecorder
    {
        if (self::$recorder === null) {
            throw new \LogicException('The recorder is not running.');
        }

        return self::$recorder;
    }
}
