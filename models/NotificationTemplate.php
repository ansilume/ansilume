<?php

declare(strict_types=1);

namespace app\models;

use app\components\NotificationConfigSecrets;
use app\services\notification\ChannelDefinition;
use app\services\notification\ChannelRegistry;
use app\services\notification\NotificationWorkflowFilter;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property string      $channel          a channel of the installation (ChannelRegistry): email, slack, teams,
 *                                         webhook, telegram, pagerduty, mattermost, or one of its own
 * @property string|null $config           JSON channel config, as its channel's definition says
 *                                         (ChannelDefinition); pages and the API show its secrets (secretKeys,
 *                                         secretMaps) only as SurveySecrets::PLACEHOLDER; its URLs (urlSchemes)
 *                                         are http or https URLs
 * @property string|null $subject_template
 * @property string|null $body_template
 * @property string|null $channel_state    JSON the server keeps for the channel; no rule assigns it
 * @property string      $events           Comma-separated event names
 * @property string|null $level            LEVEL_ALL or LEVEL_ESSENTIAL: which workflow events it delivers
 *                                         (isWithinLevel()); stored NOT NULL, default all
 * @property string|null $workflow_scope   WORKFLOW_SCOPE_ALL, or WORKFLOW_SCOPE_SELECTED: only the runs of the
 *                                         workflow templates of its workflow filter (isForWorkflowTemplate());
 *                                         stored NOT NULL, default all
 * @property int         $created_by       the user who created it: set by the create actions, never taken
 *                                         from a form or a request body
 * @property int         $delivers_as      the user whose access decides what it delivers: who last changed what
 *                                         or where it delivers (NotificationIdentity); set by the server, never
 *                                         taken from a form or a request body
 * @property int         $created_at
 * @property int         $updated_at
 *
 * @property User $creator
 * @property User|null $deliversAsUser
 * @property WorkflowTemplate[] $workflowTemplates the live workflow templates of its workflow filter
 * @property NotificationDelivery[] $deliveries
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
    public const CHANNEL_MATTERMOST = 'mattermost';

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
    public const EVENT_WORKFLOW_STEP_STARTED = 'workflow.step_started';
    public const EVENT_WORKFLOW_STEP_SUCCEEDED = 'workflow.step_succeeded';
    /** A run waits for a person: an approval or a resume (WorkflowRunEvents). */
    public const EVENT_WORKFLOW_WAITING = 'workflow.waiting';

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

    // -- Level and workflow filter ---------------------------------------------

    /** Every workflow event the template listens to. */
    public const LEVEL_ALL = 'all';

    /** Of the workflow events, only ESSENTIAL_WORKFLOW_EVENTS. */
    public const LEVEL_ESSENTIAL = 'essential';

    public const LEVELS = [self::LEVEL_ALL, self::LEVEL_ESSENTIAL];

    /** How the form and the page name each level. */
    public const LEVEL_LABELS = [
        self::LEVEL_ALL => 'Everything',
        self::LEVEL_ESSENTIAL => 'Only failures, waits and the end',
    ];

    /** The workflow events that level essential lets through: a failed step, a wait for a person and the run's end. */
    public const ESSENTIAL_WORKFLOW_EVENTS = [
        self::EVENT_WORKFLOW_STEP_FAILED,
        self::EVENT_WORKFLOW_WAITING,
        self::EVENT_WORKFLOW_SUCCEEDED,
        self::EVENT_WORKFLOW_FAILED,
        self::EVENT_WORKFLOW_CANCELED,
    ];

    /** The runs of every workflow template. */
    public const WORKFLOW_SCOPE_ALL = 'all';

    /** Only the runs of the workflow templates of the workflow filter: none while it holds none. */
    public const WORKFLOW_SCOPE_SELECTED = 'selected';

    public const WORKFLOW_SCOPES = [self::WORKFLOW_SCOPE_ALL, self::WORKFLOW_SCOPE_SELECTED];

    /** How the form and the page name each workflow scope. */
    public const WORKFLOW_SCOPE_LABELS = [
        self::WORKFLOW_SCOPE_ALL => 'All workflows',
        self::WORKFLOW_SCOPE_SELECTED => 'Only these workflows',
    ];

    /** The workflow filter: a workflow template per row (NotificationWorkflowFilter). */
    public const WORKFLOW_FILTER_TABLE = '{{%notification_template_workflow_template}}';

    // -- Channel URLs ------------------------------------------------------------

    /** A URL of the config that is no http or https URL; sprintf(): its key, such as webhook_url. */
    public const URL_SCHEME_REFUSED = '%s must be an http or https URL.';

    /** A URL of the config whose key allows https only, which it does not use; sprintf(): its key. */
    public const HTTPS_REQUIRED = '%s must be an https URL.';

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
            // Before the other config rules, which check the config as stored.
            [['config'], 'restoreSecretConfig'],
            [['config'], 'string', 'max' => 4096],
            [['config'], 'validateJson'],
            [['config'], 'validateChannelConfig'],
            [['subject_template'], 'string', 'max' => 512],
            [['events'], 'string', 'max' => 1024],
            [['events'], 'validateEvents'],
            [['level'], 'default', 'value' => self::LEVEL_ALL],
            [['level'], 'in', 'range' => self::LEVELS, 'strict' => true],
            [['workflow_scope'], 'default', 'value' => self::WORKFLOW_SCOPE_ALL],
            [['workflow_scope'], 'in', 'range' => self::WORKFLOW_SCOPES, 'strict' => true],
            // Unsafe: the create actions set both, NotificationIdentity changes
            // delivers_as; neither the form nor the API may. channel_state has
            // no rule: only the server writes it.
            [['!created_by', '!delivers_as'], 'integer'],
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
     * Puts the stored value back in place of every placeholder at a secret's
     * place of the submitted config, or reports why it cannot
     * (NotificationConfigSecrets::restore()).
     */
    public function restoreSecretConfig(string $attribute): void
    {
        $error = (new NotificationConfigSecrets($this))->restore();
        if ($error !== null) {
            $this->addError($attribute, $error);
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
     * The checks of the channel's definition (ChannelDefinition::refusals()):
     * its required keys hold a value, its URLs use a scheme their key allows
     * (a stored template with another scheme is refused too, until its URL
     * is changed), and its config rules. A channel the installation does not
     * have is refused by the channel's own rule.
     */
    public function validateChannelConfig(string $attribute): void
    {
        $definition = ChannelRegistry::definitionOf(is_string($this->channel) ? $this->channel : null);
        foreach ($definition?->refusals($this->getParsedConfig()) ?? [] as $error) {
            $this->addError($attribute, $error);
        }
    }

    /**
     * The channels of the installation by name, each with its label
     * (ChannelRegistry): the built-in ones in the form's order, then those
     * of its own.
     *
     * @return array<string, string>
     */
    public static function channelLabels(): array
    {
        return array_map(static fn (ChannelDefinition $definition): string => $definition->label, ChannelRegistry::installed());
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
                    self::EVENT_WORKFLOW_STEP_STARTED => 'Workflow step started',
                    self::EVENT_WORKFLOW_STEP_SUCCEEDED => 'Workflow step succeeded',
                    self::EVENT_WORKFLOW_STEP_FAILED => 'Workflow step failed',
                    self::EVENT_WORKFLOW_WAITING => 'Workflow waiting for someone',
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

            self::EVENT_APPROVAL_REQUESTED,
            self::EVENT_WORKFLOW_WAITING => 'warning',

            default => 'info',
        };
    }

    /**
     * Whether $event is a workflow event: one of the workflows group of
     * eventGroups().
     */
    public static function isWorkflowEvent(string $event): bool
    {
        return array_key_exists($event, self::eventGroups()['workflows']['events']);
    }

    /**
     * Whether the template's level lets $event through: every event at level
     * all (the default of a level not set); at level essential, every event
     * that is no workflow event, and of the workflow events only
     * ESSENTIAL_WORKFLOW_EVENTS. Another level counts as essential.
     */
    public function isWithinLevel(string $event): bool
    {
        return ($this->level ?? self::LEVEL_ALL) === self::LEVEL_ALL
            || !self::isWorkflowEvent($event)
            || in_array($event, self::ESSENTIAL_WORKFLOW_EVENTS, true);
    }

    /**
     * Whether the workflow filter lets the events of a run of workflow
     * template $workflowTemplateId through: every run with workflow scope
     * all (the default of a scope not set); with another scope, only the
     * runs of a workflow template of the filter, so a filter whose workflow
     * templates were all deleted lets none through. Events that belong to
     * no run do not ask.
     */
    public function isForWorkflowTemplate(int $workflowTemplateId): bool
    {
        return ($this->workflow_scope ?? self::WORKFLOW_SCOPE_ALL) === self::WORKFLOW_SCOPE_ALL
            || in_array($workflowTemplateId, $this->getWorkflowTemplateIds(), true);
    }

    /**
     * The IDs of the workflow templates of the workflow filter as stored,
     * ascending, deleted ones included; [] for a template not saved yet.
     *
     * @return list<int>
     */
    public function getWorkflowTemplateIds(): array
    {
        return NotificationWorkflowFilter::idsOf($this);
    }

    /**
     * Writes the workflow filter as the editor $editorId sets it to $ids
     * (NotificationWorkflowFilter::write()): of the stored workflow
     * templates, only those the editor may see are replaced; the others
     * stay. The template must be saved; call it before
     * NotificationIdentity::changesIdentity(), in the transaction of the
     * template's save.
     *
     * @param array<int|string, int> $ids workflow template IDs, read with SubmittedId
     * @return list<int> the IDs of $ids the editor may not see (unknown, deleted, or of another team), which
     *     are not added
     */
    public function setWorkflowTemplateIds(array $ids, int $editorId): array
    {
        return NotificationWorkflowFilter::write($this, $ids, $editorId);
    }

    /**
     * Whether the user the template delivers as is no active account: a
     * deactivated user, or nobody yet. Such a template delivers nothing.
     */
    public function isDeliveringAsInactive(): bool
    {
        return !User::find()->where(['id' => (int)$this->delivers_as, 'status' => User::STATUS_ACTIVE])->exists();
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

    /**
     * The config as the template page, the edit form and the API show it:
     * SurveySecrets::PLACEHOLDER for every secret (NotificationConfigSecrets).
     *
     * @return array<int|string, mixed>
     */
    public function getRedactedConfig(): array
    {
        return (new NotificationConfigSecrets($this))->redacted();
    }

    /**
     * getRedactedConfig() as JSON, as the edit form's script writes it; ''
     * for an empty config.
     */
    public function getRedactedConfigJson(): string
    {
        $config = $this->getRedactedConfig();

        return $config === [] ? '' : (string)json_encode($config, NotificationConfigSecrets::JSON_FLAGS);
    }

    public function getCreator(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }

    public function getDeliversAsUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'delivers_as']);
    }

    /**
     * The live workflow templates of the workflow filter; getWorkflowTemplateIds()
     * holds the deleted ones too.
     */
    public function getWorkflowTemplates(): ActiveQuery
    {
        return $this->hasMany(WorkflowTemplate::class, ['id' => 'workflow_template_id'])
            ->viaTable(self::WORKFLOW_FILTER_TABLE, ['notification_template_id' => 'id']);
    }

    public function getDeliveries(): ActiveQuery
    {
        return $this->hasMany(NotificationDelivery::class, ['notification_template_id' => 'id']);
    }
}
