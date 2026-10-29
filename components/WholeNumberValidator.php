<?php

declare(strict_types=1);

namespace app\components;

use yii\helpers\StringHelper;
use yii\validators\NumberValidator;

/**
 * Yii's NumberValidator, except that with integerOnly a float that is no
 * whole number is refused however close it is to one, and that NAN is
 * refused in every mode. It is the class of every 'integer', 'number' and
 * 'double' rule and of NumberValidator::class rules: config/web.php,
 * config/console.php and config/test.php map NumberValidator to it in the
 * container, through which Validator::createValidator() builds each rule.
 * Code that needs the integer rule of its own creates this class, never
 * NumberValidator (tests/unit/WholeNumberRulesTest.php).
 *
 * NumberValidator matches its pattern against the float's text, which PHP
 * writes with 14 significant digits (the precision setting): a float that
 * is no whole number but whose 14 digits round to one, such as
 * 2.9999999999999996 (text "3"), 10.00000000000001 ("10") or
 * 12345678901234.4 ("12345678901234"), passed the integer rule, and the
 * (int) cast of the column's typecast and of SubmittedInteger::of() then
 * truncated it to 2, 10 or 12345678901234. And it made text of NAN, for
 * which PHP 8.5 warns. Such a float now gets the rule's message as 12.5
 * does: where it stands in a list, and followed by the min and max errors
 * as the parent adds them. Every other value gets the parent's answer, the
 * same errors in the same order, a whole float included (5.0 is 5; 1e15,
 * text "1.0E+15", is refused); the client-side check is the parent's.
 */
final class WholeNumberValidator extends NumberValidator
{
    /**
     * The parent's checks, in its order, with the pattern check of
     * readsAsANumber().
     *
     * @param \yii\base\Model $model
     * @param string $attribute
     */
    public function validateAttribute($model, $attribute): void
    {
        $value = $model->$attribute;
        if (is_array($value) && !$this->allowArray) {
            $this->addError($model, $attribute, (string)$this->message);
            return;
        }
        foreach (is_array($value) ? $value : [$value] as $sample) {
            if (self::isNoNumber($sample)) {
                $this->addError($model, $attribute, (string)$this->message);
                return;
            }
            foreach ($this->errorsOf($sample) as [$message, $params]) {
                $this->addError($model, $attribute, $message, $params);
            }
        }
    }

    /**
     * The parent's checks, in its order, with the pattern check of
     * readsAsANumber(): the first error, null for none.
     *
     * @param mixed $value
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    protected function validateValue($value): ?array
    {
        if (is_array($value) && !$this->allowArray) {
            return [(string)$this->message, []];
        }
        foreach (is_array($value) ? $value : [$value] as $sample) {
            if (self::isNoNumber($sample)) {
                return [(string)$this->message, []];
            }
            $errors = $this->errorsOf($sample);
            if ($errors !== []) {
                return $errors[0];
            }
        }

        return null;
    }

    /**
     * The errors of $sample, a value of a type a number can have, in the
     * order the parent adds them: the rule's message when it does not read
     * as a number of the rule, then the min and max errors.
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function errorsOf(mixed $sample): array
    {
        $errors = [];
        if (!$this->readsAsANumber($sample)) {
            $errors[] = [(string)$this->message, []];
        }
        if ($this->min !== null && $sample < $this->min) {
            $errors[] = [(string)$this->tooSmall, ['min' => $this->min]];
        }
        if ($this->max !== null && $sample > $this->max) {
            $errors[] = [(string)$this->tooBig, ['max' => $this->max]];
        }

        return $errors;
    }

    /**
     * Whether $sample reads as a number of the rule: the parent's pattern
     * check of its text, and for a float that it is a number, and with
     * integerOnly a whole one. NAN, which is neither, is not made text of.
     * Infinity is whole to floor() and refused by the pattern ("INF").
     */
    private function readsAsANumber(mixed $sample): bool
    {
        if (is_float($sample) && (is_nan($sample) || ($this->integerOnly && floor($sample) !== $sample))) {
            return false;
        }
        $pattern = $this->integerOnly ? $this->integerPattern : $this->numberPattern;

        // normalizeNumber() starts with this cast: the text is the parent's.
        return preg_match($pattern, StringHelper::normalizeNumber((string)$sample)) === 1;
    }

    /**
     * The parent's isNotNumber(), which is private: a list, a boolean, an
     * object without __toString() and a resource are no number.
     */
    private static function isNoNumber(mixed $value): bool
    {
        return is_array($value)
            || is_bool($value)
            || (is_object($value) && !method_exists($value, '__toString'))
            || (!is_object($value) && !is_scalar($value) && $value !== null);
    }
}
