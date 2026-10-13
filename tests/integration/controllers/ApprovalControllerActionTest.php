<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ApprovalController;
use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\User;
use app\services\ApprovalDecisionRefusedException;
use app\services\ApprovalService;
use app\tests\integration\TeamScopeFixtures;
use yii\data\ActiveDataProvider;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * ApprovalController with a stubbed ApprovalService: what the actions do
 * with the service's answers. ApprovalControllerTeamScopeTest runs the real
 * services.
 */
class ApprovalControllerActionTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /** @var list<array{string, \yii\base\Component}> */
    private array $swappedServices = [];

    /**
     * @var array{refusal: ApprovalDecisionRefusedException|null, refusalCalls: int, recordCalls: int, lastDecision: string|null, lastComment: string|null, throw: \RuntimeException|null}
     */
    public array $stubState = [
        'refusal' => null,
        'refusalCalls' => 0,
        'recordCalls' => 0,
        'lastDecision' => null,
        'lastComment' => null,
        'throw' => null,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $state = &$this->stubState;
        $this->swapService('approvalService', new class ($state) extends ApprovalService {
            /** @var array{refusal: ApprovalDecisionRefusedException|null, refusalCalls: int, recordCalls: int, lastDecision: string|null, lastComment: string|null, throw: \RuntimeException|null} */
            private array $state;
            public function __construct(array &$state)
            {
                parent::__construct();
                $this->state = &$state;
            }
            public function decisionRefusal(ApprovalRequest $request, int $userId): ?ApprovalDecisionRefusedException
            {
                $this->state['refusalCalls']++;
                return $this->state['refusal'];
            }
            public function recordDecision(
                ApprovalRequest $req,
                int $userId,
                string $decision,
                ?string $comment = null
            ): ApprovalDecision {
                $this->state['recordCalls']++;
                $this->state['lastDecision'] = $decision;
                $this->state['lastComment'] = $comment;
                if ($this->state['throw'] !== null) {
                    throw $this->state['throw'];
                }
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

    /**
     * Users without approval.decide get neither buttons nor a note; the
     * service is not asked.
     */
    public function testViewOffersNothingWithoutApprovalDecide(): void
    {
        $user = $this->createUserWithRole('view-viewer', 'viewer');
        $this->loginAs($user);
        $req = $this->createRequest($user);

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$req->id);

        $this->assertFalse($ctrl->capturedParams['canDecide']);
        $this->assertNull($ctrl->capturedParams['decisionNote']);
        $this->assertSame(0, $this->stubState['refusalCalls']);
    }

    public function testViewOffersTheDecisionWhenTheVoteWouldBeRecorded(): void
    {
        $user = $this->createUserWithRole('view-approver', 'operator');
        $this->loginAs($user);
        $req = $this->createRequest($user);

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$req->id);

        $this->assertTrue($ctrl->capturedParams['canDecide']);
        $this->assertNull($ctrl->capturedParams['decisionNote']);
    }

    public function testViewAsksForASuperadminWithoutRoles(): void
    {
        $user = $this->createUser('view-superadmin');
        $user->is_superadmin = true;
        $user->save(false);
        $this->loginAs($user);
        $req = $this->createRequest($user);

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$req->id);

        $this->assertTrue($ctrl->capturedParams['canDecide']);
        $this->assertSame(1, $this->stubState['refusalCalls']);
    }

    /**
     * The note says why there are no buttons, except to a user who is simply
     * no approver of the request.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function noteProvider(): array
    {
        return [
            'the launcher' => [ApprovalDecisionRefusedException::REASON_LAUNCHER, true],
            'resolved' => [ApprovalDecisionRefusedException::REASON_RESOLVED, true],
            'expired' => [ApprovalDecisionRefusedException::REASON_EXPIRED, true],
            'voted already' => [ApprovalDecisionRefusedException::REASON_ALREADY_VOTED, true],
            'job no longer waiting' => [ApprovalDecisionRefusedException::REASON_JOB_NOT_WAITING, true],
            'no approver' => [ApprovalDecisionRefusedException::REASON_NOT_ELIGIBLE, false],
        ];
    }

    /**
     * @dataProvider noteProvider
     */
    public function testViewExplainsWhyThereAreNoButtons(string $reason, bool $noted): void
    {
        $user = $this->createUserWithRole('view-note', 'operator');
        $this->loginAs($user);
        $req = $this->createRequest($user);
        $refusal = $this->refusal($reason, $req);
        $this->stubState['refusal'] = $refusal;

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$req->id);

        $this->assertFalse($ctrl->capturedParams['canDecide']);
        $this->assertSame($noted ? $refusal->getMessage() : null, $ctrl->capturedParams['decisionNote']);
    }

    // ── actionApprove() / actionReject() ─────────────────────────────────────

    public function testApproveRecordsDecision(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $req = $this->createRequest($user);
        $this->setPost(['comment' => 'LGTM']);

        $result = $this->makeController()->actionApprove((int)$req->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(1, $this->stubState['recordCalls']);
        $this->assertSame(ApprovalDecision::DECISION_APPROVED, $this->stubState['lastDecision']);
        $this->assertSame('LGTM', $this->stubState['lastComment']);
        $this->assertSame('Approval recorded.', \Yii::$app->session->getFlash('success'));
    }

    public function testRejectRecordsDecisionWithItsComment(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $req = $this->createRequest($user);
        $this->setPost(['comment' => 'not now']);

        $this->makeController()->actionReject((int)$req->id);

        $this->assertSame(ApprovalDecision::DECISION_REJECTED, $this->stubState['lastDecision']);
        $this->assertSame('not now', $this->stubState['lastComment']);
        $this->assertSame('Rejection recorded.', \Yii::$app->session->getFlash('success'));
    }

    public function testAnEmptyCommentIsNone(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $req = $this->createRequest($user);
        $this->setPost(['comment' => '']);

        $this->makeController()->actionReject((int)$req->id);

        $this->assertNull($this->stubState['lastComment']);
    }

    public function testAVoteWithoutACommentHasNone(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $req = $this->createRequest($user);
        $this->setPost([]);

        $this->makeController()->actionApprove((int)$req->id);

        $this->assertSame(1, $this->stubState['recordCalls']);
        $this->assertNull($this->stubState['lastComment']);
        $this->assertSame('Approval recorded.', \Yii::$app->session->getFlash('success'));
    }

    /**
     * Regression: a forged form whose comment is a list (comment[]=x) ended
     * in a server error ("Array to string conversion"). It is refused like
     * any invalid decision, and nothing is recorded.
     *
     * @return array<string, array{0: string, 1: array<int|string, string>}>
     */
    public static function commentThatIsNoTextProvider(): array
    {
        return [
            'approve with a list' => ['approve', ['x']],
            'reject with named fields' => ['reject', ['text' => 'x']],
        ];
    }

    /**
     * @dataProvider commentThatIsNoTextProvider
     * @param array<int|string, string> $comment
     */
    public function testRegressionACommentThatIsNoTextEndedInAServerError(string $action, array $comment): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $req = $this->createRequest($user);
        $this->setPost(['comment' => $comment]);

        $ctrl = $this->makeController();
        $result = $action === 'approve' ? $ctrl->actionApprove((int)$req->id) : $ctrl->actionReject((int)$req->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(0, $this->stubState['recordCalls'], 'the vote is not cast');
        $this->assertSame(
            'Your decision was not recorded: The decision cannot be recorded: Comment must be a string.',
            \Yii::$app->session->getFlash('danger')
        );
        $this->assertNull(\Yii::$app->session->getFlash('success'));
    }

    /**
     * A refusal about the request's state is a warning: someone else (or a
     * timeout, or a cancel) came first.
     *
     * @return array<string, array{0: string}>
     */
    public static function conflictProvider(): array
    {
        return [
            'resolved' => [ApprovalDecisionRefusedException::REASON_RESOLVED],
            'expired' => [ApprovalDecisionRefusedException::REASON_EXPIRED],
            'job no longer waiting' => [ApprovalDecisionRefusedException::REASON_JOB_NOT_WAITING],
        ];
    }

    /**
     * Regression: another approver resolved the request after this one passed
     * the checks; the service's refusal surfaced as a server error.
     *
     * @dataProvider conflictProvider
     */
    public function testARefusalAboutTheRequestsStateIsAWarning(string $reason): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $req = $this->createRequest($user);
        $refusal = $this->refusal($reason, $req);
        $this->stubState['throw'] = $refusal;

        $result = $this->makeController()->actionApprove((int)$req->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame($refusal->getMessage(), \Yii::$app->session->getFlash('warning'));
        $this->assertNull(\Yii::$app->session->getFlash('success'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'voted already' => [ApprovalDecisionRefusedException::REASON_ALREADY_VOTED],
            'invalid comment' => [ApprovalDecisionRefusedException::REASON_INVALID],
        ];
    }

    /**
     * @dataProvider invalidProvider
     */
    public function testARefusalOfTheVoteItselfIsFlashedNotAServerError(string $reason): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $req = $this->createRequest($user);
        $refusal = $this->refusal($reason, $req);
        $this->stubState['throw'] = $refusal;

        $result = $this->makeController()->actionReject((int)$req->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(
            'Your decision was not recorded: ' . $refusal->getMessage(),
            \Yii::$app->session->getFlash('danger')
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function forbiddenProvider(): array
    {
        return [
            'the launcher' => [ApprovalDecisionRefusedException::REASON_LAUNCHER],
            'no approver' => [ApprovalDecisionRefusedException::REASON_NOT_ELIGIBLE],
        ];
    }

    /**
     * @dataProvider forbiddenProvider
     */
    public function testARefusalOfTheVoterIs403WithItsMessage(string $reason): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $req = $this->createRequest($user);
        $refusal = $this->refusal($reason, $req);
        $this->stubState['throw'] = $refusal;

        try {
            $this->makeController()->actionApprove((int)$req->id);
            $this->fail('Expected 403');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame($refusal->getMessage(), $e->getMessage());
        }
    }

    public function testARequestThatVanishedDuringTheVoteIs404(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $req = $this->createRequest($user);
        $this->stubState['throw'] = ApprovalDecisionRefusedException::gone((int)$req->id);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage(sprintf(ApprovalDecisionRefusedException::GONE, (int)$req->id));
        $this->makeController()->actionReject((int)$req->id);
    }

    public function testApproveThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionApprove(9999999);
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

    private function refusal(string $reason, ApprovalRequest $request): ApprovalDecisionRefusedException
    {
        return match ($reason) {
            ApprovalDecisionRefusedException::REASON_LAUNCHER => ApprovalDecisionRefusedException::launcher($request),
            ApprovalDecisionRefusedException::REASON_RESOLVED => ApprovalDecisionRefusedException::resolved($request),
            ApprovalDecisionRefusedException::REASON_EXPIRED => ApprovalDecisionRefusedException::expired($request),
            ApprovalDecisionRefusedException::REASON_ALREADY_VOTED => ApprovalDecisionRefusedException::alreadyVoted($request),
            ApprovalDecisionRefusedException::REASON_JOB_NOT_WAITING => ApprovalDecisionRefusedException::jobNotWaiting($request),
            ApprovalDecisionRefusedException::REASON_INVALID => ApprovalDecisionRefusedException::invalid($request, 'Comment should contain at most 1,000 characters.'),
            default => ApprovalDecisionRefusedException::notEligible($request),
        };
    }

    private function createRequest(User $user): ApprovalRequest
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
