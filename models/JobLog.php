<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * @property int    $id
 * @property int    $job_id
 * @property string $stream   stdout | stderr
 * @property string $content
 * @property int    $sequence
 * @property int    $created_at
 *
 * @property Job    $job
 */
class JobLog extends ActiveRecord
{
    public const STREAM_STDOUT = 'stdout';
    public const STREAM_STDERR = 'stderr';

    public static function tableName(): string
    {
        return '{{%job_log}}';
    }

    public function rules(): array
    {
        return [
            [['job_id', 'content'], 'required'],
            [['job_id', 'sequence'], 'integer'],
            [['stream'], 'in', 'range' => [self::STREAM_STDOUT, self::STREAM_STDERR]],
            [['content'], 'string'],
        ];
    }

    public function getJob(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Job::class, ['id' => 'job_id']);
    }
}
