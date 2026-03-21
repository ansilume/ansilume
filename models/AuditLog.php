<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * Immutable audit record. Never update — only insert.
 *
 * @property int         $id
 * @property int|null    $user_id
 * @property string      $action
 * @property string|null $object_type
 * @property int|null    $object_id
 * @property string|null $metadata    JSON context, no raw secrets
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property int         $created_at
 *
 * @property User|null   $user
 */
class AuditLog extends ActiveRecord
{
    // Common action constants
    public const ACTION_USER_LOGIN          = 'user.login';
    public const ACTION_USER_LOGOUT         = 'user.logout';
    public const ACTION_USER_LOGIN_FAILED   = 'user.login.failed';
    public const ACTION_JOB_LAUNCHED        = 'job.launched';
    public const ACTION_JOB_CANCELED        = 'job.canceled';
    public const ACTION_JOB_STARTED         = 'job.started';
    public const ACTION_JOB_FINISHED        = 'job.finished';
    public const ACTION_CREDENTIAL_CREATED  = 'credential.created';
    public const ACTION_CREDENTIAL_UPDATED  = 'credential.updated';
    public const ACTION_CREDENTIAL_DELETED  = 'credential.deleted';

    public static function tableName(): string
    {
        return '{{%audit_log}}';
    }

    /**
     * Audit logs are append-only; disable update.
     */
    public function update($runValidation = true, $attributeNames = null): never
    {
        throw new \LogicException('AuditLog records are immutable.');
    }

    public function rules(): array
    {
        return [
            [['action'], 'required'],
            [['action'], 'string', 'max' => 128],
            [['object_type'], 'string', 'max' => 64],
            [['ip_address'], 'string', 'max' => 45],
            [['user_agent'], 'string', 'max' => 512],
            [['metadata'], 'string'],
            [['user_id', 'object_id'], 'integer'],
        ];
    }

    public function getUser(): \yii\db\ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
}
