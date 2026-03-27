<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * @property int    $id
 * @property int    $job_id
 * @property int    $sequence
 * @property string $task_name
 * @property string $task_action
 * @property string $host
 * @property string $status       ok|changed|failed|skipped|unreachable
 * @property int    $changed
 * @property int    $duration_ms
 * @property int    $created_at
 */
class JobTask extends ActiveRecord
{
    public const STATUS_OK = 'ok';
    public const STATUS_CHANGED = 'changed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_UNREACHABLE = 'unreachable';

    public static function tableName(): string
    {
        return '{{%job_task}}';
    }

    public static function statusCssClass(string $status): string
    {
        return match ($status) {
            self::STATUS_OK => 'success',
            self::STATUS_CHANGED => 'warning',
            self::STATUS_FAILED => 'danger',
            self::STATUS_SKIPPED => 'secondary',
            self::STATUS_UNREACHABLE => 'dark',
            default => 'secondary',
        };
    }
}
