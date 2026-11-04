<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * One delivery of one event to one notification template: a row of the
 * outbox that the notification worker delivers, with retries. The server
 * writes every row; no form or request body does.
 *
 * @property int         $id
 * @property int         $notification_template_id
 * @property string      $event
 * @property string      $kind                KIND_MESSAGE, KIND_STATUS (the status post of a run) or KIND_REPLY
 * @property int|null    $workflow_job_id     the run whose thread the delivery belongs to
 * @property int|null    $job_id
 * @property int|null    $approval_request_id
 * @property int|null    $project_id          the project of a project event (m000093)
 * @property int|null    $job_template_id     the job template of a schedule event (m000093)
 * @property string      $variables           JSON object: the event's variables when it happened, flat strings
 *                                            without secrets (getVariableMap(), setVariableMap())
 * @property string      $status              one of STATUSES
 * @property int         $attempts
 * @property int         $next_attempt_at
 * @property int|null    $claimed_at
 * @property string|null $last_error          from message constants, never a response or a URL
 * @property string|null $remote_id           the post the delivery created, or the status post it edited
 * @property int         $created_at
 * @property int         $updated_at
 * @property int|null    $finished_at
 *
 * @property NotificationTemplate $template
 * @property WorkflowJob|null $workflowJob
 */
class NotificationDelivery extends ActiveRecord
{
    /** Waits for its next attempt (next_attempt_at). */
    public const STATUS_PENDING = 'pending';

    /** A worker claimed it (claimed_at) and sends it. */
    public const STATUS_SENDING = 'sending';

    /** The channel took it. */
    public const STATUS_DELIVERED = 'delivered';

    /** The channel refused it for good, or it ran out of attempts (last_error). */
    public const STATUS_FAILED = 'failed';

    /** Not sent: the user the template delivers as may no longer see the event. */
    public const STATUS_SKIPPED = 'skipped';

    /** Not sent: a later status of the same thread replaced it. */
    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SENDING,
        self::STATUS_DELIVERED,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
        self::STATUS_SUPERSEDED,
    ];

    /** The statuses of a delivery that is done with, sent or not. */
    public const FINISHED_STATUSES = [
        self::STATUS_DELIVERED,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
        self::STATUS_SUPERSEDED,
    ];

    /** A message of its own: the event rendered with the template's subject and body. */
    public const KIND_MESSAGE = 'message';

    /** The status post of a workflow run, created once and edited as the run moves on. */
    public const KIND_STATUS = 'status';

    /** A reply in the thread of a run's status post. */
    public const KIND_REPLY = 'reply';

    public const KINDS = [self::KIND_MESSAGE, self::KIND_STATUS, self::KIND_REPLY];

    /**
     * The event of a status delivery that refreshes the status post of a
     * run whose state changed without an event of its own, such as an
     * approval of one of its steps that a timeout settled
     * (NotificationDispatcher::refreshStatus()). It is no event of the
     * catalog, so no template listens to it; NotificationVisibility judges
     * it as a workflow event of its run.
     */
    public const EVENT_STATUS_REFRESH = 'workflow.status_refresh';

    /**
     * The columns that name the objects a delivery's event is about, its
     * refs (getRefs(), setRefs()): the keys of the refs
     * NotificationVisibility::allows() takes. workflow_job_id is also the
     * thread the delivery belongs to.
     */
    public const REF_ATTRIBUTES = ['workflow_job_id', 'job_id', 'approval_request_id', 'project_id', 'job_template_id'];

    /** How the variables are written as JSON. */
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public static function tableName(): string
    {
        return '{{%notification_delivery}}';
    }

    public function behaviors(): array
    {
        return [\yii\behaviors\TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['notification_template_id', 'event', 'variables', 'next_attempt_at'], 'required'],
            [['notification_template_id', 'workflow_job_id', 'job_id', 'approval_request_id', 'project_id', 'job_template_id'], 'integer'],
            [['attempts', 'next_attempt_at', 'claimed_at', 'finished_at'], 'integer'],
            [['event'], 'string', 'max' => 64],
            [['variables'], 'string'],
            [['kind'], 'default', 'value' => self::KIND_MESSAGE],
            [['kind'], 'in', 'range' => self::KINDS, 'strict' => true],
            [['status'], 'default', 'value' => self::STATUS_PENDING],
            [['status'], 'in', 'range' => self::STATUSES, 'strict' => true],
            [['last_error'], 'string', 'max' => 500],
            [['remote_id'], 'string', 'max' => 64],
        ];
    }

    /**
     * The variables as stored: the string values of their JSON object; []
     * for a text that is no JSON object.
     *
     * @return array<string, string>
     */
    public function getVariableMap(): array
    {
        $decoded = json_decode((string)$this->variables, true);
        $variables = [];
        foreach (is_array($decoded) ? $decoded : [] as $name => $value) {
            if (is_string($value)) {
                $variables[(string)$name] = $value;
            }
        }

        return $variables;
    }

    /**
     * Stores $variables, a flat map of strings, as the delivery's variables.
     *
     * @param array<string, string> $variables
     */
    public function setVariableMap(array $variables): void
    {
        $this->variables = (string)json_encode((object)$variables, self::JSON_FLAGS);
    }

    /**
     * The objects the delivery's event is about, by the keys of
     * REF_ATTRIBUTES, in their order: only the refs that are set, as whole
     * numbers.
     *
     * @return array{workflow_job_id?: int, job_id?: int, approval_request_id?: int, project_id?: int, job_template_id?: int}
     */
    public function getRefs(): array
    {
        $refs = [];
        foreach (self::REF_ATTRIBUTES as $attribute) {
            $value = $this->getAttribute($attribute);
            if ($value !== null) {
                $refs[$attribute] = (int)$value;
            }
        }

        /** @var array{workflow_job_id?: int, job_id?: int, approval_request_id?: int, project_id?: int, job_template_id?: int} $refs */
        return $refs;
    }

    /**
     * Sets every ref (REF_ATTRIBUTES) as $refs gives it; a ref not given is
     * cleared, a key of $refs that is no ref is ignored.
     *
     * @param array{workflow_job_id?: int, job_id?: int, approval_request_id?: int, project_id?: int, job_template_id?: int} $refs
     */
    public function setRefs(array $refs): void
    {
        foreach (self::REF_ATTRIBUTES as $attribute) {
            $this->setAttribute($attribute, $refs[$attribute] ?? null);
        }
    }

    public function getTemplate(): ActiveQuery
    {
        return $this->hasOne(NotificationTemplate::class, ['id' => 'notification_template_id']);
    }

    public function getWorkflowJob(): ActiveQuery
    {
        return $this->hasOne(WorkflowJob::class, ['id' => 'workflow_job_id']);
    }
}
