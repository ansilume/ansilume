<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\ApprovalDecisionRefusedException;
use app\services\ApprovalService;
use app\services\ApprovalSettlement;
use app\services\WorkflowExecutionService;
use yii\log\Logger;

/**
 * Fixtures of the approval vote and timeout tests; use in DbTestCase
 * subclasses together with TeamScopeFixtures.
 *
 * Approvers hold the operator role, so they may decide (approval.decide);
 * the launcher is a plain user unless a test passes one.
 */
trait ApprovalVoteFixtures
{
    /** An approver: an operator, who holds approval.decide. */
    protected function approverUser(string $suffix = 'approver'): User
    {
        return $this->createUserWithRole($suffix, 'operator');
    }

    protected function voteTemplate(int $userId): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    /**
     * A rule whose approvers are exactly $approvers.
     *
     * @param list<User> $approvers
     */
    protected function listRule(int $createdBy, array $approvers, int $required = 1): ApprovalRule
    {
        return $this->createApprovalRule(
            $createdBy,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => array_map(static fn (User $u): int => (int)$u->id, $approvers)]),
            $required
        );
    }

    /**
     * A job waiting for approval and its pending request, created as a
     * launch creates them. The rule lists $approvers.
     *
     * @param list<User> $approvers
     * @return array{job: Job, request: ApprovalRequest, rule: ApprovalRule, launcher: User}
     */
    protected function waitingRequest(array $approvers, int $required = 1, ?User $launcher = null): array
    {
        $launcher ??= $this->createUser('vote-launcher');
        $template = $this->voteTemplate((int)$launcher->id);
        $job = $this->createJob((int)$template->id, (int)$launcher->id, Job::STATUS_PENDING_APPROVAL);
        $job->queued_at = null;
        $job->save(false);
        $rule = $this->listRule((int)$launcher->id, $approvers, $required);
        $request = $this->approvals()->createRequest($job, $rule);

        return ['job' => $job, 'request' => $request, 'rule' => $rule, 'launcher' => $launcher];
    }

    /**
     * A running workflow run with one running execution of a step of
     * $stepType (approval or job) whose job waits for approval by $rule;
     * built by hand, so the test does not depend on how the engine
     * launches.
     *
     * @return array{run: WorkflowJob, step: WorkflowStep, execution: WorkflowJobStep, job: Job, request: ApprovalRequest, next: WorkflowStep}
     */
    protected function workflowStepWaiting(string $stepType, ApprovalRule $rule, User $launcher): array
    {
        $launcherId = (int)$launcher->id;
        $template = $this->voteTemplate($launcherId);
        $workflow = $this->createWorkflowTemplate($launcherId);
        $isJob = $stepType === WorkflowStep::TYPE_JOB;
        $step = $this->createWorkflowStep(
            (int)$workflow->id,
            0,
            $stepType,
            $isJob ? (int)$template->id : null,
            $isJob ? null : (int)$rule->id
        );
        $next = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_PAUSE);
        if ($isJob) {
            $template->approval_rule_id = (int)$rule->id;
            $template->save(false);
        }
        $run = $this->createWorkflowJob((int)$workflow->id, $launcherId);

        $job = new Job();
        $job->job_template_id = $isJob ? (int)$template->id : null;
        $job->launched_by = $launcherId;
        $job->status = Job::STATUS_PENDING_APPROVAL;
        $job->timeout_minutes = $isJob ? 120 : 0;
        $job->has_changes = 0;
        $job->created_at = time();
        $job->updated_at = time();
        $job->save(false);

        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = (int)$run->id;
        $execution->workflow_step_id = (int)$step->id;
        $execution->job_id = (int)$job->id;
        $execution->status = WorkflowJobStep::STATUS_RUNNING;
        $execution->started_at = time();
        $execution->created_at = time();
        $execution->updated_at = time();
        $execution->save(false);

        $request = $this->approvals()->createRequest($job, $rule);

        return ['run' => $run, 'step' => $step, 'execution' => $execution, 'job' => $job, 'request' => $request, 'next' => $next];
    }

    /**
     * $voter decides; the refusal, if any.
     */
    protected function voteAs(ApprovalRequest $request, User $voter, string $decision, ?string $comment = null): ?ApprovalDecisionRefusedException
    {
        try {
            $this->approvals()->recordDecision($request, (int)$voter->id, $decision, $comment);
        } catch (ApprovalDecisionRefusedException $e) {
            return $e;
        }

        return null;
    }

    /**
     * $voter's vote is refused for $reason, with $message (or, when null,
     * any message), and the request keeps its votes.
     */
    protected function assertVoteRefused(
        ApprovalRequest $request,
        User $voter,
        string $decision,
        string $reason,
        ?string $message = null
    ): ApprovalDecisionRefusedException {
        $before = $this->decisionCount($request);
        $refusal = $this->voteAs($request, $voter, $decision);
        $this->assertNotNull($refusal, 'the vote is refused');
        $this->assertSame($reason, $refusal->reason());
        if ($message !== null) {
            $this->assertSame($message, $refusal->getMessage());
        }
        $this->assertSame($before, $this->decisionCount($request), 'no vote is recorded');

        return $refusal;
    }

    /**
     * Let the request's time run out $secondsAgo seconds ago, with
     * $timeoutAction as the rule's timeout action.
     */
    protected function expireRequest(ApprovalRequest $request, int $secondsAgo, string $timeoutAction = ApprovalRule::TIMEOUT_ACTION_REJECT): void
    {
        $rule = ApprovalRule::findOne($request->approval_rule_id);
        $this->assertNotNull($rule);
        $rule->timeout_minutes = 5;
        $rule->timeout_action = $timeoutAction;
        $rule->save(false);
        $request->expires_at = time() - $secondsAgo;
        $request->save(false);
    }

    protected function decisionCount(ApprovalRequest $request): int
    {
        return (int)ApprovalDecision::find()->where(['approval_request_id' => $request->id])->count();
    }

    protected function reloadJob(Job $job): Job
    {
        $stored = Job::findOne($job->id);
        $this->assertNotNull($stored);

        return $stored;
    }

    protected function reloadRequest(ApprovalRequest $request): ApprovalRequest
    {
        $stored = ApprovalRequest::findOne($request->id);
        $this->assertNotNull($stored);

        return $stored;
    }

    protected function approvals(): ApprovalService
    {
        /** @var ApprovalService $service */
        $service = \Yii::$app->get('approvalService');

        return $service;
    }

    protected function settlement(): ApprovalSettlement
    {
        /** @var ApprovalSettlement $settlement */
        $settlement = \Yii::$app->get('approvalSettlement');

        return $settlement;
    }

    /**
     * Run $action while the workflow engine's onApprovalResolved() throws
     * $failure (the advance after an approval step's vote or timeout).
     *
     * @return array{result: mixed, errors: list<string>} what $action returned, and the errors
     *     ApprovalService logged meanwhile
     */
    protected function withFailingWorkflowAdvance(\Throwable $failure, callable $action): array
    {
        $previous = \Yii::$app->get('workflowExecutionService');
        \Yii::$app->set('workflowExecutionService', new class ($failure) extends WorkflowExecutionService {
            public function __construct(private readonly \Throwable $failure)
            {
                parent::__construct();
            }

            public function onApprovalResolved(Job $job, bool $approved): void
            {
                throw $this->failure;
            }
        });
        try {
            return $this->loggedErrorsOf($action);
        } finally {
            \Yii::$app->set('workflowExecutionService', $previous);
        }
    }

    /**
     * @return array{result: mixed, errors: list<string>} what $action returned, and the errors
     *     ApprovalService logged meanwhile
     */
    protected function loggedErrorsOf(callable $action): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $result = $action();
        } finally {
            \Yii::setLogger($previous);
        }
        $errors = [];
        foreach ($logger->messages as $message) {
            if ($message[1] === Logger::LEVEL_ERROR && $message[2] === ApprovalService::class && is_string($message[0])) {
                $errors[] = $message[0];
            }
        }

        return ['result' => $result, 'errors' => $errors];
    }

    /**
     * A database error as PDO reports a lost connection: its message quotes
     * the statement with a value, which must never be logged.
     */
    protected function lostConnection(): \yii\db\Exception
    {
        return new \yii\db\Exception(
            'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away; '
            . 'The SQL being executed was: UPDATE x SET secret = \'value-that-must-not-be-logged\'',
            ['HY000', 2006, 'MySQL server has gone away']
        );
    }

    /**
     * The SQL statements $action sends, in order.
     *
     * @return list<string>
     */
    protected function statementsOf(callable $action): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $action();
        } finally {
            \Yii::setLogger($previous);
        }
        $statements = [];
        foreach ($logger->messages as $message) {
            if (
                in_array($message[2], ['yii\db\Command::query', 'yii\db\Command::execute'], true)
                && $message[1] === Logger::LEVEL_INFO
                && is_string($message[0])
            ) {
                $statements[] = $message[0];
            }
        }

        return $statements;
    }

    /**
     * The index of the first statement matching $pattern.
     *
     * @param list<string> $statements
     */
    protected function statementIndex(array $statements, string $pattern): int
    {
        foreach ($statements as $index => $statement) {
            if (preg_match($pattern, $statement) === 1) {
                return $index;
            }
        }
        $this->fail("No statement matches {$pattern}:\n" . implode("\n", $statements));
    }
}
