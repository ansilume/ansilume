<?php

declare(strict_types=1);

namespace app\tests\unit\components\vault;

use app\components\vault\InlineVaultExtractor;
use app\components\vault\VaultEnvelope;
use PHPUnit\Framework\TestCase;

class InlineVaultExtractorTest extends TestCase
{
    use TemporaryTree;

    private const DEV = 'ansilume-test-dummy-dev';
    private const PROD = 'ansilume-test-dummy-prod';

    public function testFindsEveryShapeOfTheFixture(): void
    {
        $values = InlineVaultExtractor::extract(self::fixtureContent('inline.yml'));

        $this->assertSame([3, 10, 17, 25, 34, 42], array_column($values, 'line'));
        $this->assertSame(['db_password', 'token', 'keep', 'legacy', 'password', null], array_column($values, 'key'));
        $expected = [
            [null, self::DEV],
            ['prod', self::PROD],
            [null, self::DEV],
            [null, self::DEV],
            ['prod', self::PROD],
            [null, self::DEV],
        ];
        foreach ($values as $index => $value) {
            $envelope = VaultEnvelope::parse($value['envelope']);
            [$vaultId, $password] = $expected[$index];
            $this->assertSame($vaultId, $envelope->vaultId, 'line ' . $value['line']);
            $this->assertTrue($envelope->opens($password), 'line ' . $value['line']);
            $this->assertFalse($envelope->opens($password === self::DEV ? self::PROD : self::DEV), 'line ' . $value['line']);
        }
    }

    public function testTheEnvelopeIsTheBlockWithoutItsIndentation(): void
    {
        $lines = explode("\n", self::fixtureContent('inline.yml'));
        $block = array_map(static fn (string $line): string => substr($line, 10), array_slice($lines, 3, 5));

        $values = InlineVaultExtractor::extract(self::fixtureContent('inline.yml'));

        $this->assertSame(implode("\n", $block) . "\n", $values[0]['envelope']);
        $this->assertStringEndsNotWith("\n\n", $values[2]['envelope'], 'keep chomping (|+) and trailing blank lines change nothing');
    }

    public function testLineEndingsDoNotMatter(): void
    {
        $lf = InlineVaultExtractor::extract(self::fixtureContent('inline.yml'));

        $this->assertSame($lf, InlineVaultExtractor::extract(self::fixtureContent('inline-crlf.yml')));
        $this->assertSame($lf, InlineVaultExtractor::extract(str_replace("\n", "\r", self::fixtureContent('inline.yml'))));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function noValueProvider(): array
    {
        return [
            'empty text' => [''],
            'commented out' => ["# db: !vault |\n#     \$ANSIBLE_VAULT;1.1;AES256\n"],
            'quoted tag text' => ["db: '!vault |'\n    x\n"],
            'no space before the indicator' => ["db: !vault|\n    x\n"],
            'another tag' => ["db: !vaultx |\n    x\n"],
            'the YAML core tag syntax' => ["db: !!vault |\n    x\n"],
            'a quoted value (not supported)' => ["db: !vault \"\$ANSIBLE_VAULT;1.1;AES256\\n6162\"\n"],
            'plain text mentioning a tag' => ["msg: use !vault | for secrets\n"],
            'inside another block scalar' => ["script: |\n  pw: !vault |\n      \$ANSIBLE_VAULT;1.1;AES256\n      6162\n"],
            'inside another list item block' => ["- |\n  pw: !vault |\n      \$ANSIBLE_VAULT;1.1;AES256\n"],
        ];
    }

    /**
     * @dataProvider noValueProvider
     */
    public function testFindsNothingWhereAnsibleHasNoVaultValue(string $yaml): void
    {
        $this->assertSame([], InlineVaultExtractor::extract($yaml));
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function keyProvider(): array
    {
        return [
            'plain key' => ['db_password: !vault |', 'db_password'],
            'indented key' => ['    db_password: !vault |', 'db_password'],
            'double-quoted key' => ['"db password": !vault |', 'db password'],
            'single-quoted key' => ["'db': !vault |", 'db'],
            'empty quoted key' => ['"": !vault |', null],
            'unbalanced quote' => ['"db: !vault |', '"db'],
            'spaces before the colon' => ['db  : !vault |', 'db'],
            'key in a list item' => ['  - db: !vault |', 'db'],
            'bare list item' => ['  - !vault |', null],
            'deprecated tag' => ['db: !vault-encrypted |', 'db'],
            'anchor before the tag' => ['db: &secret !vault |', 'db'],
            'anchor after the tag' => ['db: !vault &secret |', 'db'],
            'tabs as separators' => ["db:\t!vault\t|", 'db'],
            'strip chomping' => ['db: !vault |-', 'db'],
            'keep chomping' => ['db: !vault |+', 'db'],
            'indentation indicator' => ['db: !vault |2', 'db'],
            'both indicators' => ['db: !vault |-2', 'db'],
            'comment after the indicator' => ['db: !vault |  # the database', 'db'],
        ];
    }

    /**
     * @dataProvider keyProvider
     */
    public function testReportsTheKeyOfTheTagLine(string $tagLine, ?string $key): void
    {
        $indent = str_repeat(' ', strspn($tagLine, ' -') + 4);
        $yaml = $tagLine . "\n" . $indent . "\$ANSIBLE_VAULT;1.1;AES256\n" . $indent . "6162\n";

        $values = InlineVaultExtractor::extract($yaml);

        $this->assertCount(1, $values);
        $this->assertSame(1, $values[0]['line']);
        $this->assertSame($key, $values[0]['key']);
        $this->assertSame("\$ANSIBLE_VAULT;1.1;AES256\n6162\n", $values[0]['envelope']);
    }

    /**
     * Regression: the block header pattern backtracked on crafted lines (runs
     * of "- " or ": !tag" pairs), about a millisecond per line until PCRE gave
     * up, so a 5 MB repository file cost every vault check seconds per read.
     */
    public function testCraftedLinesTakeLinearTime(): void
    {
        $dashes = str_repeat('- ', 1500) . 'x |y';
        $tags = 'k: ' . str_repeat('!t: ', 750) . 'X |';
        $yaml = str_repeat($dashes . "\n" . $tags . "\n", 500) . "db: !vault |\n  \$ANSIBLE_VAULT;1.1;AES256\n  6162\n";

        $started = hrtime(true);
        $values = InlineVaultExtractor::extract($yaml);
        $seconds = (hrtime(true) - $started) / 1e9;

        $this->assertSame([1001], array_column($values, 'line'));
        $this->assertLessThan(0.25, $seconds, 'about 3 MB of crafted lines; the backtracking pattern took about a second');
    }

    public function testBlocksEndAtTheFirstLineIndentedLess(): void
    {
        $yaml = implode("\n", [
            'script: |',
            '  pw: !vault |',
            '      not a value',
            'users:',
            '  - name: alice',
            '    password: !vault |',
            '          $ANSIBLE_VAULT;1.1;AES256',
            '          6162',
            '    shell: /bin/bash',
            '  - !vault |',
            '    $ANSIBLE_VAULT;1.2;AES256;prod',
            '    6364',
            '  - - !vault |',
            '      $ANSIBLE_VAULT;1.1;AES256',
            '      6566',
            'last: 1',
        ]);

        $values = InlineVaultExtractor::extract($yaml);

        $this->assertSame([6, 10, 13], array_column($values, 'line'));
        $this->assertSame(['password', null, null], array_column($values, 'key'));
        $this->assertSame([
            "\$ANSIBLE_VAULT;1.1;AES256\n6162\n",
            "\$ANSIBLE_VAULT;1.2;AES256;prod\n6364\n",
            "\$ANSIBLE_VAULT;1.1;AES256\n6566\n",
        ], array_column($values, 'envelope'));
    }

    public function testAnEmptyBlockDoesNotSwallowItsSiblings(): void
    {
        $yaml = "- msg: |\n  pw: !vault |\n    \$ANSIBLE_VAULT;1.1;AES256\n    6162\n  other: 1\n";

        $values = InlineVaultExtractor::extract($yaml);

        $this->assertSame([['line' => 2, 'key' => 'pw', 'envelope' => "\$ANSIBLE_VAULT;1.1;AES256\n6162\n"]], $values);
    }

    public function testAnEmptyVaultValueIsStillReported(): void
    {
        $values = InlineVaultExtractor::extract("db: !vault |\nnext: 1\nlast: !vault |");

        $this->assertSame([
            ['line' => 1, 'key' => 'db', 'envelope' => "\n"],
            ['line' => 3, 'key' => 'last', 'envelope' => "\n"],
        ], $values);
        $this->expectExceptionMessage('it is empty');
        VaultEnvelope::parse($values[0]['envelope']);
    }

    public function testOnlyTheContentIndentationIsRemoved(): void
    {
        $yaml = implode("\n", ['db: !vault |', '  aa  ', '    bb', '      ', ' ', '', '  cc', 'next: 1']);

        $values = InlineVaultExtractor::extract($yaml);

        // Trailing and extra spaces stay, as in YAML: Ansible then fails on them.
        $this->assertSame("aa  \n  bb\n    \n\n\ncc\n", $values[0]['envelope']);
    }

    public function testTrailingWhitespaceInsideAValueFailsLikeInAnsible(): void
    {
        $yaml = preg_replace('/^(          36313665.*)$/m', '$1  ', self::fixtureContent('inline.yml'), 1);

        $values = InlineVaultExtractor::extract((string)$yaml);

        $this->expectExceptionMessage('spaces or tabs');
        VaultEnvelope::parse($values[0]['envelope']);
    }

    /**
     * Verified with ansible-playbook: Ansible fails on the first two values
     * and decrypts the third.
     */
    public function testWhitespaceOnlyLinesFailOnlyWhenIndentedMoreThanTheBlock(): void
    {
        $body = (string)preg_replace('/^/m', '          ', rtrim(self::fixtureContent('file-1.1.yml'), "\n"));
        $lines = explode("\n", $body);
        array_splice($lines, 3, 0, ['             ']);
        $yaml = "trailing: !vault |\n{$body}\n            \n"
            . "middle: !vault |\n" . implode("\n", $lines) . "\n"
            . "equal: !vault |\n{$body}\n          \nend: 1\n";

        $values = InlineVaultExtractor::extract($yaml);

        $this->assertSame(['trailing', 'middle', 'equal'], array_column($values, 'key'));
        foreach ([0, 1] as $index) {
            try {
                VaultEnvelope::parse($values[$index]['envelope']);
                $this->fail('accepted ' . $values[$index]['key']);
            } catch (\UnexpectedValueException $e) {
                $this->assertSame('the vault body has spaces or tabs, e.g. trailing whitespace on a line', $e->getMessage());
            }
        }
        $this->assertTrue(VaultEnvelope::parse($values[2]['envelope'])->opens(self::DEV));
    }

    public function testALeadingBlankLineIsPartOfTheValue(): void
    {
        $values = InlineVaultExtractor::extract("db: !vault |\n\n    \$ANSIBLE_VAULT;1.1;AES256\n    6162\n");

        $this->assertSame("\n\$ANSIBLE_VAULT;1.1;AES256\n6162\n", $values[0]['envelope']);
        $this->expectExceptionMessage('there are blank lines or spaces before $ANSIBLE_VAULT');
        VaultEnvelope::parse($values[0]['envelope']);
    }

    public function testAFoldedValueLosesItsLineBreaksLikeInAnsible(): void
    {
        $yaml = str_replace('db_password: !vault |', 'db_password: !vault >-', self::fixtureContent('inline.yml'));

        $values = InlineVaultExtractor::extract($yaml);

        $this->assertSame('db_password', $values[0]['key']);
        $this->assertStringStartsWith('$ANSIBLE_VAULT;1.1;AES256 3631', $values[0]['envelope']);
        $this->assertCount(6, $values);
        $this->expectExceptionMessage('the header and the body are on one line');
        VaultEnvelope::parse($values[0]['envelope']);
    }

    /**
     * @return array<string, array{0: list<string>, 1: string}>
     */
    public static function foldProvider(): array
    {
        return [
            'lines join with spaces' => [['a', 'b', 'c'], "a b c\n"],
            'empty lines become line breaks' => [['a', '', 'b', 'c', '', '', 'd'], "a\nb c\n\nd\n"],
            'leading empty lines stay' => [['', '', 'a', 'b'], "\n\na b\n"],
            'trailing empty lines go' => [['a', '', ''], "a\n"],
            'only empty lines' => [['', ''], "\n"],
        ];
    }

    /**
     * @dataProvider foldProvider
     * @param list<string> $lines
     */
    public function testFoldedBlocksFollowYamlFolding(array $lines, string $folded): void
    {
        $block = array_map(static fn (string $line): string => $line === '' ? '' : '  ' . $line, $lines);

        $values = InlineVaultExtractor::extract("db: !vault >\n" . implode("\n", $block) . "\nnext: 1\n");

        $this->assertSame($folded, $values[0]['envelope']);
    }
}
