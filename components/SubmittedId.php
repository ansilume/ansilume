<?php

declare(strict_types=1);

namespace app\components;

use yii\base\Model;
use yii\validators\NumberValidator;

/**
 * A reference ID that a request submits (a job template, an approval rule),
 * read as the model's integer rule reads it, so that an access check and
 * the save use one value.
 *
 * The integer rule accepts more than filter_var() does, such as "0112709"
 * and "+0112709", and the integer column stores both as 112709: an access
 * check on a stricter parse lets them through unchecked. So every path that
 * checks a submitted ID before it saves it reads the ID here first: the web
 * controllers (TeamScopingTrait::normalizeSubmittedId()) and the step write
 * path (WorkflowStepWriteService).
 */
final class SubmittedId
{
    /**
     * The ID $value stands for: an int, a float or a string that the integer
     * rule accepts, as an int ("05" and "+5" are 5); null for anything else,
     * an empty value included, which the model's rules then report.
     */
    public static function of(mixed $value): ?int
    {
        if (!(is_int($value) || is_float($value) || is_string($value))) {
            return null;
        }
        $integerRule = new NumberValidator(['integerOnly' => true]);

        return $integerRule->validate($value) ? (int)$value : null;
    }

    /**
     * The submitted $attribute of $model as the model stores it (of()),
     * assigned back to the attribute, so that the access check and the save
     * use one value. Returns null and leaves the attribute as it is when the
     * value is empty or the integer rule rejects it; the model's rules
     * report that.
     */
    public static function normalize(Model $model, string $attribute): ?int
    {
        $id = self::of($model->$attribute);
        if ($id !== null) {
            $model->$attribute = $id;
        }

        return $id;
    }
}
