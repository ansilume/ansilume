<?php

declare(strict_types=1);

namespace app\services;

use app\models\AuditLog;
use yii\base\Component;

/**
 * Writes immutable audit log entries.
 * Never pass raw secrets in $context — callers are responsible for redaction.
 */
class AuditService extends Component
{
    // Re-export constants for convenience
    public const ACTION_USER_LOGIN        = AuditLog::ACTION_USER_LOGIN;
    public const ACTION_USER_LOGOUT       = AuditLog::ACTION_USER_LOGOUT;
    public const ACTION_USER_LOGIN_FAILED = AuditLog::ACTION_USER_LOGIN_FAILED;
    public const ACTION_JOB_LAUNCHED      = AuditLog::ACTION_JOB_LAUNCHED;
    public const ACTION_JOB_CANCELED      = AuditLog::ACTION_JOB_CANCELED;
    public const ACTION_JOB_STARTED       = AuditLog::ACTION_JOB_STARTED;
    public const ACTION_JOB_FINISHED      = AuditLog::ACTION_JOB_FINISHED;
    public const ACTION_CREDENTIAL_CREATED = AuditLog::ACTION_CREDENTIAL_CREATED;
    public const ACTION_CREDENTIAL_UPDATED = AuditLog::ACTION_CREDENTIAL_UPDATED;
    public const ACTION_CREDENTIAL_DELETED = AuditLog::ACTION_CREDENTIAL_DELETED;

    public function log(
        string  $action,
        ?string $objectType = null,
        ?int    $objectId   = null,
        ?int    $userId     = null,
        array   $context    = []
    ): void {
        $request = \Yii::$app->has('request') ? \Yii::$app->request : null;

        $entry = new AuditLog();
        $entry->action      = $action;
        $entry->object_type = $objectType;
        $entry->object_id   = $objectId;
        $entry->user_id     = $userId ?? $this->resolveUserId();
        $entry->metadata    = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : null;
        $entry->ip_address  = $request?->getUserIP();
        $entry->user_agent  = $request?->getUserAgent();
        $entry->created_at  = time();

        if (!$entry->save()) {
            \Yii::error('AuditService: failed to save log entry: ' . json_encode($entry->errors), __CLASS__);
        }
    }

    private function resolveUserId(): ?int
    {
        if (\Yii::$app->has('user') && !\Yii::$app->user->isGuest) {
            return (int)\Yii::$app->user->id;
        }
        return null;
    }
}
