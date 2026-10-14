<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\ApprovalsController;
use app\models\ApiToken;
use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\services\ApprovalDecisionRefusedException;
use app\services\JobCancellationService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\web\NotFoundHttpException;

/**
 * Approvals API under team scoping.
 *
 * Regression: the list returned every team's requests, any request could be
 * read, and a listed approver could decide on a job of a project they cannot
 * see.
 */
class ApprovalsControllerTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    // ── index ────────────────────────────────────────────────────────────────

    public function testIndexListsAndCountsOnlyTheRequestsTheCallerMaySee(): void
    {
        $s = $this->teamScope();
        $requests = $this->requestPerTemplate($s);
        $this->authenticate($s['member']);

        $result = $this->controller()->actionIndex();

        $ids = array_column($result['data'], 'id');
        $this->assertContains($requests['own'], $ids);
        $this->assertContains($requests['viewed'], $ids);
        $this->assertContains($requests['open'], $ids);
        $this->assertNotContains($requests['foreign'], $ids);
        $this->assertSame(count($ids), $result['meta']['total']);
        foreach ($result['data'] as $row) {
            $request = ApprovalRequest::findOne($row['id']);
            $this->assertNotNull($request);
            $this->assertSame($request->launcherId(), $row['launched_by']);
        }
    }

    public function testIndexListsEveryRequestForAnAdmin(): void
    {
        $s = $this->teamScope();
        $requests = $this->requestPerTemplate($s);
        $this->authenticate($s['admin']);

        $result = $this->controller()->actionIndex();

        $ids = array_column($result['data'], 'id');
        foreach ($requests as $id) {
            $this->assertContains($id, $ids);
        }
        $this->assertSame(count($ids), $result['meta']['total']);
    }

    // ── view ─────────────────────────────────────────────────────────────────

    public function testViewOfARequestOfAnotherTeamIs403(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['foreign'], [$s['member']]);
        $this->authenticate($s['member']);

        $result = $this->controller()->actionView((int)$request->id);

        $this->assertSame(['error' => ['message' => 'Forbidden.']], $result);
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    public function testViewOfAVisibleRequest(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['viewed'], [$s['member']]);
        $this->authenticate($s['member']);

        $result = $this->controller()->actionView((int)$request->id);

        $this->assertArrayHasKey('data', $result);
        $this->assertSame($request->id, $result['data']['id']);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $result['data']['status']);
        $this->assertSame($request->launcherId(), $result['data']['launched_by']);
        $this->assertSame(
            [
                'id', 'job_id', 'launched_by', 'approval_rule_id', 'status', 'requested_at', 'resolved_at', 'expires_at', 'approval_count', 'rejection_count',
                'decisions', 'workflow_job_id', 'workflow_job_step_id', 'workflow_template_id', 'workflow_step_name',
            ],
            array_keys($result['data'])
        );
        // No vote yet (ApprovalsDecisionsApiTest has the votes).
        $this->assertSame([], $result['data']['decisions']);
        // The job of a job template outside any workflow: no workflow run to name.
        foreach (['workflow_job_id', 'workflow_job_step_id', 'workflow_template_id', 'workflow_step_name'] as $field) {
            $this->assertNull($result['data'][$field], $field);
        }
    }

    public function testViewOfAMissingRequestIs404(): void
    {
        $s = $this->teamScope();
        $this->authenticate($s['member']);

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->actionView(99999999);
    }

    // ── approve / reject ─────────────────────────────────────────────────────

    public function testApproveOfARequestOfAnotherTeamIs403AndRecordsNothing(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['foreign'], [$s['member']]);
        $this->authenticate($s['member']);

        $result = $this->controller()->actionApprove((int)$request->id);

        $this->assertSame(['error' => ['message' => 'Forbidden.']], $result);
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(0, $this->decisionCount($request));
    }

    public function testRejectOfARequestOfAnotherTeamIs403AndRecordsNothing(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['foreign'], [$s['member']]);
        $this->authenticate($s['member']);

        $result = $this->controller()->actionReject((int)$request->id);

        $this->assertSame(['error' => ['message' => 'Forbidden.']], $result);
        $this->assertSame(0, $this->decisionCount($request));
    }

    public function testApproveByAnUnlistedUserIs403(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['own'], [$s['admin']]);
        $this->authenticate($s['member']);

        $result = $this->controller()->actionApprove((int)$request->id);

        $this->assertSame(['error' => ['message' => 'You are not eligible to decide on this request.']], $result);
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(0, $this->decisionCount($request));
    }

    public function testApproveByAnEligibleApprover(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['own'], [$s['member']]);
        $this->authenticate($s['member']);
        $this->setBody(['comment' => 'ship it']);

        $result = $this->controller()->actionApprove((int)$request->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $result['data']['status']);
        $this->assertSame(1, $result['data']['approval_count']);
        $decision = ApprovalDecision::findOne(['approval_request_id' => $request->id]);
        $this->assertNotNull($decision);
        $this->assertSame('ship it', $decision->comment);
    }

    public function testRejectByAnyListedApproverOnAnOpenProject(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['open'], [$s['outsider']]);
        $this->authenticate($s['outsider']);

        $result = $this->controller()->actionReject((int)$request->id);

        $this->assertSame(ApprovalRequest::STATUS_REJECTED, $result['data']['status']);
        $this->assertSame(1, $result['data']['rejection_count']);
    }

    public function testAListedAdminDecidesOnAnotherTeamsRequest(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['foreign'], [$s['admin']]);
        $this->authenticate($s['admin']);

        $result = $this->controller()->actionApprove((int)$request->id);

        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $result['data']['status']);
    }

    /**
     * Regression: the launcher could approve their own job through the API.
     */
    public function testRegressionTheLauncherCouldApproveTheirOwnJob(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['own'], [$s['member'], $s['outsider']], 1, $s['member']);
        $this->authenticate($s['member']);

        $result = $this->controller()->actionApprove((int)$request->id);

        $this->assertSame(['error' => ['message' => ApprovalDecisionRefusedException::LAUNCHER_REFUSED]], $result);
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(0, $this->decisionCount($request));
    }

    /**
     * A vote on a request that is no longer pending answers 409 with the
     * request's status, also for a caller who is no approver (the state is
     * checked first; the caller may see the request anyway).
     *
     * Regression: a resolved request answered 403 "not eligible", as if the
     * approver were none.
     */
    public function testAVoteOnAResolvedRequestIs409WithItsStatus(): void
    {
        $s = $this->teamScope();
        // An open project: the outsider, who is no approver, may see the request.
        $request = $this->request($s['open'], [$s['member'], $s['admin']]);
        $this->authenticate($s['admin']);
        $this->controller()->actionApprove((int)$request->id);

        foreach ([$s['member'], $s['outsider']] as $voter) {
            $this->authenticate($voter);
            $result = $this->controller()->actionReject((int)$request->id);

            $this->assertSame(['error' => [
                'message' => sprintf(ApprovalDecisionRefusedException::RESOLVED, (int)$request->id, 'approved'),
                'status' => ApprovalRequest::STATUS_APPROVED,
            ]], $result);
            $this->assertSame(409, \Yii::$app->response->statusCode);
        }
        $this->assertSame(1, $this->decisionCount($request));
    }

    public function testAVoteOnAnExpiredRequestIs409(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['own'], [$s['member']]);
        $request->expires_at = time() - 60;
        $request->save(false);
        $this->authenticate($s['member']);

        $result = $this->controller()->actionApprove((int)$request->id);

        $this->assertSame(['error' => [
            'message' => sprintf(ApprovalDecisionRefusedException::EXPIRED, (int)$request->id),
            'status' => ApprovalRequest::STATUS_PENDING,
        ]], $result);
        $this->assertSame(409, \Yii::$app->response->statusCode);
        $this->assertSame(0, $this->decisionCount($request));
    }

    public function testAVoteAfterTheJobWasCanceledIs409Canceled(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['own'], [$s['member']]);
        $job = Job::findOne($request->job_id);
        $this->assertNotNull($job);
        /** @var JobCancellationService $cancellation */
        $cancellation = \Yii::$app->get('jobCancellationService');
        $this->assertTrue($cancellation->cancel($job, (int)$s['admin']->id));
        $this->authenticate($s['member']);

        $result = $this->controller()->actionApprove((int)$request->id);

        $this->assertSame(['error' => [
            'message' => sprintf(ApprovalDecisionRefusedException::RESOLVED, (int)$request->id, 'canceled'),
            'status' => ApprovalRequest::STATUS_CANCELED,
        ]], $result);
        $this->assertSame(409, \Yii::$app->response->statusCode);
    }

    public function testAVoteOnARequestWhoseJobNoLongerWaitsIs409AndClosesIt(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['own'], [$s['member']]);
        Job::updateAll(['status' => Job::STATUS_FAILED], ['id' => $request->job_id]);
        $this->authenticate($s['member']);

        $result = $this->controller()->actionApprove((int)$request->id);

        $this->assertSame(['error' => [
            'message' => sprintf(ApprovalDecisionRefusedException::JOB_NOT_WAITING, (int)$request->id, (int)$request->job_id),
            'status' => ApprovalRequest::STATUS_CANCELED,
        ]], $result);
        $this->assertSame(409, \Yii::$app->response->statusCode);
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $request->status);
    }

    public function testACommentThatIsTooLongIs422(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['own'], [$s['member']]);
        $this->authenticate($s['member']);
        $this->setBody(['comment' => str_repeat('x', 1001)]);

        $result = $this->controller()->actionReject((int)$request->id);

        $this->assertSame(['error' => ['message' => sprintf(
            ApprovalDecisionRefusedException::INVALID_DECISION,
            'Comment should contain at most 1,000 characters.'
        )]], $result);
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(0, $this->decisionCount($request));
    }

    /**
     * Regression: a comment that is no text ({"comment": ["x"]}) ended in a
     * server error ("Array to string conversion"), and a number or a
     * boolean was stored as text. They answer 422 now, and nothing is
     * recorded.
     *
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function commentThatIsNoTextProvider(): array
    {
        return [
            'approve with a list' => ['approve', ['x']],
            'reject with an object' => ['reject', ['text' => 'x']],
            'approve with a number' => ['approve', 5],
            'reject with a boolean' => ['reject', true],
        ];
    }

    /**
     * @dataProvider commentThatIsNoTextProvider
     */
    public function testRegressionACommentThatIsNoTextEndedInAServerError(string $action, mixed $comment): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['own'], [$s['member']]);
        $this->authenticate($s['member']);
        $this->setBody(['comment' => $comment]);

        $ctrl = $this->controller();
        $result = $action === 'approve' ? $ctrl->actionApprove((int)$request->id) : $ctrl->actionReject((int)$request->id);

        $this->assertSame(['error' => ['message' => 'The decision cannot be recorded: Comment must be a string.']], $result);
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(0, $this->decisionCount($request));
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status);
    }

    public function testANullCommentIsNone(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['own'], [$s['member']]);
        $this->authenticate($s['member']);
        $this->setBody(['comment' => null]);

        $result = $this->controller()->actionApprove((int)$request->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $result['data']['status']);
        $decision = ApprovalDecision::findOne(['approval_request_id' => $request->id]);
        $this->assertNotNull($decision);
        $this->assertNull($decision->comment);
    }

    /**
     * Visibility comes first: a caller who may not see the request learns
     * nothing about the comment check.
     */
    public function testACommentThatIsNoTextOnARequestOfAnotherTeamIs403(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['foreign'], [$s['member']]);
        $this->authenticate($s['member']);
        $this->setBody(['comment' => ['x']]);

        $result = $this->controller()->actionApprove((int)$request->id);

        $this->assertSame(['error' => ['message' => 'Forbidden.']], $result);
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    public function testASecondVoteIs422(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['own'], [$s['member'], $s['admin']], 2);
        $this->authenticate($s['member']);
        $this->controller()->actionApprove((int)$request->id);

        $result = $this->controller()->actionApprove((int)$request->id);

        $this->assertSame(['error' => ['message' => 'User has already voted on this request.']], $result);
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(1, $this->decisionCount($request));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function controller(): ApprovalsController
    {
        return new ApprovalsController('api/v1/approvals', \Yii::$app);
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'approvals-api');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $component */
        $component = \Yii::$app->user;
        $component->loginByAccessToken($raw);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
    }

    /**
     * @param array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate} $s
     * @return array<string, int> request id by template key
     */
    private function requestPerTemplate(array $s): array
    {
        $ids = [];
        foreach (['own', 'viewed', 'foreign', 'open'] as $key) {
            $ids[$key] = (int)$this->request($s[$key], [$s['admin']])->id;
        }
        return $ids;
    }

    /**
     * @param list<User> $approvers
     * @param User|null $launcher who launched the job; by default a user who is no approver
     */
    private function request(JobTemplate $template, array $approvers, int $required = 1, ?User $launcher = null): ApprovalRequest
    {
        $createdBy = (int)$template->created_by;
        // Launched by a user who is no approver: the launcher's own decisions never count.
        $launcher = (int)($launcher ?? $this->createUser('launcher'))->id;
        $job = $this->createJob((int)$template->id, $launcher, Job::STATUS_PENDING_APPROVAL);
        $rule = $this->createApprovalRule(
            $createdBy,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => array_map(static fn (User $u): int => (int)$u->id, $approvers)]),
            $required
        );

        $request = new ApprovalRequest();
        $request->job_id = $job->id;
        $request->approval_rule_id = $rule->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $request->save(false);
        return $request;
    }

    private function decisionCount(ApprovalRequest $request): int
    {
        return (int)ApprovalDecision::find()->where(['approval_request_id' => $request->id])->count();
    }
}
