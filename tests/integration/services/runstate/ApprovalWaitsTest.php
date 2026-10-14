<?php

declare(strict_types=1);

namespace app\tests\integration\services\runstate;

use app\helpers\TimeHelper;
use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\TeamProject;
use app\models\User;
use app\services\ApprovalDecisionRefusedException;
use app\services\ApprovalService;
use app\services\runstate\ApprovalWait;
use app\services\runstate\Display;
use app\services\runstate\Viewer;
use app\tests\integration\CountsQueries;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The approval requests of jobs as a viewer may see them: counts without
 * the launcher's votes, the rule and its timeout, the viewer's relation,
 * the eligibility of the approvers and how a request was decided.
 */
class ApprovalWaitsTest extends DbTestCase
{
    use TeamScopeFixtures;
    use RunStateFixtures;
    use CountsQueries;

    private const REQUESTED = 1760000150;
    private const RESOLVED = 1760000300;

    private User $admin;

    private int $templateId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('aw-admin', 'admin');
        $this->templateId = (int)$this->openJobTemplate((int)$this->admin->id)->id;
    }

    public function testTheSummaryCountsApprovalsWithoutTheLaunchersVote(): void
    {
        $launcher = $this->namedUser('aw-launcher');
        [$one, $two, $three] = [$this->namedUser('aw-one', 'operator'), $this->namedUser('aw-two', 'operator'), $this->namedUser('aw-three', 'operator')];
        $rule = $this->ruleOf((int)$this->admin->id, [$one, $two, $three], 2, 30, ApprovalRule::TIMEOUT_ACTION_APPROVE);
        $job = $this->jobOf($this->templateId, (int)$launcher->id, Job::STATUS_PENDING_APPROVAL);
        $request = $this->requestOf($job, $rule, ApprovalRequest::STATUS_PENDING, self::REQUESTED, self::REQUESTED + 1800);
        $this->voteOn($request, $launcher, ApprovalDecision::DECISION_APPROVED);
        $this->voteOn($request, $one, ApprovalDecision::DECISION_APPROVED);
        $this->voteOn($request, $two, ApprovalDecision::DECISION_REJECTED);
        $withoutRequest = $this->jobOf($this->templateId, (int)$launcher->id, Job::STATUS_QUEUED);

        $waits = $this->approvalWaits()->forJobs([(int)$job->id, (int)$withoutRequest->id, 0, -1, (int)$job->id], Viewer::of((int)$this->admin->id));

        $this->assertSame([(int)$job->id], array_keys($waits));
        $wait = $waits[(int)$job->id];
        $this->assertSame((int)$request->id, $wait->requestId);
        $this->assertSame((int)$job->id, $wait->jobId);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $wait->status);
        $this->assertSame((int)$rule->id, $wait->ruleId);
        $this->assertSame($rule->name, $wait->ruleName);
        $this->assertSame(2, $wait->requiredApprovals);
        $this->assertSame(1, $wait->approvalCount, 'the launcher\'s approval does not count');
        $this->assertSame(1, $wait->rejectionCount);
        $this->assertSame($request->approvalCount(), $wait->approvalCount);
        $this->assertSame(self::REQUESTED, $wait->requestedAt);
        $this->assertSame(self::REQUESTED + 1800, $wait->expiresAt);
        $this->assertNull($wait->resolvedAt);
        $this->assertSame(ApprovalRule::TIMEOUT_ACTION_APPROVE, $wait->timeoutAction);
        $this->assertTrue($wait->linkable);
        $this->assertNull($wait->relation);
        $this->assertNull($wait->eligibleCount);
        $this->assertNull($wait->openCount);
        $this->assertNull($wait->approvable);
        $this->assertNull($wait->decisionNote);
    }

    public function testTheTimeoutActionNeedsATimeoutAndARule(): void
    {
        $launcher = $this->namedUser('aw-launcher');
        $rejecting = $this->ruleOf((int)$this->admin->id, [$this->namedUser('aw-one', 'operator')], 1, 10, ApprovalRule::TIMEOUT_ACTION_REJECT);
        $expiring = $this->jobOf($this->templateId, (int)$launcher->id, Job::STATUS_PENDING_APPROVAL);
        $this->requestOf($expiring, $rejecting, ApprovalRequest::STATUS_PENDING, self::REQUESTED, self::REQUESTED + 600);
        $open = $this->jobOf($this->templateId, (int)$launcher->id, Job::STATUS_PENDING_APPROVAL);
        $this->requestOf($open, $rejecting);
        $ruleless = $this->jobOf($this->templateId, (int)$launcher->id, Job::STATUS_REJECTED);
        $this->requestOf($ruleless, null, ApprovalRequest::STATUS_REJECTED, self::REQUESTED, self::REQUESTED + 600, self::RESOLVED);

        $waits = $this->approvalWaits()->forJobs([(int)$expiring->id, (int)$open->id, (int)$ruleless->id], Viewer::of((int)$this->admin->id));

        $this->assertSame(ApprovalRule::TIMEOUT_ACTION_REJECT, $waits[(int)$expiring->id]->timeoutAction);
        $this->assertNull($waits[(int)$open->id]->timeoutAction, 'a request without a timeout');
        $this->assertNull($waits[(int)$open->id]->expiresAt);
        $gone = $waits[(int)$ruleless->id];
        $this->assertNull($gone->ruleId);
        $this->assertNull($gone->ruleName);
        $this->assertNull($gone->requiredApprovals);
        $this->assertNull($gone->timeoutAction, 'a request whose rule was deleted');
        $this->assertSame(self::RESOLVED, $gone->resolvedAt);
    }

    public function testOnlyRequestsTheViewerMaySeeAreSummarised(): void
    {
        $scope = $this->teamScope();
        $adminId = (int)$scope['admin']->id;
        $rule = $this->ruleOf($adminId, [$scope['outsider']]);
        $foreign = $this->jobOf((int)$scope['foreign']->id, $adminId, Job::STATUS_PENDING_APPROVAL);
        $this->requestOf($foreign, $rule);
        $own = $this->jobOf((int)$scope['own']->id, $adminId, Job::STATUS_PENDING_APPROVAL);
        $this->requestOf($own, $rule);
        $jobIds = [(int)$foreign->id, (int)$own->id];

        $this->assertSame([(int)$own->id], array_keys($this->approvalWaits()->forJobs($jobIds, Viewer::of((int)$scope['member']->id))));
        $this->assertSame($jobIds, array_keys($this->approvalWaits()->forJobs($jobIds, Viewer::of($adminId))));
        $this->assertSame([], $this->approvalWaits()->forJobs($jobIds, Viewer::of(0)), 'nobody sees no request');
    }

    public function testOnlyAViewerWithApprovalViewMayOpenTheRequestPage(): void
    {
        $job = $this->jobOf($this->templateId, (int)$this->admin->id, Job::STATUS_PENDING_APPROVAL);
        $this->requestOf($job, $this->ruleOf((int)$this->admin->id, [$this->namedUser('aw-one', 'operator')]));
        $jobId = (int)$job->id;

        $this->assertTrue($this->approvalWaits()->forJobs([$jobId], Viewer::of((int)$this->namedUser('aw-viewer', 'viewer')->id))[$jobId]->linkable);
        $this->assertFalse($this->approvalWaits()->forJobs([$jobId], Viewer::of((int)$this->namedUser('aw-roleless')->id))[$jobId]->linkable);
    }

    /**
     * Every relation of a viewer to a pending request (for the run page's
     * personal line), and none where none applies.
     */
    public function testTheViewerRelation(): void
    {
        $launcher = $this->namedUser('aw-launcher', 'viewer');
        $approver = $this->namedUser('aw-approver', 'operator');
        $voter = $this->namedUser('aw-voter', 'operator');
        $listedViewer = $this->namedUser('aw-listed-viewer', 'viewer');
        $listedRoleless = $this->namedUser('aw-listed-roleless');
        $superadmin = $this->namedUser('aw-super');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $listedSuperadmin = $this->namedUser('aw-listed-super');
        $listedSuperadmin->is_superadmin = true;
        $listedSuperadmin->save(false);
        $rule = $this->ruleOf((int)$this->admin->id, [$launcher, $approver, $voter, $listedViewer, $listedRoleless, $listedSuperadmin], 2);
        $job = $this->jobOf($this->templateId, (int)$launcher->id, Job::STATUS_PENDING_APPROVAL);
        $request = $this->requestOf($job, $rule);
        $this->voteOn($request, $voter, ApprovalDecision::DECISION_APPROVED);

        $relations = [
            'launcher' => [$launcher, Display::RELATION_LAUNCHER],
            'approver' => [$approver, Display::RELATION_MAY_DECIDE],
            'voted' => [$voter, Display::RELATION_VOTED],
            'listed without approval.decide' => [$listedViewer, Display::RELATION_NOT_ELIGIBLE],
            'listed without approval.view' => [$listedRoleless, null],
            'operator not listed' => [$this->namedUser('aw-other', 'operator'), Display::RELATION_NOT_APPROVER],
            'superadmin not listed' => [$superadmin, Display::RELATION_NOT_APPROVER],
            'superadmin listed' => [$listedSuperadmin, Display::RELATION_MAY_DECIDE],
        ];
        foreach ($relations as $case => [$user, $relation]) {
            $this->assertSame($relation, $this->relationOf($job, Viewer::of((int)$user->id), true), $case);
            $this->assertNull($this->relationOf($job, Viewer::of((int)$user->id), false), "{$case}: only for the viewer's own page");
        }
        $this->assertNull($this->relationOf($job, Viewer::of(0), true), 'nobody');
    }

    /**
     * The relation is the answer a vote of the viewer would get now
     * (ApprovalService::decisionRefusal(), which ApprovalSettlement checks
     * under the request's lock): no refusal is "may decide", a vote of
     * theirs "voted", not eligible "not eligible" for a user on the rule's
     * list and "not an approver" for anyone else, the launcher "launcher".
     * Also for a voter who holds no approval.decide (any more), and for a
     * listed operator whom team scoping keeps from the job's project.
     */
    public function testTheRelationIsTheAnswerAVoteWouldGet(): void
    {
        $launcher = $this->namedUser('aw-launcher', 'operator');
        $approver = $this->namedUser('aw-approver', 'operator');
        $voter = $this->namedUser('aw-voter', 'operator');
        $formerVoter = $this->namedUser('aw-former-voter', 'viewer');
        $listedViewer = $this->namedUser('aw-listed-viewer', 'viewer');
        $outsider = $this->namedUser('aw-outsider', 'operator');
        // The job's project belongs to a team: the outsider, who is no member, may not see it.
        $team = $this->createTeam((int)$this->admin->id);
        foreach ([$launcher, $approver, $voter, $formerVoter, $listedViewer] as $member) {
            $this->addTeamMember((int)$team->id, (int)$member->id);
        }
        $template = JobTemplate::findOne($this->templateId);
        $this->assertNotNull($template);
        $this->createTeamProject((int)$team->id, (int)$template->project_id, TeamProject::ROLE_OPERATOR);
        $rule = $this->ruleOf((int)$this->admin->id, [$launcher, $approver, $voter, $formerVoter, $listedViewer, $outsider], 3);
        $job = $this->jobOf($this->templateId, (int)$launcher->id, Job::STATUS_PENDING_APPROVAL);
        $request = $this->requestOf($job, $rule);
        $this->voteOn($request, $voter, ApprovalDecision::DECISION_APPROVED);
        $this->voteOn($request, $formerVoter, ApprovalDecision::DECISION_APPROVED);
        $users = [$launcher, $approver, $voter, $formerVoter, $listedViewer, $outsider, $this->namedUser('aw-other', 'operator'), $this->admin];

        $relations = [];
        foreach ($users as $user) {
            $userId = (int)$user->id;
            $refusal = $this->approvalService()->decisionRefusal($request, $userId);
            $listed = in_array($userId, $rule->getApproverUserIds(), true);
            $relations[$user->username] = [self::relationOfRefusal($refusal, $listed), $this->relationOf($job, Viewer::of($userId), true)];
        }

        foreach ($relations as $username => [$vote, $relation]) {
            $this->assertSame($vote, $relation, $username);
        }
        $this->assertSame(
            [
                Display::RELATION_LAUNCHER,
                Display::RELATION_MAY_DECIDE,
                Display::RELATION_VOTED,
                Display::RELATION_NOT_ELIGIBLE,
                Display::RELATION_NOT_ELIGIBLE,
                Display::RELATION_NOT_ELIGIBLE,
                Display::RELATION_NOT_APPROVER,
                Display::RELATION_NOT_APPROVER,
            ],
            array_column(array_values($relations), 1),
            'launcher, approver, voter, former voter, listed viewer, outsider, other operator, admin'
        );
    }

    /**
     * regression: the viewer's relation computed the approvers' eligibility
     * a second time (ApprovalService::decisionRefusal(), then
     * ApproverEligibility::mayDecide() for the viewer alone) and looked up
     * the viewer's vote with a query of its own, on every poll of the run
     * page. It reads only whether the request's job still waits (its
     * workflow step and its job template).
     */
    public function testRegressionTheRelationComputedTheEligibilityASecondTime(): void
    {
        $approver = $this->namedUser('aw-approver', 'operator');
        $job = $this->jobOf($this->templateId, (int)$this->admin->id, Job::STATUS_PENDING_APPROVAL);
        $this->requestOf($job, $this->ruleOf((int)$this->admin->id, [$approver]));
        $viewer = Viewer::of((int)$approver->id);
        $wait = $this->approvalWaits()->forJobs([(int)$job->id], $viewer)[(int)$job->id];
        // Once before measuring: table schemas are read on first use.
        $this->approvalWaits()->withEligibility($wait, $viewer, true);

        $withoutRelation = $this->queriesOf(fn () => $this->approvalWaits()->withEligibility($wait, $viewer, false));
        $relation = null;
        $withRelation = $this->queriesOf(function () use ($wait, $viewer, &$relation): void {
            $relation = $this->approvalWaits()->withEligibility($wait, $viewer, true)->relation;
        });

        $this->assertSame(Display::RELATION_MAY_DECIDE, $relation);
        $this->assertLessThanOrEqual(2, $withRelation - $withoutRelation, 'the relation adds the reads of whether the job still waits, nothing else');
    }

    public function testTheLauncherIsToldSoAlsoWithoutApprovalView(): void
    {
        $launcher = $this->namedUser('aw-launcher');
        $job = $this->jobOf($this->templateId, (int)$launcher->id, Job::STATUS_PENDING_APPROVAL);
        $this->requestOf($job, $this->ruleOf((int)$this->admin->id, [$this->namedUser('aw-one', 'operator')]));

        $this->assertSame(Display::RELATION_LAUNCHER, $this->relationOf($job, Viewer::of((int)$launcher->id), true));
    }

    public function testNoRelationOnceTheRequestCanNoLongerBeDecided(): void
    {
        $approver = $this->namedUser('aw-approver', 'operator');
        $rule = $this->ruleOf((int)$this->admin->id, [$approver], 1, 5);
        $expired = $this->jobOf($this->templateId, (int)$this->admin->id, Job::STATUS_PENDING_APPROVAL);
        $this->requestOf($expired, $rule, ApprovalRequest::STATUS_PENDING, self::REQUESTED, time() - 60);
        $notWaiting = $this->jobOf($this->templateId, (int)$this->admin->id, Job::STATUS_QUEUED);
        $this->requestOf($notWaiting, $rule);
        $viewer = Viewer::of((int)$approver->id);

        $this->assertNull($this->relationOf($expired, $viewer, true), 'an expired request');
        $this->assertNull($this->relationOf($notWaiting, $viewer, true), 'a job that no longer waits');
    }

    /**
     * approvable: the approvals that count plus the eligible approvers who
     * have not voted reach the threshold, as a vote settles the request.
     */
    public function testEligibilityAndWhetherTheRequestCanStillBeApproved(): void
    {
        $launcher = $this->namedUser('aw-launcher', 'operator');
        $one = $this->namedUser('aw-one', 'operator');
        $two = $this->namedUser('aw-two', 'operator');
        $three = $this->namedUser('aw-three', 'operator');
        $disabled = $this->namedUser('aw-disabled', 'operator');
        $disabled->status = User::STATUS_INACTIVE;
        $disabled->save(false);
        $viewer = Viewer::of((int)$this->admin->id);

        $short = $this->eligibilityOf([$one, $disabled, $launcher], 2, $launcher, [], $viewer);
        $this->assertSame([1, 1, false], $short, 'a disabled approver and the launcher never decide');

        $voted = $this->eligibilityOf([$one, $two, $three], 2, $launcher, [[$one, ApprovalDecision::DECISION_APPROVED]], $viewer);
        $this->assertSame([3, 2, true], $voted);

        $rejected = $this->eligibilityOf(
            [$one, $two, $three],
            2,
            $launcher,
            [[$one, ApprovalDecision::DECISION_APPROVED], [$two, ApprovalDecision::DECISION_REJECTED], [$three, ApprovalDecision::DECISION_REJECTED]],
            $viewer
        );
        $this->assertSame([3, 0, false], $rejected);

        $job = $this->jobOf($this->templateId, (int)$launcher->id, Job::STATUS_PENDING_APPROVAL);
        $this->requestOf($job, null);
        $ruleless = $this->approvalWaits()->withEligibility($this->approvalWaits()->forJobs([(int)$job->id], $viewer)[(int)$job->id], $viewer, false);
        $this->assertSame([0, 0, false], [$ruleless->eligibleCount, $ruleless->openCount, $ruleless->approvable], 'without its rule');
    }

    public function testWithEligibilityKeepsTheRestOfTheWait(): void
    {
        $approver = $this->namedUser('aw-approver', 'operator');
        $rule = $this->ruleOf((int)$this->admin->id, [$approver], 1, 30, ApprovalRule::TIMEOUT_ACTION_APPROVE);
        $job = $this->jobOf($this->templateId, (int)$this->admin->id, Job::STATUS_PENDING_APPROVAL);
        $this->requestOf($job, $rule, ApprovalRequest::STATUS_PENDING, self::REQUESTED, time() + 600);
        $viewer = Viewer::of((int)$approver->id);
        $wait = $this->approvalWaits()->forJobs([(int)$job->id], $viewer)[(int)$job->id];

        $full = $this->approvalWaits()->withEligibility($wait, $viewer, true);

        $this->assertNotSame($wait, $full);
        $expected = (array)$wait;
        $expected['relation'] = Display::RELATION_MAY_DECIDE;
        $expected['eligibleCount'] = 1;
        $expected['openCount'] = 1;
        $expected['approvable'] = true;
        $this->assertSame($expected, (array)$full);
    }

    public function testWithEligibilityLeavesAResolvedOrVanishedRequestAsItIs(): void
    {
        $rule = $this->ruleOf((int)$this->admin->id, [$this->namedUser('aw-one', 'operator')]);
        $approved = $this->jobOf($this->templateId, (int)$this->admin->id, Job::STATUS_QUEUED);
        $this->requestOf($approved, $rule, ApprovalRequest::STATUS_APPROVED, self::REQUESTED, null, self::RESOLVED);
        $vanishing = $this->jobOf($this->templateId, (int)$this->admin->id, Job::STATUS_PENDING_APPROVAL);
        $request = $this->requestOf($vanishing, $rule);
        $viewer = Viewer::of((int)$this->admin->id);
        $waits = $this->approvalWaits()->forJobs([(int)$approved->id, (int)$vanishing->id], $viewer);
        $request->delete();

        $this->assertSame($waits[(int)$approved->id], $this->approvalWaits()->withEligibility($waits[(int)$approved->id], $viewer, true));
        $this->assertSame($waits[(int)$vanishing->id], $this->approvalWaits()->withEligibility($waits[(int)$vanishing->id], $viewer, true));
    }

    /**
     * How a request was decided, and who may read the names: approvers'
     * and rejecters' names (and the approvers' comment) only with
     * approval.view; the launcher's own legacy vote is never named.
     */
    public function testDecisionNotes(): void
    {
        $launcher = $this->namedUser('aw-launcher');
        $one = $this->namedUser('aw-one', 'operator');
        $two = $this->namedUser('aw-two', 'operator');
        $three = $this->namedUser('aw-three', 'operator');
        $rule = $this->ruleOf((int)$this->admin->id, [$one, $two, $three], 2);
        $at = TimeHelper::stamp(self::RESOLVED);
        $decided = function (string $status, string $jobStatus, array $votes, ?int $resolvedAt = self::RESOLVED) use ($launcher, $rule): int {
            $job = $this->jobOf($this->templateId, (int)$launcher->id, $jobStatus);
            $request = $this->requestOf($job, $rule, $status, self::REQUESTED, null, $resolvedAt);
            foreach ($votes as [$voter, $decision, $comment]) {
                $this->voteOn($request, $voter, $decision, $comment);
            }

            return (int)$job->id;
        };
        $approved = $decided(ApprovalRequest::STATUS_APPROVED, Job::STATUS_QUEUED, [
            [$launcher, ApprovalDecision::DECISION_APPROVED, 'my own'],
            [$one, ApprovalDecision::DECISION_APPROVED, 'ok'],
            [$two, ApprovalDecision::DECISION_APPROVED, '  '],
            [$three, ApprovalDecision::DECISION_REJECTED, 'no'],
        ]);
        $lastComment = $decided(ApprovalRequest::STATUS_APPROVED, Job::STATUS_SUCCEEDED, [
            [$one, ApprovalDecision::DECISION_APPROVED, 'first'],
            [$two, ApprovalDecision::DECISION_APPROVED, 'second'],
        ]);
        $uncommented = $decided(ApprovalRequest::STATUS_APPROVED, Job::STATUS_SUCCEEDED, [
            [$one, ApprovalDecision::DECISION_APPROVED, null],
            [$two, ApprovalDecision::DECISION_APPROVED, ''],
        ]);
        $byLauncherOnly = $decided(ApprovalRequest::STATUS_APPROVED, Job::STATUS_QUEUED, [
            [$launcher, ApprovalDecision::DECISION_APPROVED, 'self'],
        ]);
        $rejected = $decided(ApprovalRequest::STATUS_REJECTED, Job::STATUS_REJECTED, [
            [$launcher, ApprovalDecision::DECISION_REJECTED, null],
            [$two, ApprovalDecision::DECISION_REJECTED, 'not now'],
            [$three, ApprovalDecision::DECISION_REJECTED, null],
        ]);
        $timeoutApproved = $decided(ApprovalRequest::STATUS_TIMED_OUT, Job::STATUS_QUEUED, []);
        $timeoutRejected = $decided(ApprovalRequest::STATUS_TIMED_OUT, Job::STATUS_REJECTED, []);
        $canceled = $decided(ApprovalRequest::STATUS_CANCELED, Job::STATUS_CANCELED, [[$one, ApprovalDecision::DECISION_APPROVED, null]]);
        $pending = $decided(ApprovalRequest::STATUS_PENDING, Job::STATUS_PENDING_APPROVAL, [[$one, ApprovalDecision::DECISION_APPROVED, 'ok']], null);
        $undated = $decided(ApprovalRequest::STATUS_APPROVED, Job::STATUS_QUEUED, [[$one, ApprovalDecision::DECISION_APPROVED, null]], null);
        $jobIds = [$approved, $lastComment, $uncommented, $byLauncherOnly, $rejected, $timeoutApproved, $timeoutRejected, $canceled, $pending, $undated];

        $names = $this->notesOf($jobIds, Viewer::of((int)$this->admin->id));
        $this->assertSame([
            $approved => sprintf('Approved by %s, %s at %s. Comment: "ok"', $one->username, $two->username, $at),
            $lastComment => sprintf('Approved by %s, %s at %s. Comment: "second"', $one->username, $two->username, $at),
            $uncommented => sprintf('Approved by %s, %s at %s.', $one->username, $two->username, $at),
            $byLauncherOnly => sprintf('Approved at %s.', $at),
            $rejected => sprintf('Rejected by %s, %s at %s.', $two->username, $three->username, $at),
            $timeoutApproved => sprintf('Approved by its timeout at %s, without a decision.', $at),
            $timeoutRejected => null,
            $canceled => null,
            $pending => null,
            $undated => null,
        ], $names);

        $anonymous = $this->notesOf($jobIds, Viewer::of((int)$this->namedUser('aw-watcher')->id));
        $this->assertSame(sprintf('Approved at %s.', $at), $anonymous[$approved], 'no names and no comment without approval.view');
        $this->assertSame(sprintf('Approved at %s.', $at), $anonymous[$lastComment]);
        $this->assertNull($anonymous[$rejected], 'a rejection names only its rejecters');
        $this->assertSame($names[$timeoutApproved], $anonymous[$timeoutApproved]);

        foreach ($this->approvalWaits()->forJobs($jobIds, Viewer::of((int)$this->admin->id)) as $wait) {
            $this->assertNull($wait->decisionNote, 'notes only with decisions');
        }
    }

    public function testForJobsUsesAConstantNumberOfQueries(): void
    {
        $approver = $this->namedUser('aw-approver', 'operator');
        $rule = $this->ruleOf((int)$this->admin->id, [$approver]);
        $jobIds = [];
        for ($i = 0; $i < 8; $i++) {
            $job = $this->jobOf($this->templateId, (int)$this->admin->id, Job::STATUS_QUEUED);
            $request = $this->requestOf($job, $rule, ApprovalRequest::STATUS_APPROVED, self::REQUESTED, null, self::RESOLVED);
            $this->voteOn($request, $approver, ApprovalDecision::DECISION_APPROVED, 'ok ' . $i);
            $jobIds[] = (int)$job->id;
        }
        $this->approvalWaits()->forJobs($jobIds, Viewer::of((int)$this->admin->id), true);

        $one = $this->queriesOf(fn () => $this->approvalWaits()->forJobs([$jobIds[0]], Viewer::of((int)$this->admin->id), true));
        $eight = $this->queriesOf(fn () => $this->approvalWaits()->forJobs($jobIds, Viewer::of((int)$this->admin->id), true));

        $this->assertSame($one, $eight);
        $this->assertCount(8, $this->approvalWaits()->forJobs($jobIds, Viewer::of((int)$this->admin->id), true));
    }

    public function testForJobsWithoutJobsAsksNothing(): void
    {
        $viewer = Viewer::of((int)$this->admin->id);

        $this->assertSame(0, $this->queriesOf(fn () => $this->approvalWaits()->forJobs([], $viewer)));
        $this->assertSame(0, $this->queriesOf(fn () => $this->approvalWaits()->forJobs([0, -3], $viewer)));
        $this->assertSame([], $this->approvalWaits()->forJobs([0, -3], $viewer));
    }

    /**
     * [eligibleCount, openCount, approvable] of a pending request of a job
     * launched by $launcher whose rule lists $approvers, after $votes.
     *
     * @param list<User> $approvers
     * @param list<array{0: User, 1: string}> $votes
     * @return array{0: int|null, 1: int|null, 2: bool|null}
     */
    private function eligibilityOf(array $approvers, int $required, User $launcher, array $votes, Viewer $viewer): array
    {
        $job = $this->jobOf($this->templateId, (int)$launcher->id, Job::STATUS_PENDING_APPROVAL);
        $request = $this->requestOf($job, $this->ruleOf((int)$this->admin->id, $approvers, $required));
        foreach ($votes as [$voter, $decision]) {
            $this->voteOn($request, $voter, $decision);
        }
        $wait = $this->approvalWaits()->withEligibility($this->approvalWaits()->forJobs([(int)$job->id], $viewer)[(int)$job->id], $viewer, false);

        return [$wait->eligibleCount, $wait->openCount, $wait->approvable];
    }

    /**
     * The relation a refused (or recorded) vote stands for, as the run
     * page's personal line names it.
     */
    private static function relationOfRefusal(?ApprovalDecisionRefusedException $refusal, bool $listed): ?string
    {
        if ($refusal === null) {
            return Display::RELATION_MAY_DECIDE;
        }

        return match ($refusal->reason()) {
            ApprovalDecisionRefusedException::REASON_LAUNCHER => Display::RELATION_LAUNCHER,
            ApprovalDecisionRefusedException::REASON_ALREADY_VOTED => Display::RELATION_VOTED,
            ApprovalDecisionRefusedException::REASON_NOT_ELIGIBLE => $listed ? Display::RELATION_NOT_ELIGIBLE : Display::RELATION_NOT_APPROVER,
            default => null,
        };
    }

    private function approvalService(): ApprovalService
    {
        /** @var ApprovalService $service */
        $service = \Yii::$app->get('approvalService');

        return $service;
    }

    private function relationOf(Job $job, Viewer $viewer, bool $personal): ?string
    {
        $wait = $this->approvalWaits()->forJobs([(int)$job->id], Viewer::of((int)$this->admin->id))[(int)$job->id];
        $this->assertInstanceOf(ApprovalWait::class, $wait);

        return $this->approvalWaits()->withEligibility($wait, $viewer, $personal)->relation;
    }

    /**
     * @param list<int> $jobIds
     * @return array<int, string|null>
     */
    private function notesOf(array $jobIds, Viewer $viewer): array
    {
        $notes = [];
        $waits = $this->approvalWaits()->forJobs($jobIds, $viewer, true);
        foreach ($jobIds as $jobId) {
            $notes[$jobId] = $waits[$jobId]->decisionNote;
        }

        return $notes;
    }
}
