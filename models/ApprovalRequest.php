<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property int         $job_id
 * @property int         $approval_rule_id
 * @property string      $status
 * @property int|null    $requested_at
 * @property int|null    $resolved_at
 * @property int|null    $expires_at
 *
 * @property Job $job
 * @property ApprovalRule $approvalRule
 * @property ApprovalDecision[] $decisions
 */
class ApprovalRequest extends ActiveRecord
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_TIMED_OUT = 'timed_out';

    public static function tableName(): string
    {
        return '{{%approval_request}}';
    }

    public function rules(): array
    {
        return [
            [['job_id', 'approval_rule_id'], 'required'],
            [['job_id', 'approval_rule_id'], 'integer'],
            [['status'], 'in', 'range' => [
                self::STATUS_PENDING,
                self::STATUS_APPROVED,
                self::STATUS_REJECTED,
                self::STATUS_TIMED_OUT,
            ]],
            [['requested_at', 'resolved_at', 'expires_at'], 'integer'],
        ];
    }

    /**
     * @return string[]
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_TIMED_OUT,
        ];
    }

    public function isResolved(): bool
    {
        return $this->status !== self::STATUS_PENDING;
    }

    public function approvalCount(): int
    {
        return (int)ApprovalDecision::find()
            ->where(['approval_request_id' => $this->id, 'decision' => 'approved'])
            ->count();
    }

    public function rejectionCount(): int
    {
        return (int)ApprovalDecision::find()
            ->where(['approval_request_id' => $this->id, 'decision' => 'rejected'])
            ->count();
    }

    public function getJob(): ActiveQuery
    {
        return $this->hasOne(Job::class, ['id' => 'job_id']);
    }

    public function getApprovalRule(): ActiveQuery
    {
        return $this->hasOne(ApprovalRule::class, ['id' => 'approval_rule_id']);
    }

    public function getDecisions(): ActiveQuery
    {
        return $this->hasMany(ApprovalDecision::class, ['approval_request_id' => 'id']);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_TIMED_OUT => 'Timed Out',
            default => $status,
        };
    }

    public static function statusCssClass(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'warning',
            self::STATUS_APPROVED => 'success',
            self::STATUS_REJECTED => 'danger',
            self::STATUS_TIMED_OUT => 'secondary',
            default => 'secondary',
        };
    }
}
