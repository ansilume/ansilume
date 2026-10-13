<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\WorkflowExecutionService;
use yii\log\Logger;

/**
 * Fixtures of the workflow engine tests; use in DbTestCase subclasses
 * together with TeamScopeFixtures.
 *
 * Approvers hold the operator role (approval.decide) and are never the user
 * who launches, so the launch pre-flight lets their approvals through.
 * Decisions are applied as ApprovalService applies them (contract C1 of
 * step 3): the request's final status first, then the job, then the engine.
 */
trait WorkflowEngineFixtures
{
    protected function engine(): WorkflowExecutionService
    {
        /** @var WorkflowExecutionService $engine */
        $engine = \Yii::$app->get('workflowExecutionService');

        return $engine;
    }

    protected function operator(string $suffix): User
    {
        return $this->createUserWithRole($suffix, 'operator');
    }

    /**
     * A job template in a project of its own, without a team, so every
     * operator may launch it.
     */
    protected function openTemplate(int $userId): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    /**
     * A rule whose approvers are exactly $approverIds; one approval needed.
     */
    protected function usersRuleOf(int $createdBy, int ...$approverIds): ApprovalRule
    {
        return $this->createApprovalRule(
            $createdBy,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => array_values($approverIds)]),
            1
        );
    }

    /**
     * An open job template whose jobs wait for $rule.
     */
    protected function gatedTemplate(int $userId, ApprovalRule $rule): JobTemplate
    {
        $template = $this->openTemplate($userId);
        JobTemplate::updateAll(['approval_rule_id' => $rule->id], ['id' => $template->id]);
        $template->refresh();

        return $template;
    }

    /**
     * Point the branch $field (on_success_step_id, on_failure_step_id,
     * on_always_step_id) of $from at $to, as stored: a step ID, 0 for the end
     * of the workflow, null for the next step.
     */
    protected function branchTo(WorkflowStep $from, string $field, ?int $to): void
    {
        WorkflowStep::updateAll([$field => $to], ['id' => $from->id]);
        $from->refresh();
    }

    /**
     * The (last) execution of $step in $run; null when the step has not run.
     */
    protected function runOf(WorkflowJob $run, WorkflowStep $step): ?WorkflowJobStep
    {
        /** @var WorkflowJobStep|null $execution */
        $execution = WorkflowJobStep::find()
            ->where(['workflow_job_id' => $run->id, 'workflow_step_id' => $step->id])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        return $execution;
    }

    protected function executionOfStep(WorkflowJob $run, WorkflowStep $step): WorkflowJobStep
    {
        $execution = $this->runOf($run, $step);
        $this->assertNotNull($execution, "step {$step->name} ran");

        return $execution;
    }

    protected function jobOfStep(WorkflowJob $run, WorkflowStep $step): Job
    {
        /** @var Job|null $job */
        $job = Job::findOne(['id' => $this->executionOfStep($run, $step)->job_id]);
        $this->assertNotNull($job, "step {$step->name} has a job");

        return $job;
    }

    protected function requestOfJob(Job $job): ApprovalRequest
    {
        /** @var ApprovalRequest|null $request */
        $request = ApprovalRequest::findOne(['job_id' => $job->id]);
        $this->assertNotNull($request, "job #{$job->id} has an approval request");

        return $request;
    }

    /**
     * The runner reported: $job ended with $status, and the engine hears it.
     */
    protected function finishJob(Job $job, string $status = Job::STATUS_SUCCEEDED): void
    {
        Job::updateAll(['status' => $status, 'finished_at' => time()], ['id' => $job->id]);
        $job->refresh();
        $this->engine()->onChildJobCompleted($job);
    }

    /**
     * The approval request of $job was decided as ApprovalService decides it:
     * approved, an approval step's placeholder succeeds and the engine hears
     * it; any other job is queued, and the engine hears nothing. Rejected or
     * timed out (rejecting), the job is rejected and the engine hears it.
     */
    protected function decide(Job $job, string $requestStatus): void
    {
        ApprovalRequest::updateAll(['status' => $requestStatus, 'resolved_at' => time()], ['job_id' => $job->id]);
        $approved = $requestStatus === ApprovalRequest::STATUS_APPROVED;
        $execution = WorkflowJobStep::findOne(['job_id' => $job->id]);
        $placeholder = $execution !== null && $execution->isApprovalPlaceholder();
        if ($approved && !$placeholder) {
            Job::updateAll(['status' => Job::STATUS_QUEUED, 'queued_at' => time()], ['id' => $job->id]);
            $job->refresh();
            return;
        }
        Job::updateAll(
            ['status' => $approved ? Job::STATUS_SUCCEEDED : Job::STATUS_REJECTED, 'finished_at' => time()],
            ['id' => $job->id]
        );
        $job->refresh();
        $this->engine()->onApprovalResolved($job, $approved);
    }

    /**
     * @return list<AuditLog>
     */
    protected function auditEntries(string $action, string $objectType, int $objectId): array
    {
        /** @var list<AuditLog> $entries */
        $entries = AuditLog::find()
            ->where(['action' => $action, 'object_type' => $objectType, 'object_id' => $objectId])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    protected function metadataOf(AuditLog $entry): array
    {
        $decoded = json_decode((string)$entry->metadata, true);
        $this->assertIsArray($decoded);

        return $decoded;
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
     * The index of the first statement that matches $pattern.
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
