<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property int         $job_template_id
 * @property string      $status
 * @property string|null $extra_vars       JSON
 * @property string|null $limit
 * @property int|null    $verbosity
 * @property string|null $runner_payload   JSON snapshot
 * @property int         $launched_by
 * @property int|null    $queued_at
 * @property int|null    $started_at
 * @property int|null    $finished_at
 * @property int|null    $exit_code
 * @property int|null    $pid
 * @property int         $created_at
 * @property int         $updated_at
 *
 * @property JobTemplate $jobTemplate
 * @property User        $launcher
 * @property JobLog[]    $logs
 */
class Job extends ActiveRecord
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_QUEUED    = 'queued';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELED  = 'canceled';

    public static function tableName(): string
    {
        return '{{%job}}';
    }

    public function behaviors(): array
    {
        return [\yii\behaviors\TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['job_template_id', 'launched_by'], 'required'],
            [['job_template_id', 'launched_by', 'exit_code', 'pid'], 'integer'],
            [['status'], 'in', 'range' => self::statuses()],
            [['extra_vars', 'runner_payload'], 'string'],
            [['extra_vars'], 'validateJson'],
            [['limit'], 'string', 'max' => 255],
            [['verbosity'], 'integer', 'min' => 0, 'max' => 5],
        ];
    }

    public function validateJson(string $attribute): void
    {
        if (!empty($this->$attribute)) {
            json_decode((string)$this->$attribute);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->addError($attribute, ucfirst($attribute) . ' must be valid JSON.');
            }
        }
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_QUEUED,
            self::STATUS_RUNNING,
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_CANCELED,
        ];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_CANCELED,
        ], true);
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isCancelable(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_QUEUED,
            self::STATUS_RUNNING,
        ], true);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING   => 'Pending',
            self::STATUS_QUEUED    => 'Queued',
            self::STATUS_RUNNING   => 'Running',
            self::STATUS_SUCCEEDED => 'Succeeded',
            self::STATUS_FAILED    => 'Failed',
            self::STATUS_CANCELED  => 'Canceled',
            default                => $status,
        };
    }

    public static function statusCssClass(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING, self::STATUS_QUEUED => 'secondary',
            self::STATUS_RUNNING                      => 'primary',
            self::STATUS_SUCCEEDED                    => 'success',
            self::STATUS_FAILED                       => 'danger',
            self::STATUS_CANCELED                     => 'warning',
            default                                   => 'secondary',
        };
    }

    public function getJobTemplate(): \yii\db\ActiveQuery
    {
        return $this->hasOne(JobTemplate::class, ['id' => 'job_template_id']);
    }

    public function getLauncher(): \yii\db\ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'launched_by']);
    }

    public function getLogs(): \yii\db\ActiveQuery
    {
        return $this->hasMany(JobLog::class, ['job_id' => 'id'])->orderBy('sequence ASC');
    }
}
