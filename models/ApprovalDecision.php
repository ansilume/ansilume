<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property int         $approval_request_id
 * @property int         $user_id
 * @property string      $decision            approved or rejected
 * @property string|null $comment
 * @property int         $created_at
 *
 * @property ApprovalRequest $approvalRequest
 * @property User $user
 */
class ApprovalDecision extends ActiveRecord
{
    public const DECISION_APPROVED = 'approved';
    public const DECISION_REJECTED = 'rejected';

    public static function tableName(): string
    {
        return '{{%approval_decision}}';
    }

    public function rules(): array
    {
        return [
            [['approval_request_id', 'user_id', 'decision'], 'required'],
            [['approval_request_id', 'user_id'], 'integer'],
            [['decision'], 'in', 'range' => [self::DECISION_APPROVED, self::DECISION_REJECTED]],
            [['comment'], 'string', 'max' => 1000],
            [['created_at'], 'integer'],
        ];
    }

    public function getApprovalRequest(): ActiveQuery
    {
        return $this->hasOne(ApprovalRequest::class, ['id' => 'approval_request_id']);
    }

    public function getUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
}
