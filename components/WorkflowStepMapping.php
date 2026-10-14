<?php

declare(strict_types=1);

namespace app\components;

/**
 * The variable mapping of a workflow step (WorkflowStep::extra_vars_template,
 * JSON text): the extra vars a job step takes from the output of the step
 * before it, as a JSON object of extra var name => output variable name,
 * such as {"app_version": "build_version"}.
 *
 * - None (isNone()): null, empty text, or JSON text of null, [] or {}. The
 *   API answers [] for a step without a mapping, so a client that sends it
 *   back sends none; a request that sends none stores null
 *   (WorkflowStepsInput::extraVarsTemplate()).
 * - Valid (isValid()): none, or a JSON object whose keys are extra var
 *   names (non-empty text) and whose values are text, the names of output
 *   variables. A list, a number, text, true or false, a key PHP reads as a
 *   number (such as "0") and a value that is no text are not: a list mapped
 *   into a job's extra vars made them a JSON list, which Ansible refuses.
 * - The same (same()): both none, or JSON of the same value, whitespace and
 *   the order of an object's keys aside. Text that is no JSON is compared as
 *   it is.
 */
final class WorkflowStepMapping
{
    /** A mapping that is not none and not a JSON object of extra var names to output variable names (422). */
    public const INVALID = 'extra_vars_template must be null or a JSON object that maps extra var names '
        . 'to the names of output variables, such as {"app_version": "build_version"}.';

    /**
     * An attribute's value as the text of a mapping: text as it is, null
     * for anything else.
     */
    public static function textOf(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * Whether $text is no mapping: null, empty text, or JSON text of null,
     * [] or {} (with spaces around it, too).
     */
    public static function isNone(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return true;
        }
        $decoded = json_decode($text, true);

        return json_last_error() === JSON_ERROR_NONE && ($decoded === null || $decoded === []);
    }

    /**
     * Whether $text is a mapping a step may store: none, or a JSON object
     * whose keys are non-empty text and whose values are text.
     */
    public static function isValid(?string $text): bool
    {
        if (self::isNone($text)) {
            return true;
        }
        $decoded = json_decode((string)$text, true);
        if (!is_array($decoded)) {
            return false;
        }
        foreach ($decoded as $name => $source) {
            if (!is_string($name) || $name === '' || !is_string($source)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether $first and $second are the same mapping: both none, or JSON
     * of the same value, whitespace and the order of an object's keys
     * aside; text that is no JSON only when it is the same text.
     */
    public static function same(?string $first, ?string $second): bool
    {
        return self::comparable($first) === self::comparable($second);
    }

    /**
     * $text as same() compares it: null for none, else "json:" and the JSON
     * of its value with every object's keys sorted, or "text:" and the text
     * itself when it is no JSON, or JSON that cannot be written back.
     */
    private static function comparable(?string $text): ?string
    {
        if (self::isNone($text)) {
            return null;
        }
        $decoded = json_decode((string)$text, true);
        $json = json_last_error() === JSON_ERROR_NONE ? json_encode(self::sortKeys($decoded)) : false;

        return $json === false ? 'text:' . $text : 'json:' . $json;
    }

    /**
     * $value with the keys of every object in it sorted; a list keeps its
     * order.
     */
    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(static fn (mixed $item): mixed => self::sortKeys($item), $value);
    }
}
