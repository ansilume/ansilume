<?php

declare(strict_types=1);

namespace app\components\vault;

/**
 * Single YAML values as PlaybookVarsFiles reads them, without a YAML parser:
 * plain and quoted scalars, comments, and flow sequences (`[a.yml, "b.yml"]`).
 * Every pattern is possessive and nothing recurses, so the time stays linear
 * in the length of the value, whatever a repository file holds.
 */
final class YamlValue
{
    /**
     * Longer values, a line or a flow sequence over several lines, give
     * nothing: no list of file names is that long, and the reader never
     * builds huge token lists.
     */
    public const MAX_VALUE_BYTES = 65536;

    /** Double- and single-quoted scalars. Possessive, so linear. */
    private const QUOTED = '"(?:[^"\\\\]++|\\\\.)*+"|\'(?:[^\']++|\'\')*+\'';

    /** Flow tokens: quoted scalars, indicators, plain text. */
    private const FLOW_TOKEN = '/' . self::QUOTED . '|[\[\]{},]|[^\[\]{},"\']++/';

    /** Anchors and tags in front of a value. */
    private const PROPERTIES = '/^(?:[&!]\S*+[ \t]*+)++/';

    /** Plain scalars YAML reads as null, a boolean or a number. */
    private const NOT_A_STRING = '/^(?:~|null|true|false|yes|no|on|off|[-+]?(?:[0-9][0-9_]*+(?:\.[0-9_]*+)?|\.[0-9_]++)(?:e[-+]?[0-9]++)?|[-+]?\.inf|\.nan)$/i';

    /**
     * How many brackets the text opens and does not close; quoted text does
     * not count.
     */
    public static function depth(string $text): int
    {
        $bare = (string)preg_replace('/' . self::QUOTED . '/', '', $text);

        return substr_count($bare, '[') + substr_count($bare, '{') - substr_count($bare, ']') - substr_count($bare, '}');
    }

    /**
     * A value without its comment, anchor or tag; null when it is too long
     * to be a list of file names.
     */
    public static function value(string $raw): ?string
    {
        if (strlen($raw) > self::MAX_VALUE_BYTES) {
            return null;
        }

        return (string)preg_replace(self::PROPERTIES, '', self::withoutComment(trim($raw, " \t")));
    }

    /**
     * The scalars of a flow sequence and of the sequences directly in it
     * (alternatives); deeper nesting and flow mappings give nothing.
     *
     * @return list<string>
     */
    public static function flowEntries(string $text): array
    {
        preg_match_all(self::FLOW_TOKEN, $text, $matches);
        $entries = [];
        $open = [];
        $pending = null;
        foreach ($matches[0] as $token) {
            if (!in_array($token, ['[', '{', ']', '}', ','], true)) {
                $pending = self::scalar($token) ?? $pending;
                continue;
            }
            // Sequences only, the outer one and those directly in it.
            if ($pending !== null && count($open) <= 2 && !in_array('{', $open, true)) {
                $entries[] = $pending;
            }
            $pending = null;
            $open = self::nested($open, $token);
            if ($open === null) {
                break;
            }
        }

        return $entries;
    }

    /**
     * @param list<string> $open the collections open before the token
     * @return list<string>|null those open after it, null once the outermost one closed
     */
    private static function nested(array $open, string $token): ?array
    {
        if ($token === '[' || $token === '{') {
            $open[] = $token;

            return $open;
        }
        if ($token !== ',') {
            array_pop($open);
        }

        return $open === [] ? null : $open;
    }

    /**
     * A scalar's string value: quotes removed, null for blank values and for
     * null, booleans and numbers.
     */
    public static function scalar(string $raw): ?string
    {
        $value = trim($raw, " \t");
        $quote = $value[0] ?? '';
        if ($quote === '"' || $quote === "'") {
            return self::unquoted($value, $quote);
        }

        return $value === '' || preg_match(self::NOT_A_STRING, $value) === 1 ? null : $value;
    }

    /**
     * The text of a quoted scalar; null when the closing quote is missing.
     */
    private static function unquoted(string $value, string $quote): ?string
    {
        if (strlen($value) < 2 || !str_ends_with($value, $quote)) {
            return null;
        }
        $inner = substr($value, 1, -1);

        return $quote === '"' ? str_replace(['\\"', '\\\\'], ['"', '\\'], $inner) : str_replace("''", "'", $inner);
    }

    /**
     * The text without a trailing comment: a # at the start or after a space
     * or tab, outside quotes.
     */
    private static function withoutComment(string $text): string
    {
        $quote = null;
        $length = strlen($text);
        for ($position = 0; $position < $length; $position++) {
            if ($quote !== null) {
                [$quote, $position] = self::insideQuote($text, $position, $quote);
                continue;
            }
            $char = $text[$position];
            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '#' && ($position === 0 || ctype_space($text[$position - 1]))) {
                return rtrim(substr($text, 0, $position), " \t");
            }
        }

        return rtrim($text, " \t");
    }

    /**
     * One character inside a quoted scalar: the closing quote ends it, and
     * in double quotes a backslash escapes the next character.
     *
     * @return array{0: string|null, 1: int} the quote still open, the position
     */
    private static function insideQuote(string $text, int $position, string $quote): array
    {
        $char = $text[$position];
        $escape = $quote === '"' && $char === '\\' ? 1 : 0;

        return [$char === $quote ? null : $quote, $position + $escape];
    }
}
