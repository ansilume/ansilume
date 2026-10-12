<?php

declare(strict_types=1);

namespace app\services;

use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\WorkflowTemplate;

/**
 * Generates and revokes the inbound trigger token of a job or workflow
 * template, and audits both, for the web UI and the REST API alike.
 *
 * A trigger runs as the user who generated its token, so generating a new
 * token rebinds the trigger to that user. Callers check the permissions
 * first: job-template.update (workflow-template.update) and operator access
 * to the template's project (to the project of every job step).
 */
class TriggerTokenService
{
    /**
     * Generate a new token as $userId, replacing any earlier one. Returns
     * the raw token: only its hash is stored, so this is the only time it
     * is available.
     *
     * @param array<string, scalar> $auditContext added to the audit entry, e.g. ['source' => 'api']
     */
    public function generate(JobTemplate|WorkflowTemplate $template, int $userId, array $auditContext = []): string
    {
        $raw = $template->generateTriggerToken($userId);
        $this->audit($template, true, $userId, $auditContext);

        return $raw;
    }

    /**
     * Revoke the token, which disables the trigger. Returns whether there
     * was one; revoking a template without a token changes nothing and is
     * not audited.
     *
     * @param array<string, scalar> $auditContext added to the audit entry, e.g. ['source' => 'api']
     */
    public function revoke(JobTemplate|WorkflowTemplate $template, int $userId, array $auditContext = []): bool
    {
        if (!$template->hasTriggerToken()) {
            return false;
        }
        $template->revokeTriggerToken();
        $this->audit($template, false, $userId, $auditContext);

        return true;
    }

    /**
     * @param array<string, scalar> $context
     */
    private function audit(JobTemplate|WorkflowTemplate $template, bool $generated, int $userId, array $context): void
    {
        if ($template instanceof JobTemplate) {
            $objectType = 'job_template';
            $action = $generated
                ? AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_GENERATED
                : AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_REVOKED;
        } else {
            $objectType = 'workflow_template';
            $action = $generated
                ? AuditLog::ACTION_WORKFLOW_TEMPLATE_TRIGGER_TOKEN_GENERATED
                : AuditLog::ACTION_WORKFLOW_TEMPLATE_TRIGGER_TOKEN_REVOKED;
        }

        /** @var AuditService $audit */
        $audit = \Yii::$app->get('auditService');
        $audit->log($action, $objectType, (int)$template->id, $userId, ['name' => (string)$template->name] + $context);
    }
}
