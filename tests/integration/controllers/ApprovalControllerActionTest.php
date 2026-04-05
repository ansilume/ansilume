<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ApprovalController;
use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\services\ApprovalService;
use yii\data\ActiveDataProvider;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ApprovalControllerActionTest extends WebControllerTestCase
{
    /** @var list<array{string, \yii\base\Component}> */
    private array $swappedServices = [];

    /** @var array{canApprove: bool, recordCalls: int, lastDecision: string|null} */
    public array $stubState = ['canApprove' => true, 'recordCalls' => 0, 'lastDecision' => null];

    protected function setUp(): void
    {
        parent::setUp();

        $state = &$this->stubState;
        $this->swapService('approvalService', new class ($state) extends ApprovalService {
            /** @var array{canApprove: bool, recordCalls: int, lastDecision: string|null} */
            private array $state;
            public function __construct(array &$state)
            {
                parent::__construct();
                $this->state = &$state;
            }
            public function canUserApprove(ApprovalRequest $req, int $userId): bool
            {
                return $this->state['canApprove'];
            }
            public function recordDecision(
                ApprovalRequest $req,
                int $userId,
                string $decision,
                ?string $comment = null
            ): ApprovalDecision {
                $this->state['recordCalls']++;
                $this->state['lastDecision'] = $decision;
                $d = new ApprovalDecision();
                $d->approval_request_id = $req->id;
                $d->user_id = $userId;
                $d->decision = $decision;
                $d->comment = $comment;
                $d->created_at = time();
                $d->save(false);
                return $d;
            }
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->swappedServices as [$id, $original]) {
            \Yii::$app->set($id, $original);
        }
        $this->swappedServices = [];
        parent::tearDown();
    }

    // ── actionIndex() ────────────────────────────────────────────────────────

    public function testIndexRendersDataProvider(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $this->createRequest($user);

        $ctrl = $this->makeController();
        $result = $ctrl->actionIndex();

        $this->assertSame('rendered:index', $result);
        $this->assertInstanceOf(ActiveDataProvider::class, $ctrl->capturedParams['dataProvider']);
    }

    // ── actionView() ─────────────────────────────────────────────────────────

    public function testViewRendersModel(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $req = $this->createRequest($user);

        $ctrl = $this->makeController();
        $result = $ctrl->actionView((int)$req->id);

        $this->assertSame('rendered:view', $result);
        $this->assertSame($req->id, $ctrl->capturedParams['model']->id);
    }

    public function testViewThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionView(9999999);
    }

    // ── actionApprove() ──────────────────────────────────────────────────────

    public function testApproveRecordsDecision(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $req = $this->createRequest($user);

        $this->stubState['canApprove'] = true;
        $this->setPost(['comment' => 'LGTM']);

        $ctrl = $this->makeController();
        $result = $ctrl->actionApprove((int)$req->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(1, $this->stubState['recordCalls']);
        $this->assertSame(ApprovalDecision::DECISION_APPROVED, $this->stubState['lastDecision']);
    }

    public function testApproveForbiddenWhenIneligible(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $req = $this->createRequest($user);

        $this->stubState['canApprove'] = false;

        $ctrl = $this->makeController();
        $this->expectException(ForbiddenHttpException::class);
        $ctrl->actionApprove((int)$req->id);
    }

    public function testApproveThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionApprove(9999999);
    }

    // ── actionReject() ───────────────────────────────────────────────────────

    public function testRejectRecordsDecision(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $req = $this->createRequest($user);

        $this->stubState['canApprove'] = true;
        $this->setPost(['comment' => 'nope']);

        $ctrl = $this->makeController();
        $result = $ctrl->actionReject((int)$req->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(1, $this->stubState['recordCalls']);
        $this->assertSame(ApprovalDecision::DECISION_REJECTED, $this->stubState['lastDecision']);
    }

    public function testRejectForbiddenWhenIneligible(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $req = $this->createRequest($user);

        $this->stubState['canApprove'] = false;

        $ctrl = $this->makeController();
        $this->expectException(ForbiddenHttpException::class);
        $ctrl->actionReject((int)$req->id);
    }

    public function testRejectThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionReject(9999999);
    }

    public function testBehaviorsWiresAccessAndVerbFilters(): void
    {
        // Invokes accessRules() and verbRules() via BaseController::behaviors().
        $ctrl = new ApprovalController('approval', \Yii::$app);
        $behaviors = $ctrl->behaviors();
        $this->assertArrayHasKey('access', $behaviors);
        $this->assertArrayHasKey('verbs', $behaviors);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function createRequest(\app\models\User $user): ApprovalRequest
    {
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);
        $job = $this->createJob((int)$tpl->id, $user->id);
        $rule = $this->createApprovalRule($user->id);

        $req = new ApprovalRequest();
        $req->job_id = $job->id;
        $req->approval_rule_id = $rule->id;
        $req->status = ApprovalRequest::STATUS_PENDING;
        $req->requested_at = time();
        $req->save(false);
        return $req;
    }

    private function swapService(string $id, \yii\base\Component $replacement): void
    {
        /** @var \yii\base\Component $original */
        $original = \Yii::$app->get($id);
        $this->swappedServices[] = [$id, $original];
        \Yii::$app->set($id, $replacement);
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

            public function redirect($url, $statusCode = 302): \yii\web\Response
            {
                $r = new \yii\web\Response();
                $r->content = 'redirected';
                return $r;
            }
        };
    }
}
