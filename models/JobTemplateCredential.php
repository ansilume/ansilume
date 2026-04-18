<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * Pivot row linking a {@see JobTemplate} to one of its many
 * {@see Credential} entries. `sort_order` resolves conflicts when two
 * credentials target the same single-slot ansible argument (`--user`,
 * `--private-key`, `--vault-password-file`): the lowest order wins.
 *
 * @property int $job_template_id
 * @property int $credential_id
 * @property int $sort_order
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

    /**
     * @return string[]
     */
    public static function primaryKey(): array
    {
        return ['job_template_id', 'credential_id'];
    }
}
