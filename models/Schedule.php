<?php

declare(strict_types=1);

namespace app\models;

use Cron\CronExpression;
use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property string      $name
 * @property int         $job_template_id
 * @property string      $cron_expression  Standard 5-field cron: min hour dom mon dow
 * @property string      $timezone         PHP timezone name
 * @property string|null $extra_vars       JSON extra vars override
 * @property bool        $enabled
 * @property int|null    $last_run_at      Unix timestamp of last execution
 * @property int|null    $next_run_at      Pre-computed Unix timestamp of next execution
 * @property int         $created_by
 * @property int         $created_at
 * @property int         $updated_at
 *
 * @property JobTemplate $jobTemplate
 * @property User        $creator
 */
class Schedule extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%schedule}}';
    }

    public function behaviors(): array
    {
        return [\yii\behaviors\TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['name', 'job_template_id', 'cron_expression'], 'required'],
            [['name'], 'string', 'max' => 128],
            [['job_template_id', 'created_by'], 'integer'],
            [['cron_expression'], 'string', 'max' => 64],
            [['cron_expression'], 'validateCronExpression'],
            [['timezone'], 'string', 'max' => 64],
            [['timezone'], 'validateTimezone'],
            [['timezone'], 'default', 'value' => 'UTC'],
            [['extra_vars'], 'string'],
            [['extra_vars'], 'validateJson'],
            [['enabled'], 'boolean'],
            [['enabled'], 'default', 'value' => true],
            [['job_template_id'], 'exist', 'targetClass' => JobTemplate::class, 'targetAttribute' => 'id'],
        ];
    }

    public function validateCronExpression(string $attribute): void
    {
        try {
            new CronExpression($this->$attribute);
        } catch (\InvalidArgumentException $e) {
            $this->addError($attribute, 'Invalid cron expression. Use standard 5-field format: min hour dom mon dow');
        }
    }

    public function validateTimezone(string $attribute): void
    {
        try {
            new \DateTimeZone($this->$attribute);
        } catch (\Exception $e) {
            $this->addError($attribute, 'Invalid timezone identifier.');
        }
    }

    public function validateJson(string $attribute): void
    {
        if (!empty($this->$attribute)) {
            json_decode((string)$this->$attribute);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->addError($attribute, 'Must be valid JSON.');
            }
        }
    }

    /**
     * Compute and store the next_run_at timestamp based on cron_expression + timezone.
     * Call this after saving to keep the value current.
     */
    public function computeNextRunAt(): void
    {
        try {
            $tz   = new \DateTimeZone($this->timezone ?: 'UTC');
            $cron = new CronExpression($this->cron_expression);
            $next = $cron->getNextRunDate('now', 0, false, $this->timezone ?: 'UTC');
            $this->next_run_at = $next->getTimestamp();
        } catch (\Exception $e) {
            $this->next_run_at = null;
        }
    }

    /**
     * Returns true if this schedule is due to run right now (next_run_at <= time()).
     */
    public function isDue(): bool
    {
        if (!$this->enabled || $this->next_run_at === null) {
            return false;
        }
        return $this->next_run_at <= time();
    }

    public function getJobTemplate(): \yii\db\ActiveQuery
    {
        return $this->hasOne(JobTemplate::class, ['id' => 'job_template_id']);
    }

    public function getCreator(): \yii\db\ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }
}
