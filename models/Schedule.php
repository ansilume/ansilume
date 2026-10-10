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
    /** Longest cron expression and timezone the string rules accept. */
    public const CRON_FIELD_MAX_LENGTH = 64;

    /**
     * A negative step, such as the "/-5" of a minute field "0-30/-5". The
     * cron library accepts it, then loops until PHP runs out of memory, a
     * fatal error no catch block stops, or throws a ValueError when it
     * computes a run.
     */
    private const NEGATIVE_CRON_STEP = '~/-~';

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
            [['job_template_id'], 'integer'],
            // Set in code only: a form must not choose whom the schedule runs as.
            [['!created_by'], 'integer'],
            [['cron_expression'], 'string', 'max' => self::CRON_FIELD_MAX_LENGTH],
            [['cron_expression'], 'validateCronExpression'],
            [['timezone'], 'string', 'max' => self::CRON_FIELD_MAX_LENGTH],
            [['timezone'], 'validateTimezone'],
            [['timezone'], 'default', 'value' => 'UTC'],
            [['extra_vars'], 'string', 'max' => 65535],
            [['extra_vars'], 'validateJson'],
            [['enabled'], 'boolean'],
            [['enabled'], 'default', 'value' => true],
            [['job_template_id'], 'exist', 'targetClass' => JobTemplate::class, 'targetAttribute' => 'id'],
        ];
    }

    public function validateCronExpression(string $attribute): void
    {
        $value = $this->$attribute;
        if (!is_string($value) || !self::isParsableCron($value) || !self::constructs($value)) {
            $this->addError($attribute, 'Invalid cron expression. Use standard 5-field format: min hour dom mon dow');
        }
    }

    /**
     * Whether a cron expression may be handed to the cron library: no
     * longer than the rule allows, as parsing takes time quadratic in the
     * length, and without a negative step. Stored expressions are checked
     * too: older versions could save ones that fail it.
     */
    public static function isParsableCron(string $expression): bool
    {
        return self::isComputable($expression) && preg_match(self::NEGATIVE_CRON_STEP, $expression) === 0;
    }

    private static function constructs(string $expression): bool
    {
        try {
            new CronExpression($expression);
            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    public function validateTimezone(string $attribute): void
    {
        try {
            new \DateTimeZone($this->$attribute);
        } catch (\Exception | \ValueError $e) {
            // ValueError: a timezone with a NUL byte.
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
     * Set next_run_at from cron_expression and timezone; null when there is
     * no valid expression. The caller saves it.
     */
    public function computeNextRunAt(): void
    {
        // Forms compute this before validating: a missing, non-text,
        // over-long or otherwise unparsable cron expression or timezone (a
        // list from a form, a number or a boolean from a JSON body, text
        // longer than the string rules allow, a negative step) is left to the
        // rules. An empty timezone is UTC, as the default rule stores it.
        $timezone = $this->timezone ?: 'UTC';
        $expression = $this->cron_expression;
        if (!is_string($expression) || !self::isParsableCron($expression) || !self::isComputable($timezone)) {
            $this->next_run_at = null;
            return;
        }
        try {
            $cron = new CronExpression($expression);
            $next = $cron->getNextRunDate('now', 0, false, $timezone);
            $this->next_run_at = $next->getTimestamp();
        } catch (\Exception | \ValueError $e) {
            // ValueError: DateTimeZone refuses a timezone with a NUL byte.
            $this->next_run_at = null;
        }
    }

    /**
     * Whether a cron expression or timezone may be handed to the parsers:
     * non-empty text no longer than the string rules allow.
     */
    private static function isComputable(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= self::CRON_FIELD_MAX_LENGTH;
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
