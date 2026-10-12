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
 *   3. Set the job to status=queued; a runner of the template's runner
 *      group claims it through the runner API.
 *
 * Password answers (extra vars whose key is a password survey field) are
 * stored only sealed, in job.secret_extra_vars; job.extra_vars and the
 * runner payload hold the placeholder instead (see JobLaunchSecrets).
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
     * @param array<string, mixed> $overrides Optional launch-time overrides: extra_vars, survey, limit,
     *     verbosity, check_mode, and a LaunchContext under LaunchContext::KEY (server code only).
     *
     * @return Job The created job record.
     *
     * @throws JobLaunchRejectedException when the launch is refused for a reason the caller can fix.
     * @throws \RuntimeException on failure.
     */
    public function launch(JobTemplate $template, int $launchedBy, #[\SensitiveParameter] array $overrides = []): Job
    {
        $context = self::contextFrom($overrides);
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
        } catch (\yii\db\Exception $e) {
            $transaction->rollBack();
            throw self::saveFailed($e);
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw new \RuntimeException('Job launch failed: ' . $e->getMessage(), 0, $e);
        }

        \Yii::$app->get('auditService')->log(
            AuditService::ACTION_JOB_LAUNCHED,
            'job',
            $job->id,
            $launchedBy,
            ['template_id' => $template->id, 'template_name' => $template->name] + ($context === null ? [] : $context->auditContext)
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

    /**
     * The LaunchContext of $overrides: only an instance counts, which request
     * data cannot produce.
     *
     * @param array<string, mixed> $overrides
     */
    private static function contextFrom(#[\SensitiveParameter] array $overrides): ?LaunchContext
    {
        $context = $overrides[LaunchContext::KEY] ?? null;

        return $context instanceof LaunchContext ? $context : null;
    }

    /**
     * The exception for a database error while saving the job. Its message
     * carries the SQL with every value, so it is neither passed on nor
     * logged: only SQLSTATE and the driver's error code are.
     */
    private static function saveFailed(\yii\db\Exception $e): \RuntimeException
    {
        $info = $e->errorInfo;
        \Yii::error(sprintf(
            'Job launch failed: the job could not be saved (SQLSTATE %s, driver error %s).',
            is_scalar($info[0] ?? null) ? (string)$info[0] : 'unknown',
            is_scalar($info[1] ?? null) ? (string)$info[1] : 'unknown'
        ), __CLASS__);

        return new \RuntimeException('Job launch failed: the job could not be saved.');
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
    private function buildJobRecord(JobTemplate $template, int $launchedBy, #[\SensitiveParameter] array $overrides): Job
    {
        $job = $this->newJobRecord();
        $job->job_template_id = $template->id;
        $job->launched_by = $launchedBy;
        $job->status = Job::STATUS_PENDING;
        $job->created_at = time();
        $job->updated_at = time();

        $this->applyExtraVars($job, $template, $overrides);

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
     * The job record a launch fills in.
     */
    protected function newJobRecord(): Job
    {
        return new Job();
    }

    /**
     * Merge extra_vars (template defaults ← survey answers ← explicit
     * overrides), then store the password answers sealed and the rest, with
     * placeholders, in job.extra_vars.
     *
     * @param array<string, mixed> $overrides
     * @throws JobLaunchRejectedException also when the extra vars or answers
     *     cannot be stored as JSON (a number too large for JSON, text that is not UTF-8)
     * @throws \RuntimeException
     */
    private function applyExtraVars(Job $job, JobTemplate $template, #[\SensitiveParameter] array $overrides): void
    {
        $secrets = (new JobLaunchSecrets())->apply($template, $this->mergeExtraVars($template, $overrides), self::contextFrom($overrides));
        if ($secrets['public'] !== []) {
            $job->extra_vars = JobLaunchSecrets::encode($secrets['public'], JobLaunchSecrets::EXTRA_VARS);
        }
        $job->setSealedSecretExtraVars($secrets['sealed']);
    }

    /**
     * Build merged extra_vars: template defaults, then survey answers, then explicit overrides.
     * Password answers among them are sealed afterwards (applyExtraVars()).
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function mergeExtraVars(\app\models\JobTemplate $template, #[\SensitiveParameter] array $overrides): array
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
        $snapshot = $template->credentialSnapshot();

        return (string)json_encode([
            'template_id' => $template->id,
            'template_name' => $template->name,
            'project_id' => $template->project_id,
            'inventory_id' => $template->inventory_id,
            // Primary credential FK — kept for API/UI back-compat.
            'credential_id' => $template->credential_id,
            // All credentials in precedence order: primary first, then the
            // additional ones by sort_order. The claim layer resolves them
            // into decrypted secrets before the runner sees them.
            'credential_ids' => array_column($snapshot, 'id'),
            // The same credentials without secrets, as launched.
            \app\models\Job::PAYLOAD_CREDENTIAL_SNAPSHOT => $snapshot,
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
