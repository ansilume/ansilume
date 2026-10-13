<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Job;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\ApprovalService;
use app\services\ApproverEligibility;
use app\services\WorkflowStepDispatcher;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\log\Logger;

/**
 * How an approval step starts (WorkflowStepDispatcher): the placeholder job
 * and its approval request are written together, or neither; a step whose
 * approvers can no longer decide when the run reaches it fails there without
 * either, and without following its branches.
 */
class WorkflowExecutionDispatchTest extends DbTestCase
{
    use TeamScopeFixtures;
    use WorkflowEngineFixtures;

    /** @var list<array{0: string, 1: mixed}> */
    private array $swapped = [];

    private User $launcher;
    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->launcher = $this->operator('wf-dispatch-launcher');
        $this->approver = $this->operator('wf-dispatch-approver');
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->swapped) as [$id, $original]) {
            \Yii::$app->set($id, $original);
        }
        $this->swapped = [];
        parent::tearDown();
    }

    /**
     * The launch found an approver besides the launcher; by the time the run
     * reaches the step, that approver may no longer decide (here: the
     * account was disabled while the run waited at a pause step). The step
     * fails with why, without a placeholder job or a request nobody could
     * approve, and its failure branch is not followed.
     */
    public function testAnApprovalStepWhoseApproversCanNoLongerDecideFailsThere(): void
    {
        ['run' => $run, 'rule' => $rule, 'gate' => $gate, 'branch' => $branch] = $this->runBeforeAnApprovalStep();
        User::updateAll(['status' => User::STATUS_INACTIVE], ['id' => $this->approver->id]);

        $this->engine()->resume($run, (int)$this->launcher->id);

        $execution = $this->executionOfStep($run, $gate);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(
            sprintf(ApproverEligibility::REFUSAL_WORKFLOW_STEP, json_encode($rule->name), 1, $this->launcher->id, 0),
            $execution->error_message
        );
        $this->assertNull($execution->job_id);
        $this->assertFalse(ApprovalRequest::find()->where(['approval_rule_id' => $rule->id])->exists());
        $this->assertNull($this->runOf($run, $branch), 'the failure branch is not followed');
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
    }

    /**
     * The placeholder job and its request are one transaction, so a cancel
     * never finds the placeholder without its request; the request is
     * announced (approval.requested) only after the commit.
     */
    public function testThePlaceholderAndItsRequestAreWrittenInOneTransaction(): void
    {
        ['run' => $run, 'gate' => $gate] = $this->runBeforeAnApprovalStep();

        $statements = $this->statementsOf(fn () => $this->engine()->resume($run, (int)$this->launcher->id));

        $placeholder = $this->jobOfStep($run, $gate);
        $request = $this->requestOfJob($placeholder);
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $placeholder->status);
        $this->assertNull($placeholder->job_template_id);
        $savepoint = $this->statementIndex($statements, '~^SAVEPOINT LEVEL\d+$~');
        $job = $this->statementIndex($statements, '~^INSERT INTO `job`~');
        $link = $this->statementIndex($statements, '~^UPDATE `workflow_job_step` SET `job_id`=' . $placeholder->id . '~');
        $insert = $this->statementIndex($statements, '~^INSERT INTO `approval_request`~');
        $release = $this->statementIndex($statements, '~^RELEASE SAVEPOINT LEVEL\d+$~');
        $announced = $this->statementIndex($statements, '~^INSERT INTO `audit_log` .*approval\.requested~s');
        $this->assertTrue(
            $savepoint < $job && $job < $link && $link < $insert && $insert < $release && $release < $announced,
            implode("\n", $statements)
        );
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status);
        $this->assertCount(1, $this->auditEntries(AuditLog::ACTION_APPROVAL_REQUESTED, 'approval_request', (int)$request->id));
    }

    /**
     * @return array<string, array{0: \Throwable}>
     */
    public static function requestFailureProvider(): array
    {
        return [
            'the request does not validate' => [new \RuntimeException('Failed to create approval request: {"job_id":["invalid"]}')],
            'a database error' => [new \yii\db\Exception('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found')],
        ];
    }

    /**
     * When the request cannot be written, the placeholder goes with the
     * rollback: the step fails with a generic reason (the log has the cause)
     * instead of waiting for a decision nobody can make.
     *
     * @dataProvider requestFailureProvider
     */
    public function testAStepWhoseRequestCannotBeWrittenFailsWithoutAPlaceholder(\Throwable $failure): void
    {
        ['run' => $run, 'gate' => $gate] = $this->runBeforeAnApprovalStep();
        $jobsBefore = (int)Job::find()->count();
        $this->swap('approvalService', new class ($failure) extends ApprovalService {
            public function __construct(private \Throwable $failure)
            {
                parent::__construct();
            }

            public function openRequest(Job $job, ApprovalRule $rule): ApprovalRequest
            {
                throw $this->failure;
            }
        });

        $this->engine()->resume($run, (int)$this->launcher->id);

        $execution = $this->executionOfStep($run, $gate);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(WorkflowStepDispatcher::REQUEST_FAILED, $execution->error_message);
        $this->assertNull($execution->job_id);
        $this->assertSame($jobsBefore, (int)Job::find()->count(), 'the placeholder was rolled back');
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
    }

    /**
     * Regression: when the approval request of a step could not be written,
     * the log line carried the database error's message, which quotes the
     * SQL with its values. It names the error's class, SQLSTATE and driver
     * code now (REQUEST_FAILED_LOG).
     */
    public function testRegressionAFailedApprovalRequestOfAWorkflowStepLoggedItsSql(): void
    {
        ['run' => $run, 'gate' => $gate] = $this->runBeforeAnApprovalStep();
        $failure = new \yii\db\Exception(
            "SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; The SQL being executed was: INSERT INTO `approval_request` VALUES ('marker-4711')",
            ['HY000', 1205, 'Lock wait timeout exceeded']
        );
        $this->swap('approvalService', new class ($failure) extends ApprovalService {
            public function __construct(private \Throwable $failure)
            {
                parent::__construct();
            }

            public function openRequest(Job $job, ApprovalRule $rule): ApprovalRequest
            {
                throw $this->failure;
            }
        });

        $errors = $this->dispatcherErrorsOf(fn () => $this->engine()->resume($run, (int)$this->launcher->id));

        $this->assertCount(1, $errors);
        $this->assertStringNotContainsString('marker-4711', $errors[0], 'the SQL with its values is not logged');
        $execution = $this->executionOfStep($run, $gate);
        $this->assertSame(WorkflowStepDispatcher::REQUEST_FAILED, $execution->error_message);
        $this->assertSame(
            [sprintf(WorkflowStepDispatcher::REQUEST_FAILED_LOG, $run->id, $execution->id, \yii\db\Exception::class, 'HY000', '1205')],
            $errors
        );
    }

    /**
     * An error while the rule's approvers are checked (a lost connection)
     * fails the step like one while its request is written: the run ends as
     * failed instead of staying active with a step that waits for nothing.
     */
    public function testAnErrorWhileTheApproversAreCheckedFailsTheStep(): void
    {
        ['run' => $run, 'gate' => $gate, 'branch' => $branch] = $this->runBeforeAnApprovalStep();
        $failure = new \yii\db\Exception('SQLSTATE[HY000]: 2006 gone away', ['HY000', 2006, 'MySQL server has gone away']);
        $this->swap('approverEligibility', new class ($failure) extends ApproverEligibility {
            public function __construct(private \Throwable $failure)
            {
                parent::__construct();
            }

            public function workflowStepRefusal(ApprovalRule $rule, WorkflowJob $run): ?string
            {
                throw $this->failure;
            }
        });

        $errors = $this->dispatcherErrorsOf(fn () => $this->engine()->resume($run, (int)$this->launcher->id));

        $execution = $this->executionOfStep($run, $gate);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(WorkflowStepDispatcher::REQUEST_FAILED, $execution->error_message);
        $this->assertNull($execution->job_id);
        $this->assertNull($this->runOf($run, $branch), 'the failure branch is not followed');
        $this->assertSame(
            [sprintf(WorkflowStepDispatcher::REQUEST_FAILED_LOG, $run->id, $execution->id, \yii\db\Exception::class, 'HY000', '2006')],
            $errors
        );
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
    }

    /**
     * A request that was written but could not be announced (its audit
     * entry or notification failed) waits for the decision all the same:
     * the step keeps running with its placeholder and the pending request.
     */
    public function testARequestWhoseAnnouncementFailedStillWaits(): void
    {
        ['run' => $run, 'gate' => $gate] = $this->runBeforeAnApprovalStep();
        $failure = new \yii\db\Exception('SQLSTATE[HY000]: 2006 gone away', ['HY000', 2006, 'MySQL server has gone away']);
        $this->swap('approvalService', new class ($failure) extends ApprovalService {
            public function __construct(private \Throwable $failure)
            {
                parent::__construct();
            }

            public function announceRequest(ApprovalRequest $request, Job $job, ApprovalRule $rule): void
            {
                throw $this->failure;
            }
        });

        $errors = $this->dispatcherErrorsOf(fn () => $this->engine()->resume($run, (int)$this->launcher->id));

        $execution = $this->executionOfStep($run, $gate);
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $execution->status);
        $placeholder = $this->jobOfStep($run, $gate);
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $placeholder->status);
        $request = $this->requestOfJob($placeholder);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status);
        $this->assertSame(
            [sprintf(WorkflowStepDispatcher::REQUEST_ANNOUNCE_FAILED_LOG, $run->id, $request->id, $execution->id, \yii\db\Exception::class, 'HY000', '2006')],
            $errors
        );
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status);
    }

    /**
     * The error lines WorkflowStepDispatcher logged while $action ran.
     *
     * @return list<string>
     */
    private function dispatcherErrorsOf(callable $action): array
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
        $errors = [];
        foreach ($logger->messages as $message) {
            if ($message[1] === Logger::LEVEL_ERROR && $message[2] === WorkflowStepDispatcher::class && is_string($message[0])) {
                $errors[] = $message[0];
            }
        }

        return $errors;
    }

    /**
     * A run at a pause step, followed by an approval step ("gate") that the
     * approver may decide, with a failure branch ("branch", a pause step).
     *
     * @return array{run: WorkflowJob, rule: ApprovalRule, gate: WorkflowStep, branch: WorkflowStep}
     */
    private function runBeforeAnApprovalStep(): array
    {
        $rule = $this->usersRuleOf((int)$this->launcher->id, (int)$this->approver->id);
        $workflow = $this->createWorkflowTemplate((int)$this->launcher->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $gate = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $branch = $this->createWorkflowStep((int)$workflow->id, 2, WorkflowStep::TYPE_PAUSE);
        $this->branchTo($gate, 'on_failure_step_id', (int)$branch->id);

        return ['run' => $this->engine()->launch($workflow, (int)$this->launcher->id), 'rule' => $rule, 'gate' => $gate, 'branch' => $branch];
    }

    private function swap(string $id, object $replacement): void
    {
        $this->swapped[] = [$id, \Yii::$app->getComponents(true)[$id] ?? null];
        \Yii::$app->set($id, $replacement);
    }
}
