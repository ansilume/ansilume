<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\Job;
use app\tests\integration\CountsQueries;
use app\tests\integration\DbTestCase;

/**
 * The launcher of a request is the user who launched its job. Their own
 * decisions, recorded before self-approval was refused, stay in the history
 * but count neither as approvals nor as rejections.
 */
class ApprovalRequestLauncherTest extends DbTestCase
{
    use CountsQueries;

    public function testTheLauncherIsTheUserWhoLaunchedTheJob(): void
    {
        $launcher = (int)$this->createUser('launcher')->id;
        $request = $this->requestLaunchedBy($launcher);

        $this->assertSame($launcher, $request->launcherId());
        $this->assertSame($launcher, ApprovalRequest::findOne($request->id)?->launcherId());
    }

    public function testARequestWithoutAJobHasNoLauncher(): void
    {
        $this->assertSame(0, (new ApprovalRequest())->launcherId());
    }

    /**
     * Regression: the launcher's own approval counted toward the threshold,
     * so a launcher listed as approver could approve their own job.
     */
    public function testTheLaunchersDecisionsAreNotCounted(): void
    {
        $launcher = (int)$this->createUser('launcher')->id;
        $request = $this->requestLaunchedBy($launcher);
        $this->decide($request, $launcher, ApprovalDecision::DECISION_APPROVED);
        $this->decide($request, (int)$this->createUser('approver')->id, ApprovalDecision::DECISION_APPROVED);
        $this->decide($request, (int)$this->createUser('rejecter')->id, ApprovalDecision::DECISION_REJECTED);

        $this->assertSame(1, $request->approvalCount());
        $this->assertSame(1, $request->rejectionCount());

        $rejectedByLauncher = $this->requestLaunchedBy($launcher);
        $this->decide($rejectedByLauncher, $launcher, ApprovalDecision::DECISION_REJECTED);

        $this->assertSame(0, $rejectedByLauncher->rejectionCount());
        $this->assertSame(0, $rejectedByLauncher->approvalCount());
    }

    public function testADecisionIsTheLaunchersOnlyWhenTheLauncherCastIt(): void
    {
        $launcher = (int)$this->createUser('launcher')->id;
        $request = $this->requestLaunchedBy($launcher);
        $own = $this->decide($request, $launcher, ApprovalDecision::DECISION_APPROVED);
        $other = $this->decide($request, (int)$this->createUser('approver')->id, ApprovalDecision::DECISION_APPROVED);

        $this->assertTrue($request->isLauncherDecision($own));
        $this->assertFalse($request->isLauncherDecision($other));
    }

    public function testACountIsOneQueryWhetherOrNotTheJobIsLoaded(): void
    {
        $launcher = (int)$this->createUser('launcher')->id;
        $request = $this->requestLaunchedBy($launcher);
        $this->decide($request, $launcher, ApprovalDecision::DECISION_APPROVED);
        $request->approvalCount();

        $fresh = ApprovalRequest::findOne($request->id);
        $this->assertNotNull($fresh);
        $this->assertSame(1, $this->queriesOf(fn () => $fresh->approvalCount()));
        $this->assertSame(1, $this->queriesOf(fn () => $fresh->rejectionCount()));
    }

    private function requestLaunchedBy(int $launcherId): ApprovalRequest
    {
        $owner = (int)$this->createUser('owner')->id;
        $template = $this->createJobTemplate(
            (int)$this->createProject($owner)->id,
            (int)$this->createInventory($owner)->id,
            (int)$this->createRunnerGroup($owner)->id,
            $owner
        );
        $job = $this->createJob((int)$template->id, $launcherId, Job::STATUS_PENDING_APPROVAL);
        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->approval_rule_id = (int)$this->createApprovalRule($owner)->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $request->save(false);

        return $request;
    }

    private function decide(ApprovalRequest $request, int $userId, string $decision): ApprovalDecision
    {
        $vote = new ApprovalDecision();
        $vote->approval_request_id = (int)$request->id;
        $vote->user_id = $userId;
        $vote->decision = $decision;
        $vote->created_at = time();
        $vote->save(false);

        return $vote;
    }
}
