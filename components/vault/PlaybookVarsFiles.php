<?php

declare(strict_types=1);

namespace app\components\vault;

/**
 * Reads the vars_files of a playbook's plays line by line, without a YAML
 * parser.
 *
 * Playbooks come from the repository and are untrusted. symfony/yaml parses
 * nested flow collections recursively, and a few kilobytes of `[[[[` made PHP
 * crash during a vault check. This reader is linear and never recurses. It
 * understands the shapes playbooks use:
 *
 *     - hosts: all
 *       vars_files:
 *         - vars/common.yml
 *         - "vars/{{ env }}.yml"
 *         - [vars/first-choice.yml, vars/fallback.yml]
 *     - hosts: web
 *       vars_files: [a.yml, "b.yml"]
 *     - hosts: db
 *       vars_files: c.yml
 *
 * A file whose first content is not a list of plays has none. Quoted values
 * lose their quotes; numbers, booleans and null are no file names. Other
 * shapes (aliases, flow mappings, multi-line plain values) give nothing: the
 * vault check built on this is informational.
 */
final class PlaybookVarsFiles
{
    /** Lines a flow sequence such as `vars_files: [a.yml,` may continue over. */
    private const MAX_FLOW_LINES = 100;

    private const KEY = '/^(?:vars_files|"vars_files"|\'vars_files\')[ \t]*+:(?:[ \t]++(.*+))?$/';

    /**
     * @return list<string> the vars_files entries of all plays as written, in
     *         file order; the alternatives of a list entry one by one
     */
    public static function read(string $yaml): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $yaml));
        $count = count($lines);
        $files = [];
        $dash = null;
        $keys = null;
        for ($index = 0; $index < $count; $index++) {
            $indent = self::contentIndent($lines[$index]);
            if ($indent === null) {
                continue;
            }
            $dash ??= self::isItem($lines[$index], $indent) ? $indent : -1;
            if ($dash < 0) {
                return [];
            }
            [$keys, $column] = self::keyColumn($lines[$index], $indent, $dash, $keys);
            if ($column !== null && $column === $keys) {
                [$found, $index] = self::varsFiles($lines, $index, $column);
                array_push($files, ...$found);
            }
        }

        return $files;
    }

    /**
     * The indentation of a line with content; null for blank lines, comments,
     * directives and document markers.
     */
    private static function contentIndent(string $line): ?int
    {
        $indent = strspn($line, ' ');
        $rest = rtrim(substr($line, $indent));
        $skip = $rest === '' || $rest[0] === '#'
            || ($indent === 0 && ($rest[0] === '%' || preg_match('/^(?:---|\.\.\.)(?:[ \t]|$)/', $rest) === 1));

        return $skip ? null : $indent;
    }

    private static function isItem(string $line, int $indent): bool
    {
        $next = $line[$indent + 1] ?? ' ';

        return ($line[$indent] ?? '') === '-' && ($next === ' ' || $next === "\t");
    }

    /**
     * Which column the current play's keys start in, and the column of the
     * key on this line, if the line holds one: a play item (`- hosts: all`)
     * starts a play and may hold its first key.
     *
     * @return array{0: int|null, 1: int|null} the play's key column, the line's key column
     */
    private static function keyColumn(string $line, int $indent, int $dash, ?int $keys): array
    {
        if ($indent === $dash && self::isItem($line, $indent)) {
            $column = $indent + 1 + strspn($line, " \t", $indent + 1);
            $empty = self::contentIndent(substr($line, $column)) === null;

            return $empty ? [null, null] : [$column, $column];
        }
        if ($indent < $dash) {
            return [$keys, null];
        }
        $keys ??= $indent;

        return [$keys, $indent];
    }

    /**
     * The entries of a vars_files key at $column of line $index, if the line
     * holds that key.
     *
     * @param list<string> $lines
     * @return array{0: list<string>, 1: int} the entries, the last line they take
     */
    private static function varsFiles(array $lines, int $index, int $column): array
    {
        if (preg_match(self::KEY, substr($lines[$index], $column), $match) !== 1) {
            return [[], $index];
        }
        $value = YamlValue::value((string)($match[1] ?? ''));
        if ($value === '') {
            return self::block($lines, $index + 1, $column);
        }
        if (str_starts_with((string)$value, '[')) {
            return self::flow($lines, $index, (string)$value);
        }
        $scalar = $value === null || $value[0] === '{' || $value[0] === '*' ? null : YamlValue::scalar($value);

        return [$scalar === null ? [] : [$scalar], $index];
    }

    /**
     * A block sequence below the key: items at the column of the first one
     * (YAML allows the key's own column), and alternatives on deeper items.
     *
     * @param list<string> $lines
     * @return array{0: list<string>, 1: int} the entries, the last line they take
     */
    private static function block(array $lines, int $start, int $column): array
    {
        $entries = [];
        $items = null;
        $count = count($lines);
        for ($index = $start; $index < $count; $index++) {
            $indent = self::contentIndent($lines[$index]);
            if ($indent === null) {
                continue;
            }
            $items ??= $indent;
            $item = self::isItem($lines[$index], $indent);
            if ($indent < $column || $indent < $items || ($indent === $items && !$item)) {
                break;
            }
            [$found, $index] = self::item($lines, $index, $indent, $item);
            array_push($entries, ...$found);
        }

        return [$entries, $index - 1];
    }

    /**
     * One line of a block sequence: an item's value, or a flow sequence on
     * a line of its own. Other lines (continuations) give nothing.
     *
     * @param list<string> $lines
     * @return array{0: list<string>, 1: int} the entries, the last line they take
     */
    private static function item(array $lines, int $index, int $indent, bool $item): array
    {
        $value = (string)YamlValue::value(substr($lines[$index], $item ? $indent + 1 : $indent));
        if (str_starts_with($value, '[')) {
            return self::flow($lines, $index, $value);
        }
        $scalar = $item && $value !== '' ? YamlValue::scalar($value) : null;

        return [$scalar === null ? [] : [$scalar], $index];
    }

    /**
     * A flow sequence that starts in $text and may continue on the next lines.
     *
     * @param list<string> $lines
     * @return array{0: list<string>, 1: int} the entries, the last line they take
     */
    private static function flow(array $lines, int $index, string $text): array
    {
        $depth = YamlValue::depth($text);
        $last = min(count($lines) - 1, $index + self::MAX_FLOW_LINES);
        while ($depth > 0 && $index < $last) {
            $index++;
            $more = YamlValue::value($lines[$index]);
            if ($more === null || strlen($text) + strlen($more) >= YamlValue::MAX_VALUE_BYTES) {
                return [[], $index];
            }
            $text .= ' ' . $more;
            $depth += YamlValue::depth($more);
        }

        return [YamlValue::flowEntries($text), $index];
    }
}
