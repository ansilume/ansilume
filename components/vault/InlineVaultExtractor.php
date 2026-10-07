<?php

declare(strict_types=1);

namespace app\components\vault;

/**
 * Finds inline vault values, `key: !vault |` blocks, in YAML text without a
 * YAML parser.
 *
 * A YAML parser cannot be the scanner: symfony/yaml rejects duplicate keys,
 * multi-document files and `- !vault |` list items, all of which Ansible
 * loads, and one parse error would hide every value in the file. Block
 * scalars are read line by line instead, with the rules Ansible's YAML parser
 * applies: the first non-empty line sets the content indentation, the block
 * ends at the first non-empty line indented less, only that indentation is
 * removed (trailing whitespace stays and fails the hex check later, as in
 * Ansible), and a folded block (>) turns line breaks into spaces. Other
 * block scalars (`script: |`) are skipped, so text inside them is never taken
 * for a vault value.
 *
 * Not found: quoted or plain values (`key: !vault "..."`), flow collections
 * and JSON `__ansible_vault` objects.
 */
final class InlineVaultExtractor
{
    /**
     * A block scalar header: indentation, sequence dashes, an optional
     * mapping key, node properties (tags, anchors), then | or > with optional
     * chomping/indentation indicators and an optional comment.
     *
     * Linear in the line length: every quantifier is possessive, and a plain
     * key ends at the first ': ', as in YAML, so no part of the line can be
     * split between two groups in more than one way (repository files are
     * untrusted; a backtracking pattern let crafted lines cost seconds).
     * Quoted keys may contain ': '; an unbalanced quote starts a plain key.
     */
    private const BLOCK_HEADER = '/^( *+)((?:-[ \t]++)*+)'
        . '(?:("(?:[^"\\\\]++|\\\\.)*+"|\'(?:[^\']++|\'\')*+\'|[^#\s](?:[^#:]++|:(?![ \t]))*+)[ \t]*+:[ \t]++)?'
        . '((?:[!&]\S*+[ \t]++)*+)([|>])[-+1-9]{0,2}+[ \t]*+(?:#.*)?$/';

    /** The vault tags among the node properties (!vault-encrypted is the deprecated alias). */
    private const VAULT_TAG = '/(?:^|[ \t])!vault(?:-encrypted)?(?=[ \t]|$)/';

    /**
     * @return list<array{line: int, key: string|null, envelope: string}>
     *         line: 1-based line of the tag; key: the mapping key on that
     *         line, null for a list item; envelope: the block's text
     */
    public static function extract(string $yaml): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $yaml));
        $count = count($lines);
        $values = [];
        $index = 0;
        while ($index < $count) {
            // Every block header has a | or >; most lines have neither.
            $header = strpbrk($lines[$index], '|>') === false ? null : self::blockHeader($lines[$index]);
            if ($header === null) {
                $index++;
                continue;
            }
            [$block, $next] = self::collectBlock($lines, $index + 1, $header['owner']);
            if ($header['vault']) {
                $values[] = [
                    'line' => $index + 1,
                    'key' => $header['key'],
                    'envelope' => $header['folded'] ? self::fold($block) : self::literal($block),
                ];
            }
            $index = $next;
        }

        return $values;
    }

    /**
     * @return array{vault: bool, folded: bool, key: string|null, owner: int}|null
     */
    private static function blockHeader(string $line): ?array
    {
        if (preg_match(self::BLOCK_HEADER, $line, $match, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }

        return [
            'vault' => preg_match(self::VAULT_TAG, (string)$match[4]) === 1,
            'folded' => $match[5] === '>',
            'key' => self::keyName($match[3]),
            'owner' => self::ownerIndent((string)$match[1], (string)$match[2], $match[3] !== null),
        ];
    }

    private static function keyName(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $key = trim($raw);
        $quote = $key[0];
        if (strlen($key) > 1 && ($quote === '"' || $quote === "'") && str_ends_with($key, $quote)) {
            $key = substr($key, 1, -1);
        }

        return $key === '' ? null : $key;
    }

    /**
     * The indentation of the node that owns the block scalar: the column of
     * the mapping key, or of the dash for a list item. Block content must be
     * indented more.
     */
    private static function ownerIndent(string $spaces, string $dashes, bool $hasKey): int
    {
        if ($hasKey || $dashes === '') {
            return strlen($spaces) + strlen($dashes);
        }

        return (int)strrpos($spaces . $dashes, '-');
    }

    /**
     * @param list<string> $lines
     * @return array{0: list<string>, 1: int} the block's lines without the
     *         content indentation, and the index of the first line after it
     */
    private static function collectBlock(array $lines, int $start, int $owner): array
    {
        $count = count($lines);
        $indent = null;
        $block = [];
        for ($index = $start; $index < $count; $index++) {
            $line = $lines[$index];
            $lineIndent = strspn($line, ' ');
            $blank = $lineIndent === strlen($line);
            // Blank lines never end a block; they belong to it.
            if (!$blank && $lineIndent < ($indent ?? $owner + 1)) {
                break;
            }
            $indent ??= $blank ? null : $lineIndent;
            $block[] = $line;
        }
        $cut = $indent ?? PHP_INT_MAX;

        return [array_map(static fn (string $line): string => substr($line, $cut), $block), $index];
    }

    /**
     * @param list<string> $lines
     */
    private static function literal(array $lines): string
    {
        return rtrim(implode("\n", $lines), "\n") . "\n";
    }

    /**
     * YAML folding: a line break between two lines becomes a space, a run of
     * n empty lines becomes n line breaks.
     *
     * @param list<string> $lines
     */
    private static function fold(array $lines): string
    {
        $text = null;
        $breaks = 0;
        foreach ($lines as $line) {
            if ($line === '') {
                $breaks++;
                continue;
            }
            $separator = $breaks > 0 ? str_repeat("\n", $breaks) : ' ';
            $text = $text === null ? str_repeat("\n", $breaks) . $line : $text . $separator . $line;
            $breaks = 0;
        }

        return ($text ?? '') . "\n";
    }
}
