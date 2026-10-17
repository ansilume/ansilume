<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Credential;
use app\models\Job;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\Runner;
use app\models\RunnerGroup;
use app\models\Webhook;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\ApprovalSettlement;
use app\services\JobCancellationService;
use app\services\JobClaimGate;
use app\services\JobClaimRefusedException;
use app\services\JobClaimService;
use app\services\JobReclaimService;
use app\services\JobTemplateGate;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\db\Query;

/**
 * The claim's approval gate (JobClaimGate, through
 * JobClaimService::claimNextPayload()): a runner starts a job whose job
 * template, launch inventory or current inventory has an approval rule only
 * once the job was approved, by the votes or by a timeout that approves. Any
 * other such job fails before execution, before any credential is
 * decrypted, with a hint to launch it again; job.finished records why, and
 * the runner gets the next job.
 *
 * Jobs and requests are written as a launch writes them (JobClaimFixtures);
 * launches themselves are tested in InventoryGateLaunchPathsTest.
 */
class JobClaimGateTest extends DbTestCase
{
    use TeamScopeFixtures;
    use WorkflowEngineFixtures;
    use JobClaimFixtures;

    /**
     * Where the gate's transaction begins and ends: DbTestCase runs every
     * test in a transaction, so here it is a savepoint (Yii's first nested
     * level), released after the reads, before a refusal is thrown.
     */
    private const GATE_TRANSACTION_BEGINS = 'SAVEPOINT LEVEL1';
    private const GATE_TRANSACTION_ENDS = 'RELEASE SAVEPOINT LEVEL1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClaimFixtures();
    }

    protected function tearDown(): void
    {
        $this->restoreClaimServices();
        parent::tearDown();
    }

    public function testAJobWithoutAnApprovalRuleRuns(): void
    {
        $job = $this->launchedJob($this->template);

        $payload = $this->claimNext();

        $this->assertSame((int)$job->id, $payload['job_id'] ?? null);
        $this->assertSame(Job::STATUS_RUNNING, $this->reloadJob($job)->status);
    }

    /**
     * Regression: a job queued before its job template got an approval rule
     * ran without approval: the claim never looked at the rule.
     */
    public function testRegressionAJobQueuedBeforeItsTemplateGotARuleRanWithoutApproval(): void
    {
        $job = $this->launchedJob($this->template);
        $rule = $this->approvalRule();
        $this->giveTemplateRule($this->template, $rule);

        $payload = $this->claimNext();

        $this->assertNull($payload, 'the runner gets no job');
        $job = $this->reloadJob($job);
        $this->assertSame(Job::STATUS_FAILED, $job->status);
        $this->assertSame(
            [sprintf(JobClaimRefusedException::NOT_APPROVED, sprintf(JobClaimRefusedException::GATE_JOB_TEMPLATE, $this->template->id), $rule->id)],
            $this->logLinesOf($job)
        );
        $this->assertSame($this->notApprovedContext($rule, ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE, null), array_slice($this->auditContextOf($job), 3));
    }

    /**
     * Regression: a job queued before its inventory got an approval rule ran
     * without approval.
     */
    public function testRegressionAJobQueuedBeforeItsInventoryGotARuleRanWithoutApproval(): void
    {
        $job = $this->launchedJob($this->template);
        $rule = $this->approvalRule();
        $this->giveInventoryRule($this->inventory, $rule);

        $payload = $this->claimNext();

        $this->assertNull($payload, 'the runner gets no job');
        $job = $this->reloadJob($job);
        $this->assertSame(Job::STATUS_FAILED, $job->status);
        $this->assertSame(
            [sprintf(JobClaimRefusedException::NOT_APPROVED, sprintf(JobClaimRefusedException::GATE_INVENTORY, $this->inventory->id), $rule->id)],
            $this->logLinesOf($job)
        );
        $this->assertSame(
            $this->notApprovedContext($rule, ApprovalRequest::REQUIRED_BY_INVENTORY, (int)$this->inventory->id),
            array_slice($this->auditContextOf($job), 3)
        );
    }

    /**
     * Regression: a job that JOB_RECLAIM_MODE=requeue put back in the queue
     * was handed to the next runner without a check, although its template
     * had got an approval rule meanwhile.
     */
    public function testRegressionARequeuedJobWasNotCheckedAgain(): void
    {
        $job = $this->launchedJob($this->template);
        Job::updateAll(['max_attempts' => 3], ['id' => $job->id]);
        $this->assertSame((int)$job->id, $this->claimNext()['job_id'] ?? null, 'the job ran before the rule');
        $rule = $this->approvalRule();
        $this->giveTemplateRule($this->template, $rule);
        // Its runner stopped answering: the job is stale, and the reclaim requeues it.
        Job::updateAll(['last_progress_at' => time() - 3600], ['id' => $job->id]);
        Runner::updateAll(['last_seen_at' => time() - (RunnerGroup::STALE_AFTER + 60)], ['id' => $this->runner->id]);
        (new JobReclaimService(['mode' => JobReclaimService::MODE_REQUEUE]))->reclaimStaleJobs();
        $this->assertSame(Job::STATUS_QUEUED, $this->reloadJob($job)->status, 'requeued');

        $payload = $this->claimNext($this->createRunner((int)$this->group->id, (int)$this->launcher->id));

        $this->assertNull($payload, 'the requeued job is checked again and refused');
        $job = $this->reloadJob($job);
        $this->assertSame(Job::STATUS_FAILED, $job->status);
        $lines = $this->logLinesOf($job);
        $this->assertSame(
            sprintf(JobClaimRefusedException::NOT_APPROVED, sprintf(JobClaimRefusedException::GATE_JOB_TEMPLATE, $this->template->id), $rule->id),
            end($lines),
            'after the line of the requeue'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ruleSourceProvider(): array
    {
        return [
            'the job template\'s own rule' => [ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE],
            'its inventory\'s rule' => [ApprovalRequest::REQUIRED_BY_INVENTORY],
        ];
    }

    /**
     * A job approved by the votes is queued by the approval and runs.
     *
     * @dataProvider ruleSourceProvider
     */
    public function testAJobApprovedByAVoteRuns(string $requiredBy): void
    {
        $approver = $this->createUserWithRole('claim-approver', 'operator');
        $rule = $this->approvalRule([$approver]);
        $this->gateBy($requiredBy, $rule);
        $job = $this->launchedJob($this->template, Job::STATUS_PENDING_APPROVAL);
        $request = $this->approvalRequest($job, $rule, ApprovalRequest::STATUS_PENDING, $requiredBy);

        $this->settlement()->vote((int)$request->id, (int)$approver->id, ApprovalDecision::DECISION_APPROVED, null);
        $this->assertSame(Job::STATUS_QUEUED, $this->reloadJob($job)->status, 'the approval queued the job');

        $payload = $this->claimNext();

        $this->assertSame((int)$job->id, $payload['job_id'] ?? null);
        $this->assertSame(Job::STATUS_RUNNING, $this->reloadJob($job)->status);
    }

    /**
     * A timeout whose action is approve queues the job, and it runs.
     *
     * @dataProvider ruleSourceProvider
     */
    public function testAJobApprovedByATimeoutRuns(string $requiredBy): void
    {
        $rule = $this->approvalRule([], ApprovalRule::TIMEOUT_ACTION_APPROVE);
        $this->gateBy($requiredBy, $rule);
        $job = $this->launchedJob($this->template, Job::STATUS_PENDING_APPROVAL);
        $request = $this->approvalRequest($job, $rule, ApprovalRequest::STATUS_PENDING, $requiredBy, time() - 60);

        $this->settlement()->timeOut((int)$request->id, time());
        $this->assertSame(ApprovalRequest::STATUS_TIMED_OUT, ApprovalRequest::findOne($request->id)?->status);
        $this->assertSame(Job::STATUS_QUEUED, $this->reloadJob($job)->status, 'the timeout approved the job');

        $payload = $this->claimNext();

        $this->assertSame((int)$job->id, $payload['job_id'] ?? null);
    }

    /**
     * A timeout whose action is reject rejects the job, which no runner claims.
     */
    public function testAJobRejectedByATimeoutIsNeverClaimed(): void
    {
        $rule = $this->approvalRule([], ApprovalRule::TIMEOUT_ACTION_REJECT);
        $this->giveTemplateRule($this->template, $rule);
        $job = $this->launchedJob($this->template, Job::STATUS_PENDING_APPROVAL);
        $request = $this->approvalRequest($job, $rule, ApprovalRequest::STATUS_PENDING, ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE, time() - 60);
        $this->settlement()->timeOut((int)$request->id, time());

        $this->assertNull($this->claimNext());

        $job = $this->reloadJob($job);
        $this->assertSame(Job::STATUS_REJECTED, $job->status);
        $this->assertNull($job->runner_id);
        $this->assertNotContains(AuditLog::ACTION_JOB_STARTED, $this->auditActionsOf($job));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unapprovedStatusProvider(): array
    {
        return [
            'pending' => [ApprovalRequest::STATUS_PENDING],
            'canceled' => [ApprovalRequest::STATUS_CANCELED],
            'rejected' => [ApprovalRequest::STATUS_REJECTED],
        ];
    }

    /**
     * A queued job whose request was left pending, or closed without an
     * approval (by hand, or by a bug), does not run; job.finished names the
     * request and its status.
     *
     * @dataProvider unapprovedStatusProvider
     */
    public function testARequestThatIsNotApprovedDoesNotLetTheJobRun(string $status): void
    {
        $rule = $this->approvalRule();
        $this->giveInventoryRule($this->inventory, $rule);
        $job = $this->launchedJob($this->template);
        $request = $this->approvalRequest($job, $rule, $status, ApprovalRequest::REQUIRED_BY_INVENTORY);

        $this->assertNull($this->claimNext());

        $job = $this->reloadJob($job);
        $this->assertSame(Job::STATUS_FAILED, $job->status);
        $this->assertSame(
            $this->notApprovedContext($rule, ApprovalRequest::REQUIRED_BY_INVENTORY, (int)$this->inventory->id) + [
                'approval_request_id' => (int)$request->id,
                'approval_request_status' => $status,
            ],
            array_slice($this->auditContextOf($job), 3)
        );
    }

    /**
     * The template was pointed at a gated inventory after the launch: the
     * job's own inventory has no rule, but the template's does, and the job
     * was never approved (fail closed).
     */
    public function testAJobWhoseTemplateWasRepointedToAGatedInventoryIsRefused(): void
    {
        $job = $this->launchedJob($this->template);
        $gated = $this->createInventory((int)$this->launcher->id);
        $rule = $this->approvalRule();
        $this->giveInventoryRule($gated, $rule);
        JobTemplate::updateAll(['inventory_id' => $gated->id], ['id' => $this->template->id]);

        $this->assertNull($this->claimNext());

        $job = $this->reloadJob($job);
        $this->assertSame(Job::STATUS_FAILED, $job->status);
        $this->assertSame(
            [sprintf(JobClaimRefusedException::NOT_APPROVED, sprintf(JobClaimRefusedException::GATE_INVENTORY, $gated->id), $rule->id)],
            $this->logLinesOf($job)
        );
        $this->assertSame($this->notApprovedContext($rule, ApprovalRequest::REQUIRED_BY_INVENTORY, (int)$gated->id), array_slice($this->auditContextOf($job), 3));
    }

    /**
     * The hosts the runner gets are the launch inventory's, so its rule is
     * named before the rule of the template's current inventory.
     */
    public function testTheLaunchInventorysRuleComesBeforeTheCurrentInventorysRule(): void
    {
        $launchRule = $this->approvalRule();
        $this->giveInventoryRule($this->inventory, $launchRule);
        $job = $this->launchedJob($this->template);
        $current = $this->createInventory((int)$this->launcher->id);
        $this->giveInventoryRule($current, $this->approvalRule());
        JobTemplate::updateAll(['inventory_id' => $current->id], ['id' => $this->template->id]);

        $refusal = $this->gateRefusal($job);

        $this->assertNotNull($refusal);
        $this->assertSame(
            $this->notApprovedContext($launchRule, ApprovalRequest::REQUIRED_BY_INVENTORY, (int)$this->inventory->id),
            $refusal->auditContext()
        );
    }

    /**
     * The refused job ends as a failure before execution: the log line says
     * why, job.finished records it after the base keys, and the failure
     * webhook and notification go out.
     */
    public function testARefusalEndsTheJobAsAFailureWithItsReasonWebhookAndNotification(): void
    {
        $webhooks = $this->recordWebhooks();
        $notifications = $this->recordClaimNotifications();
        $rule = $this->approvalRule();
        $this->giveTemplateRule($this->template, $rule);
        $job = $this->launchedJob($this->template);

        $this->assertNull($this->claimNext());

        $job = $this->reloadJob($job);
        $this->assertSame(Job::STATUS_FAILED, $job->status);
        $this->assertSame(-1, $job->exit_code);
        $this->assertNotNull($job->finished_at);
        $this->assertSame((int)$this->runner->id, $job->runner_id, 'the runner had claimed it');
        $this->assertSame(
            ['exit_code' => -1, 'status' => Job::STATUS_FAILED, 'reason' => 'pre_execution_failure']
                + $this->notApprovedContext($rule, ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE, null),
            $this->auditContextOf($job)
        );
        $this->assertSame([Webhook::EVENT_JOB_FAILURE], $webhooks->events);
        $this->assertSame([NotificationTemplate::EVENT_JOB_FAILED], array_column($notifications->events, 'event'));
    }

    /**
     * The claim committed the job as running and wrote job.started (the
     * runner and the job's credentials by name) before the gate ran; the
     * gate refused it before any credential was resolved, and nothing was
     * stored to run.
     */
    public function testARefusedJobKeepsItsStartEntryAndNoCredentialIsDecrypted(): void
    {
        $credential = $this->storedCredential(Credential::TYPE_TOKEN, ['token' => 'claim-gate-token-4711']);
        $resolver = $this->spyCredentialResolver();
        $this->giveTemplateRule($this->template, $this->approvalRule());
        $job = $this->launchedJob($this->template, Job::STATUS_QUEUED, null, ['credential_id' => (int)$credential->id]);

        $this->assertNull($this->claimNext());

        $this->assertSame([], $resolver->resolved, 'no credential was resolved, so none was decrypted');
        $job = $this->reloadJob($job);
        $this->assertNull($job->execution_command, 'nothing is stored to run');
        $this->assertNotNull($job->started_at, 'the claim started it');
        $this->assertSame([AuditLog::ACTION_JOB_STARTED, AuditLog::ACTION_JOB_FINISHED], $this->auditActionsOf($job));
        $started = $this->auditContextOf($job, AuditLog::ACTION_JOB_STARTED);
        $this->assertSame((int)$this->runner->id, $started['runner_id']);
        $this->assertSame([(int)$credential->id], array_column($started['credentials'], 'id'), 'named, not decrypted');
        $this->assertStringNotContainsString('claim-gate-token-4711', (string)json_encode($started));
        $this->assertSame(JobClaimRefusedException::REASON_NOT_APPROVED, $this->auditContextOf($job)['claim_refusal']);
    }

    /**
     * Regression class of 2.11: a failure before execution never overwrites
     * a cancel that committed after the claim loaded the job, also when the
     * gate refuses it.
     */
    public function testACancelThatCommitsWhileTheGateChecksWins(): void
    {
        $this->giveTemplateRule($this->template, $this->approvalRule());
        $job = $this->launchedJob($this->template);
        $claims = new class ((int)$job->id) extends JobClaimService {
            public function __construct(private readonly int $jobId)
            {
                parent::__construct();
            }

            protected function checkClaimable(Job $job, array $raw): void
            {
                // An operator cancels the claimed job in a parallel request.
                $copy = Job::findOne($this->jobId);
                if ($copy === null) {
                    throw new \LogicException("Job #{$this->jobId} is gone.");
                }
                /** @var JobCancellationService $cancellation */
                $cancellation = \Yii::$app->get('jobCancellationService');
                $cancellation->cancel($copy, (int)$copy->launched_by);

                parent::checkClaimable($job, $raw);
            }
        };

        $this->assertNull($claims->claimNextPayload($this->group, $this->runner));

        $job = $this->reloadJob($job);
        $this->assertSame(Job::STATUS_CANCELED, $job->status, 'the cancel stays');
        $this->assertNull($job->exit_code);
        $this->assertSame([], $this->logLinesOf($job), 'no reason for a job that did not fail');
        $this->assertNotContains(AuditLog::ACTION_JOB_FINISHED, $this->auditActionsOf($job));
    }

    public function testTheRunnerGetsTheNextJobAfterARefusedOne(): void
    {
        $this->giveTemplateRule($this->template, $this->approvalRule());
        $refused = $this->launchedJob($this->template);
        $healthy = $this->launchedJob($this->templateOn($this->createInventory((int)$this->launcher->id)));

        $payload = $this->claimNext();

        $this->assertSame((int)$healthy->id, $payload['job_id'] ?? null);
        $this->assertSame(Job::STATUS_FAILED, $this->reloadJob($refused)->status);
    }

    public function testRefusalsCountTowardTheMaximumClaimAttempts(): void
    {
        $this->giveTemplateRule($this->template, $this->approvalRule());
        $jobs = [];
        for ($i = 0; $i <= JobClaimService::MAX_CLAIM_ATTEMPTS; $i++) {
            $jobs[] = $this->launchedJob($this->template);
        }

        $this->assertNull($this->claimNext());

        $expected = array_fill(0, JobClaimService::MAX_CLAIM_ATTEMPTS, Job::STATUS_FAILED);
        $expected[] = Job::STATUS_QUEUED;
        $this->assertSame($expected, array_map(fn (Job $job): string => $this->reloadJob($job)->status, $jobs));
    }

    /**
     * The job of a workflow job step that the claim refuses fails like any
     * failed job: its step fails and the run follows the step's On Failure
     * branch.
     */
    public function testARefusedWorkflowJobStepFollowsItsOnFailureBranch(): void
    {
        $this->recordClaimNotifications();
        $userId = (int)$this->launcher->id;
        $workflow = $this->createWorkflowTemplate($userId);
        $step = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$this->template->id);
        $onSuccess = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_PAUSE);
        $onFailure = $this->createWorkflowStep((int)$workflow->id, 2, WorkflowStep::TYPE_PAUSE);
        $this->branchTo($step, 'on_success_step_id', (int)$onSuccess->id);
        $this->branchTo($step, 'on_failure_step_id', (int)$onFailure->id);
        $run = $this->createWorkflowJob((int)$workflow->id, $userId);
        $run->current_step_id = $step->id;
        $run->save(false);
        $job = $this->launchedJob($this->template);
        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = $run->id;
        $execution->workflow_step_id = $step->id;
        $execution->job_id = $job->id;
        $execution->status = WorkflowJobStep::STATUS_RUNNING;
        $execution->started_at = time();
        $execution->save(false);
        // The step's job was queued before its template got a rule.
        $this->giveTemplateRule($this->template, $this->approvalRule());

        $this->assertNull($this->claimNext());

        $this->assertSame(Job::STATUS_FAILED, $this->reloadJob($job)->status);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $this->executionOfStep($run, $step)->status);
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $this->executionOfStep($run, $onFailure)->status, 'the run waits at its On Failure step');
        $this->assertNull($this->runOf($run, $onSuccess), 'never on the success route');
    }

    /**
     * The placeholder job of a workflow approval step has no job template,
     * so no runner group: a runner never claims it, queued or not.
     */
    public function testAnApprovalStepPlaceholderIsNeverClaimed(): void
    {
        $rule = $this->approvalRule();
        $placeholder = new Job();
        $placeholder->job_template_id = null;
        $placeholder->launched_by = (int)$this->launcher->id;
        $placeholder->status = Job::STATUS_QUEUED;
        $placeholder->timeout_minutes = 0;
        $placeholder->has_changes = 0;
        $placeholder->queued_at = time();
        $placeholder->created_at = time();
        $placeholder->updated_at = time();
        $placeholder->save(false);
        $this->approvalRequest($placeholder, $rule, ApprovalRequest::STATUS_APPROVED, ApprovalRequest::REQUIRED_BY_APPROVAL_STEP);

        $this->assertNull($this->claimNext());

        $placeholder = $this->reloadJob($placeholder);
        $this->assertSame(Job::STATUS_QUEUED, $placeholder->status);
        $this->assertNull($placeholder->runner_id);
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null, 2: string}>
     */
    public static function gateCaseProvider(): array
    {
        $cases = [];
        foreach ([null, 'A'] as $own) {
            foreach ([null, 'A', 'B'] as $current) {
                foreach (['its inventory', 'another inventory', 'a gated inventory', 'no inventory', 'a deleted inventory'] as $launchedWith) {
                    $name = sprintf('own rule %s, inventory rule %s, launched with %s', $own ?? 'none', $current ?? 'none', $launchedWith);
                    $cases[$name] = [$own, $current, $launchedWith];
                }
            }
        }

        return $cases;
    }

    /**
     * Drift guard: the claim refuses an unapproved job exactly when
     * JobTemplateGate calls its template gated (its own rule, else its
     * current inventory's) or the inventory it was launched with has a rule,
     * and names the rule in this order: the template's own, the launch
     * inventory's, the current inventory's.
     *
     * @dataProvider gateCaseProvider
     */
    public function testTheGateRefusesExactlyWhatTheTemplateGateOrTheLaunchInventoryRequires(?string $own, ?string $current, string $launchedWith): void
    {
        $rules = ['A' => $this->approvalRule(), 'B' => $this->approvalRule(), 'C' => $this->approvalRule()];
        $this->giveTemplateRule($this->template, $own === null ? null : $rules[$own]);
        $this->giveInventoryRule($this->inventory, $current === null ? null : $rules[$current]);
        [$launchInventoryId, $launchRule] = $this->launchInventory($launchedWith, $current === null ? null : $rules[$current], $rules['C']);
        $payload = ['project_id' => (int)$this->template->project_id, 'playbook' => 'site.yml'];
        $job = $this->jobWithPayload($this->template, Job::STATUS_QUEUED, $launchInventoryId === null ? $payload : $payload + ['inventory_id' => $launchInventoryId]);
        $template = JobTemplate::findOne($this->template->id);
        $this->assertNotNull($template);
        $expectRefusal = JobTemplateGate::of($template)->isGated() || $launchRule !== null;

        $refusal = $this->gateRefusal($job);

        $this->assertSame($expectRefusal, $refusal !== null, 'the claim gate agrees with JobTemplateGate');
        $gate = match (true) {
            $own !== null => [$rules[$own], ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE, null],
            $launchRule !== null => [$launchRule, ApprovalRequest::REQUIRED_BY_INVENTORY, $launchInventoryId],
            $current !== null => [$rules[$current], ApprovalRequest::REQUIRED_BY_INVENTORY, (int)$this->inventory->id],
            default => null,
        };
        $this->assertSame(
            $gate === null ? null : $this->notApprovedContext($gate[0], $gate[1], $gate[2]),
            $refusal?->auditContext()
        );
    }

    /**
     * The gate reads without locks, in at most three statements, all in one
     * transaction (JobClaimGateSnapshotTest pins it outside a transaction;
     * inside the one of DbTestCase it is a savepoint): the template, both
     * inventories in one statement in ascending ID order, and the request of
     * a gated job.
     */
    public function testTheGateReadsTheTemplateBothInventoriesInOneStatementAndTheRequest(): void
    {
        $launched = $this->createInventory((int)$this->launcher->id);
        $job = $this->launchedJob($this->template, Job::STATUS_QUEUED, $launched);
        $this->giveInventoryRule($this->inventory, $this->approvalRule());
        $ids = [(int)$launched->id, (int)$this->inventory->id];
        sort($ids);

        $statements = $this->statementsOf(fn () => $this->gateRefusal($job));

        $this->assertSame([
            self::GATE_TRANSACTION_BEGINS,
            "SELECT `approval_rule_id`, `inventory_id` FROM `job_template` WHERE `id` = {$this->template->id}",
            "SELECT * FROM `inventory` WHERE `id` IN ({$ids[0]}, {$ids[1]}) ORDER BY `id`",
            "SELECT `id`, `status` FROM `approval_request` WHERE `job_id` = {$job->id}",
            self::GATE_TRANSACTION_ENDS,
        ], $statements);
    }

    public function testAJobWithoutARuleReadsNoRequest(): void
    {
        $job = $this->launchedJob($this->template);

        $statements = $this->statementsOf(fn () => $this->assertNull($this->gateRefusal($job)));

        $this->assertSame([
            self::GATE_TRANSACTION_BEGINS,
            "SELECT `approval_rule_id`, `inventory_id` FROM `job_template` WHERE `id` = {$this->template->id}",
            "SELECT * FROM `inventory` WHERE `id` IN ({$this->inventory->id}) ORDER BY `id`",
            self::GATE_TRANSACTION_ENDS,
        ], $statements);
    }

    /**
     * The template's own rule comes first, so its inventories are not read.
     */
    public function testAJobWhoseTemplateHasItsOwnRuleReadsNoInventory(): void
    {
        $rule = $this->approvalRule();
        $this->giveTemplateRule($this->template, $rule);
        $job = $this->launchedJob($this->template);
        $this->approvalRequest($job, $rule, ApprovalRequest::STATUS_APPROVED);

        $statements = $this->statementsOf(fn () => $this->assertNull($this->gateRefusal($job), 'approved'));

        $this->assertSame([
            self::GATE_TRANSACTION_BEGINS,
            "SELECT `approval_rule_id`, `inventory_id` FROM `job_template` WHERE `id` = {$this->template->id}",
            "SELECT `id`, `status` FROM `approval_request` WHERE `job_id` = {$job->id}",
            self::GATE_TRANSACTION_ENDS,
        ], $statements);
    }

    /**
     * A job without a job template (the claim takes none, but the gate is
     * public) is gated by the inventory it was launched with only.
     */
    public function testAJobWithoutATemplateIsGatedByItsLaunchInventoryOnly(): void
    {
        $rule = $this->approvalRule();
        $this->giveInventoryRule($this->inventory, $rule);
        $job = $this->launchedJob($this->template);
        $job->job_template_id = null;

        $statements = $this->statementsOf(function () use ($job, &$refusal): void {
            $refusal = $this->gateRefusal($job);
        });

        $this->assertInstanceOf(JobClaimRefusedException::class, $refusal);
        $this->assertSame(
            ['claim_refusal' => 'not_approved', 'approval_rule_id' => (int)$rule->id, 'required_by' => 'inventory', 'inventory_id' => (int)$this->inventory->id],
            $refusal->auditContext()
        );
        $this->assertSame([
            self::GATE_TRANSACTION_BEGINS,
            "SELECT * FROM `inventory` WHERE `id` IN ({$this->inventory->id}) ORDER BY `id`",
            "SELECT `id`, `status` FROM `approval_request` WHERE `job_id` = {$job->id}",
            self::GATE_TRANSACTION_ENDS,
        ], $statements);
    }

    /**
     * A template row that is gone has no rule of its own and no current
     * inventory; the launch inventory still counts.
     */
    public function testATemplateRowThatIsGoneGatesNothingItself(): void
    {
        $job = $this->launchedJob($this->template);
        $job->job_template_id = (int)(new Query())->from(JobTemplate::tableName())->max('id') + 1000;

        $this->assertNull($this->gateRefusal($job));

        $rule = $this->approvalRule();
        $this->giveInventoryRule($this->inventory, $rule);
        $refusal = $this->gateRefusal($job);
        $this->assertNotNull($refusal);
        $this->assertSame((int)$rule->id, $refusal->auditContext()['approval_rule_id']);
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * What the refusal of an unapproved job of the fixtures' template
     * records (JobClaimRefusedException::auditContext()); job.finished has
     * it after its three base keys.
     *
     * @return array<string, int|string>
     */
    private function notApprovedContext(ApprovalRule $rule, string $requiredBy, ?int $inventoryId): array
    {
        return ['claim_refusal' => 'not_approved', 'approval_rule_id' => (int)$rule->id, 'required_by' => $requiredBy, 'job_template_id' => (int)$this->template->id]
            + ($inventoryId === null ? [] : ['inventory_id' => $inventoryId]);
    }

    /**
     * The template (own rule) or its inventory gets $rule.
     */
    private function gateBy(string $requiredBy, ApprovalRule $rule): void
    {
        if ($requiredBy === ApprovalRequest::REQUIRED_BY_INVENTORY) {
            $this->giveInventoryRule($this->inventory, $rule);
            return;
        }
        $this->giveTemplateRule($this->template, $rule);
    }

    /**
     * The inventory a job of the drift guard was launched with: its ID (null
     * for none) and its rule.
     *
     * @return array{0: int|null, 1: ApprovalRule|null}
     */
    private function launchInventory(string $launchedWith, ?ApprovalRule $currentRule, ApprovalRule $otherRule): array
    {
        $userId = (int)$this->launcher->id;
        switch ($launchedWith) {
            case 'its inventory':
                return [(int)$this->inventory->id, $currentRule];
            case 'another inventory':
                return [(int)$this->createInventory($userId)->id, null];
            case 'a gated inventory':
                $gated = $this->createInventory($userId);
                $this->giveInventoryRule($gated, $otherRule);
                return [(int)$gated->id, $otherRule];
            case 'a deleted inventory':
                $deleted = $this->createInventory($userId);
                $deleted->delete();
                return [(int)$deleted->id, null];
            default:
                return [null, null];
        }
    }

    /**
     * The gate's refusal of $job, as its payload records it; null when it may run.
     */
    private function gateRefusal(Job $job): ?JobClaimRefusedException
    {
        try {
            (new JobClaimGate())->check($job, $job->decodedRunnerPayload());
        } catch (JobClaimRefusedException $e) {
            return $e;
        }

        return null;
    }

    private function settlement(): ApprovalSettlement
    {
        /** @var ApprovalSettlement $settlement */
        $settlement = \Yii::$app->get('approvalSettlement');

        return $settlement;
    }
}
