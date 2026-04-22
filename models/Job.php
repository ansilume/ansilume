<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property int|null    $job_template_id
 * @property string      $status
 * @property string|null $extra_vars       JSON
 * @property string|null $limit
 * @property int|null    $verbosity
 * @property int         $check_mode          1 = dry run (--check --diff)
 * @property string|null $runner_payload      JSON snapshot
 * @property string|null $execution_command   Ansible command built at claim/run time
 * @property int         $launched_by
 * @property int|null    $queued_at
 * @property int|null    $started_at
 * @property int|null    $finished_at
 * @property int|null    $last_progress_at  Bumped by runner log/status posts; used for stale-job reclaim.
 * @property int|null    $timeout_minutes
 * @property int|null    $exit_code
 * @property int         $attempt_count    Incremented each time JobReclaimService re-queues a stuck job.
 * @property int         $max_attempts     Hard limit on attempt_count; reclaim falls back to FAILED at this ceiling.
 * @property int|null    $pid
 * @property string|null $worker_id
 * @property int|null    $runner_id
 * @property int         $has_changes
 * @property int         $created_at
 * @property int         $updated_at
 *
 * @property JobTemplate|null $jobTemplate
 * @property User        $launcher
 * @property Runner|null          $runner
 * @property JobLog[]             $logs
 * @property JobHostSummary[]     $hostSummaries
 * @property JobArtifact[]        $artifacts
 */
class Job extends ActiveRecord
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_TIMED_OUT = 'timed_out';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_REJECTED = 'rejected';

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
            [['launched_by'], 'required'],
            [['job_template_id', 'launched_by'], 'integer'],
            [['exit_code'], 'integer', 'min' => -256, 'max' => 255],
            [['attempt_count', 'max_attempts'], 'integer', 'min' => 1],
            [['pid'], 'integer', 'min' => 0],
            [['timeout_minutes'], 'integer', 'min' => 0, 'max' => 1440],
            [['status'], 'in', 'range' => self::statuses()],
            [['extra_vars'], 'string', 'max' => 65535],
            [['runner_payload', 'execution_command'], 'string', 'max' => 1048576],
            [['extra_vars'], 'validateJson'],
            [['limit'], 'string', 'max' => 255],
            [['verbosity'], 'integer', 'min' => 0, 'max' => 5],
            [['check_mode'], 'boolean'],
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
     * @return string[]
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_QUEUED,
            self::STATUS_RUNNING,
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_CANCELED,
            self::STATUS_TIMED_OUT,
            self::STATUS_PENDING_APPROVAL,
            self::STATUS_REJECTED,
        ];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_CANCELED,
            self::STATUS_TIMED_OUT,
            self::STATUS_REJECTED,
        ], true);
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    /**
     * Whether this job reached a terminal negative outcome. Used by the
     * dashboard's "failed jobs" list and by the daily-failure stat so
     * timed-out runs show up alongside regular failures instead of being
     * invisible. The dedicated `timed_out` filter on /job/index still
     * matches the exact status so forensic filtering keeps working.
     */
    public function isTerminalFailure(): bool
    {
        return in_array($this->status, self::terminalFailureStatuses(), true);
    }

    /**
     * Status values that count as a terminal failure for aggregate views.
     * Keep in sync with the list above.
     *
     * @return string[]
     */
    public static function terminalFailureStatuses(): array
    {
        return [
            self::STATUS_FAILED,
            self::STATUS_TIMED_OUT,
        ];
    }

    public function isCancelable(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_QUEUED,
            self::STATUS_RUNNING,
            self::STATUS_PENDING_APPROVAL,
        ], true);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_QUEUED => 'Queued',
            self::STATUS_RUNNING => 'Running',
            self::STATUS_SUCCEEDED => 'Succeeded',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_CANCELED => 'Canceled',
            self::STATUS_TIMED_OUT => 'Timed Out',
            self::STATUS_PENDING_APPROVAL => 'Awaiting Approval',
            self::STATUS_REJECTED => 'Rejected',
            default => $status,
        };
    }

    public static function statusCssClass(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING, self::STATUS_QUEUED => 'secondary',
            self::STATUS_RUNNING => 'primary',
            self::STATUS_SUCCEEDED => 'success',
            self::STATUS_FAILED => 'danger',
            self::STATUS_CANCELED => 'warning',
            self::STATUS_TIMED_OUT => 'danger',
            self::STATUS_PENDING_APPROVAL => 'info',
            self::STATUS_REJECTED => 'danger',
            default => 'secondary',
        };
    }

    public function getJobTemplate(): \yii\db\ActiveQuery
    {
        // Include soft-deleted templates so job history remains intact.
        return $this->hasOne(JobTemplate::class, ['id' => 'job_template_id'])
            ->where([]); // clear the default soft-delete scope
    }

    public function getLauncher(): \yii\db\ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'launched_by']);
    }

    public function getRunner(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Runner::class, ['id' => 'runner_id']);
    }

    public function getHostSummaries(): \yii\db\ActiveQuery
    {
        return $this->hasMany(JobHostSummary::class, ['job_id' => 'id'])->orderBy('host');
    }

    public function getLogs(): \yii\db\ActiveQuery
    {
        return $this->hasMany(JobLog::class, ['job_id' => 'id'])->orderBy('sequence ASC');
    }

    public function getArtifacts(): \yii\db\ActiveQuery
    {
        return $this->hasMany(JobArtifact::class, ['job_id' => 'id'])->orderBy('display_name ASC');
    }
}
