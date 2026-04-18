<?php

declare(strict_types=1);

namespace app\services;

use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\services\notification\JobPayloadBuilder;
use yii\base\Component;

/**
 * Orchestrates the creation and queuing of a Job.
 *
 * Responsibilities:
 *   1. Validate launch parameters.
 *   2. Build and persist a Job record with status=pending.
 *   3. Push a RunAnsibleJob message to the queue (status → queued).
 *
 * Ansible execution is intentionally NOT performed here.
 */
class JobLaunchService extends Component
{
    /**
     * Launch a job from a template with optional runtime overrides.
     *
     * @param JobTemplate $template The template to execute.
     * @param int $launchedBy User ID of the person launching.
     * @param array<string, mixed> $overrides Optional launch-time overrides: extra_vars, limit, verbosity.
     *
     * @return Job The created job record.
     *
     * @throws \RuntimeException on failure.
     */
    public function launch(JobTemplate $template, int $launchedBy, array $overrides = []): Job
    {
        $job = $this->buildJobRecord($template, $launchedBy, $overrides);
        $needsApproval = $this->requiresApproval($template);

        $transaction = \Yii::$app->db->beginTransaction();
        try {
            if (!$job->save()) {
                throw new \RuntimeException('Failed to save job record: ' . json_encode($job->errors));
            }

            if ($needsApproval) {
                $job->status = Job::STATUS_PENDING_APPROVAL;
            } else {
                $job->status = Job::STATUS_QUEUED;
                $job->queued_at = time();
            }

            if (!$job->save()) {
                throw new \RuntimeException('Failed to update job status: ' . json_encode($job->errors));
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw new \RuntimeException('Job launch failed: ' . $e->getMessage(), 0, $e);
        }

        \Yii::$app->get('auditService')->log(
            AuditService::ACTION_JOB_LAUNCHED,
            'job',
            $job->id,
            $launchedBy,
            ['template_id' => $template->id, 'template_name' => $template->name]
        );

        if ($needsApproval) {
            $this->initiateApproval($job, $template);
        } else {
            // Only fire job.launched once the job is actually queued — a
            // pending-approval job might never run, so notifying then would
            // be a false signal.
            /** @var NotificationDispatcher $dispatcher */
            $dispatcher = \Yii::$app->get('notificationDispatcher');
            $dispatcher->dispatch(
                NotificationTemplate::EVENT_JOB_LAUNCHED,
                JobPayloadBuilder::build($job)
            );
        }

        return $job;
    }

    private function requiresApproval(JobTemplate $template): bool
    {
        if ($template->approval_rule_id === null) {
            return false;
        }
        /** @var ApprovalRule|null $rule */
        $rule = $template->approvalRule;
        return $rule !== null;
    }

    private function initiateApproval(Job $job, JobTemplate $template): void
    {
        /** @var ApprovalRule|null $rule */
        $rule = $template->approvalRule;
        if ($rule === null) {
            return;
        }

        /** @var ApprovalService $approvalService */
        $approvalService = \Yii::$app->get('approvalService');
        $approvalService->createRequest($job, $rule);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function buildJobRecord(JobTemplate $template, int $launchedBy, array $overrides): Job
    {
        $job = new Job();
        $job->job_template_id = $template->id;
        $job->launched_by = $launchedBy;
        $job->status = Job::STATUS_PENDING;
        $job->created_at = time();
        $job->updated_at = time();

        // Merge extra_vars: template defaults ← survey answers ← explicit overrides
        $merged = $this->mergeExtraVars($template, $overrides);
        if ($merged !== []) {
            $job->extra_vars = json_encode($merged, JSON_UNESCAPED_UNICODE) ?: null;
        }

        if (!empty($overrides['limit'])) {
            $job->limit = (string)$overrides['limit'];
        }

        if (isset($overrides['verbosity'])) {
            $job->verbosity = (int)$overrides['verbosity'];
        }

        if (!empty($overrides['check_mode'])) {
            $job->check_mode = 1;
        }

        $job->timeout_minutes = $template->timeout_minutes;

        // Snapshot runner payload for auditability
        $job->runner_payload = $this->buildRunnerPayload($template, $job);

        return $job;
    }

    /**
     * Build merged extra_vars: template defaults, then survey answers, then explicit overrides.
     * Survey passwords are accepted but will be visible in extra_vars — callers should be aware.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function mergeExtraVars(\app\models\JobTemplate $template, array $overrides): array
    {
        // Start with template defaults
        $base = [];
        if (!empty($template->extra_vars)) {
            $decoded = json_decode($template->extra_vars, true);
            if (is_array($decoded)) {
                $base = $decoded;
            }
        }

        // Merge survey answers (keyed by field name)
        if (!empty($overrides['survey']) && is_array($overrides['survey'])) {
            foreach ($overrides['survey'] as $key => $value) {
                $base[(string)$key] = $value;
            }
        }

        // Merge explicit extra_vars override (JSON string or array)
        if (!empty($overrides['extra_vars'])) {
            $explicit = is_array($overrides['extra_vars'])
                ? $overrides['extra_vars']
                : json_decode((string)$overrides['extra_vars'], true);
            if (is_array($explicit)) {
                $base = array_merge($base, $explicit);
            }
        }

        return $base;
    }

    protected function buildRunnerPayload(\app\models\JobTemplate $template, \app\models\Job $job): string
    {
        $credentialIds = array_map(static fn ($c) => (int)$c->id, $template->credentials);

        return (string)json_encode([
            'template_id' => $template->id,
            'template_name' => $template->name,
            'project_id' => $template->project_id,
            'inventory_id' => $template->inventory_id,
            // Primary credential FK — kept for API/UI back-compat.
            'credential_id' => $template->credential_id,
            // All credentials attached to this template, in sort_order. The
            // claim layer resolves this list into decrypted secrets before
            // the runner sees it.
            'credential_ids' => $credentialIds,
            'playbook' => $template->playbook,
            'extra_vars' => $job->extra_vars ?? $template->extra_vars,
            'limit' => $job->limit ?? $template->limit,
            'verbosity' => $job->verbosity ?? $template->verbosity,
            'forks' => $template->forks,
            'become' => $template->become,
            'become_method' => $template->become_method,
            'become_user' => $template->become_user,
            'tags' => $template->tags,
            'skip_tags' => $template->skip_tags,
            'check_mode' => (bool)$job->check_mode,
            'timeout_minutes' => $template->timeout_minutes,
        ], JSON_UNESCAPED_UNICODE);
    }
}
