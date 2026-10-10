<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\ApprovalService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Team scoping of approval decisions: an approver must also be able to see
 * the request (view access to the job's project, or to the workflow of a
 * workflow approval step), and the threshold only counts such approvers.
 *
 * Regression: the rule's approver list alone decided, so a member of another
 * team could approve a job of a project they cannot see, and a rule naming
 * approvers outside the team never auto-rejected.
 */
class ApprovalServiceTeamScopeTest extends DbTestCase
{
    use TeamScopeFixtures;

    public function testEligibleApproversAreTheListedApproversWhoMaySeeTheRequest(): void
    {
        $s = $this->teamScope();
        $request = $this->requestFor($s['own'], [$s['member'], $s['outsider'], $s['admin']]);

        $this->assertSame(
            [(int)$s['member']->id, (int)$s['admin']->id],
            $this->service()->eligibleApproverIds($request)
        );
    }

    public function testAListedApproverOfAnotherTeamMayNotDecide(): void
    {
        $s = $this->teamScope();
        $request = $this->requestFor($s['own'], [$s['member'], $s['outsider']]);

        $this->assertTrue($this->service()->canUserApprove($request, (int)$s['member']->id));
        $this->assertFalse($this->service()->canUserApprove($request, (int)$s['outsider']->id));
    }

    public function testAnUnlistedMemberOfTheTeamMayNotDecide(): void
    {
        $s = $this->teamScope();
        $request = $this->requestFor($s['own'], [$s['admin']]);

        $this->assertFalse($this->service()->canUserApprove($request, (int)$s['member']->id));
    }

    public function testViewAccessToTheProjectIsEnoughToDecide(): void
    {
        $s = $this->teamScope();
        $request = $this->requestFor($s['viewed'], [$s['member']]);

        $this->assertTrue($this->service()->canUserApprove($request, (int)$s['member']->id));
    }

    public function testEveryListedApproverDecidesOnAnOpenProject(): void
    {
        $s = $this->teamScope();
        $request = $this->requestFor($s['open'], [$s['member'], $s['outsider']]);

        $this->assertSame(
            [(int)$s['member']->id, (int)$s['outsider']->id],
            $this->service()->eligibleApproverIds($request)
        );
        $this->assertTrue($this->service()->canUserApprove($request, (int)$s['outsider']->id));
    }

    public function testAListedAdminDecidesOnEveryTeamsRequest(): void
    {
        $s = $this->teamScope();
        $request = $this->requestFor($s['foreign'], [$s['admin'], $s['member']]);

        $this->assertSame([(int)$s['admin']->id], $this->service()->eligibleApproverIds($request));
        $this->assertTrue($this->service()->canUserApprove($request, (int)$s['admin']->id));
        $this->assertFalse($this->service()->canUserApprove($request, (int)$s['member']->id));
    }

    /**
     * Regression: the threshold counted the outsider as a possible approval,
     * so two approvals still looked reachable and the request stayed pending
     * although only one approver who may see it was left.
     */
    public function testARejectionAutoRejectsWhenOnlyApproversWithoutAccessRemain(): void
    {
        $s = $this->teamScope();
        $request = $this->requestFor($s['own'], [$s['member'], $s['admin'], $s['outsider']], 2);

        $this->service()->recordDecision($request, (int)$s['admin']->id, 'rejected');

        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_REJECTED, $request->status);
        $job = Job::findOne($request->job_id);
        $this->assertNotNull($job);
        $this->assertSame(Job::STATUS_REJECTED, $job->status);
    }

    /**
     * The API stores a rule's user_ids as given, so an approver can be listed
     * twice. They are one possible vote: after the admin's rejection only the
     * member may still approve, and two approvals are out of reach.
     */
    public function testAnApproverListedTwiceCountsOnce(): void
    {
        $s = $this->teamScope();
        $request = $this->requestFor($s['own'], [$s['member'], $s['member'], $s['admin']], 2);

        $this->assertSame(
            [(int)$s['member']->id, (int)$s['admin']->id],
            $this->service()->eligibleApproverIds($request)
        );

        $this->service()->recordDecision($request, (int)$s['admin']->id, 'rejected');

        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_REJECTED, $request->status, 'only one approval is still possible');
    }

    public function testTheSameVotesKeepAnOpenProjectRequestPending(): void
    {
        $s = $this->teamScope();
        $request = $this->requestFor($s['open'], [$s['member'], $s['admin'], $s['outsider']], 2);

        $this->service()->recordDecision($request, (int)$s['admin']->id, 'rejected');

        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status);
    }

    public function testApprovalsOfEligibleApproversMeetTheThreshold(): void
    {
        $s = $this->teamScope();
        $request = $this->requestFor($s['own'], [$s['member'], $s['admin'], $s['outsider']], 2);

        $this->service()->recordDecision($request, (int)$s['member']->id, 'approved');
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status, 'one eligible approver may still vote');

        $this->service()->recordDecision($request, (int)$s['admin']->id, 'approved');
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $request->status);
    }

    public function testAWorkflowApprovalStepFollowsItsWorkflow(): void
    {
        $s = $this->teamScope();
        $request = $this->workflowApprovalRequest((int)$s['admin']->id, [$s['member'], $s['outsider']], (int)$s['own']->id);

        $this->assertSame([(int)$s['member']->id], $this->service()->eligibleApproverIds($request));
        $this->assertFalse($this->service()->canUserApprove($request, (int)$s['outsider']->id));
    }

    public function testAnApprovalOnlyWorkflowIsDecidedByEveryListedApprover(): void
    {
        $s = $this->teamScope();
        $request = $this->workflowApprovalRequest((int)$s['admin']->id, [$s['member'], $s['outsider']]);

        $this->assertSame(
            [(int)$s['member']->id, (int)$s['outsider']->id],
            $this->service()->eligibleApproverIds($request)
        );
    }

    public function testARequestWithoutItsRuleHasNoEligibleApprovers(): void
    {
        $request = new ApprovalRequest();
        $request->approval_rule_id = 0;
        $request->status = ApprovalRequest::STATUS_PENDING;

        $this->assertSame([], $this->service()->eligibleApproverIds($request));
        $this->assertFalse($this->service()->canUserApprove($request, 1));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function service(): ApprovalService
    {
        /** @var ApprovalService $service */
        $service = \Yii::$app->get('approvalService');
        return $service;
    }

    /**
     * @param list<User> $approvers
     */
    private function rule(int $createdBy, array $approvers, int $required): ApprovalRule
    {
        $ids = array_map(static fn (User $u): int => (int)$u->id, $approvers);
        return $this->createApprovalRule(
            $createdBy,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => $ids]),
            $required
        );
    }

    /**
     * @param list<User> $approvers
     */
    private function requestFor(JobTemplate $template, array $approvers, int $required = 1): ApprovalRequest
    {
        $createdBy = (int)$template->created_by;
        $job = $this->createJob((int)$template->id, $createdBy, Job::STATUS_PENDING_APPROVAL);
        return $this->service()->createRequest($job, $this->rule($createdBy, $approvers, $required));
    }

    /**
     * The request of a running workflow's approval step: its placeholder job
     * has no job template. The workflow has one job step per $jobTemplateIds.
     *
     * @param list<User> $approvers
     */
    private function workflowApprovalRequest(int $adminId, array $approvers, int ...$jobTemplateIds): ApprovalRequest
    {
        $rule = $this->rule($adminId, $approvers, 1);
        $workflow = $this->createWorkflowWithJobSteps($adminId, ...$jobTemplateIds);
        $step = $this->createWorkflowStep((int)$workflow->id, 9, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $wfJob = $this->createWorkflowJob((int)$workflow->id, $adminId);

        $job = new Job();
        $job->job_template_id = null;
        $job->launched_by = $adminId;
        $job->status = Job::STATUS_PENDING_APPROVAL;
        $job->timeout_minutes = 0;
        $job->has_changes = 0;
        $job->created_at = time();
        $job->updated_at = time();
        $job->save(false);

        $wjs = new WorkflowJobStep();
        $wjs->workflow_job_id = $wfJob->id;
        $wjs->workflow_step_id = $step->id;
        $wjs->job_id = $job->id;
        $wjs->status = WorkflowJobStep::STATUS_RUNNING;
        $wjs->started_at = time();
        $wjs->created_at = time();
        $wjs->updated_at = time();
        $wjs->save(false);

        return $this->service()->createRequest($job, $rule);
    }
}
