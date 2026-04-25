<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;
use yii\db\ActiveQuery;

/**
 * @property int    $id
 * @property int    $project_id
 * @property string $stream     stdout | stderr | system
 * @property string $content
 * @property int    $sequence
 * @property int    $created_at
 *
 * @property Project $project
 */
class ProjectSyncLog extends ActiveRecord
{
    public const STREAM_STDOUT = 'stdout';
    public const STREAM_STDERR = 'stderr';
    public const STREAM_SYSTEM = 'system';

    public static function tableName(): string
    {
        return '{{%project_sync_log}}';
    }

    public function rules(): array
    {
        return [
            [['project_id', 'content'], 'required'],
            [['project_id', 'sequence'], 'integer'],
            [['stream'], 'in', 'range' => [self::STREAM_STDOUT, self::STREAM_STDERR, self::STREAM_SYSTEM]],
            [['content'], 'string'],
        ];
    }

    public function getProject(): ActiveQuery
    {
        return $this->hasOne(Project::class, ['id' => 'project_id']);
    }
}
