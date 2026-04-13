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
    // -- User actions ----------------------------------------------------------
    public const ACTION_USER_LOGIN = 'user.login';
    public const ACTION_USER_LOGOUT = 'user.logout';
    public const ACTION_USER_LOGIN_FAILED = 'user.login.failed';
    public const ACTION_USER_CREATED = 'user.created';
    public const ACTION_USER_UPDATED = 'user.updated';
    public const ACTION_USER_DELETED = 'user.deleted';
    public const ACTION_USER_STATUS_CHANGED = 'user.status_changed';

    // -- Project actions -------------------------------------------------------
    public const ACTION_PROJECT_CREATED = 'project.created';
    public const ACTION_PROJECT_UPDATED = 'project.updated';
    public const ACTION_PROJECT_DELETED = 'project.deleted';
    public const ACTION_PROJECT_SYNCED = 'project.synced';
    public const ACTION_PROJECT_LINTED = 'project.linted';

    // -- Inventory actions -----------------------------------------------------
    public const ACTION_INVENTORY_CREATED = 'inventory.created';
    public const ACTION_INVENTORY_UPDATED = 'inventory.updated';
    public const ACTION_INVENTORY_DELETED = 'inventory.deleted';

    // -- Credential actions ----------------------------------------------------
    public const ACTION_CREDENTIAL_CREATED = 'credential.created';
    public const ACTION_CREDENTIAL_UPDATED = 'credential.updated';
    public const ACTION_CREDENTIAL_DELETED = 'credential.deleted';

    // -- Job template actions --------------------------------------------------
    public const ACTION_TEMPLATE_CREATED = 'job-template.created';
    public const ACTION_TEMPLATE_UPDATED = 'job-template.updated';
    public const ACTION_TEMPLATE_DELETED = 'job-template.deleted';
    public const ACTION_TEMPLATE_TRIGGER_TOKEN_GENERATED = 'job-template.trigger-token.generated';
    public const ACTION_TEMPLATE_TRIGGER_TOKEN_REVOKED = 'job-template.trigger-token.revoked';

    // -- Job actions -----------------------------------------------------------
    public const ACTION_JOB_LAUNCHED = 'job.launched';
    public const ACTION_JOB_CANCELED = 'job.canceled';
    public const ACTION_JOB_STARTED = 'job.started';
    public const ACTION_JOB_FINISHED = 'job.finished';

    // -- Team actions ----------------------------------------------------------
    public const ACTION_TEAM_CREATED = 'team.created';
    public const ACTION_TEAM_UPDATED = 'team.updated';
    public const ACTION_TEAM_DELETED = 'team.deleted';
    public const ACTION_TEAM_MEMBER_ADDED = 'team.member.added';
    public const ACTION_TEAM_MEMBER_REMOVED = 'team.member.removed';
    public const ACTION_TEAM_PROJECT_ADDED = 'team.project.added';
    public const ACTION_TEAM_PROJECT_REMOVED = 'team.project.removed';

    // -- Schedule actions ------------------------------------------------------
    public const ACTION_SCHEDULE_CREATED = 'schedule.created';
    public const ACTION_SCHEDULE_UPDATED = 'schedule.updated';
    public const ACTION_SCHEDULE_DELETED = 'schedule.deleted';
    public const ACTION_SCHEDULE_TOGGLED = 'schedule.toggled';

    // -- Runner group actions --------------------------------------------------
    public const ACTION_RUNNER_GROUP_CREATED = 'runner-group.created';
    public const ACTION_RUNNER_GROUP_UPDATED = 'runner-group.updated';
    public const ACTION_RUNNER_GROUP_DELETED = 'runner-group.deleted';

    // -- Runner actions --------------------------------------------------------
    public const ACTION_RUNNER_CREATED = 'runner.created';
    public const ACTION_RUNNER_UPDATED = 'runner.updated';
    public const ACTION_RUNNER_DELETED = 'runner.deleted';
    public const ACTION_RUNNER_TOKEN_REGENERATED = 'runner.token.regenerated';

    // -- Webhook actions -------------------------------------------------------
    public const ACTION_WEBHOOK_CREATED = 'webhook.created';
    public const ACTION_WEBHOOK_UPDATED = 'webhook.updated';
    public const ACTION_WEBHOOK_DELETED = 'webhook.deleted';

    // -- Approval actions ------------------------------------------------------
    public const ACTION_APPROVAL_RULE_CREATED = 'approval-rule.created';
    public const ACTION_APPROVAL_RULE_UPDATED = 'approval-rule.updated';
    public const ACTION_APPROVAL_RULE_DELETED = 'approval-rule.deleted';
    public const ACTION_APPROVAL_REQUESTED = 'approval.requested';
    public const ACTION_APPROVAL_DECIDED = 'approval.decided';
    public const ACTION_APPROVAL_TIMED_OUT = 'approval.timed_out';

    // -- Workflow actions ------------------------------------------------------
    public const ACTION_WORKFLOW_TEMPLATE_CREATED = 'workflow-template.created';
    public const ACTION_WORKFLOW_TEMPLATE_UPDATED = 'workflow-template.updated';
    public const ACTION_WORKFLOW_TEMPLATE_DELETED = 'workflow-template.deleted';
    public const ACTION_WORKFLOW_LAUNCHED = 'workflow.launched';
    public const ACTION_WORKFLOW_COMPLETED = 'workflow.completed';
    public const ACTION_WORKFLOW_FAILED = 'workflow.failed';
    public const ACTION_WORKFLOW_CANCELED = 'workflow.canceled';
    public const ACTION_WORKFLOW_STEP_STARTED = 'workflow.step.started';
    public const ACTION_WORKFLOW_STEP_COMPLETED = 'workflow.step.completed';
    public const ACTION_WORKFLOW_STEP_RESUMED = 'workflow.step.resumed';

    // -- Notification template actions -----------------------------------------
    public const ACTION_NOTIFICATION_TEMPLATE_CREATED = 'notification-template.created';
    public const ACTION_NOTIFICATION_TEMPLATE_UPDATED = 'notification-template.updated';
    public const ACTION_NOTIFICATION_TEMPLATE_DELETED = 'notification-template.deleted';

    // -- Notification dispatch (per-channel-delivery audit) --------------------
    public const ACTION_NOTIFICATION_DISPATCHED = 'notification.dispatched';
    public const ACTION_NOTIFICATION_FAILED = 'notification.failed';

    // -- API token actions -----------------------------------------------------
    public const ACTION_API_TOKEN_CREATED = 'api-token.created';
    public const ACTION_API_TOKEN_DELETED = 'api-token.deleted';

    // -- Password actions -------------------------------------------------------
    public const ACTION_PASSWORD_CHANGED = 'password.changed';
    public const ACTION_PASSWORD_RESET_REQUESTED = 'password-reset.requested';
    public const ACTION_PASSWORD_RESET_COMPLETED = 'password-reset.completed';

    // -- MFA actions -----------------------------------------------------------
    public const ACTION_MFA_ENABLED = 'mfa.enabled';
    public const ACTION_MFA_DISABLED = 'mfa.disabled';

    // -- Role (RBAC) actions ---------------------------------------------------
    public const ACTION_ROLE_CREATED = 'role.created';
    public const ACTION_ROLE_UPDATED = 'role.updated';
    public const ACTION_ROLE_DELETED = 'role.deleted';

    public static function tableName(): string
    {
        return '{{%audit_log}}';
    }

    /**
     * Audit logs are append-only; disable update.
     *
     * @param bool $runValidation
     * @param string[]|null $attributeNames
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
            [['metadata'], 'string', 'max' => 65535],
            [['user_id', 'object_id'], 'integer'],
        ];
    }

    public function getUser(): \yii\db\ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
}
