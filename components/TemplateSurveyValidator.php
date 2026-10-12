<?php

declare(strict_types=1);

namespace app\components;

use app\models\JobTemplate;
use yii\validators\Validator;

/**
 * The survey rules of a job template. Each runs only when what it protects
 * differs from the template's baseline ({@see JobTemplate::getValidationBaseline()}:
 * the stored values, or the cloned template's), so templates saved before
 * the rules keep saving and cloning:
 * - survey_fields: {@see SurveyDefinition::problems()}, when the survey
 *   changed (field names compared as stored, untrimmed);
 * - extra_vars: no top-level key may be the name of a password field. Its
 *   value would be a default for the password answer, in plaintext on the
 *   template. This runs when the extra vars changed (a new, removed or
 *   changed key or value; see sameExtraVars() for the rewrites of the same
 *   JSON that are no change), or when the names of the password
 *   fields did (one added, renamed, retyped to or from password, or
 *   removed). Survey edits that leave the password fields' names alone,
 *   such as defaults, options, labels or other fields, do not count.
 *
 * The extra vars of a template saved before the rule may hold a password
 * field's plaintext default, so the parameters that carry them are marked
 * #[\SensitiveParameter].
 */
final class TemplateSurveyValidator extends Validator
{
    public const PASSWORD_KEY_IN_EXTRA_VARS = 'Remove %s from Extra Vars: a password survey field cannot have a default; enter it at launch.';

    /**
     * 2^63, a float: the int range is -2^63 up to, not including, 2^63. A
     * cast to int keeps the value of a whole float only within that range;
     * beyond, PHP's cast gives another int: 2^64 becomes 0, 2^63 the
     * smallest int.
     */
    private const INT_RANGE_END = 2 ** 63;

    /**
     * @param \yii\base\Model $model
     * @param string $attribute
     */
    public function validateAttribute($model, $attribute): void
    {
        if (!$model instanceof JobTemplate) {
            return;
        }
        $baseline = $model->getValidationBaseline();
        $survey = self::textOrNull($model->survey_fields);
        if ($attribute === 'survey_fields') {
            foreach (SurveyDefinition::problems($survey, $baseline['survey_fields']) as $problem) {
                $model->addError($attribute, $problem);
            }
            return;
        }
        if ($attribute !== 'extra_vars') {
            return;
        }
        $extraVars = self::textOrNull($model->extra_vars);
        $changed = !self::sameExtraVars($extraVars, $baseline['extra_vars'])
            || self::passwordNames($survey) !== self::passwordNames($baseline['survey_fields']);
        if (!$changed) {
            return;
        }
        foreach (self::passwordKeys($extraVars, $survey) as $key) {
            $model->addError($attribute, sprintf(self::PASSWORD_KEY_IN_EXTRA_VARS, SurveyDefinition::quote($key)));
        }
    }

    /**
     * The top-level keys of $extraVars that are password field names of
     * $survey.
     *
     * @return list<string>
     */
    private static function passwordKeys(#[\SensitiveParameter] ?string $extraVars, ?string $survey): array
    {
        $vars = json_decode((string)$extraVars, true);
        if (!is_array($vars)) {
            return [];
        }
        $names = self::passwordNames($survey);
        $keys = array_map('strval', array_keys($vars));

        return array_values(array_filter($keys, static fn (string $key): bool => in_array($key, $names, true)));
    }

    /**
     * The names of the password fields of $survey, usable and unusable, as
     * stored ({@see SurveyDefinition::secretNames()}) and sorted, so that two
     * surveys can be compared by them.
     *
     * @return list<string>
     */
    private static function passwordNames(?string $survey): array
    {
        $names = SurveyDefinition::secretNames($survey);
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Whether the extra vars texts $value and $baseline are the same extra
     * vars: decode() reads both twice, once with whole numbers beyond the
     * int range (-2^63 up to, not including, 2^63) as the text of their
     * digits and once as floats, and both times their values must be the
     * same ({@see sameValue()}).
     *
     * So a number written another way is no change when it reads as the
     * same int or float: 1.0 is 1, 1e2 is 100, 1e18 is 1000000000000000000,
     * -0.0 is 0. A whole number beyond the int range is no change only when
     * it is written with the same digits. As text in the first decode, it
     * differs from other digits (12345678901234567999 is not
     * 12345678901234567890, although both read as one float) and from a
     * number with a fraction or an exponent, which reads as a float in both
     * decodes (1e20 is not 100000000000000000000). As a float in the second,
     * it differs from the same digits written as JSON text
     * ("12345678901234567890" is text in both), as in the launch, which
     * reads such a number as a float.
     */
    private static function sameExtraVars(
        #[\SensitiveParameter] ?string $value,
        #[\SensitiveParameter] ?string $baseline
    ): bool {
        foreach ([JSON_BIGINT_AS_STRING, 0] as $flags) {
            if (!self::sameValue(self::decode($value, $flags), self::decode($baseline, $flags))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Extra vars JSON as {@see sameValue()} compares it: objects as
     * \stdClass, so that an object never equals a list. $flags is
     * JSON_BIGINT_AS_STRING, for whole numbers beyond the int range as the
     * text of their digits, or 0, for them as the nearest float, as the
     * launch reads them. null when $json is no JSON.
     */
    private static function decode(#[\SensitiveParameter] ?string $json, int $flags): mixed
    {
        return json_decode((string)$json, false, 512, $flags);
    }

    /**
     * Whether $value and $baseline, extra vars as decode() reads them, are
     * the same extra vars.
     *
     * A client that decodes and re-encodes the stored JSON posts it back
     * rewritten although the save leaves its values alone: the JSON library
     * of an API client, or the template form's Extra Vars editor once it was
     * switched to YAML (in JSON mode it posts the text as stored or typed).
     * Numbers come back in their shortest form (1.0 as 1, 1e2 as 100, which
     * PHP decodes as int instead of float) and the integer-like keys of every
     * object first. So objects compare by their keys, in any order, and lists
     * by position; an object never equals a list. Values compare as
     * {@see sameScalar()} says.
     *
     * A key written twice keeps its last value, here as in the launch, which
     * decodes the extra vars with json_decode() too: a changed earlier
     * duplicate changes no value a launch uses, so it is no change.
     */
    private static function sameValue(#[\SensitiveParameter] mixed $value, #[\SensitiveParameter] mixed $baseline): bool
    {
        if ($value instanceof \stdClass && $baseline instanceof \stdClass) {
            return self::sameEntries(get_object_vars($value), get_object_vars($baseline));
        }
        if (is_array($value) && is_array($baseline)) {
            return self::sameEntries($value, $baseline);
        }

        return self::sameScalar($value, $baseline);
    }

    /**
     * Whether $values and $baseline have the same keys, in any order, with
     * the same values ({@see sameValue()}). A list keeps its order: its keys
     * are the positions.
     *
     * @param array<mixed> $values
     * @param array<mixed> $baseline
     */
    private static function sameEntries(#[\SensitiveParameter] array $values, #[\SensitiveParameter] array $baseline): bool
    {
        if (count($values) !== count($baseline)) {
            return false;
        }
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $baseline) || !self::sameValue($value, $baseline[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether two values that are no object and no list are the same.
     *
     * An int and a float are the same only when they are exactly the same
     * number: the float is whole and lies within the int range, so it is
     * finite and a cast to int keeps its value, and it casts to that int.
     * So 1.0 and 1 are the same, as are 1e2 and 100, 1e18 and
     * 1000000000000000000, or -0.0 and 0. 9007199254740992.0 is not
     * 9007199254740993, although PHP's == would say so; neither is
     * 9007199254740993.0, which PHP reads as the float 9007199254740992.
     * The rule compares values, not how a launch writes them: from 10^17 on,
     * json_encode() writes a whole float with an exponent, so a launch passes
     * 1e18 as a float although it is the same as 1000000000000000000 here.
     *
     * Everything else compares strictly (===): two floats, two ints, text,
     * booleans and null. PHP's == would also take true for any non-empty
     * text and "1" for "01", so the value of a legacy password key could
     * change unnoticed. Two floats are the same when PHP reads them as the
     * same float (0.50 and 0.5, 1e20 and 100000000000000000000.0). A whole
     * number beyond the int range that decode() read as the text of its
     * digits compares as that text.
     */
    private static function sameScalar(#[\SensitiveParameter] mixed $value, #[\SensitiveParameter] mixed $baseline): bool
    {
        if (is_float($value) && is_int($baseline)) {
            return self::sameScalar($baseline, $value);
        }
        if (is_int($value) && is_float($baseline)) {
            return floor($baseline) === $baseline
                && $baseline >= -self::INT_RANGE_END && $baseline < self::INT_RANGE_END
                && (int)$baseline === $value;
        }

        return $value === $baseline;
    }

    private static function textOrNull(#[\SensitiveParameter] mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
