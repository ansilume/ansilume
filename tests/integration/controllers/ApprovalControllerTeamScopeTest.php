<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ApprovalController;
use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\tests\integration\TeamScopeFixtures;
use yii\data\ActiveDataProvider;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Approvals in the web UI under team scoping, with the real services.
 *
 * Regression: the list showed every team's requests, the detail page opened
 * for anyone, a listed approver could decide on a job of a project they cannot
 * see, the Approve/Reject buttons only checked approval.decide, and a second
 * vote ended in a server error.
 */
class ApprovalControllerTeamScopeTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    // ── index ────────────────────────────────────────────────────────────────

    public function testIndexListsOnlyTheRequestsTheUserMaySee(): void
    {
        $s = $this->teamScope();
        $requests = $this->requestPerTemplate($s);
        $this->loginAs($s['member']);

        $ids = $this->listedIds();

        $this->assertContains($requests['own'], $ids);
        $this->assertContains($requests['viewed'], $ids);
        $this->assertContains($requests['open'], $ids);
        $this->assertNotContains($requests['foreign'], $ids);
    }

    public function testIndexListsEveryRequestForAnAdmin(): void
    {
        $s = $this->teamScope();
        $requests = $this->requestPerTemplate($s);
        $this->loginAs($s['admin']);

        $ids = $this->listedIds();

        foreach ($requests as $id) {
            $this->assertContains($id, $ids);
        }
    }

    // ── view ─────────────────────────────────────────────────────────────────

    public function testViewOfARequestOfAnotherTeamIs403(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['foreign'], [$s['member']]);
        $this->loginAs($s['member']);

        $this->expectException(ForbiddenHttpException::class);
        $this->makeController()->actionView((int)$request->id);
    }

    public function testViewOfAMissingRequestIs404(): void
    {
        $s = $this->teamScope();
        $this->loginAs($s['member']);

        $this->expectException(NotFoundHttpException::class);
        $this->makeController()->actionView(99999999);
    }

    public function testViewWithoutASignedInUserIs403(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['open'], [$s['member']]);

        $this->expectException(ForbiddenHttpException::class);
        $this->makeController()->actionView((int)$request->id);
    }

    public function testViewOffersTheDecisionToAnEligibleApprover(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['own'], [$s['member']]);
        $this->loginAs($s['member']);

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$request->id);

        $this->assertSame('view', $ctrl->capturedView);
        $this->assertTrue($ctrl->capturedParams['canDecide']);
    }

    /**
     * Regression: the buttons showed for everyone with approval.decide,
     * although the request names other approvers.
     */
    public function testViewOffersNoDecisionToAnUnlistedUserWithApprovalDecide(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['own'], [$s['admin']]);
        $this->loginAs($s['member']);

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$request->id);

        $this->assertFalse($ctrl->capturedParams['canDecide']);
    }

    public function testViewOffersNoDecisionWithoutApprovalDecide(): void
    {
        $s = $this->teamScope('viewer');
        $request = $this->request($s['own'], [$s['member']]);
        $this->loginAs($s['member']);

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$request->id);

        $this->assertFalse($ctrl->capturedParams['canDecide']);
    }

    public function testViewOffersNoDecisionOnAResolvedRequest(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['own'], [$s['member']]);
        $request->status = ApprovalRequest::STATUS_APPROVED;
        $request->save(false);
        $this->loginAs($s['member']);

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$request->id);

        $this->assertFalse($ctrl->capturedParams['canDecide']);
    }

    public function testViewOffersTheDecisionToAListedSuperadminWithoutRoles(): void
    {
        $s = $this->teamScope();
        $superadmin = $this->createUser('approval-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $request = $this->request($s['foreign'], [$superadmin]);
        $this->loginAs($superadmin);

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$request->id);

        $this->assertTrue($ctrl->capturedParams['canDecide']);
    }

    public function testTheViewShowsTheButtonsOnlyWhenTheUserMayDecide(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['own'], [$s['member']]);
        $previous = \Yii::$app->controller;
        // The view's form actions are routes relative to the active controller.
        \Yii::$app->controller = $this->makeController();

        try {
            foreach ([true, false] as $canDecide) {
                $html = \Yii::$app->view->renderFile(
                    '@app/views/approval/view.php',
                    ['model' => $request, 'canDecide' => $canDecide]
                );
                $this->assertSame($canDecide, str_contains($html, '>Approve</button>'));
                $this->assertSame($canDecide, str_contains($html, '>Reject</button>'));
            }
        } finally {
            \Yii::$app->controller = $previous;
        }
    }

    // ── approve / reject ─────────────────────────────────────────────────────

    /**
     * Regression: the approver list alone decided, so the outsider's vote
     * on a job of a project they cannot see was recorded.
     */
    public function testApproveOfARequestOfAnotherTeamIs403AndRecordsNothing(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['foreign'], [$s['member']]);
        $this->loginAs($s['member']);

        try {
            $this->makeController()->actionApprove((int)$request->id);
            $this->fail('Expected 403');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame('You do not have access to this resource.', $e->getMessage());
        }
        $this->assertSame(0, $this->decisionCount($request));
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status);
    }

    public function testRejectOfARequestOfAnotherTeamIs403AndRecordsNothing(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['foreign'], [$s['member']]);
        $this->loginAs($s['member']);

        try {
            $this->makeController()->actionReject((int)$request->id);
            $this->fail('Expected 403');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame('You do not have access to this resource.', $e->getMessage());
        }
        $this->assertSame(0, $this->decisionCount($request));
    }

    public function testApproveByAnUnlistedUserIs403(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['own'], [$s['admin']]);
        $this->loginAs($s['member']);

        try {
            $this->makeController()->actionApprove((int)$request->id);
            $this->fail('Expected 403');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame('You are not eligible to decide on this request.', $e->getMessage());
        }
        $this->assertSame(0, $this->decisionCount($request));
    }

    public function testApproveByAnEligibleApproverQueuesTheJob(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['own'], [$s['member']]);
        $this->loginAs($s['member']);
        $this->setPost(['comment' => 'LGTM']);

        $result = $this->makeController()->actionApprove((int)$request->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame('Approval recorded.', \Yii::$app->session->getFlash('success'));
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $request->status);
        $decision = ApprovalDecision::findOne(['approval_request_id' => $request->id]);
        $this->assertNotNull($decision);
        $this->assertSame('LGTM', $decision->comment);
        $job = Job::findOne($request->job_id);
        $this->assertNotNull($job);
        $this->assertSame(Job::STATUS_QUEUED, $job->status);
    }

    public function testRejectByAnEligibleApproverRejectsTheJob(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['viewed'], [$s['member']]);
        $this->loginAs($s['member']);

        $this->makeController()->actionReject((int)$request->id);

        $this->assertSame('Rejection recorded.', \Yii::$app->session->getFlash('success'));
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_REJECTED, $request->status);
        $decision = ApprovalDecision::findOne(['approval_request_id' => $request->id]);
        $this->assertNotNull($decision);
        $this->assertNull($decision->comment, 'an empty comment is stored as none');
    }

    public function testAnyListedApproverDecidesOnAnOpenProject(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['open'], [$s['outsider']]);
        $this->loginAs($s['outsider']);

        $this->makeController()->actionApprove((int)$request->id);

        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $request->status);
    }

    public function testAListedAdminDecidesOnAnotherTeamsRequest(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['foreign'], [$s['admin']]);
        $this->loginAs($s['admin']);

        $this->makeController()->actionApprove((int)$request->id);

        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $request->status);
    }

    /**
     * Regression: deciding on a request another approver had already
     * resolved was refused as if the user were not an approver.
     */
    public function testApproveOfAnAlreadyResolvedRequestIsFlashed(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['own'], [$s['member'], $s['admin']]);
        $this->decide($request, $s['admin'], ApprovalDecision::DECISION_APPROVED);
        $this->loginAs($s['member']);

        $result = $this->makeController()->actionApprove((int)$request->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(
            "Approval request #{$request->id} is already resolved.",
            \Yii::$app->session->getFlash('warning')
        );
        $this->assertSame(1, $this->decisionCount($request));
    }

    /**
     * Regression: the service's refusal (here: a second vote) escaped the
     * controller as a RuntimeException, a server error for the user.
     */
    public function testASecondVoteIsFlashedInsteadOfAServerError(): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['own'], [$s['member'], $s['admin']], 2);
        $this->loginAs($s['member']);
        $this->makeController()->actionApprove((int)$request->id);
        \Yii::$app->session->removeAllFlashes();

        $result = $this->makeController()->actionReject((int)$request->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(
            'Your decision was not recorded: User has already voted on this request.',
            \Yii::$app->session->getFlash('danger')
        );
        $this->assertSame(1, $this->decisionCount($request));
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

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

    private function decide(ApprovalRequest $request, User $user, string $decision): void
    {
        /** @var \app\services\ApprovalService $service */
        $service = \Yii::$app->get('approvalService');
        $service->recordDecision($request, (int)$user->id, $decision);
    }

    private function decisionCount(ApprovalRequest $request): int
    {
        return (int)ApprovalDecision::find()->where(['approval_request_id' => $request->id])->count();
    }

    /**
     * @return list<int>
     */
    private function listedIds(): array
    {
        $ctrl = $this->makeController();
        $ctrl->actionIndex();
        $dataProvider = $ctrl->capturedParams['dataProvider'];
        $this->assertInstanceOf(ActiveDataProvider::class, $dataProvider);
        $dataProvider->pagination = false;

        return array_map(static fn (ApprovalRequest $r): int => (int)$r->id, $dataProvider->getModels());
    }

    private function makeController(): ApprovalController
    {
        return new class ('approval', \Yii::$app) extends ApprovalController {
            public string $capturedView = '';
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                $this->capturedView = $view;
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): Response
            {
                $r = new Response();
                $r->content = 'redirected';
                return $r;
            }
        };
    }
}
