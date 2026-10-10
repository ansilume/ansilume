<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\User;
use app\services\ApprovalService;
use app\services\ProjectAccessChecker;
use app\services\WorkflowAccessChecker;
use app\tests\integration\CountsQueries;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Eligible approvers on an installation without team-restricted projects,
 * where every approver may see every request: they are the rule's approvers,
 * found without an access check per approver.
 *
 * Regression: every vote that did not settle a request checked each
 * approver's access, several queries per approver (for a role-based rule:
 * per user of the role), although the answer was always "every approver".
 */
class ApprovalServiceEligibleApproversTest extends DbTestCase
{
    use CountsQueries;
    use TeamScopeFixtures;

    public function testAVoteCostsTheSameQueriesForTwoAndForTwentyFiveApprovers(): void
    {
        $this->assertNoProjectIsRestricted();
        $this->queriesOfAVote(2);

        $this->assertSame(
            $this->queriesOfAVote(2),
            $this->queriesOfAVote(25),
            'eligibility must not cost queries per approver'
        );
    }

    /**
     * The approvers returned without checking each one are exactly those the
     * access check finds one by one, for every kind of approver.
     */
    public function testEveryKindOfListedApproverIsEligibleAsTheAccessCheckFindsOneByOne(): void
    {
        $this->assertNoProjectIsRestricted();
        $plain = (int)$this->createUser('eligible-plain')->id;
        $admin = (int)$this->createUserWithRole('eligible-admin', 'admin')->id;
        $viewer = (int)$this->createUserWithRole('eligible-viewer', 'viewer')->id;
        $superadmin = $this->createUser('eligible-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $inactive = $this->createUser('eligible-inactive');
        $inactive->status = User::STATUS_INACTIVE;
        $inactive->save(false);
        $deleted = $this->createUser('eligible-deleted');
        $deletedId = (int)$deleted->id;
        $deleted->delete();
        $expected = [$plain, $admin, (int)$superadmin->id, (int)$inactive->id, $viewer, $deletedId];

        $request = $this->requestFor(
            $this->createUser('eligible-owner'),
            [$plain, $admin, (int)$superadmin->id, $plain, (int)$inactive->id, $viewer, $deletedId],
            2
        );

        $this->assertSame($expected, $this->service()->eligibleApproverIds($request));
        $this->assertSame($expected, $this->checkedOneByOne($request));
        foreach ($expected as $userId) {
            $this->assertTrue($this->service()->canUserApprove($request, $userId), "user #{$userId}");
        }
        $this->assertFalse($this->service()->canUserApprove($request, (int)$this->createUser('unlisted')->id));
    }

    public function testARoleBasedRuleMakesEveryUserOfTheRoleEligible(): void
    {
        $this->assertNoProjectIsRestricted();
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('eligible-approvers-' . uniqid());
        $auth->add($role);
        $members = [];
        foreach (['role-a', 'role-b', 'role-c'] as $suffix) {
            $userId = (int)$this->createUser($suffix)->id;
            $auth->assign($role, (string)$userId);
            $members[] = $userId;
        }
        $owner = $this->createUser('role-owner');
        $rule = $this->createApprovalRule(
            (int)$owner->id,
            ApprovalRule::APPROVER_TYPE_ROLE,
            (string)json_encode(['role' => $role->name]),
            3
        );
        $request = $this->service()->createRequest($this->pendingJob($owner), $rule);

        $eligible = $this->service()->eligibleApproverIds($request);

        $this->assertEqualsCanonicalizing($members, $eligible);
        $this->assertEqualsCanonicalizing($this->checkedOneByOne($request), $eligible);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function assertNoProjectIsRestricted(): void
    {
        $this->assertFalse(
            $this->projects()->hasRestrictedProjects(),
            'precondition: the test database has no team-restricted project'
        );
    }

    /**
     * Statements of the first approval on a request that needs every
     * approver, so the vote settles nothing and the open votes are counted.
     */
    private function queriesOfAVote(int $approverCount): int
    {
        $approvers = [];
        for ($i = 0; $i < $approverCount; $i++) {
            $approvers[] = (int)$this->createUser('vote-approver')->id;
        }
        $request = $this->requestFor($this->createUser('vote-owner'), $approvers, $approverCount);

        $queries = $this->queriesOf(fn () => $this->service()->recordDecision(
            $request,
            $approvers[0],
            ApprovalDecision::DECISION_APPROVED
        ));

        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status);

        return $queries;
    }

    /**
     * The rule's distinct approvers who pass the access check one by one.
     *
     * @return list<int>
     */
    private function checkedOneByOne(ApprovalRequest $request): array
    {
        /** @var ApprovalRule|null $rule */
        $rule = $request->approvalRule;
        $this->assertNotNull($rule);
        /** @var WorkflowAccessChecker $access */
        $access = \Yii::$app->get('workflowAccessChecker');

        return array_values(array_filter(
            array_values(array_unique($rule->getApproverUserIds())),
            static fn (int $userId): bool => $access->canViewApprovalRequest($userId, $request)
        ));
    }

    /**
     * @param list<int> $approverIds
     */
    private function requestFor(User $owner, array $approverIds, int $required): ApprovalRequest
    {
        $rule = $this->createApprovalRule(
            (int)$owner->id,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => $approverIds]),
            $required
        );

        return $this->service()->createRequest($this->pendingJob($owner), $rule);
    }

    private function pendingJob(User $owner): Job
    {
        $ownerId = (int)$owner->id;
        $template = $this->createJobTemplate(
            (int)$this->createProject($ownerId)->id,
            (int)$this->createInventory($ownerId)->id,
            (int)$this->createRunnerGroup($ownerId)->id,
            $ownerId
        );

        return $this->createJob((int)$template->id, $ownerId, Job::STATUS_PENDING_APPROVAL);
    }

    private function service(): ApprovalService
    {
        /** @var ApprovalService $service */
        $service = \Yii::$app->get('approvalService');
        return $service;
    }

    private function projects(): ProjectAccessChecker
    {
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');
        return $checker;
    }
}
