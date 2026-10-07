<?php

declare(strict_types=1);

namespace app\tests\unit\components\vault;

use app\components\vault\AnsibleCfgParser;
use PHPUnit\Framework\TestCase;

/**
 * The expectations follow Python's configparser with Ansible's options
 * (ConfigParser(inline_comment_prefixes=(';',)), values read raw).
 */
class AnsibleCfgParserTest extends TestCase
{
    public function testReadsSectionsAndOptions(): void
    {
        $text = "[defaults]\ninventory = hosts.yml\nforks=10\n\n[ssh_connection]\npipelining : True\n";

        $this->assertSame([
            'defaults' => ['inventory' => 'hosts.yml', 'forks' => '10'],
            'ssh_connection' => ['pipelining' => 'True'],
        ], AnsibleCfgParser::parse($text));
    }

    public function testCommentsFollowConfigparserRules(): void
    {
        $text = implode("\n", [
            '# a comment',
            '; another comment',
            '[defaults]',
            '   # an indented comment',
            'a = 1 ; an inline comment',
            'b = 2 # not a comment',
            'c = x;y',
            'd = ; only a comment',
            "e = 3\t; after a tab",
        ]);

        $this->assertSame(['defaults' => [
            'a' => '1',
            'b' => '2 # not a comment',
            'c' => 'x;y',
            'd' => '',
            'e' => '3',
        ]], AnsibleCfgParser::parse($text));
    }

    public function testKeysAreCaseInsensitiveAndSectionsAreNot(): void
    {
        $sections = AnsibleCfgParser::parse("[Defaults]\nVault_Password_File = a\n[defaults]\nASK_VAULT_PASS = yes\n");

        $this->assertSame(['Defaults' => ['vault_password_file' => 'a'], 'defaults' => ['ask_vault_pass' => 'yes']], $sections);
    }

    public function testTheFirstSeparatorSplitsKeyAndValue(): void
    {
        $sections = AnsibleCfgParser::parse("[defaults]\na = b = c\nd: e = f\ng=h:i\nempty =\n");

        $this->assertSame(['defaults' => ['a' => 'b = c', 'd' => 'e = f', 'g' => 'h:i', 'empty' => '']], $sections);
    }

    public function testIndentedLinesContinueAValue(): void
    {
        $text = "[defaults]\nlist = dev@a,\n    prod@b,\n\n  # comment\n  test@c\nnext = 1\n  [not a section]\n";

        $this->assertSame(['defaults' => [
            'list' => "dev@a,\nprod@b,\n\ntest@c",
            'next' => "1\n[not a section]",
        ]], AnsibleCfgParser::parse($text));
    }

    public function testBlankLinesStayInsideAValueOnly(): void
    {
        $text = "[defaults]\nlist = a,\n   \n\n    b\nafter = x\n\n\nlast = y\n\n";

        $this->assertSame(['defaults' => ['list' => "a,\n\n\nb", 'after' => 'x', 'last' => 'y']], AnsibleCfgParser::parse($text));
    }

    public function testLinesConfigparserRejectsAreSkipped(): void
    {
        $text = "orphan = 1\n[defaults]\nno separator here\n    not a continuation\n= no key\na = 1\n";

        $this->assertSame(['defaults' => ['a' => '1']], AnsibleCfgParser::parse($text));
    }

    public function testASectionHeaderEndsAtItsLastBracket(): void
    {
        $sections = AnsibleCfgParser::parse("[defaults] # trailing text\na = 1\n[ spaced ]\nb = 2\n");

        $this->assertSame(['defaults' => ['a' => '1'], ' spaced ' => ['b' => '2']], $sections);
    }

    public function testCrlfLineEndingsAndRepeatedOptions(): void
    {
        $sections = AnsibleCfgParser::parse("[defaults]\r\na = 1\r\na = 2\r\n");

        $this->assertSame(['defaults' => ['a' => '2']], $sections);
    }

    public function testEmptyText(): void
    {
        $this->assertSame([], AnsibleCfgParser::parse(''));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unquoteProvider(): array
    {
        return [
            'double quotes' => ['"x"', 'x'],
            'single quotes' => ["'x y'", 'x y'],
            'empty quotes' => ['""', ''],
            'mixed quotes' => ['"x\'', '"x\''],
            'escaped closing quote' => ['"x\\"', '"x\\"'],
            'one quote' => ['"', '"'],
            'opening quote only' => ['"x', '"x'],
            'inner quotes' => ['a"b"c', 'a"b"c'],
            'unquoted' => ['False', 'False'],
            'empty' => ['', ''],
        ];
    }

    /**
     * @dataProvider unquoteProvider
     */
    public function testUnquoteRemovesOnePairOfMatchingQuotes(string $value, string $expected): void
    {
        $this->assertSame($expected, AnsibleCfgParser::unquote($value));
    }
}
