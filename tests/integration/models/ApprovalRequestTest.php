<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\tests\integration\CountsQueries;
use app\tests\integration\DbTestCase;

class ApprovalRequestTest extends DbTestCase
{
    use CountsQueries;

    public function testTableName(): void
    {
        $this->assertSame('{{%approval_request}}', ApprovalRequest::tableName());
    }

    public function testPersistAndRetrieve(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);
        $job = $this->createJob((int)$tpl->id, $user->id);
        $rule = $this->createApprovalRule($user->id);

        $request = new ApprovalRequest();
        $request->job_id = $job->id;
        $request->approval_rule_id = $rule->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $this->assertTrue($request->save(false));
        $this->assertNotNull($request->id);

        $reloaded = ApprovalRequest::findOne($request->id);
        $this->assertNotNull($reloaded);
        $this->assertSame($job->id, $reloaded->job_id);
        $this->assertSame($rule->id, $reloaded->approval_rule_id);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $reloaded->status);
    }

    public function testStatuses(): void
    {
        $statuses = ApprovalRequest::statuses();
        $this->assertCount(5, $statuses);
        $this->assertContains(ApprovalRequest::STATUS_PENDING, $statuses);
        $this->assertContains(ApprovalRequest::STATUS_APPROVED, $statuses);
        $this->assertContains(ApprovalRequest::STATUS_REJECTED, $statuses);
        $this->assertContains(ApprovalRequest::STATUS_TIMED_OUT, $statuses);
        $this->assertContains(ApprovalRequest::STATUS_CANCELED, $statuses);
    }

    public function testIsResolvedWhenPending(): void
    {
        $request = $this->createApprovalRequest(ApprovalRequest::STATUS_PENDING);
        $this->assertFalse($request->isResolved());
    }

    public function testIsResolvedWhenApproved(): void
    {
        $request = $this->createApprovalRequest(ApprovalRequest::STATUS_APPROVED);
        $this->assertTrue($request->isResolved());
    }

    public function testIsResolvedWhenRejected(): void
    {
        $request = $this->createApprovalRequest(ApprovalRequest::STATUS_REJECTED);
        $this->assertTrue($request->isResolved());
    }

    public function testIsResolvedWhenTimedOut(): void
    {
        $request = $this->createApprovalRequest(ApprovalRequest::STATUS_TIMED_OUT);
        $this->assertTrue($request->isResolved());
    }

    public function testIsResolvedWhenCanceled(): void
    {
        $request = $this->createApprovalRequest(ApprovalRequest::STATUS_CANCELED);
        $this->assertTrue($request->isResolved());
    }

    public function testApprovalCount(): void
    {
        $user = $this->createUser();
        $request = $this->createApprovalRequest(ApprovalRequest::STATUS_PENDING);

        $decision = new ApprovalDecision();
        $decision->approval_request_id = $request->id;
        $decision->user_id = $user->id;
        $decision->decision = 'approved';
        $decision->created_at = time();
        $decision->save(false);

        $this->assertSame(1, $request->approvalCount());
    }

    public function testRejectionCount(): void
    {
        $user = $this->createUser();
        $request = $this->createApprovalRequest(ApprovalRequest::STATUS_PENDING);

        $decision = new ApprovalDecision();
        $decision->approval_request_id = $request->id;
        $decision->user_id = $user->id;
        $decision->decision = 'rejected';
        $decision->created_at = time();
        $decision->save(false);

        $this->assertSame(1, $request->rejectionCount());
    }

    public function testHasDecisionByTellsWhetherTheUserVoted(): void
    {
        $voter = $this->createUser('has-voted');
        $other = $this->createUser('has-not-voted');
        $request = $this->createApprovalRequest(ApprovalRequest::STATUS_PENDING);
        $decision = new ApprovalDecision();
        $decision->approval_request_id = $request->id;
        $decision->user_id = $voter->id;
        $decision->decision = ApprovalDecision::DECISION_REJECTED;
        $decision->created_at = time();
        $decision->save(false);

        $this->assertTrue($request->hasDecisionBy((int)$voter->id));
        $this->assertFalse($request->hasDecisionBy((int)$other->id));
        $this->assertFalse($this->createApprovalRequest(ApprovalRequest::STATUS_PENDING)->hasDecisionBy((int)$voter->id));
    }

    public function testApprovalCountZero(): void
    {
        $request = $this->createApprovalRequest(ApprovalRequest::STATUS_PENDING);
        $this->assertSame(0, $request->approvalCount());
    }

    public function testStatusLabelForAllStatuses(): void
    {
        $this->assertSame('Pending', ApprovalRequest::statusLabel(ApprovalRequest::STATUS_PENDING));
        $this->assertSame('Approved', ApprovalRequest::statusLabel(ApprovalRequest::STATUS_APPROVED));
        $this->assertSame('Rejected', ApprovalRequest::statusLabel(ApprovalRequest::STATUS_REJECTED));
        $this->assertSame('Timed Out', ApprovalRequest::statusLabel(ApprovalRequest::STATUS_TIMED_OUT));
        $this->assertSame('Canceled', ApprovalRequest::statusLabel(ApprovalRequest::STATUS_CANCELED));
        $this->assertSame('unknown_status', ApprovalRequest::statusLabel('unknown_status'));
    }

    public function testStatusCssClassForAllStatuses(): void
    {
        $this->assertSame('warning', ApprovalRequest::statusCssClass(ApprovalRequest::STATUS_PENDING));
        $this->assertSame('success', ApprovalRequest::statusCssClass(ApprovalRequest::STATUS_APPROVED));
        $this->assertSame('danger', ApprovalRequest::statusCssClass(ApprovalRequest::STATUS_REJECTED));
        $this->assertSame('secondary', ApprovalRequest::statusCssClass(ApprovalRequest::STATUS_TIMED_OUT));
        $this->assertSame('secondary', ApprovalRequest::statusCssClass(ApprovalRequest::STATUS_CANCELED));
        $this->assertSame('secondary', ApprovalRequest::statusCssClass('unknown_status'));
    }

    public function testJobRelation(): void
    {
        $request = $this->createApprovalRequest(ApprovalRequest::STATUS_PENDING);
        $this->assertInstanceOf(Job::class, $request->job);
        $this->assertSame($request->job_id, $request->job->id);
    }

    public function testApprovalRuleRelation(): void
    {
        $request = $this->createApprovalRequest(ApprovalRequest::STATUS_PENDING);
        $this->assertInstanceOf(ApprovalRule::class, $request->approvalRule);
        $this->assertSame($request->approval_rule_id, $request->approvalRule->id);
    }

    public function testDecisionsRelation(): void
    {
        $user = $this->createUser();
        $request = $this->createApprovalRequest(ApprovalRequest::STATUS_PENDING);

        $decision = new ApprovalDecision();
        $decision->approval_request_id = $request->id;
        $decision->user_id = $user->id;
        $decision->decision = 'approved';
        $decision->created_at = time();
        $decision->save(false);

        $reloaded = ApprovalRequest::findOne($request->id);
        $this->assertNotNull($reloaded);
        $decisions = $reloaded->decisions;
        $this->assertCount(1, $decisions);
        $this->assertInstanceOf(ApprovalDecision::class, $decisions[0]);
        $this->assertSame($decision->id, $decisions[0]->id);
    }

    /**
     * regression: the request page listed the votes by user ID, while the
     * API's "decisions" lists them in the order cast: getDecisions() had no
     * order, and MariaDB read the votes of a request through the unique index
     * (approval_request_id, user_id). It does so once the table holds a few
     * more votes than those of the one request (with those alone it scans
     * the table, in the order cast), so two other requests have votes too.
     * The higher user ID votes first; the lowest ID votes last. Loaded with
     * the request or on its own, the votes come in the order cast.
     */
    public function testRegressionTheDecisionsCameByUserIdNotInTheOrderCast(): void
    {
        $lowest = (int)$this->createUser('votes-last')->id;
        $lower = (int)$this->createUser('votes-second')->id;
        $higher = (int)$this->createUser('votes-first')->id;
        $this->assertGreaterThan($lowest, $lower);
        $this->assertGreaterThan($lower, $higher, 'the user who votes first has the higher ID');
        foreach ([1, 2] as $other) {
            $otherRequest = $this->createApprovalRequest(ApprovalRequest::STATUS_PENDING);
            foreach ([$lowest, $lower, $higher] as $voter) {
                $this->vote($otherRequest, $voter, ApprovalDecision::DECISION_APPROVED);
            }
        }
        $request = $this->createApprovalRequest(ApprovalRequest::STATUS_PENDING);
        $this->vote($request, $higher, ApprovalDecision::DECISION_REJECTED);
        $this->vote($request, $lower, ApprovalDecision::DECISION_APPROVED);
        $this->vote($request, $lowest, ApprovalDecision::DECISION_APPROVED);
        $castOrder = [$higher, $lower, $lowest];
        $voters = static fn (array $decisions): array => array_map(static fn (ApprovalDecision $vote): int => (int)$vote->user_id, $decisions);

        $lazy = ApprovalRequest::findOne($request->id);
        $this->assertNotNull($lazy);
        $this->assertSame($castOrder, $voters($lazy->decisions), 'read on their own, as the request page reads them');
        $eager = ApprovalRequest::find()->where(['id' => $request->id])->with('decisions')->one();
        $this->assertInstanceOf(ApprovalRequest::class, $eager);
        $this->assertSame($castOrder, $voters($eager->decisions), 'read with the request');
    }

    public function testCountedDecisionsOfLeavesOutTheLaunchersVotesAsTheCountsDo(): void
    {
        $voted = $this->createApprovalRequest(ApprovalRequest::STATUS_PENDING);
        $launcherId = (int)$voted->job->launched_by;
        $this->vote($voted, $launcherId, ApprovalDecision::DECISION_APPROVED);
        $this->vote($voted, (int)$this->createUser('approves')->id, ApprovalDecision::DECISION_APPROVED);
        $this->vote($voted, (int)$this->createUser('approves-too')->id, ApprovalDecision::DECISION_APPROVED);
        $this->vote($voted, (int)$this->createUser('rejects')->id, ApprovalDecision::DECISION_REJECTED);
        $rejectedByLauncher = $this->createApprovalRequest(ApprovalRequest::STATUS_PENDING);
        $this->vote($rejectedByLauncher, (int)$rejectedByLauncher->job->launched_by, ApprovalDecision::DECISION_REJECTED);
        $unvoted = $this->createApprovalRequest(ApprovalRequest::STATUS_PENDING);
        $missingId = (int)$unvoted->id + 1000;

        $counts = ApprovalRequest::countedDecisionsOf([(int)$voted->id, (int)$rejectedByLauncher->id, (int)$unvoted->id, $missingId]);

        $this->assertSame([
            (int)$voted->id => ['approved' => 2, 'rejected' => 1],
            (int)$rejectedByLauncher->id => ['approved' => 0, 'rejected' => 0],
            (int)$unvoted->id => ['approved' => 0, 'rejected' => 0],
            $missingId => ['approved' => 0, 'rejected' => 0],
        ], $counts);
        foreach ([$voted, $rejectedByLauncher, $unvoted] as $request) {
            $this->assertSame($request->approvalCount(), $counts[(int)$request->id]['approved']);
            $this->assertSame($request->rejectionCount(), $counts[(int)$request->id]['rejected']);
        }
    }

    public function testCountedDecisionsOfCountsAsTheColumnCompares(): void
    {
        $request = $this->createApprovalRequest(ApprovalRequest::STATUS_PENDING);
        $this->vote($request, (int)$this->createUser('upper')->id, 'APPROVED');
        $this->vote($request, (int)$this->createUser('lower')->id, ApprovalDecision::DECISION_APPROVED);
        $this->vote($request, (int)$this->createUser('other')->id, 'maybe');

        $this->assertSame(
            [(int)$request->id => ['approved' => $request->approvalCount(), 'rejected' => $request->rejectionCount()]],
            ApprovalRequest::countedDecisionsOf([(int)$request->id])
        );
        $this->assertSame(2, $request->approvalCount());
    }

    public function testCountedDecisionsOfIsOneQueryHoweverManyRequests(): void
    {
        $one = [(int)$this->createApprovalRequest(ApprovalRequest::STATUS_PENDING)->id];
        $many = [];
        for ($i = 0; $i < 6; $i++) {
            $request = $this->createApprovalRequest(ApprovalRequest::STATUS_PENDING);
            $this->vote($request, (int)$this->createUser('voter' . $i)->id, ApprovalDecision::DECISION_APPROVED);
            $many[] = (int)$request->id;
        }
        ApprovalRequest::countedDecisionsOf($one);

        $this->assertSame(1, $this->queriesOf(static fn () => ApprovalRequest::countedDecisionsOf($one)));
        $this->assertSame(1, $this->queriesOf(static fn () => ApprovalRequest::countedDecisionsOf($many)));
        $this->assertSame(0, $this->queriesOf(static fn () => ApprovalRequest::countedDecisionsOf([])));
        $this->assertSame([], ApprovalRequest::countedDecisionsOf([]));
    }

    private function vote(ApprovalRequest $request, int $userId, string $decision): void
    {
        $vote = new ApprovalDecision();
        $vote->approval_request_id = (int)$request->id;
        $vote->user_id = $userId;
        $vote->decision = $decision;
        $vote->created_at = time();
        $vote->save(false);
    }

    /**
     * Helper to create a persisted ApprovalRequest with the given status.
     */
    private function createApprovalRequest(string $status): ApprovalRequest
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);
        $job = $this->createJob((int)$tpl->id, $user->id);
        $rule = $this->createApprovalRule($user->id);

        $request = new ApprovalRequest();
        $request->job_id = $job->id;
        $request->approval_rule_id = $rule->id;
        $request->status = $status;
        $request->requested_at = time();
        $request->save(false);

        return $request;
    }
}
