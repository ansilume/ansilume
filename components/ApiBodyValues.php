<?php

declare(strict_types=1);

namespace app\components;

/**
 * Refuses a request body whose field holds a list or an object where the
 * endpoint takes a single value, before the body reaches the model: a cast
 * would turn a list into "Array" or 1 (with a PHP error for the string
 * cast), a "default" rule would turn an empty one into "none", and a typed
 * access check would throw. The JSON body parser decodes an object as an
 * array, so both arrive as arrays.
 *
 * The body can hold secrets (inventory content, passwords in it), so the
 * parameter is marked and no value is ever named: only the field.
 */
final class ApiBodyValues
{
    /** The refusal (422): the field's name. */
    public const NOT_A_VALUE = '%s must be a single value, not a list or an object.';

    /**
     * The refusal of the first field, in the order of $fields, whose value
     * in $body is a list or an object; null when there is none. Scalars,
     * null and fields that $body leaves out pass, and so do keys that
     * $fields does not name.
     *
     * @param array<array-key, mixed> $body
     * @param list<string> $fields
     */
    public static function listValueRefusal(#[\SensitiveParameter] array $body, array $fields): ?string
    {
        foreach ($fields as $field) {
            $value = $body[$field] ?? null;
            if ($value !== null && !is_scalar($value)) {
                return sprintf(self::NOT_A_VALUE, $field);
            }
        }

        return null;
    }
}
