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
     */
    private function request(JobTemplate $template, array $approvers, int $required = 1): ApprovalRequest
    {
        $createdBy = (int)$template->created_by;
        $job = $this->createJob((int)$template->id, $createdBy, Job::STATUS_PENDING_APPROVAL);
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
