<?php

declare(strict_types=1);

namespace app\components;

use yii\base\Model;

/**
 * What an edit form shows in a field that holds a stored secret (the channel
 * config of a notification template, the signing secret of a webhook).
 *
 * The value this request submitted for the field, as it was submitted: the
 * user's own input, so a save that failed validation shows a typed value
 * again and submitting the corrected form stores it, and a placeholder stays
 * a placeholder. Otherwise what the model shows for its stored value, with
 * SurveySecrets::PLACEHOLDER for every secret. Never the attribute after
 * validation, which holds the stored secret in place of a placeholder.
 */
final class ShownSecretField
{
    /**
     * @param string $stored what the model shows for the stored value, never the secret itself
     */
    public static function value(Model $model, string $attribute, string $stored): string
    {
        $posted = \Yii::$app->request->post($model->formName());
        if (is_array($posted) && is_string($posted[$attribute] ?? null)) {
            return $posted[$attribute];
        }

        return $stored;
    }
}
