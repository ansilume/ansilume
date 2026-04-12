<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property string      $channel          email, slack, teams, webhook, telegram, pagerduty
 * @property string|null $config           JSON channel config
 * @property string|null $subject_template
 * @property string|null $body_template
 * @property string      $events           Comma-separated event names
 * @property int         $created_by
 * @property int         $created_at
 * @property int         $updated_at
 *
 * @property User $creator
 */
class NotificationTemplate extends ActiveRecord
{
    // -- Channels --------------------------------------------------------------
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SLACK = 'slack';
    public const CHANNEL_TEAMS = 'teams';
    public const CHANNEL_WEBHOOK = 'webhook';
    public const CHANNEL_TELEGRAM = 'telegram';
    public const CHANNEL_PAGERDUTY = 'pagerduty';

    // -- Event catalog ---------------------------------------------------------
    // Jobs
    public const EVENT_JOB_LAUNCHED = 'job.launched';
    public const EVENT_JOB_SUCCEEDED = 'job.succeeded';
    public const EVENT_JOB_FAILED = 'job.failed';
    public const EVENT_JOB_CANCELED = 'job.canceled';

    // Workflows
    public const EVENT_WORKFLOW_LAUNCHED = 'workflow.launched';
    public const EVENT_WORKFLOW_SUCCEEDED = 'workflow.succeeded';
    public const EVENT_WORKFLOW_FAILED = 'workflow.failed';
    public const EVENT_WORKFLOW_CANCELED = 'workflow.canceled';
    public const EVENT_WORKFLOW_STEP_FAILED = 'workflow.step_failed';

    // Approvals
    public const EVENT_APPROVAL_REQUESTED = 'approval.requested';
    public const EVENT_APPROVAL_APPROVED = 'approval.approved';
    public const EVENT_APPROVAL_REJECTED = 'approval.rejected';

    // Schedules
    public const EVENT_SCHEDULE_FAILED_TO_LAUNCH = 'schedule.failed_to_launch';

    // Runners
    public const EVENT_RUNNER_OFFLINE = 'runner.offline';
    public const EVENT_RUNNER_RECOVERED = 'runner.recovered';

    // Projects
    public const EVENT_PROJECT_SYNC_FAILED = 'project.sync_failed';
    public const EVENT_PROJECT_SYNC_SUCCEEDED = 'project.sync_succeeded';

    // Webhooks
    public const EVENT_WEBHOOK_INVALID_TOKEN = 'webhook.invalid_token';

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
            [['description'], 'string', 'max' => 1000],
            [['body_template'], 'string', 'max' => 65535],
            [['channel'], 'in', 'range' => array_keys(self::channelLabels())],
            [['config'], 'string', 'max' => 4096],
            [['config'], 'validateJson'],
            [['config'], 'validateChannelConfig'],
            [['subject_template'], 'string', 'max' => 512],
            [['events'], 'string', 'max' => 1024],
            [['events'], 'validateEvents'],
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

    public function validateEvents(string $attribute): void
    {
        $list = $this->getEventList();
        if ($list === []) {
            $this->addError($attribute, 'Select at least one event.');
            return;
        }
        $known = array_keys(self::eventLabels());
        foreach ($list as $event) {
            if (!in_array($event, $known, true)) {
                $this->addError($attribute, 'Unknown event: ' . $event);
                return;
            }
        }
    }

    /**
     * Validate that channel-specific required config fields are present.
     */
    public function validateChannelConfig(string $attribute): void
    {
        $cfg = $this->getParsedConfig();
        $missing = $this->missingChannelConfigFields($cfg);
        foreach ($missing as $field) {
            $this->addError($attribute, "{$field} is required for {$this->channelLabel($this->channel)}.");
        }
    }

    /**
     * @param array<string, mixed> $cfg
     * @return string[]
     */
    private function missingChannelConfigFields(array $cfg): array
    {
        $required = match ($this->channel) {
            self::CHANNEL_TELEGRAM => ['bot_token', 'chat_id'],
            self::CHANNEL_SLACK => ['webhook_url'],
            self::CHANNEL_TEAMS => ['webhook_url'],
            self::CHANNEL_WEBHOOK => ['url'],
            self::CHANNEL_PAGERDUTY => ['routing_key'],
            self::CHANNEL_EMAIL => ['emails'],
            default => [],
        };
        $missing = [];
        foreach ($required as $field) {
            if (empty($cfg[$field])) {
                $missing[] = $field;
            }
        }
        return $missing;
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
            self::CHANNEL_TELEGRAM => 'Telegram',
            self::CHANNEL_PAGERDUTY => 'PagerDuty',
        ];
    }

    public static function channelLabel(string $channel): string
    {
        return self::channelLabels()[$channel] ?? $channel;
    }

    /**
     * Flat event => human label map (used for validation + simple lookups).
     *
     * @return array<string, string>
     */
    public static function eventLabels(): array
    {
        $flat = [];
        foreach (self::eventGroups() as $group) {
            foreach ($group['events'] as $event => $label) {
                $flat[$event] = $label;
            }
        }
        return $flat;
    }

    /**
     * Events grouped by domain for UI rendering.
     *
     * @return array<string, array{label: string, events: array<string, string>}>
     */
    public static function eventGroups(): array
    {
        return [
            'jobs' => [
                'label' => 'Jobs',
                'events' => [
                    self::EVENT_JOB_LAUNCHED => 'Job launched',
                    self::EVENT_JOB_SUCCEEDED => 'Job succeeded',
                    self::EVENT_JOB_FAILED => 'Job failed',
                    self::EVENT_JOB_CANCELED => 'Job canceled',
                ],
            ],
            'workflows' => [
                'label' => 'Workflows',
                'events' => [
                    self::EVENT_WORKFLOW_LAUNCHED => 'Workflow launched',
                    self::EVENT_WORKFLOW_SUCCEEDED => 'Workflow succeeded',
                    self::EVENT_WORKFLOW_FAILED => 'Workflow failed',
                    self::EVENT_WORKFLOW_CANCELED => 'Workflow canceled',
                    self::EVENT_WORKFLOW_STEP_FAILED => 'Workflow step failed',
                ],
            ],
            'approvals' => [
                'label' => 'Approvals',
                'events' => [
                    self::EVENT_APPROVAL_REQUESTED => 'Approval requested',
                    self::EVENT_APPROVAL_APPROVED => 'Approval approved',
                    self::EVENT_APPROVAL_REJECTED => 'Approval rejected',
                ],
            ],
            'schedules' => [
                'label' => 'Schedules',
                'events' => [
                    self::EVENT_SCHEDULE_FAILED_TO_LAUNCH => 'Schedule failed to launch',
                ],
            ],
            'runners' => [
                'label' => 'Runners',
                'events' => [
                    self::EVENT_RUNNER_OFFLINE => 'Runner went offline',
                    self::EVENT_RUNNER_RECOVERED => 'Runner recovered',
                ],
            ],
            'projects' => [
                'label' => 'Projects',
                'events' => [
                    self::EVENT_PROJECT_SYNC_FAILED => 'Project sync failed',
                    self::EVENT_PROJECT_SYNC_SUCCEEDED => 'Project sync succeeded',
                ],
            ],
            'webhooks' => [
                'label' => 'Webhooks',
                'events' => [
                    self::EVENT_WEBHOOK_INVALID_TOKEN => 'Webhook invalid token',
                ],
            ],
        ];
    }

    /**
     * Smart default: only failure events get pre-checked on new templates.
     *
     * @return string[]
     */
    public static function defaultFailureEvents(): array
    {
        return [
            self::EVENT_JOB_FAILED,
            self::EVENT_WORKFLOW_FAILED,
            self::EVENT_SCHEDULE_FAILED_TO_LAUNCH,
            self::EVENT_PROJECT_SYNC_FAILED,
            self::EVENT_RUNNER_OFFLINE,
        ];
    }

    /**
     * "Subscribe to all failures" preset for the form quick-button.
     *
     * @return string[]
     */
    public static function allFailureEvents(): array
    {
        return [
            self::EVENT_JOB_FAILED,
            self::EVENT_JOB_CANCELED,
            self::EVENT_WORKFLOW_FAILED,
            self::EVENT_WORKFLOW_CANCELED,
            self::EVENT_WORKFLOW_STEP_FAILED,
            self::EVENT_APPROVAL_REJECTED,
            self::EVENT_SCHEDULE_FAILED_TO_LAUNCH,
            self::EVENT_RUNNER_OFFLINE,
            self::EVENT_PROJECT_SYNC_FAILED,
            self::EVENT_WEBHOOK_INVALID_TOKEN,
        ];
    }

    /**
     * Severity hint used by PagerDuty (and any other severity-aware channel).
     */
    public static function eventSeverity(string $event): string
    {
        return match ($event) {
            self::EVENT_JOB_FAILED,
            self::EVENT_WORKFLOW_FAILED,
            self::EVENT_SCHEDULE_FAILED_TO_LAUNCH,
            self::EVENT_RUNNER_OFFLINE,
            self::EVENT_PROJECT_SYNC_FAILED => 'critical',

            self::EVENT_JOB_CANCELED,
            self::EVENT_WORKFLOW_CANCELED,
            self::EVENT_WORKFLOW_STEP_FAILED,
            self::EVENT_APPROVAL_REJECTED,
            self::EVENT_WEBHOOK_INVALID_TOKEN => 'error',

            self::EVENT_APPROVAL_REQUESTED => 'warning',

            default => 'info',
        };
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
        return array_values(array_filter(array_map('trim', explode(',', $this->events))));
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
}
