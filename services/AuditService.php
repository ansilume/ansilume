<?php

declare(strict_types=1);

namespace app\services;

use app\models\AuditLog;
use app\services\audit\AuditTargetInterface;
use yii\base\Component;

/**
 * Dispatches immutable audit log entries to one or more targets.
 *
 * By default, entries are written to the database. Additional targets
 * (syslog, Splunk HEC, etc.) can be configured via the $targets property.
 *
 * Never pass raw secrets in $context — callers are responsible for redaction.
 */
class AuditService extends Component
{
    // Re-export constants for convenience — kept in sync with AuditLog
    public const ACTION_USER_LOGIN = AuditLog::ACTION_USER_LOGIN;
    public const ACTION_USER_LOGOUT = AuditLog::ACTION_USER_LOGOUT;
    public const ACTION_USER_LOGIN_FAILED = AuditLog::ACTION_USER_LOGIN_FAILED;
    public const ACTION_USER_CREATED = AuditLog::ACTION_USER_CREATED;
    public const ACTION_USER_UPDATED = AuditLog::ACTION_USER_UPDATED;
    public const ACTION_USER_DELETED = AuditLog::ACTION_USER_DELETED;
    public const ACTION_USER_STATUS_CHANGED = AuditLog::ACTION_USER_STATUS_CHANGED;
    public const ACTION_PROJECT_CREATED = AuditLog::ACTION_PROJECT_CREATED;
    public const ACTION_PROJECT_UPDATED = AuditLog::ACTION_PROJECT_UPDATED;
    public const ACTION_PROJECT_DELETED = AuditLog::ACTION_PROJECT_DELETED;
    public const ACTION_PROJECT_SYNCED = AuditLog::ACTION_PROJECT_SYNCED;
    public const ACTION_PROJECT_LINTED = AuditLog::ACTION_PROJECT_LINTED;
    public const ACTION_INVENTORY_CREATED = AuditLog::ACTION_INVENTORY_CREATED;
    public const ACTION_INVENTORY_UPDATED = AuditLog::ACTION_INVENTORY_UPDATED;
    public const ACTION_INVENTORY_DELETED = AuditLog::ACTION_INVENTORY_DELETED;
    public const ACTION_CREDENTIAL_CREATED = AuditLog::ACTION_CREDENTIAL_CREATED;
    public const ACTION_CREDENTIAL_UPDATED = AuditLog::ACTION_CREDENTIAL_UPDATED;
    public const ACTION_CREDENTIAL_DELETED = AuditLog::ACTION_CREDENTIAL_DELETED;
    public const ACTION_TEMPLATE_CREATED = AuditLog::ACTION_TEMPLATE_CREATED;
    public const ACTION_TEMPLATE_UPDATED = AuditLog::ACTION_TEMPLATE_UPDATED;
    public const ACTION_TEMPLATE_DELETED = AuditLog::ACTION_TEMPLATE_DELETED;
    public const ACTION_TEMPLATE_TRIGGER_TOKEN_GENERATED = AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_GENERATED;
    public const ACTION_TEMPLATE_TRIGGER_TOKEN_REVOKED = AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_REVOKED;
    public const ACTION_JOB_LAUNCHED = AuditLog::ACTION_JOB_LAUNCHED;
    public const ACTION_JOB_CANCELED = AuditLog::ACTION_JOB_CANCELED;
    public const ACTION_JOB_STARTED = AuditLog::ACTION_JOB_STARTED;
    public const ACTION_JOB_FINISHED = AuditLog::ACTION_JOB_FINISHED;
    public const ACTION_TEAM_CREATED = AuditLog::ACTION_TEAM_CREATED;
    public const ACTION_TEAM_UPDATED = AuditLog::ACTION_TEAM_UPDATED;
    public const ACTION_TEAM_DELETED = AuditLog::ACTION_TEAM_DELETED;
    public const ACTION_TEAM_MEMBER_ADDED = AuditLog::ACTION_TEAM_MEMBER_ADDED;
    public const ACTION_TEAM_MEMBER_REMOVED = AuditLog::ACTION_TEAM_MEMBER_REMOVED;
    public const ACTION_TEAM_PROJECT_ADDED = AuditLog::ACTION_TEAM_PROJECT_ADDED;
    public const ACTION_TEAM_PROJECT_REMOVED = AuditLog::ACTION_TEAM_PROJECT_REMOVED;
    public const ACTION_SCHEDULE_CREATED = AuditLog::ACTION_SCHEDULE_CREATED;
    public const ACTION_SCHEDULE_UPDATED = AuditLog::ACTION_SCHEDULE_UPDATED;
    public const ACTION_SCHEDULE_DELETED = AuditLog::ACTION_SCHEDULE_DELETED;
    public const ACTION_SCHEDULE_TOGGLED = AuditLog::ACTION_SCHEDULE_TOGGLED;
    public const ACTION_RUNNER_GROUP_CREATED = AuditLog::ACTION_RUNNER_GROUP_CREATED;
    public const ACTION_RUNNER_GROUP_UPDATED = AuditLog::ACTION_RUNNER_GROUP_UPDATED;
    public const ACTION_RUNNER_GROUP_DELETED = AuditLog::ACTION_RUNNER_GROUP_DELETED;
    public const ACTION_RUNNER_CREATED = AuditLog::ACTION_RUNNER_CREATED;
    public const ACTION_RUNNER_DELETED = AuditLog::ACTION_RUNNER_DELETED;
    public const ACTION_RUNNER_TOKEN_REGENERATED = AuditLog::ACTION_RUNNER_TOKEN_REGENERATED;
    public const ACTION_WEBHOOK_CREATED = AuditLog::ACTION_WEBHOOK_CREATED;
    public const ACTION_WEBHOOK_UPDATED = AuditLog::ACTION_WEBHOOK_UPDATED;
    public const ACTION_WEBHOOK_DELETED = AuditLog::ACTION_WEBHOOK_DELETED;
    public const ACTION_NOTIFICATION_TEMPLATE_CREATED = AuditLog::ACTION_NOTIFICATION_TEMPLATE_CREATED;
    public const ACTION_NOTIFICATION_TEMPLATE_UPDATED = AuditLog::ACTION_NOTIFICATION_TEMPLATE_UPDATED;
    public const ACTION_NOTIFICATION_TEMPLATE_DELETED = AuditLog::ACTION_NOTIFICATION_TEMPLATE_DELETED;
    public const ACTION_NOTIFICATION_DISPATCHED = AuditLog::ACTION_NOTIFICATION_DISPATCHED;
    public const ACTION_NOTIFICATION_FAILED = AuditLog::ACTION_NOTIFICATION_FAILED;
    public const ACTION_APPROVAL_RULE_CREATED = AuditLog::ACTION_APPROVAL_RULE_CREATED;
    public const ACTION_APPROVAL_RULE_UPDATED = AuditLog::ACTION_APPROVAL_RULE_UPDATED;
    public const ACTION_APPROVAL_RULE_DELETED = AuditLog::ACTION_APPROVAL_RULE_DELETED;
    public const ACTION_APPROVAL_REQUESTED = AuditLog::ACTION_APPROVAL_REQUESTED;
    public const ACTION_APPROVAL_DECIDED = AuditLog::ACTION_APPROVAL_DECIDED;
    public const ACTION_APPROVAL_TIMED_OUT = AuditLog::ACTION_APPROVAL_TIMED_OUT;
    public const ACTION_WORKFLOW_TEMPLATE_CREATED = AuditLog::ACTION_WORKFLOW_TEMPLATE_CREATED;
    public const ACTION_WORKFLOW_TEMPLATE_UPDATED = AuditLog::ACTION_WORKFLOW_TEMPLATE_UPDATED;
    public const ACTION_WORKFLOW_TEMPLATE_DELETED = AuditLog::ACTION_WORKFLOW_TEMPLATE_DELETED;
    public const ACTION_WORKFLOW_LAUNCHED = AuditLog::ACTION_WORKFLOW_LAUNCHED;
    public const ACTION_WORKFLOW_COMPLETED = AuditLog::ACTION_WORKFLOW_COMPLETED;
    public const ACTION_WORKFLOW_FAILED = AuditLog::ACTION_WORKFLOW_FAILED;
    public const ACTION_WORKFLOW_CANCELED = AuditLog::ACTION_WORKFLOW_CANCELED;
    public const ACTION_WORKFLOW_STEP_STARTED = AuditLog::ACTION_WORKFLOW_STEP_STARTED;
    public const ACTION_WORKFLOW_STEP_COMPLETED = AuditLog::ACTION_WORKFLOW_STEP_COMPLETED;
    public const ACTION_WORKFLOW_STEP_RESUMED = AuditLog::ACTION_WORKFLOW_STEP_RESUMED;
    public const ACTION_API_TOKEN_CREATED = AuditLog::ACTION_API_TOKEN_CREATED;
    public const ACTION_API_TOKEN_DELETED = AuditLog::ACTION_API_TOKEN_DELETED;
    public const ACTION_PASSWORD_CHANGED = AuditLog::ACTION_PASSWORD_CHANGED;
    public const ACTION_PASSWORD_RESET_REQUESTED = AuditLog::ACTION_PASSWORD_RESET_REQUESTED;
    public const ACTION_PASSWORD_RESET_COMPLETED = AuditLog::ACTION_PASSWORD_RESET_COMPLETED;
    public const ACTION_MFA_ENABLED = AuditLog::ACTION_MFA_ENABLED;
    public const ACTION_MFA_DISABLED = AuditLog::ACTION_MFA_DISABLED;
    public const ACTION_ROLE_CREATED = AuditLog::ACTION_ROLE_CREATED;
    public const ACTION_ROLE_UPDATED = AuditLog::ACTION_ROLE_UPDATED;
    public const ACTION_ROLE_DELETED = AuditLog::ACTION_ROLE_DELETED;

    /**
     * @var AuditTargetInterface[] Dispatch targets. Populated in init() from
     *                             Yii component configuration.
     */
    public array $targets = [];

    /**
     * @param array<string, mixed> $context Key-value metadata; must not contain raw secrets.
     */
    public function log(
        string $action,
        ?string $objectType = null,
        ?int $objectId = null,
        ?int $userId = null,
        array $context = []
    ): void {
        $request = \Yii::$app->has('request') ? \Yii::$app->request : null;
        $isWebRequest = $request instanceof \yii\web\Request;

        $entry = [
            'action' => $action,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'user_id' => $userId ?? $this->resolveUserId(),
            'metadata' => !empty($context) ? (json_encode($context, JSON_UNESCAPED_UNICODE) ?: null) : null,
            'ip_address' => $isWebRequest ? $request->getUserIP() : null,
            'user_agent' => $isWebRequest ? $request->getUserAgent() : null,
            'created_at' => time(),
        ];

        foreach ($this->targets as $target) {
            try {
                $target->send($entry);
            } catch (\Throwable $e) {
                \Yii::error(
                    'AuditService: target ' . get_class($target) . ' failed: ' . $e->getMessage(),
                    __CLASS__
                );
            }
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
