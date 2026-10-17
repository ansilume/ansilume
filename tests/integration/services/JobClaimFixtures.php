<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Credential;
use app\models\Inventory;
use app\models\Job;
use app\models\JobLog;
use app\models\JobTemplate;
use app\models\Project;
use app\models\Runner;
use app\models\RunnerGroup;
use app\models\User;
use app\services\CredentialWriteService;
use app\services\JobClaimService;
use app\services\JobCredentialResolver;
use app\services\WebhookService;

/**
 * Fixtures of the claim tests (JobClaimGateTest, JobClaimInventoryTest,
 * JobClaimBudgetTest); use
 * in DbTestCase subclasses, call setUpClaimFixtures() in setUp() and
 * restoreClaimServices() in tearDown().
 *
 * Jobs are written as a launch records them, without JobLaunchService: a
 * runner_payload with the inventory's ID and name (Job::PAYLOAD_INVENTORY_NAME).
 * Approval requests record what required them with
 * ApprovalRequest::recordRequiredBy(). The template, its inventory and the
 * runner share one runner group, whose runner claims with claimNext().
 */
trait JobClaimFixtures
{
    private User $launcher;
    private RunnerGroup $group;
    private Runner $runner;
    private Inventory $inventory;
    private JobTemplate $template;

    /** @var array<string, mixed> the components a test swapped, by ID, as they were */
    private array $claimSwapped = [];

    protected function setUpClaimFixtures(): void
    {
        $this->launcher = $this->createUser('claim');
        $userId = (int)$this->launcher->id;
        $this->group = $this->createRunnerGroup($userId);
        $this->runner = $this->createRunner((int)$this->group->id, $userId);
        $this->inventory = $this->createInventory($userId);
        $this->template = $this->templateOn($this->inventory);
    }

    protected function restoreClaimServices(): void
    {
        foreach ($this->claimSwapped as $id => $original) {
            \Yii::$app->set($id, $original);
        }
        $this->claimSwapped = [];
    }

    /**
     * A job template on $inventory, in a project of its own, run by the
     * runner group of the fixtures.
     */
    protected function templateOn(Inventory $inventory): JobTemplate
    {
        $userId = (int)$this->launcher->id;

        return $this->createJobTemplate((int)$this->createProject($userId)->id, (int)$inventory->id, (int)$this->group->id, $userId);
    }

    /**
     * A job of $template in $status, as a launch records it: its payload
     * names $launchedWith (the template's inventory by default) by ID and
     * name, and holds $extra on top.
     *
     * @param array<string, mixed> $extra
     */
    protected function launchedJob(
        JobTemplate $template,
        string $status = Job::STATUS_QUEUED,
        ?Inventory $launchedWith = null,
        array $extra = []
    ): Job {
        $inventory = $launchedWith ?? Inventory::findOne($template->inventory_id);
        $this->assertNotNull($inventory, 'the template has an inventory');

        return $this->jobWithPayload($template, $status, $extra + [
            'template_id' => (int)$template->id,
            'project_id' => (int)$template->project_id,
            'inventory_id' => (int)$inventory->id,
            Job::PAYLOAD_INVENTORY_NAME => (string)$inventory->name,
            'playbook' => 'site.yml',
        ]);
    }

    /**
     * A job of $template in $status whose runner payload is exactly $payload.
     *
     * @param array<string, mixed> $payload
     */
    protected function jobWithPayload(JobTemplate $template, string $status, array $payload): Job
    {
        $job = $this->createJob((int)$template->id, (int)$this->launcher->id, $status);
        $job->runner_payload = (string)json_encode($payload);
        if ($status === Job::STATUS_PENDING_APPROVAL) {
            $job->queued_at = null;
        }
        $job->save(false);

        return $job;
    }

    /**
     * An approval rule whose approvers are exactly $approvers (none by
     * default: the claim reads only whether a rule applies).
     *
     * @param list<User> $approvers
     */
    protected function approvalRule(array $approvers = [], string $timeoutAction = ApprovalRule::TIMEOUT_ACTION_REJECT): ApprovalRule
    {
        $rule = $this->createApprovalRule(
            (int)$this->launcher->id,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => array_map(static fn (User $user): int => (int)$user->id, $approvers)])
        );
        $rule->timeout_minutes = 5;
        $rule->timeout_action = $timeoutAction;
        $rule->save(false);

        return $rule;
    }

    /**
     * $template's own approval rule becomes $rule (null: none), as stored.
     */
    protected function giveTemplateRule(JobTemplate $template, ?ApprovalRule $rule): void
    {
        JobTemplate::updateAll(['approval_rule_id' => $rule?->id], ['id' => $template->id]);
        $template->refresh();
    }

    /**
     * $inventory's approval rule becomes $rule (null: none), as stored.
     */
    protected function giveInventoryRule(Inventory $inventory, ?ApprovalRule $rule): void
    {
        Inventory::updateAll(['approval_rule_id' => $rule?->id], ['id' => $inventory->id]);
        $inventory->refresh();
    }

    /**
     * The approval request of $job in $status, written as a launch or a
     * workflow writes it.
     */
    protected function approvalRequest(
        Job $job,
        ApprovalRule $rule,
        string $status,
        string $requiredBy = ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE,
        ?int $expiresAt = null
    ): ApprovalRequest {
        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->approval_rule_id = (int)$rule->id;
        $request->status = $status;
        $request->requested_at = time() - 600;
        $request->resolved_at = $status === ApprovalRequest::STATUS_PENDING ? null : time() - 60;
        $request->expires_at = $expiresAt;
        $request->recordRequiredBy($requiredBy);
        $request->save(false);

        return $request;
    }

    /**
     * A credential stored with $secrets, as the credential form stores it.
     *
     * @param array<string, string> $secrets
     */
    protected function storedCredential(string $type, array $secrets): Credential
    {
        $credential = new Credential();
        $credential->name = 'claim-cred-' . uniqid('', true);
        $credential->credential_type = $type;
        $credential->created_by = (int)$this->launcher->id;
        /** @var CredentialWriteService $writer */
        $writer = \Yii::$app->get('credentialWriteService');
        $this->assertTrue($writer->create($credential, $secrets), (string)json_encode($credential->errors));

        return $credential;
    }

    /**
     * What the runner of the fixtures (or $runner) gets when it asks for a job.
     *
     * @return array<string, mixed>|null
     */
    protected function claimNext(?Runner $runner = null): ?array
    {
        return $this->claims()->claimNextPayload($this->group, $runner ?? $this->runner);
    }

    protected function claims(): JobClaimService
    {
        /** @var JobClaimService $claims */
        $claims = \Yii::$app->get('jobClaimService');

        return $claims;
    }

    protected function reloadJob(Job $job): Job
    {
        $stored = Job::findOne($job->id);
        $this->assertNotNull($stored);

        return $stored;
    }

    /**
     * @return list<string> the job's log lines, in order
     */
    protected function logLinesOf(Job $job): array
    {
        return array_map(
            static fn (mixed $content): string => (string)$content,
            JobLog::find()->select('content')->where(['job_id' => $job->id])->orderBy(['sequence' => SORT_ASC, 'id' => SORT_ASC])->column()
        );
    }

    /**
     * @return list<string> the job's audit actions, oldest first
     */
    protected function auditActionsOf(Job $job): array
    {
        return array_map(
            static fn (mixed $action): string => (string)$action,
            AuditLog::find()->select('action')->where(['object_type' => 'job', 'object_id' => $job->id])->orderBy(['id' => SORT_ASC])->column()
        );
    }

    /**
     * The context of the job's (last) $action audit entry.
     *
     * @return array<string, mixed>
     */
    protected function auditContextOf(Job $job, string $action = AuditLog::ACTION_JOB_FINISHED): array
    {
        $entry = AuditLog::find()
            ->where(['action' => $action, 'object_type' => 'job', 'object_id' => $job->id])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertNotNull($entry, "job #{$job->id} has a {$action} entry");
        $context = json_decode((string)$entry->metadata, true);
        $this->assertIsArray($context);

        return $context;
    }

    /**
     * Replace the component $id with $replacement until restoreClaimServices().
     */
    protected function swapClaimService(string $id, object $replacement): void
    {
        if (!array_key_exists($id, $this->claimSwapped)) {
            $this->claimSwapped[$id] = \Yii::$app->getComponents(true)[$id] ?? null;
        }
        \Yii::$app->set($id, $replacement);
    }

    /**
     * Webhooks are recorded, not sent: the events, in order.
     *
     * @return WebhookService&object{events: list<string>}
     */
    protected function recordWebhooks(): WebhookService
    {
        $recorder = new class extends WebhookService {
            /** @var list<string> */
            public array $events = [];

            public function dispatch(string $event, Job $job): void
            {
                $this->events[] = $event;
            }
        };
        $this->swapClaimService('webhookService', $recorder);

        return $recorder;
    }

    /**
     * Notifications are recorded, not sent.
     */
    protected function recordClaimNotifications(): RecordingNotificationDispatcher
    {
        $recorder = new RecordingNotificationDispatcher();
        $this->swapClaimService('notificationDispatcher', $recorder);

        return $recorder;
    }

    /**
     * The credential resolver records which credentials it resolves (and
     * so decrypts); it still describes them for job.started, which
     * decrypts nothing.
     *
     * @return JobCredentialResolver&object{resolved: list<string>}
     */
    protected function spyCredentialResolver(): JobCredentialResolver
    {
        $spy = new class extends JobCredentialResolver {
            /** @var list<string> */
            public array $resolved = [];

            public function resolveTemplateCredentials(array $raw): array
            {
                $this->resolved[] = 'template';

                return parent::resolveTemplateCredentials($raw);
            }

            public function resolveScmCredential(?Project $project): ?array
            {
                $this->resolved[] = 'scm';

                return parent::resolveScmCredential($project);
            }
        };
        $this->swapClaimService('jobCredentialResolver', $spy);

        return $spy;
    }
}
