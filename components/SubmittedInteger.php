<?php

declare(strict_types=1);

namespace app\components;

use yii\base\Model;

/**
 * A whole number that a request submits (an ID, a verbosity, a count), read
 * as the model's integer rule reads it, never with a plain (int) cast.
 *
 * A JSON body gives a number beyond the int range, such as 1e30,
 * 9223372036854775808 or 1e999 (infinity), as a float. Since PHP 8.5 an
 * (int) cast of such a float warns ("The float 1.0E+30 is not representable
 * as an int, cast occurred"), and the error handlers turn the warning into
 * an exception: the request answered 500 instead of 422. Up to PHP 8.4 the
 * cast wrapped it to an arbitrary int, or made infinity 0. The integer rule
 * refuses such a float without a warning, as it refuses a fraction, also
 * one whose text reads as a whole number, such as 2.9999999999999996 (text
 * "3"), which Yii's NumberValidator took for one: the rule is
 * WholeNumberValidator, here and, through the container definition of the
 * configs, in every model.
 *
 * It refuses a list as well, but not an empty one: Yii takes [] for empty,
 * and the request's JSON parser makes a JSON [] and {} one. The rules skip
 * it, the required rule aside, and the column stores it as 0, or its
 * foreign key refuses 0 with a server error. isEmpty() makes a rule take
 * only null and "" for empty; refusesLists() refuses a list where the
 * model's rules are not ours to change.
 */
final class SubmittedInteger
{
    /**
     * The whole number $value stands for: an int, a float or a string that
     * the integer rule accepts, as an int ("05", "+5" and "5\n" are 5, 5.0
     * is 5); null for anything else, an empty value, a fraction (however
     * close to a whole number), NAN and a float beyond the int range
     * included, which the model's rules then report.
     */
    public static function of(mixed $value): ?int
    {
        if (!(is_int($value) || is_float($value) || is_string($value))) {
            return null;
        }
        $integerRule = new WholeNumberValidator(['integerOnly' => true]);

        return $integerRule->validate($value) ? (int)$value : null;
    }

    /**
     * $value as of() reads it, or $value itself when it is no whole number,
     * so that the model's integer rule reports it. An empty list is empty to
     * Yii's rules, which skip it: a caller refuses lists and objects first,
     * with refusesLists(), unless a rule of the model refuses an empty list
     * itself: an integer rule that has isEmpty() as its isEmpty, or a
     * required rule.
     */
    public static function orAsSubmitted(mixed $value): mixed
    {
        return self::of($value) ?? $value;
    }

    /**
     * Whether $value is empty to the integer rule of a whole number that a
     * request submits: null, or "", the empty choice of a form. An empty list
     * is not, whatever Yii takes for empty: given as the rule's isEmpty, this
     * makes the rule refuse [] as it refuses a list with values.
     */
    public static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * Whether $values (a request body, or the posted fields of a form) holds
     * a list or an object for one of $attributes of $model: each such
     * attribute gets the integer rule's error, "<label> must be an
     * integer.", as its rule gives it for a list with values. The caller then
     * saves nothing. For a model whose rules are not the caller's to change
     * (isEmpty()); the attributes are left as they are.
     *
     * @param array<array-key, mixed> $values
     * @param list<string> $attributes
     */
    public static function refusesLists(Model $model, #[\SensitiveParameter] array $values, array $attributes): bool
    {
        $integerRule = new WholeNumberValidator(['integerOnly' => true]);
        $refused = false;
        foreach ($attributes as $attribute) {
            if (is_array($values[$attribute] ?? null)) {
                $integerRule->addError($model, $attribute, (string)$integerRule->message);
                $refused = true;
            }
        }

        return $refused;
    }
}
