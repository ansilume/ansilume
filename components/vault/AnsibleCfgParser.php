<?php

declare(strict_types=1);

namespace app\components\vault;

/**
 * Reads ansible.cfg text the way Ansible does, with Python's configparser
 * and inline_comment_prefixes=(';',):
 *
 * - lines that start with # or ; are comments; neither comments nor blank
 *   lines end a value, and blank lines inside a value are kept;
 * - ; after whitespace starts an inline comment, # does not;
 * - the first = or : separates key and value, keys are lower-cased;
 * - a line indented more than its option line continues the value;
 * - section names are case-sensitive.
 *
 * Lines configparser rejects (options before the first section, lines
 * without a separator) make Ansible stop with an error; they are skipped here.
 */
final class AnsibleCfgParser
{
    private const WHITESPACE = " \t\n\r\x0B\x0C";

    /** @var array<string, array<string, string>> */
    private array $sections = [];

    private ?string $section = null;

    private ?string $option = null;

    private int $optionIndent = 0;

    /** Blank lines since the last line with content. */
    private int $blankLines = 0;

    private function __construct(string $text)
    {
        foreach (explode("\n", $text) as $line) {
            $this->line($line);
        }
    }

    /**
     * @return array<string, array<string, string>> section => option => value
     */
    public static function parse(string $text): array
    {
        return (new self($text))->sections;
    }

    /**
     * Ansible's unquote(): drops one pair of matching quotes around a value.
     */
    public static function unquote(string $value): string
    {
        $quote = $value[0] ?? '';
        $quoted = strlen($value) > 1 && ($quote === '"' || $quote === "'")
            && $value[-1] === $quote && $value[-2] !== '\\';

        return $quoted ? substr($value, 1, -1) : $value;
    }

    private function line(string $raw): void
    {
        $line = self::withoutComment($raw);
        if ($line === '') {
            $this->blankLines += trim($raw, self::WHITESPACE) === '' ? 1 : 0;

            return;
        }
        $indent = strspn($raw, " \t");
        $blankLines = $this->blankLines;
        $this->blankLines = 0;
        if ($this->section !== null && $this->option !== null && $indent > $this->optionIndent) {
            $this->sections[$this->section][$this->option] .= str_repeat("\n", $blankLines + 1) . $line;

            return;
        }
        $this->optionIndent = $indent;
        $this->option = null;
        if (preg_match('/^\[(.+)\]/', $line, $match) === 1) {
            $this->section = $match[1];

            return;
        }
        $this->startOption($line);
    }

    private function startOption(string $line): void
    {
        if ($this->section !== null && preg_match('/^(.*?)\s*[=:]\s*(.*)$/', $line, $match) === 1 && $match[1] !== '') {
            $this->option = strtolower($match[1]);
            $this->sections[$this->section][$this->option] = $match[2];
        }
    }

    private static function withoutComment(string $raw): string
    {
        $line = trim($raw, self::WHITESPACE);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            return '';
        }
        $comment = preg_match('/\s;/', $line, $match, PREG_OFFSET_CAPTURE) === 1 ? $match[0][1] : strlen($line);

        return rtrim(substr($line, 0, $comment), self::WHITESPACE);
    }
}
