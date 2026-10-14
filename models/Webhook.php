<?php

declare(strict_types=1);

namespace app\models;

use app\components\SurveySecrets;
use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property string      $name
 * @property string      $url
 * @property string|null $secret    HMAC signing secret (stored in plain text — operator-managed); the
 *                                 edit form shows SurveySecrets::PLACEHOLDER for it, the API never answers it
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

    /** The placeholder submitted for a secret where none is stored (a new webhook, or one without a secret). */
    public const SECRET_NOT_STORED = 'The secret has no stored value; enter the real value.';

    /** The hint of the edit form's secret field when a secret is stored. */
    public const SECRET_HINT = 'A secret is stored: leave the field as it is to keep it, clear it to send deliveries unsigned, or enter a new one.';

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
            // Before the length rule, which checks the secret as stored.
            [['secret'], 'restoreSecret'],
            [['secret'], 'string', 'max' => 128],
            [['events'], 'string', 'max' => 255],
            [['events'], 'validateEvents'],
            [['enabled'], 'boolean'],
            [['enabled'], 'default', 'value' => true],
            [['eventsArray'], 'safe'],
        ];
    }

    /**
     * Convert the checkbox array (from the form) to the comma-separated events string
     * before validation runs — the `events` attribute carries a `required` rule that
     * would otherwise fail on form submissions that only populate `eventsArray`.
     */
    public function beforeValidate(): bool
    {
        if ($this->eventsArray !== null) {
            $this->events = implode(',', array_filter((array)$this->eventsArray));
        }
        return parent::beforeValidate();
    }

    /**
     * The stored secret in place of SurveySecrets::PLACEHOLDER, which the
     * edit form shows for it, so a form or an API request that sends the
     * placeholder back keeps it; an error where no secret is stored.
     */
    public function restoreSecret(string $attribute): void
    {
        if ($this->secret !== SurveySecrets::PLACEHOLDER) {
            return;
        }
        $stored = $this->getOldAttribute('secret');
        if (!is_string($stored) || $stored === '') {
            $this->addError($attribute, self::SECRET_NOT_STORED);
            return;
        }
        $this->secret = $stored;
    }

    /**
     * What the edit form shows for the stored secret: SurveySecrets::PLACEHOLDER
     * when one is stored, else ''.
     */
    public function getShownSecret(): string
    {
        $stored = $this->getOldAttribute('secret');

        return is_string($stored) && $stored !== '' ? SurveySecrets::PLACEHOLDER : '';
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
