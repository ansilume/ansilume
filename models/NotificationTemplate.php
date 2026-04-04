<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property string      $channel          email, slack, teams, webhook
 * @property string|null $config           JSON channel config
 * @property string|null $subject_template
 * @property string|null $body_template
 * @property string      $events           Comma-separated event names
 * @property int         $created_by
 * @property int         $created_at
 * @property int         $updated_at
 *
 * @property User $creator
 * @property JobTemplate[] $jobTemplates
 */
class NotificationTemplate extends ActiveRecord
{
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SLACK = 'slack';
    public const CHANNEL_TEAMS = 'teams';
    public const CHANNEL_WEBHOOK = 'webhook';

    public const EVENT_JOB_STARTED = 'job.started';
    public const EVENT_JOB_SUCCEEDED = 'job.succeeded';
    public const EVENT_JOB_FAILED = 'job.failed';
    public const EVENT_JOB_TIMED_OUT = 'job.timed_out';

    public static function tableName(): string
    {
        return '{{%notification_template}}';
    }

    public function behaviors(): array
    {
        return [\yii\behaviors\TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['name', 'channel', 'events'], 'required'],
            [['name'], 'string', 'max' => 128],
            [['description', 'body_template'], 'string'],
            [['channel'], 'in', 'range' => [self::CHANNEL_EMAIL, self::CHANNEL_SLACK, self::CHANNEL_TEAMS, self::CHANNEL_WEBHOOK]],
            [['config'], 'string'],
            [['config'], 'validateJson'],
            [['subject_template'], 'string', 'max' => 512],
            [['events'], 'string', 'max' => 512],
            [['created_by'], 'integer'],
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

    /**
     * @return array<string, string>
     */
    public static function channelLabels(): array
    {
        return [
            self::CHANNEL_EMAIL => 'Email',
            self::CHANNEL_SLACK => 'Slack',
            self::CHANNEL_TEAMS => 'Microsoft Teams',
            self::CHANNEL_WEBHOOK => 'Webhook',
        ];
    }

    public static function channelLabel(string $channel): string
    {
        return self::channelLabels()[$channel] ?? $channel;
    }

    /**
     * @return array<string, string>
     */
    public static function eventLabels(): array
    {
        return [
            self::EVENT_JOB_STARTED => 'Job Started',
            self::EVENT_JOB_SUCCEEDED => 'Job Succeeded',
            self::EVENT_JOB_FAILED => 'Job Failed',
            self::EVENT_JOB_TIMED_OUT => 'Job Timed Out',
        ];
    }

    /**
     * Whether this template listens to a given event.
     */
    public function listensTo(string $event): bool
    {
        return in_array($event, $this->getEventList(), true);
    }

    /**
     * @return string[]
     */
    public function getEventList(): array
    {
        if (empty($this->events)) {
            return [];
        }
        return array_map('trim', explode(',', $this->events));
    }

    /**
     * @return array<string, mixed>
     */
    public function getParsedConfig(): array
    {
        if (empty($this->config)) {
            return [];
        }
        $decoded = json_decode($this->config, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function getCreator(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }

    public function getJobTemplates(): ActiveQuery
    {
        return $this->hasMany(JobTemplate::class, ['id' => 'job_template_id'])
            ->viaTable('{{%job_template_notification}}', ['notification_template_id' => 'id']);
    }
}
