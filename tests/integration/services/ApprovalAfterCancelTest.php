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
use app\services\JobCancellationService;
use app\services\JobCompletionService;
use app\services\WorkflowCancellationService;
use app\services\WorkflowExecutionService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Canceling a job that waits for approval, or a workflow run at an approval
 * step, closes the approval request as canceled in the same transaction
 * (JobCancellationService; the job cancels here go through
 * JobCompletionService::cancel(), the entry point from before it). The
 * request can no longer be decided, and no timeout applies to it.
 *
 * Regression: the cancel left the request pending. Deciding it later still
 * changed the job: approving queued the canceled job again, so it ran, and
 * for a workflow step the success route ran after the failure route had
 * already run. Since then a decision changes only a job still waiting for
 * approval, and only a workflow step still running takes a route; the tests
 * with a request "as loaded before the cancel" (an approver's open page)
 * keep that guard of the job side.
 *
 * A vote on a canceled request is refused under the request's lock, also
 * with the request as loaded before the cancel: 409 "Approval request #N is
 * already resolved: canceled." (ApprovalDecisionRefusedException, reason
 * resolved), and the request keeps its canceled status.
 *
 * Regression: a vote with the request as loaded before the cancel was
 * stored and rewrote the request from canceled to approved or rejected.
 */
class ApprovalAfterCancelTest extends DbTestCase
{
    use TeamScopeFixtures;

    /**
     * @return array<string, array{0: string}>
     */
    public static function decisionProvider(): array
    {
        return [
            'approve' => [ApprovalDecision::DECISION_APPROVED],
            'reject' => [ApprovalDecision::DECISION_REJECTED],
        ];
    }

    /**
     * Regression: canceling a job left its approval request pending and
     * decidable; a vote turned it approved or rejected.
     *
     * @dataProvider decisionProvider
     */
    public function testTheRequestOfACanceledJobCannotBeDecided(string $decision): void
    {
        [$job, $request, $approver] = $this->canceledJobWithRequest(ApprovalRule::TIMEOUT_ACTION_REJECT);
        $before = $job->getAttributes();
        $resolvedAt = $request->resolved_at;
        $this->assertFalse($this->approvals()->canUserApprove($request, (int)$approver->id), 'no Approve/Reject buttons');

        $this->assertRefusedAsCanceled($this->decide($request, $approver, $decision), $request);
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $request->status);
        $this->assertSame($resolvedAt, $request->resolved_at);
        $this->assertSame(0, (int)ApprovalDecision::find()->where(['approval_request_id' => $request->id])->count());
        $job->refresh();
        $this->assertSame($before, $job->getAttributes(), 'The canceled job must not change.');
    }

    /**
     * A decision with the request as an approver's page loaded it before the
     * cancel leaves the canceled job canceled.
     *
     * @dataProvider decisionProvider
     */
    public function testADecisionWithTheRequestAsLoadedBeforeTheCancelLeavesTheJobCanceled(string $decision): void
    {
        [$job, $request, $approver] = $this->jobWithRequest(ApprovalRule::TIMEOUT_ACTION_REJECT);
        $this->completion()->cancel($job, (int)$job->launched_by);
        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $before = $job->getAttributes();
        $canceled = ApprovalRequest::findOne($request->id)?->getAttributes();
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status, 'the page\'s model is stale');

        $this->assertRefusedAsCanceled($this->decide($request, $approver, $decision), $request);

        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $this->assertSame($before, $job->getAttributes(), 'The canceled job must not change.');
        $this->assertSame($canceled, ApprovalRequest::findOne($request->id)?->getAttributes(), 'The request stays canceled.');
        $this->assertSame(0, (int)ApprovalDecision::find()->where(['approval_request_id' => $request->id])->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function timeoutActionProvider(): array
    {
        return [
            'approve on timeout' => [ApprovalRule::TIMEOUT_ACTION_APPROVE],
            'reject on timeout' => [ApprovalRule::TIMEOUT_ACTION_REJECT],
        ];
    }

    /**
     * Regression: the request of a canceled job stayed pending, and the
     * timeout run turned it timed out. It is no longer pending, so the
     * timeout run passes it by: it stays canceled, and the job too.
     *
     * @dataProvider timeoutActionProvider
     */
    public function testNoTimeoutAppliesToTheRequestOfACanceledJob(string $action): void
    {
        [$job, $request] = $this->canceledJobWithRequest($action);
        $request->expires_at = time() - 60;
        $request->save(false);
        $resolvedAt = $request->resolved_at;
        $before = $job->getAttributes();

        $this->assertSame(0, $this->approvals()->processTimeouts());

        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $request->status);
        $this->assertSame($resolvedAt, $request->resolved_at);
        $job->refresh();
        $this->assertSame($before, $job->getAttributes(), 'The canceled job must not change.');
    }

    /**
     * The decision on a job still waiting for approval works as before.
     */
    public function testApprovingAJobThatStillWaitsQueuesIt(): void
    {
        [$job, $request, $approver] = $this->jobWithRequest(ApprovalRule::TIMEOUT_ACTION_REJECT);
        $job->queued_at = null;
        $job->save(false);

        $this->approvals()->recordDecision($request, (int)$approver->id, 'approved');

        $job->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $job->status);
        $this->assertNotNull($job->queued_at);
    }

    /**
     * Regression: canceling the placeholder job of an approval step fails
     * the step and takes the failure route. Approving the request later
     * marked the placeholder succeeded and took the success route as well.
     * The cancel closes the request now; a decision with the request as
     * loaded before the cancel takes no second route either.
     */
    public function testADecisionAfterTheStepsPlaceholderWasCanceledTakesNoSecondRoute(): void
    {
        $flow = $this->workflowAtApproval();
        $placeholder = $this->placeholderJob($flow['run'], $flow['approval']);
        $request = $this->requestOf($placeholder);
        $this->completion()->cancel($placeholder, (int)$flow['user']->id);
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, ApprovalRequest::findOne($request->id)?->status);
        $this->assertStepStatus($flow['run'], $flow['onFailure'], WorkflowJobStep::STATUS_RUNNING);

        $this->assertRefusedAsCanceled($this->decide($request, $flow['approver'], ApprovalDecision::DECISION_APPROVED), $request);

        $placeholder->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $placeholder->status);
        $this->assertStepStatus($flow['run'], $flow['approval'], WorkflowJobStep::STATUS_FAILED);
        $this->assertNull($this->stepRun($flow['run'], $flow['onSuccess']), 'The success route must not run.');
        $this->assertStepStatus($flow['run'], $flow['onFailure'], WorkflowJobStep::STATUS_RUNNING);
    }

    /**
     * The engine itself ignores a decision for a step that is no longer
     * running, whatever state the job is in.
     */
    public function testTheEngineIgnoresADecisionForAStepThatIsNoLongerRunning(): void
    {
        $flow = $this->workflowAtApproval();
        $placeholder = $this->placeholderJob($flow['run'], $flow['approval']);
        $this->completion()->cancel($placeholder, (int)$flow['user']->id);

        $this->engine()->onApprovalResolved($placeholder, true);

        $this->assertStepStatus($flow['run'], $flow['approval'], WorkflowJobStep::STATUS_FAILED);
        $this->assertNull($this->stepRun($flow['run'], $flow['onSuccess']), 'The success route must not run.');
    }

    /**
     * Regression: canceling the run left the request pending; approving it
     * later marked the canceled placeholder job succeeded. The run's cancel
     * closes the request now, and a decision with the request as loaded
     * before the cancel moves neither the placeholder nor the run.
     */
    public function testADecisionAfterTheRunWasCanceledLeavesItsPlaceholderCanceled(): void
    {
        $flow = $this->workflowAtApproval();
        $placeholder = $this->placeholderJob($flow['run'], $flow['approval']);
        $request = $this->requestOf($placeholder);
        $this->assertTrue($this->runs()->cancelRun($flow['run'], (int)$flow['user']->id, JobCancellationService::REASON_OPERATOR));
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, ApprovalRequest::findOne($request->id)?->status);

        $this->assertRefusedAsCanceled($this->decide($request, $flow['approver'], ApprovalDecision::DECISION_APPROVED), $request);

        $placeholder->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $placeholder->status);
        $flow['run']->refresh();
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $flow['run']->status);
        $this->assertNull($this->stepRun($flow['run'], $flow['onSuccess']), 'The success route must not run.');
    }

    /**
     * Approving a step that is still waiting advances the run as before.
     */
    public function testApprovingAStepThatStillWaitsTakesTheSuccessRoute(): void
    {
        $flow = $this->workflowAtApproval();
        $placeholder = $this->placeholderJob($flow['run'], $flow['approval']);

        $this->approvals()->recordDecision($this->requestOf($placeholder), (int)$flow['approver']->id, 'approved');

        $placeholder->refresh();
        $this->assertSame(Job::STATUS_SUCCEEDED, $placeholder->status);
        $this->assertStepStatus($flow['run'], $flow['approval'], WorkflowJobStep::STATUS_SUCCEEDED);
        $this->assertNotNull($this->stepRun($flow['run'], $flow['onSuccess']));
        $this->assertNull($this->stepRun($flow['run'], $flow['onFailure']));
    }

    /**
     * A job waiting for approval, its pending request, and the one approver
     * (an operator, who may decide, and not the launcher).
     *
     * @return array{0: Job, 1: ApprovalRequest, 2: User}
     */
    private function jobWithRequest(string $timeoutAction): array
    {
        $user = $this->createUser('cancel-approval');
        $approver = $this->createUserWithRole('cancel-approver', 'operator');
        $template = $this->jobTemplate((int)$user->id);
        $job = $this->createJob((int)$template->id, (int)$user->id, Job::STATUS_PENDING_APPROVAL);
        $rule = $this->rule((int)$user->id, (int)$approver->id);
        $rule->timeout_minutes = 5;
        $rule->timeout_action = $timeoutAction;
        $rule->save(false);
        $request = $this->approvals()->createRequest($job, $rule);

        return [$job, $request, $approver];
    }

    /**
     * As {@see jobWithRequest()}, with the job canceled by its launcher: the
     * request is canceled with it.
     *
     * @return array{0: Job, 1: ApprovalRequest, 2: User}
     */
    private function canceledJobWithRequest(string $timeoutAction): array
    {
        [$job, $request, $approver] = $this->jobWithRequest($timeoutAction);
        $this->assertTrue($this->completion()->cancel($job, (int)$job->launched_by));
        $job->refresh();
        $request->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $request->status, 'Canceling closes the request.');
        $this->assertNotNull($request->resolved_at);

        return [$job, $request, $approver];
    }

    /**
     * $approver decides with $request as given; the refusal, if any.
     */
    private function decide(ApprovalRequest $request, User $approver, string $decision): ?ApprovalDecisionRefusedException
    {
        try {
            $this->approvals()->recordDecision($request, (int)$approver->id, $decision);
        } catch (ApprovalDecisionRefusedException $e) {
            return $e;
        }

        return null;
    }

    /**
     * The vote was refused because the request is canceled: 409, with the
     * request's status.
     */
    private function assertRefusedAsCanceled(?ApprovalDecisionRefusedException $refusal, ApprovalRequest $request): void
    {
        $this->assertNotNull($refusal, 'A decision on a canceled request is refused.');
        $this->assertSame(ApprovalDecisionRefusedException::REASON_RESOLVED, $refusal->reason());
        $this->assertSame(sprintf(ApprovalDecisionRefusedException::RESOLVED, (int)$request->id, 'canceled'), $refusal->getMessage());
        $this->assertSame(409, $refusal->httpStatus());
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $refusal->requestStatus());
    }

    /**
     * A run waiting at an approval step whose success route is a job step
     * and whose failure route is a pause step.
     *
     * @return array{
     *     user: User,
     *     approver: User,
     *     run: WorkflowJob,
     *     approval: WorkflowStep,
     *     onSuccess: WorkflowStep,
     *     onFailure: WorkflowStep
     * }
     */
    private function workflowAtApproval(): array
    {
        $user = $this->createUserWithRole('cancel-wf', 'operator');
        $approver = $this->createUserWithRole('cancel-wf-approver', 'operator');
        $template = $this->jobTemplate((int)$user->id);
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        $rule = $this->rule((int)$user->id, (int)$approver->id);
        $approval = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $onSuccess = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$template->id);
        $onFailure = $this->createWorkflowStep((int)$workflow->id, 2, WorkflowStep::TYPE_PAUSE);
        $approval->on_success_step_id = $onSuccess->id;
        $approval->on_failure_step_id = $onFailure->id;
        $approval->save(false);

        $run = $this->engine()->launch($workflow, (int)$user->id);
        $this->assertStepStatus($run, $approval, WorkflowJobStep::STATUS_RUNNING);

        return [
            'user' => $user,
            'approver' => $approver,
            'run' => $run,
            'approval' => $approval,
            'onSuccess' => $onSuccess,
            'onFailure' => $onFailure,
        ];
    }

    private function jobTemplate(int $userId): JobTemplate
    {
        $group = $this->createRunnerGroup($userId);
        $project = $this->createProject($userId);
        $inventory = $this->createInventory($userId);

        return $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $userId);
    }

    private function rule(int $createdBy, int $approverId): ApprovalRule
    {
        return $this->createApprovalRule(
            $createdBy,
            ApprovalRule::APPROVER_TYPE_USERS,
            json_encode(['user_ids' => [$approverId]]) ?: '{}',
            1
        );
    }

    private function placeholderJob(WorkflowJob $run, WorkflowStep $step): Job
    {
        $stepRun = $this->stepRun($run, $step);
        $this->assertNotNull($stepRun);
        $job = Job::findOne($stepRun->job_id);
        $this->assertNotNull($job);
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $job->status);

        return $job;
    }

    private function requestOf(Job $job): ApprovalRequest
    {
        $request = ApprovalRequest::findOne(['job_id' => $job->id]);
        $this->assertNotNull($request);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status);

        return $request;
    }

    private function stepRun(WorkflowJob $run, WorkflowStep $step): ?WorkflowJobStep
    {
        return WorkflowJobStep::findOne(['workflow_job_id' => $run->id, 'workflow_step_id' => $step->id]);
    }

    private function assertStepStatus(WorkflowJob $run, WorkflowStep $step, string $status): void
    {
        $stepRun = $this->stepRun($run, $step);
        $this->assertNotNull($stepRun, "Step {$step->name} has not run.");
        $this->assertSame($status, $stepRun->status);
    }

    private function approvals(): ApprovalService
    {
        /** @var ApprovalService $service */
        $service = \Yii::$app->get('approvalService');
        return $service;
    }

    private function engine(): WorkflowExecutionService
    {
        /** @var WorkflowExecutionService $service */
        $service = \Yii::$app->get('workflowExecutionService');
        return $service;
    }

    private function completion(): JobCompletionService
    {
        /** @var JobCompletionService $service */
        $service = \Yii::$app->get('jobCompletionService');
        return $service;
    }

    private function runs(): WorkflowCancellationService
    {
        /** @var WorkflowCancellationService $service */
        $service = \Yii::$app->get('workflowCancellationService');
        return $service;
    }
}
