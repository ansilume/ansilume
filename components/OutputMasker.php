<?php

declare(strict_types=1);

namespace app\components;

/**
 * Masks known password answers in a job's output on the runner, before the
 * output reaches the server. Best effort, on runners that get the answers in
 * payload['secret_extra_vars'] ({@see RunnerSecretVars}).
 *
 * What is masked (the needles):
 * - answers that are strings of at least MIN_LENGTH bytes, and their
 *   JSON-escaped forms where those differ, because Ansible prints results
 *   as JSON;
 * - integers whose string form has at least MIN_LENGTH characters;
 * - floats in each text of at least MIN_LENGTH characters in which Ansible
 *   may print them (floatForms()): as Python writes them
 *   ({@see PythonFloatText}), 1e+20 in task names, templated text and JSON
 *   results and 1.0e+20 in YAML; as the answers file holds them, whose
 *   whole numbers, such as 1000000000000000, Python reads as integers; and
 *   in PHP's own text (1.0E+20).
 * Booleans, null, lists and maps are not masked.
 *
 * Replacement follows strtr(): at each position the longest needle wins and
 * replaced text is not searched again, so an answer that contains another
 * leaves no fragment. Output arrives in chunks per stream. feed() holds back
 * only a tail that could still become a needle, so live output is not
 * delayed, and flush() releases it when the stream ends. The result is the
 * same as strtr() over the whole stream, wherever the chunks were cut.
 *
 * Limits:
 * - shorter answers, and answers Ansible prints transformed (hashed,
 *   encoded, split, quoted another way), stay visible;
 * - the mask is an oracle: where it appears, the output equalled an answer,
 *   so an answer that equals a host name masks that host name;
 * - output the playbook sends elsewhere (files, other systems) is not touched.
 */
final class OutputMasker
{
    public const MASK = '********';

    public const MIN_LENGTH = 4;

    /**
     * The escapings a string answer can have in JSON output: slashes escaped
     * or not, non-ASCII characters as \u escapes (Python's ensure_ascii) or not.
     */
    private const JSON_FLAGS = [
        0,
        JSON_UNESCAPED_SLASHES,
        JSON_UNESCAPED_UNICODE,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ];

    /** @var list<string> longest first */
    private array $needles = [];

    /** @var array<array-key, string> needle => MASK, for strtr(); PHP turns numeric needles into int keys */
    private array $replacements = [];

    /** Every byte a needle starts with. */
    private string $firstBytes = '';

    private int $longest = 0;

    /** @var array<string, string> held-back output per stream */
    private array $pending = [];

    /**
     * @param array<array-key, mixed> $answers password answers by name, any JSON type
     */
    public function __construct(#[\SensitiveParameter] array $answers)
    {
        $needles = [];
        foreach ($answers as $answer) {
            array_push($needles, ...self::forms($answer));
        }
        $needles = array_values(array_unique($needles));
        usort($needles, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($needles as $needle) {
            $this->replacements[$needle] = self::MASK;
            $this->firstBytes .= str_contains($this->firstBytes, $needle[0]) ? '' : $needle[0];
        }
        $this->needles = $needles;
        $this->longest = $needles === [] ? 0 : strlen($needles[0]);
    }

    /**
     * The masked output of $bytes, the next chunk of $stream, without the
     * tail that may still turn out to be an answer (see flush()).
     */
    public function feed(string $stream, #[\SensitiveParameter] string $bytes): string
    {
        if ($this->needles === []) {
            return $bytes;
        }
        [$out, $held] = $this->scan(($this->pending[$stream] ?? '') . $bytes, false);
        $this->pending[$stream] = $held;

        return $out;
    }

    /**
     * The masked tail feed() held back for $stream; call it when the stream ends.
     */
    public function flush(string $stream): string
    {
        $held = $this->pending[$stream] ?? '';
        unset($this->pending[$stream]);

        return $held === '' ? '' : $this->scan($held, true)[0];
    }

    /**
     * A complete text, masked: a task name or host from the callback plugin.
     */
    public function mask(#[\SensitiveParameter] string $text): string
    {
        return $this->needles === [] ? $text : strtr($text, $this->replacements);
    }

    /**
     * $record with the strings under $keys masked; other values stay.
     *
     * @param array<array-key, mixed> $record
     * @param list<string> $keys
     * @return array<array-key, mixed>
     */
    public function maskValues(#[\SensitiveParameter] array $record, array $keys): array
    {
        foreach ($keys as $key) {
            if (is_string($record[$key] ?? null)) {
                $record[$key] = $this->mask($record[$key]);
            }
        }

        return $record;
    }

    /**
     * The needles of one answer.
     *
     * @return list<string>
     */
    private static function forms(#[\SensitiveParameter] mixed $answer): array
    {
        if (is_int($answer)) {
            $text = (string)$answer;

            return strlen($text) >= self::MIN_LENGTH ? [$text] : [];
        }
        if (is_float($answer)) {
            return self::floatForms($answer);
        }
        if (!is_string($answer) || strlen($answer) < self::MIN_LENGTH) {
            return [];
        }
        $forms = [$answer];
        foreach (self::JSON_FLAGS as $flags) {
            $json = json_encode($answer, $flags);
            if (is_string($json)) {
                $forms[] = substr($json, 1, -1);
            }
        }

        return $forms;
    }

    /**
     * The needles of the float $answer: the texts of at least MIN_LENGTH
     * characters in which Ansible may print it. They are Python's repr(),
     * json.dumps() and PyYAML's; and for a finite one the text of the
     * answers file, json_encode()'s (RunnerSecretVars::prepare()), whose
     * whole numbers Python reads as integers and prints so, and PHP's own
     * text, 14 significant digits. JSON has no number for INF and NAN, so
     * the runner fails such a job before Ansible starts.
     *
     * @return list<string>
     */
    private static function floatForms(#[\SensitiveParameter] float $answer): array
    {
        $texts = [PythonFloatText::repr($answer), PythonFloatText::json($answer), PythonFloatText::yaml($answer)];
        if (is_finite($answer)) {
            // Not for INF and NAN, which Ansible never gets: PHP 8.5 also warns
            // when NAN becomes a string, and the error handler throws for it.
            array_push($texts, (string)json_encode($answer), (string)$answer);
        }
        $forms = [];
        foreach ($texts as $text) {
            if (strlen($text) >= self::MIN_LENGTH) {
                $forms[] = $text;
            }
        }

        return $forms;
    }

    /**
     * strtr() over $text, up to the first position where a needle may start
     * that $text ends too early to tell; unless $final, that tail is held
     * back instead of masked.
     *
     * @return array{0: string, 1: string} the masked output and the held-back tail
     */
    private function scan(#[\SensitiveParameter] string $text, bool $final): array
    {
        $out = '';
        $length = strlen($text);
        $position = 0;
        while ($position < $length) {
            // Bytes no needle starts with pass unchanged.
            $plain = strcspn($text, $this->firstBytes, $position);
            $out .= substr($text, $position, $plain);
            $position += $plain;
            if ($position === $length) {
                break;
            }
            if (!$final && $this->mayBecomeNeedle($text, $position)) {
                return [$out, substr($text, $position)];
            }
            $needle = $this->needleAt($text, $position);
            $out .= $needle === null ? $text[$position] : self::MASK;
            $position += $needle === null ? 1 : strlen($needle);
        }

        return [$out, ''];
    }

    /**
     * Whether the rest of $text from $position is a proper prefix of a
     * needle: more output decides whether that needle is there.
     */
    private function mayBecomeNeedle(#[\SensitiveParameter] string $text, int $position): bool
    {
        $rest = strlen($text) - $position;
        if ($rest >= $this->longest) {
            return false;
        }
        foreach ($this->needles as $needle) {
            if (strlen($needle) > $rest && substr_compare($text, $needle, $position, $rest) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The longest needle at $position of $text, or null.
     */
    private function needleAt(#[\SensitiveParameter] string $text, int $position): ?string
    {
        foreach ($this->needles as $needle) {
            if (substr_compare($text, $needle, $position, strlen($needle)) === 0) {
                return $needle;
            }
        }

        return null;
    }
}
