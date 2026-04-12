<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property int         $created_by
 * @property int         $created_at
 * @property int         $updated_at
 *
 * @property Runner[]    $runners
 * @property User        $creator
 * @property JobTemplate[] $jobTemplates
 */
class RunnerGroup extends ActiveRecord
{
    public const STALE_AFTER = 120; // seconds — runner considered offline

    public static function tableName(): string
    {
        return '{{%runner_group}}';
    }

    public function behaviors(): array
    {
        return [\yii\behaviors\TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['name'], 'required'],
            [['name'], 'string', 'max' => 128],
            [['description'], 'string', 'max' => 1000],
            [['created_by'], 'integer'],
        ];
    }

    public function getRunners(): \yii\db\ActiveQuery
    {
        return $this->hasMany(Runner::class, ['runner_group_id' => 'id']);
    }

    public function getCreator(): \yii\db\ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }

    public function getJobTemplates(): \yii\db\ActiveQuery
    {
        return $this->hasMany(JobTemplate::class, ['runner_group_id' => 'id']);
    }

    public function countOnline(): int
    {
        $cutoff = time() - self::STALE_AFTER;
        return (int)$this->getRunners()->where(['>=', 'last_seen_at', $cutoff])->count();
    }

    public function countTotal(): int
    {
        return (int)$this->getRunners()->count();
    }
}
