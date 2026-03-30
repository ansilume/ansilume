<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property string      $name
 * @property string      $url
 * @property string|null $secret    HMAC signing secret (stored in plain text — operator-managed)
 * @property string      $events    Comma-separated: job.success,job.failure,job.started
 * @property bool        $enabled
 * @property int         $created_by
 * @property int         $created_at
 * @property int         $updated_at
 *
 * @property User        $creator
 */
class Webhook extends ActiveRecord
{
    public const EVENT_JOB_STARTED = 'job.started';
    public const EVENT_JOB_SUCCESS = 'job.success';
    public const EVENT_JOB_FAILURE = 'job.failure';

    public static function tableName(): string
    {
        return '{{%webhook}}';
    }

    public function behaviors(): array
    {
        return [\yii\behaviors\TimestampBehavior::class];
    }

    /** @var string[]|null Temporary holder for the checkbox array from the form. */
    public ?array $eventsArray = null;

    public function rules(): array
    {
        return [
            [['name', 'url', 'events'], 'required'],
            [['name'], 'string', 'max' => 128],
            [['url'], 'url', 'validSchemes' => ['http', 'https']],
            [['url'], 'string', 'max' => 512],
            [['secret'], 'string', 'max' => 128],
            [['events'], 'string', 'max' => 255],
            [['events'], 'validateEvents'],
            [['enabled'], 'boolean'],
            [['enabled'], 'default', 'value' => true],
            [['eventsArray'], 'safe'],
        ];
    }

    /**
     * Convert the checkbox array (from the form) to the comma-separated events string.
     */
    public function afterValidate(): void
    {
        parent::afterValidate();
        if ($this->eventsArray !== null) {
            $this->events = implode(',', array_filter((array)$this->eventsArray));
        }
    }

    public function validateEvents(string $attribute): void
    {
        $valid = [self::EVENT_JOB_STARTED, self::EVENT_JOB_SUCCESS, self::EVENT_JOB_FAILURE];
        foreach ($this->getEventList() as $event) {
            if (!in_array($event, $valid, true)) {
                $this->addError($attribute, "Unknown event '{$event}'. Valid: " . implode(', ', $valid));
                return;
            }
        }
    }

    /**
     * Returns the events as an array.
     *
     * @return string[]
     */
    public function getEventList(): array
    {
        return array_filter(array_map('trim', explode(',', $this->events ?? '')));
    }

    /**
     * Returns true if this webhook should fire for the given event name.
     */
    public function listensTo(string $event): bool
    {
        return $this->enabled && in_array($event, $this->getEventList(), true);
    }

    /**
     * @return array<string, string>
     */
    public static function allEvents(): array
    {
        return [
            self::EVENT_JOB_STARTED => 'Job started',
            self::EVENT_JOB_SUCCESS => 'Job succeeded',
            self::EVENT_JOB_FAILURE => 'Job failed',
        ];
    }

    public function getCreator(): \yii\db\ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }
}
