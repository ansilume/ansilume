<?php

declare(strict_types=1);

namespace app\tests\unit\services\notification;

use app\services\notification\MattermostText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How the Mattermost channel writes text that it did not write itself
 * (MattermostText): as plain text on one line, Markdown escaped, every @
 * neutralised, so that no workflow, step or user name mentions anyone or
 * breaks the post; and its messages cut well below Mattermost's limit, with
 * a link to the rest.
 */
class MattermostTextTest extends TestCase
{
    private const ZWSP = "\u{200B}";

    private const RUN_URL = 'https://ansilume.example.com/workflow-job/view?id=12';

    /**
     * The sanity check of the test's mention parser: raw text mentions as
     * Mattermost reads it, a backslash before the @ included.
     */
    public function testTheMentionParserOfTheTestFindsWhatMattermostFinds(): void
    {
        $this->assertSame(['all'], MattermostMentions::in('deploy @all now'));
        $this->assertSame(['channel', 'alice'], MattermostMentions::in('\\@channel, (@alice.)'));
        $this->assertSame(['here'], MattermostMentions::in(':@here'));
        $this->assertSame([], MattermostMentions::in('ops@ example :smile: @'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function mentionProvider(): array
    {
        return [
            'the whole channel' => ['@all'],
            'the channel' => ['@channel'],
            'who is online' => ['@here'],
            'upper case' => ['@ALL'],
            'a user' => ['@alice'],
            'a group after a colon' => [':@ops-team'],
            'in a sentence' => ['ping @here.'],
            'after a backslash' => ['\\@channel'],
            'an e-mail address' => ['ops@example.com'],
        ];
    }

    /**
     * Regression (review focus 4): a name with a mention mentions nobody:
     * every @ is followed by a zero-width space, which ends Mattermost's
     * word, and shows the name as it was.
     */
    #[DataProvider('mentionProvider')]
    public function testANameMentionsNobody(string $name): void
    {
        $escaped = MattermostText::escape($name);
        $plain = MattermostText::plain($name);

        $this->assertSame([], MattermostMentions::in($escaped));
        $this->assertSame([], MattermostMentions::in($plain));
        $this->assertSame(substr_count($name, '@'), substr_count($escaped, '@' . self::ZWSP), 'every @ is neutralised');
        $this->assertSame(str_replace('@', '@' . self::ZWSP, $name), $plain);
    }

    /**
     * Every character that Mattermost's Markdown reads is escaped with a
     * backslash, so that a name renders as it is written: no emphasis,
     * heading, list, quote, code, table, link or image.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function markdownProvider(): array
    {
        return [
            'emphasis' => ['**bold** _it_', '\\*\\*bold\\*\\* \\_it\\_'],
            'a heading' => ['# Release', '\\# Release'],
            'a list' => ['- one + two 1. three', '\\- one \\+ two 1\\. three'],
            'a quote' => ['> quoted', '\\> quoted'],
            'code' => ['`rm -rf`', '\\`rm \\-rf\\`'],
            'a table' => ['a | b', 'a \\| b'],
            'a link' => ['[click](https://evil.example)', '\\[click\\]\\(https://evil\\.example\\)'],
            'an image' => ['![x](https://evil.example/x.png)', '\\!\\[x\\]\\(https://evil\\.example/x\\.png\\)'],
            'strikethrough and a channel link' => ['~~gone~~ ~town-square', '\\~\\~gone\\~\\~ \\~town\\-square'],
            'braces' => ['{a}', '\\{a\\}'],
            'a backslash' => ['C:\\deploy', 'C:\\\\deploy'],
            'only what the renderer reads is escaped' => ['a: b & <c> = "d" $e', 'a: b & <c\\> = "d" $e'],
        ];
    }

    #[DataProvider('markdownProvider')]
    public function testMarkdownIsEscaped(string $name, string $escaped): void
    {
        $this->assertSame($escaped, MattermostText::escape($name));
    }

    /**
     * A name stays on one line: each line break, tab, other control
     * character and Unicode line or paragraph separator becomes a space, so that
     * no name starts a heading, a list or a table on a line of its own.
     * Bytes that are no UTF-8 become '?'.
     */
    public function testANameStaysOnOneLine(): void
    {
        $this->assertSame('a b  c d e f g', MattermostText::escape("a\nb\r\nc\td\u{2028}e\u{2029}f\x00g"));
        $this->assertSame('a  b', MattermostText::plain("a\r\nb"));
        $this->assertSame('deploy?x', MattermostText::escape("deploy\xC3x"));
        $this->assertSame('', MattermostText::escape(''));
    }

    /**
     * plain() is for a field that Mattermost shows as it is (an
     * attachment's fallback): nothing is escaped there.
     */
    public function testPlainEscapesNoMarkdown(): void
    {
        $this->assertSame('**deploy** #1', MattermostText::plain('**deploy** #1'));
    }

    /**
     * A link goes only to an absolute http or https address; its label is
     * escaped, and the characters that would end the address are encoded.
     * Without such an address the label stands alone.
     */
    public function testALinkGoesOnlyToAnHttpAddress(): void
    {
        $this->assertSame('[Open the run](' . self::RUN_URL . ')', MattermostText::link('Open the run', self::RUN_URL));
        $this->assertSame('[@' . self::ZWSP . 'all \\[x\\]](https://a.example/p%28q%29%5C%3Cr%3E)', MattermostText::link('@all [x]', 'https://a.example/p(q)\\<r>'));
        $this->assertSame('Open the run', MattermostText::link('Open the run', '/workflow-job/view?id=12'));
        $this->assertSame('Open the run', MattermostText::link('Open the run', 'javascript:alert(1)'));
        $this->assertSame('Open the run', MattermostText::link('Open the run', ''));
    }

    /**
     * A message within MAX_LENGTH is left as it is.
     */
    public function testAShortMessageIsLeftAsItIs(): void
    {
        $message = str_repeat('a', MattermostText::MAX_LENGTH);

        $this->assertSame($message, MattermostText::truncate($message, self::RUN_URL));
    }

    /**
     * Regression (review focus 4): a message of 20000 characters is cut to
     * MAX_LENGTH, well below Mattermost's 16383, and ends with the link to
     * the run; the cut splits no character and leaves no backslash that
     * would escape what follows.
     */
    public function testALongMessageIsCutWithALinkToTheRun(): void
    {
        $cut = MattermostText::truncate(str_repeat('ä', 20000), self::RUN_URL);
        $escapes = MattermostText::truncate(str_repeat('\\*', 10000), self::RUN_URL);

        $suffix = sprintf(MattermostText::CUT, '[' . MattermostText::REST . '](' . self::RUN_URL . ')');
        foreach ([$cut, $escapes] as $message) {
            $this->assertLessThanOrEqual(MattermostText::MAX_LENGTH, mb_strlen($message));
            $this->assertLessThan(MattermostText::MATTERMOST_LIMIT, mb_strlen($message));
            $this->assertStringEndsWith($suffix, $message);
            $this->assertTrue(mb_check_encoding($message, 'UTF-8'));
        }
        $this->assertSame(MattermostText::MAX_LENGTH, mb_strlen($cut));
        $this->assertStringEndsWith('\\*' . $suffix, $escapes, 'the cut ends after an escaped character');
        $this->assertStringContainsString('…', MattermostText::CUT, 'the mark of the cut');
    }

    /**
     * Cut without an http address to link, the message ends with the label
     * alone.
     */
    public function testACutWithoutAnAddressEndsWithTheLabel(): void
    {
        $cut = MattermostText::truncate(str_repeat('a', 9000), '');

        $this->assertStringEndsWith(sprintf(MattermostText::CUT, MattermostText::REST), $cut);
        $this->assertSame(MattermostText::MAX_LENGTH, mb_strlen($cut));
    }
}
