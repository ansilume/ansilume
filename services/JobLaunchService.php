<?php

declare(strict_types=1);

namespace app\services;

use app\components\DbErrorSummary;
use app\components\ErrorText;
use app\components\SubmittedId;
use app\components\SubmittedInteger;
use app\models\ApprovalRequest;
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
 *   1. Validate launch parameters. A template that waits for an approval
 *      rule, its own or else its inventory's (JobTemplateGate), is launched
 *      only when enough eligible approvers besides the launcher remain
 *      among those who may see the template's project (ApproverEligibility),
 *      never when that rule is gone, and never when the template and its
 *      inventory have different rules: a launch waits for one rule only.
 *   2. In one transaction, which locks the template's row and then its
 *      inventory's: refuse a template deleted since it was loaded, or whose
 *      approval rule, inventory or inventory's approval rule changed since,
 *      or whose inventory is gone; persist the Job record with
 *      status=pending, and set it to queued, or to pending_approval together
 *      with its pending approval request, which records what required it
 *      (the job template's own rule or its inventory's).
 *   3. After the commit, best-effort (a failure is logged, EFFECTS_FAILED):
 *      the job.launched audit entry, then the approval.requested
 *      announcement, or the job.launched notification. So an exception out
 *      of launch() means that nothing was written. A runner of the
 *      template's runner group claims a queued job through the runner API.
 *
 * Lock order: the launch transaction locks the job_template row FOR UPDATE
 * as its first statement, then the template's inventory row LOCK IN SHARE
 * MODE (JobTemplateGate::readInventoryRules(), the next statement), and only
 * then inserts the job and its approval request. A template save locks the
 * job_template row first too, then the inventory rows it reads in one
 * statement in ascending ID order (GatedJobTemplateLock); an inventory save
 * locks only its own row, first, and never takes a locking read of
 * job_template (InventoryWriteService); approval_request comes before job
 * (ApprovalSettlement). So these orders cannot cross, with one exception
 * that leaves launches alone, as a launch locks no project row (a job
 * refers to no project): a project delete (ProjectDeletionService) locks
 * the project row and then, through the foreign key's ON DELETE SET NULL
 * (fk_inventory_project_id), the rows of the project's inventories, so it
 * can deadlock with a template save that moves a template into that
 * project while its inventory belongs to it (GatedJobTemplateLock).
 *
 * The runner payload records the inventory the job was launched with, its
 * ID and its name (Job::PAYLOAD_INVENTORY_NAME, JobInventory); the claim
 * payload a runner gets does not change.
 *
 * Password answers (extra vars whose key is a password survey field) are
 * stored only sealed, in job.secret_extra_vars; job.extra_vars and the
 * runner payload hold the placeholder instead (see JobLaunchSecrets).
 *
 * Ansible execution is intentionally NOT performed here.
 */
class JobLaunchService extends Component
{
    /** The template waits for an approval rule that no longer exists; sprintf(): the template ID. */
    public const RULE_MISSING = 'The approval rule of job template #%d no longer exists. An admin can choose another rule.';

    /** The template was deleted since the caller loaded it; sprintf(): the template ID. */
    public const TEMPLATE_DELETED = 'Job template #%d was deleted.';

    /** The template's approval rule changed since the caller loaded it; sprintf(): the template ID. */
    public const TEMPLATE_CHANGED = 'The approval rule of job template #%d changed while the job was being launched. '
        . 'Launch it again.';

    /**
     * The template waits for its inventory's approval rule, which no longer
     * exists; sprintf(): the template ID, then the inventory ID.
     */
    public const INVENTORY_RULE_MISSING = 'The approval rule of inventory #%2$d, which job template #%1$d uses, '
        . 'no longer exists. An admin can choose another rule.';

    /**
     * The template and its inventory have different approval rules, and a
     * launch waits for one rule only; sprintf(): the template ID, then the
     * inventory ID.
     */
    public const RULES_DIFFER = 'Job template #%1$d and its inventory #%2$d have different approval rules, '
        . 'and a launch waits for one rule only. An admin can remove the template\'s own rule '
        . 'or give it the inventory\'s rule.';

    /** The template's inventory changed since the caller loaded it; sprintf(): the template ID. */
    public const TEMPLATE_INVENTORY_CHANGED = 'The inventory of job template #%d changed while the job was being launched. '
        . 'Launch it again.';

    /**
     * The approval rule of the template's inventory changed since the caller
     * loaded it; sprintf(): the template ID, then the inventory ID.
     */
    public const INVENTORY_CHANGED = 'The approval rule of inventory #%2$d, which job template #%1$d uses, '
        . 'changed while the job was being launched. Launch it again.';

    /** The template's inventory no longer exists; sprintf(): the template ID, then the inventory ID. */
    public const INVENTORY_MISSING = 'Inventory #%2$d of job template #%1$d no longer exists. '
        . 'Choose another inventory for the template.';

    /**
     * Logged when what follows a committed launch failed (the job.launched
     * audit entry, the announcement of the job's approval request, the
     * job.launched notification); the job stands, queued or waiting for
     * approval. sprintf(): the job ID, then the exception's class, SQLSTATE
     * and driver error code (DbErrorSummary).
     */
    public const EFFECTS_FAILED = 'Job #%d was launched, but its audit entry, approval request announcement or '
        . 'notification failed: %s (SQLSTATE %s, driver error %s).';

    /**
     * What the messages of this service's own failures begin with
     * (LAUNCH_FAILED). A caller that words a failed launch in a text of its
     * own leaves it out: failureReason().
     */
    public const FAILURE_PREFIX = 'Job launch failed: ';

    /**
     * The message of the RuntimeException for an error of the launch
     * transaction; nothing was written. sprintf(): for a yii\db\Exception
     * SAVE_FAILED, else what may be said about the error (ErrorText): its
     * message, but for a PDOException that escaped Yii's wrapping (such as a
     * failed commit) only its class, SQLSTATE and driver error code.
     */
    public const LAUNCH_FAILED = self::FAILURE_PREFIX . '%s';

    /** The reason in LAUNCH_FAILED for a database error while the job was saved (logged with its codes). */
    public const SAVE_FAILED = 'the job could not be saved.';

    /**
     * Reads the template's row and locks it until the launch transaction
     * ends: the first statement of the transaction (lockTemplate()).
     */
    private const TEMPLATE_LOCK_SQL = 'SELECT [[id]], [[deleted_at]], [[approval_rule_id]], [[inventory_id]]'
        . ' FROM {{%job_template}} WHERE [[id]] = :id FOR UPDATE';

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
        $gate = $this->approvalGate($template, $launchedBy);
        $job = $this->buildJobRecord($template, $launchedBy, $overrides);
        $request = $this->persist($template, $job, $gate);

        // Committed: what follows is best-effort, so an exception out of
        // launch() always means that nothing was written.
        try {
            $this->announce($template, $job, $launchedBy, $context, $request, $gate->rule);
        } catch (\Throwable $e) {
            \Yii::error(sprintf(self::EFFECTS_FAILED, (int)$job->id, ...DbErrorSummary::of($e)), __CLASS__);
        }

        return $job;
    }

    /**
     * What a caller that words a failed launch in a text of its own (a
     * flash such as "Launch failed: %s", a log line) says about $e: what
     * ErrorText says, without the FAILURE_PREFIX of this service's own
     * failures, so that the text does not read
     * "Launch failed: Job launch failed: ...". The one way for every such
     * caller: the web launch, the re-launch, the job trigger and the API.
     */
    public static function failureReason(\Throwable $e): string
    {
        $text = ErrorText::of($e);

        return str_starts_with($text, self::FAILURE_PREFIX) ? substr($text, strlen(self::FAILURE_PREFIX)) : $text;
    }

    /**
     * After the commit: the job.launched audit entry, then the announcement
     * of the job's approval request (approval.requested), or, for a queued
     * job, the job.launched notification.
     */
    private function announce(
        JobTemplate $template,
        Job $job,
        int $launchedBy,
        ?LaunchContext $context,
        ?ApprovalRequest $request,
        ?ApprovalRule $rule
    ): void {
        \Yii::$app->get('auditService')->log(
            AuditService::ACTION_JOB_LAUNCHED,
            'job',
            $job->id,
            $launchedBy,
            ['template_id' => $template->id, 'template_name' => $template->name] + ($context === null ? [] : $context->auditContext)
        );

        if ($request instanceof ApprovalRequest && $rule instanceof ApprovalRule) {
            $this->approvals()->announceRequest($request, $job, $rule);
            return;
        }
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

    /**
     * The approval gate of a launch of $template by user #$launchedBy, as
     * the template was loaded (JobTemplateGate): its own approval rule,
     * else its inventory's; none when neither has one. Fails closed, before
     * anything is written (JobTemplateGate::refusal()): the template and its
     * inventory have different rules (RULES_DIFFER), the gating rule no
     * longer exists (RULE_MISSING, INVENTORY_RULE_MISSING), or too few
     * eligible approvers besides the launcher (who never approves their own
     * launch) may see the template's project, also for its inventory's rule
     * (ApproverEligibility::REFUSAL_JOB).
     *
     * @throws JobLaunchRejectedException
     */
    private function approvalGate(JobTemplate $template, int $launchedBy): JobTemplateGate
    {
        $gate = JobTemplateGate::of($template);
        /** @var ApproverEligibility $eligibility */
        $eligibility = \Yii::$app->get('approverEligibility');
        $refusal = $gate->refusal($launchedBy, $eligibility);
        if ($refusal !== null) {
            throw new JobLaunchRejectedException($refusal);
        }

        return $gate;
    }

    /**
     * Save $job in one transaction that first locks the template's row,
     * then its inventory's (lockTemplate()): queued, or waiting for
     * approval by the gate's rule together with its pending request, which
     * records what required it (the gate's source). A template soft-deleted
     * meanwhile (its deletion locks the same row, and cancels the waiting
     * jobs it finds after its commit), or whose approval rule, inventory or
     * inventory's approval rule changed, or whose inventory is gone, is
     * refused, so no launch slips past a deletion or an assignment.
     * approvalGate() refused a gate whose rule is gone, so the job waits
     * exactly when the gate has a rule.
     *
     * @return ApprovalRequest|null the job's approval request; null when it is queued
     * @throws JobLaunchRejectedException
     * @throws \RuntimeException
     */
    private function persist(JobTemplate $template, Job $job, JobTemplateGate $gate): ?ApprovalRequest
    {
        $transaction = \Yii::$app->db->beginTransaction();
        try {
            $this->lockTemplate($template, $gate);
            if (!$job->save()) {
                throw new \RuntimeException('Failed to save job record: ' . json_encode($job->errors));
            }

            $rule = $gate->rule;
            if ($rule !== null) {
                $job->status = Job::STATUS_PENDING_APPROVAL;
            } else {
                $job->status = Job::STATUS_QUEUED;
                $job->queued_at = time();
            }

            if (!$job->save()) {
                throw new \RuntimeException('Failed to update job status: ' . json_encode($job->errors));
            }
            $request = $rule === null ? null : $this->approvals()->openRequest($job, $rule, (string)$gate->source());

            $transaction->commit();
        } catch (JobLaunchRejectedException $e) {
            $transaction->rollBack();
            throw $e;
        } catch (\yii\db\Exception $e) {
            $transaction->rollBack();
            throw self::saveFailed($e);
        } catch (\Throwable $e) {
            $transaction->rollBack();
            // A database error is passed on neither by its message nor as
            // the previous exception: the driver's message can quote a value.
            throw new \RuntimeException(
                sprintf(self::LAUNCH_FAILED, ErrorText::of($e)),
                0,
                $e instanceof \PDOException ? null : $e
            );
        }

        return $request;
    }

    /**
     * Lock the template's row (FOR UPDATE, the transaction's first
     * statement), then its inventory's row (LOCK IN SHARE MODE, the next
     * statement, JobTemplateGate::readInventoryRules()), for the rest of the
     * launch transaction, and check both against the gate of the template
     * as the caller loaded it, in this order: the template was deleted or is
     * gone (TEMPLATE_DELETED); its own approval rule changed
     * (TEMPLATE_CHANGED); it uses another inventory now
     * (TEMPLATE_INVENTORY_CHANGED); its inventory is gone
     * (INVENTORY_MISSING); its inventory's approval rule changed
     * (INVENTORY_CHANGED). The inventory's row is read as stored, not
     * through the cached schema, so a schema cached from before m000088,
     * which shows no rule, fails closed with INVENTORY_CHANGED.
     *
     * @throws JobLaunchRejectedException
     */
    private function lockTemplate(JobTemplate $template, JobTemplateGate $gate): void
    {
        $templateId = (int)$template->id;
        /** @var array{id: int|string, deleted_at: int|string|null, approval_rule_id: int|string|null, inventory_id: int|string}|false $row */
        $row = JobTemplate::getDb()->createCommand(self::TEMPLATE_LOCK_SQL, [':id' => $template->id])->queryOne();
        if ($row === false || $row['deleted_at'] !== null) {
            throw new JobLaunchRejectedException(sprintf(self::TEMPLATE_DELETED, $templateId));
        }
        // An approval rule ID as JobTemplateGate reads one: any whole number is an ID (SubmittedId).
        $storedRuleId = SubmittedId::of($row['approval_rule_id']);
        if ($storedRuleId !== $gate->templateRuleId) {
            throw new JobLaunchRejectedException(sprintf(self::TEMPLATE_CHANGED, $templateId));
        }
        $inventoryId = $gate->inventoryId;
        if ((int)$row['inventory_id'] !== $inventoryId) {
            throw new JobLaunchRejectedException(sprintf(self::TEMPLATE_INVENTORY_CHANGED, $templateId));
        }
        $inventoryRules = JobTemplateGate::readInventoryRules([$inventoryId], true);
        if (!array_key_exists($inventoryId, $inventoryRules)) {
            throw new JobLaunchRejectedException(sprintf(self::INVENTORY_MISSING, $templateId, $inventoryId));
        }
        if ($inventoryRules[$inventoryId] !== $gate->inventoryRuleId) {
            throw new JobLaunchRejectedException(sprintf(self::INVENTORY_CHANGED, $templateId, $inventoryId));
        }
    }

    private function approvals(): ApprovalService
    {
        /** @var ApprovalService $approvals */
        $approvals = \Yii::$app->get('approvalService');

        return $approvals;
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

        return new \RuntimeException(sprintf(self::LAUNCH_FAILED, self::SAVE_FAILED));
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

        // As the record's integer rule reads it, never cast (JobLaunchInput
        // checked it): a whole number as an int, anything else for the rule
        // to refuse. An empty one, which the rule skips, is none: the
        // template's applies, as for an empty limit. JobLaunchInput refuses
        // an empty list before the launch of a request; a caller that does
        // not check gets none for it, as for an empty limit list.
        $verbosity = SubmittedInteger::orAsSubmitted($overrides['verbosity'] ?? null);
        if (!in_array($verbosity, [null, '', []], true)) {
            $job->setAttribute('verbosity', $verbosity);
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
        /** @var \app\models\Inventory|null $inventory as the launch's gate loaded it; null when its row is gone */
        $inventory = $template->inventory;

        return (string)json_encode([
            'template_id' => $template->id,
            'template_name' => $template->name,
            'project_id' => $template->project_id,
            'inventory_id' => $template->inventory_id,
            // The inventory's name as launched (JobInventory), for the
            // server only: the runner's claim payload has no such key.
            \app\models\Job::PAYLOAD_INVENTORY_NAME => $inventory?->name,
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
