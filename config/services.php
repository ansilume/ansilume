<?php

declare(strict_types=1);

// Application services shared by the web and the console application. Both
// configs spread this array into their 'components', so every service is
// defined exactly once.

return [
    'auditService' => [
        'class' => 'app\services\AuditService',
        'targets' => call_user_func(static function (): array {
            $targets = [new \app\services\audit\DatabaseAuditTarget()];
            if (filter_var(getenv('AUDIT_SYSLOG_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
                $targets[] = new \app\services\audit\SyslogAuditTarget(
                    getenv('AUDIT_SYSLOG_IDENT') ?: 'ansilume',
                    getenv('AUDIT_SYSLOG_FACILITY') ?: 'LOG_LOCAL0',
                );
            }
            return $targets;
        }),
    ],
    'projectService' => [
        'class' => 'app\services\ProjectService',
        'workspacePath' => '@runtime/projects',
    ],
    'credentialService' => [
        'class' => 'app\services\CredentialService',
    ],
    'notificationDispatcher' => [
        'class' => 'app\services\NotificationDispatcher',
    ],
    'analyticsService' => [
        'class' => 'app\services\AnalyticsService',
    ],
    'approvalService' => [
        'class' => 'app\services\ApprovalService',
    ],
    'workflowExecutionService' => [
        'class' => 'app\services\WorkflowExecutionService',
    ],
    'workflowStepReorderService' => [
        'class' => 'app\services\WorkflowStepReorderService',
    ],
    'lintService' => [
        'class' => 'app\services\LintService',
    ],
    'projectDeletionService' => [
        'class' => 'app\services\ProjectDeletionService',
    ],
    'jobLaunchService' => [
        'class' => 'app\services\JobLaunchService',
    ],
    'scheduleService' => [
        'class' => 'app\services\ScheduleService',
    ],
    'roleService' => [
        'class' => 'app\services\RoleService',
    ],
    'projectAccessChecker' => [
        'class' => 'app\services\ProjectAccessChecker',
    ],
    'webhookService' => [
        'class' => 'app\services\WebhookService',
    ],
    'jobCompletionService' => [
        'class' => 'app\services\JobCompletionService',
    ],
    'jobClaimService' => [
        'class' => 'app\services\JobClaimService',
    ],
    'jobReclaimService' => [
        'class' => 'app\services\JobReclaimService',
        'progressTimeoutSeconds' => (int)(getenv('JOB_PROGRESS_TIMEOUT') ?: 600),
        'mode' => getenv('JOB_RECLAIM_MODE') ?: 'fail',
        'queueTimeoutSeconds' => (int)(getenv('JOB_QUEUE_TIMEOUT') ?: 1800),
    ],
    'totpService' => [
        'class' => 'app\services\TotpService',
        'rateLimiter' => [
            'class' => 'app\services\TotpRateLimiter',
        ],
    ],
    'inventoryService' => [
        'class' => 'app\services\InventoryService',
        'timeout' => 30,
    ],
    'artifactService' => [
        'class' => 'app\services\ArtifactService',
        'storagePath' => '@runtime/artifacts',
        'maxFileSize' => (int)(getenv('ARTIFACT_MAX_FILE_SIZE') ?: 10485760),
        'maxBytesPerJob' => (int)(getenv('ARTIFACT_MAX_BYTES_PER_JOB') ?: 52428800),
        'maxTotalBytes' => (int)(getenv('ARTIFACT_MAX_TOTAL_BYTES') ?: 0),
        'retentionDays' => (int)(getenv('ARTIFACT_RETENTION_DAYS') ?: 0),
        'maxJobsWithArtifacts' => (int)(getenv('ARTIFACT_MAX_JOBS_WITH_ARTIFACTS') ?: 0),
    ],
    'maintenanceService' => [
        'class' => 'app\services\MaintenanceService',
        'artifactCleanupIntervalSeconds' => (int)(getenv('MAINTENANCE_ARTIFACT_CLEANUP_INTERVAL') ?: 86400),
    ],
    'ldapService' => [
        'class' => 'app\services\ldap\LdapService',
    ],
    'ldapUserProvisioner' => [
        'class' => 'app\services\ldap\LdapUserProvisioner',
    ],
    'jobTemplateCredentialService' => [
        'class' => 'app\services\JobTemplateCredentialService',
    ],
    'jobCredentialResolver' => [
        'class' => 'app\services\JobCredentialResolver',
    ],
    'credentialUsageService' => [
        'class' => 'app\services\CredentialUsageService',
    ],
    'credentialWriteService' => [
        'class' => 'app\services\CredentialWriteService',
    ],
    'vaultCredentialAssignmentService' => [
        'class' => 'app\services\VaultCredentialAssignmentService',
    ],
    'vaultScanService' => [
        'class' => 'app\services\VaultScanService',
    ],
    'vaultCheckService' => [
        'class' => 'app\services\VaultCheckService',
    ],
    'vaultOverviewService' => [
        'class' => 'app\services\VaultOverviewService',
    ],
];
