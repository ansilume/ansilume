<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ApprovalController;
use app\controllers\api\v1\ApprovalsController;
use app\models\ApiToken;
use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\Job;
use app\models\User;
use app\models\WorkflowStep;
use app\services\ApprovalService;
use app\tests\integration\services\ApprovalVoteFixtures;
use app\tests\integration\services\CancellationFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\web\Response;

/**
 * A vote is answered as recorded, in the web UI and in the API, when what
 * follows it after the commit fails: here the workflow advance after the
 * approval of a workflow approval step. The failure is logged without
 * values, with the real services.
 *
 * Regression: both answered with a server error although the vote was
 * recorded, and a retry then got "already resolved".
 */
class ApprovalControllerVoteEffectsTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;
    use ApprovalVoteFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordNotifications();
    }

    protected function tearDown(): void
    {
        $this->restoreNotifications();
        parent::tearDown();
    }

    public function testRegressionAWebVoteWhoseWorkflowAdvanceFailedEndedInAServerError(): void
    {
        ['request' => $request, 'approver' => $approver, 'job' => $job] = $this->approvalStepWaiting('web');
        $this->loginAs($approver);
        $this->setPost(['comment' => 'go']);

        ['result' => $result, 'errors' => $errors] = $this->withFailingWorkflowAdvance(
            $this->lostConnection(),
            fn (): Response => $this->webController()->actionApprove((int)$request->id)
        );

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame('Approval recorded.', \Yii::$app->session->getFlash('success'));
        $this->assertNull(\Yii::$app->session->getFlash('danger'));
        $this->assertNull(\Yii::$app->session->getFlash('warning'));
        $this->assertVoteStoredAndLogged($request, $approver, $job, $errors);
    }

    public function testRegressionAnApiVoteWhoseWorkflowAdvanceFailedEndedInAServerError(): void
    {
        ['request' => $request, 'approver' => $approver, 'job' => $job] = $this->approvalStepWaiting('api');
        $this->authenticate($approver);
        $this->setBody(['comment' => 'go']);

        ['result' => $result, 'errors' => $errors] = $this->withFailingWorkflowAdvance(
            $this->lostConnection(),
            fn (): array => (new ApprovalsController('api/v1/approvals', \Yii::$app))->actionApprove((int)$request->id)
        );

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertIsArray($result);
        $this->assertSame((int)$request->id, $result['data']['id']);
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $result['data']['status']);
        $this->assertSame(1, $result['data']['approval_count']);
        $this->assertVoteStoredAndLogged($request, $approver, $job, $errors);
    }

    // -- Helpers ----------------------------------------------------------------------

    /**
     * A run waiting at an approval step whose rule lists one approver, who
     * is not the launcher.
     *
     * @return array{request: ApprovalRequest, approver: User, job: Job}
     */
    private function approvalStepWaiting(string $suffix): array
    {
        $launcher = $this->approverUser('effects-' . $suffix . '-launcher');
        $approver = $this->approverUser('effects-' . $suffix . '-approver');
        $flow = $this->workflowStepWaiting(
            WorkflowStep::TYPE_APPROVAL,
            $this->listRule((int)$launcher->id, [$approver]),
            $launcher
        );

        return ['request' => $flow['request'], 'approver' => $approver, 'job' => $flow['job']];
    }

    /**
     * @param list<string> $errors what ApprovalService logged
     */
    private function assertVoteStoredAndLogged(ApprovalRequest $request, User $approver, Job $job, array $errors): void
    {
        $vote = ApprovalDecision::findOne(['approval_request_id' => $request->id, 'user_id' => $approver->id]);
        $this->assertNotNull($vote, 'the vote is stored');
        $this->assertSame(ApprovalDecision::DECISION_APPROVED, $vote->decision);
        $this->assertSame('go', $vote->comment);
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $this->reloadRequest($request)->status);
        $this->assertSame(Job::STATUS_SUCCEEDED, $this->reloadJob($job)->status);
        $this->assertSame(
            [sprintf(ApprovalService::VOTE_EFFECTS_FAILED, (int)$request->id, (int)$approver->id, \yii\db\Exception::class, 'HY000', '2006')],
            $errors
        );
        $this->assertStringNotContainsString('value-that-must-not-be-logged', implode("\n", $errors));
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'approval-effects');
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

    private function webController(): ApprovalController
    {
        return new class ('approval', \Yii::$app) extends ApprovalController {
            public function redirect($url, $statusCode = 302): Response
            {
                $r = new Response();
                $r->content = 'redirected';
                return $r;
            }
        };
    }
}
