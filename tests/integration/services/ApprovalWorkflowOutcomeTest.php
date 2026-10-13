<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\User;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\WorkflowExecutionService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * What a decision does to the job of a workflow step, and what the engine
 * is told (contract C1 with the workflow engine):
 * - the placeholder of an approval step: approved, it succeeds and the
 *   engine hears onApprovalResolved(job, true); rejected, it is rejected
 *   and the engine hears onApprovalResolved(job, false);
 * - the job of a job step whose template waits for approval: approved, it
 *   is queued and the engine hears nothing (the step ends with the job);
 *   rejected, it is rejected and the engine hears onApprovalResolved(job, false);
 * - a job outside a workflow: the engine hears nothing.
 * The engine is called after the request's status committed; timeouts
 * call it the same way.
 */
class ApprovalWorkflowOutcomeTest extends DbTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;
    use ApprovalVoteFixtures;

    /** @var list<array{job: int, approved: bool, request: string|null, level: int|null}> */
    public array $engineCalls = [];

    private mixed $engineBefore = null;

    private User $launcher;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordNotifications();
        $this->launcher = $this->approverUser('wf-launcher');
        $this->approver = $this->approverUser('wf-approver');
    }

    protected function tearDown(): void
    {
        if ($this->engineBefore !== null) {
            \Yii::$app->set('workflowExecutionService', $this->engineBefore);
            $this->engineBefore = null;
        }
        $this->restoreNotifications();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: list<bool>}>
     */
    public static function voteProvider(): array
    {
        return [
            'approval step, approved' => [WorkflowStep::TYPE_APPROVAL, ApprovalDecision::DECISION_APPROVED, Job::STATUS_SUCCEEDED, [true]],
            'approval step, rejected' => [WorkflowStep::TYPE_APPROVAL, ApprovalDecision::DECISION_REJECTED, Job::STATUS_REJECTED, [false]],
            'gated job step, approved' => [WorkflowStep::TYPE_JOB, ApprovalDecision::DECISION_APPROVED, Job::STATUS_QUEUED, []],
            'gated job step, rejected' => [WorkflowStep::TYPE_JOB, ApprovalDecision::DECISION_REJECTED, Job::STATUS_REJECTED, [false]],
        ];
    }

    /**
     * @dataProvider voteProvider
     * @param list<bool> $calls what the engine is told
     */
    public function testAVoteOnAWorkflowStepsJob(string $stepType, string $decision, string $jobStatus, array $calls): void
    {
        $flow = $this->workflowStepWaiting($stepType, $this->rule(), $this->launcher);
        $this->recordEngineCalls();

        $this->approvals()->recordDecision($flow['request'], (int)$this->approver->id, $decision);

        $job = $this->reloadJob($flow['job']);
        $this->assertSame($jobStatus, $job->status);
        $this->assertSame($jobStatus === Job::STATUS_QUEUED, $job->queued_at !== null && $job->finished_at === null);
        $this->assertSame($calls, array_column($this->engineCalls, 'approved'));
        foreach ($this->engineCalls as $call) {
            $this->assertSame((int)$job->id, $call['job']);
            $this->assertSame($call['approved'] ? 'approved' : 'rejected', $call['request'], 'the request\'s status is written first');
            $this->assertSame(1, $call['level'], 'the engine runs after the vote\'s transaction (only the test\'s own is open)');
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: list<bool>}>
     */
    public static function timeoutProvider(): array
    {
        return [
            'approval step, approve' => [WorkflowStep::TYPE_APPROVAL, ApprovalRule::TIMEOUT_ACTION_APPROVE, Job::STATUS_SUCCEEDED, [true]],
            'approval step, reject' => [WorkflowStep::TYPE_APPROVAL, ApprovalRule::TIMEOUT_ACTION_REJECT, Job::STATUS_REJECTED, [false]],
            'gated job step, approve' => [WorkflowStep::TYPE_JOB, ApprovalRule::TIMEOUT_ACTION_APPROVE, Job::STATUS_QUEUED, []],
            'gated job step, reject' => [WorkflowStep::TYPE_JOB, ApprovalRule::TIMEOUT_ACTION_REJECT, Job::STATUS_REJECTED, [false]],
        ];
    }

    /**
     * @dataProvider timeoutProvider
     * @param list<bool> $calls what the engine is told
     */
    public function testATimeoutOfAWorkflowStepsJob(string $stepType, string $action, string $jobStatus, array $calls): void
    {
        $flow = $this->workflowStepWaiting($stepType, $this->rule(), $this->launcher);
        $this->expireRequest($flow['request'], 60, $action);
        $this->recordEngineCalls();

        $this->assertSame(1, $this->approvals()->processTimeouts());

        $this->assertSame($jobStatus, $this->reloadJob($flow['job'])->status);
        $this->assertSame($calls, array_column($this->engineCalls, 'approved'));
        foreach ($this->engineCalls as $call) {
            $this->assertSame(ApprovalRequest::STATUS_TIMED_OUT, $call['request']);
            $this->assertSame(1, $call['level']);
        }
    }

    public function testAJobOutsideAWorkflowTellsTheEngineNothing(): void
    {
        ['job' => $approvedJob, 'request' => $approved] = $this->waitingRequest([$this->approver]);
        ['job' => $rejectedJob, 'request' => $rejected] = $this->waitingRequest([$this->approver]);
        $this->recordEngineCalls();

        $this->approvals()->recordDecision($approved, (int)$this->approver->id, ApprovalDecision::DECISION_APPROVED);
        $this->approvals()->recordDecision($rejected, (int)$this->approver->id, ApprovalDecision::DECISION_REJECTED);

        $this->assertSame(Job::STATUS_QUEUED, $this->reloadJob($approvedJob)->status);
        $this->assertSame(Job::STATUS_REJECTED, $this->reloadJob($rejectedJob)->status);
        $this->assertSame([], $this->engineCalls);
    }

    /**
     * Regression: approving a gated workflow job step marked its job
     * succeeded without running it. Any job linked to a workflow step was
     * taken for an approval step's placeholder: the approval marked the real
     * job of a job step whose template waits for approval succeeded, and the
     * run took the step's success route, although the playbook never ran.
     * With the real engine: the job is queued, and its step keeps running
     * until the job ends.
     */
    public function testRegressionApprovingAGatedWorkflowJobStepMarkedItsJobSucceededWithoutRunningIt(): void
    {
        $flow = $this->workflowStepWaiting(WorkflowStep::TYPE_JOB, $this->rule(), $this->launcher);

        $this->approvals()->recordDecision($flow['request'], (int)$this->approver->id, ApprovalDecision::DECISION_APPROVED);

        $job = $this->reloadJob($flow['job']);
        $this->assertSame(Job::STATUS_QUEUED, $job->status, 'the job runs');
        $this->assertNull($job->finished_at);
        $execution = WorkflowJobStep::findOne($flow['execution']->id);
        $this->assertNotNull($execution);
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $execution->status, 'the step ends with its job');
        $this->assertNull(
            WorkflowJobStep::findOne(['workflow_job_id' => $flow['run']->id, 'workflow_step_id' => $flow['next']->id]),
            'the run has not moved on'
        );
    }

    /**
     * Defensive: should the job leave pending_approval inside the settlement
     * anyway (the job row is locked, so nothing else can move it), the
     * conditional UPDATE leaves it as it is, and the engine hears nothing.
     */
    public function testAJobThatLeftPendingApprovalMeanwhileIsNotMovedAndTheEngineHearsNothing(): void
    {
        $flow = $this->workflowStepWaiting(WorkflowStep::TYPE_APPROVAL, $this->rule(), $this->launcher);
        $this->recordEngineCalls();
        $jobId = (int)$flow['job']->id;
        $move = static function () use ($jobId): void {
            Job::updateAll(['status' => Job::STATUS_CANCELED], ['id' => $jobId, 'status' => Job::STATUS_PENDING_APPROVAL]);
        };
        \yii\base\Event::on(WorkflowJobStep::class, \yii\db\BaseActiveRecord::EVENT_AFTER_FIND, $move);
        try {
            $this->approvals()->recordDecision($flow['request'], (int)$this->approver->id, ApprovalDecision::DECISION_APPROVED);
        } finally {
            \yii\base\Event::off(WorkflowJobStep::class, \yii\db\BaseActiveRecord::EVENT_AFTER_FIND, $move);
        }

        $this->assertSame(Job::STATUS_CANCELED, $this->reloadJob($flow['job'])->status);
        $this->assertSame([], $this->engineCalls);
    }

    // -- Helpers ----------------------------------------------------------------------

    private function rule(): ApprovalRule
    {
        return $this->listRule((int)$this->launcher->id, [$this->approver]);
    }

    /**
     * Replace the engine with one that records what it is told, with the
     * request's status and the transaction level at that moment.
     */
    private function recordEngineCalls(): void
    {
        $this->engineBefore = \Yii::$app->get('workflowExecutionService');
        $test = $this;
        \Yii::$app->set('workflowExecutionService', new class ($test) extends WorkflowExecutionService {
            public function __construct(private readonly ApprovalWorkflowOutcomeTest $test)
            {
                parent::__construct();
            }

            public function onApprovalResolved(Job $job, bool $approved): void
            {
                $this->test->engineCalls[] = [
                    'job' => (int)$job->id,
                    'approved' => $approved,
                    'request' => ApprovalRequest::findOne(['job_id' => $job->id])?->status,
                    'level' => \Yii::$app->db->getTransaction()?->level,
                ];
            }
        });
    }
}
