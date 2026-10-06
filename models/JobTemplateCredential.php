<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * Pivot row linking a {@see JobTemplate} to one of its many
 * {@see Credential} entries. Precedence: the template's primary credential
 * first, then the pivot rows by `sort_order`. It decides which credential
 * wins when two target the same single-slot ansible argument (`--user`,
 * `--private-key`, `--vault-password-file`): the first one.
 *
 * @property int $job_template_id
 * @property int $credential_id
 * @property int $sort_order
 *
 * @property Credential|null $credential
 */
class JobTemplateCredential extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%job_template_credential}}';
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            [['job_template_id', 'credential_id'], 'required'],
            [['job_template_id', 'credential_id', 'sort_order'], 'integer'],
        ];
    }

    public function getCredential(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Credential::class, ['id' => 'credential_id']);
    }

    /**
     * @return string[]
     */
    public static function primaryKey(): array
    {
        return ['job_template_id', 'credential_id'];
    }
}
