<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\WorkflowTemplateController;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowExecutionService;
use app\tests\integration\TeamScopeFixtures;
use yii\data\ActiveDataProvider;
use yii\helpers\Url;
use yii\web\AssetManager;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\View;

class WorkflowTemplateControllerActionTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /** @var list<array{string, \yii\base\Component}> */
    private array $swappedServices = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Flashes live in $_SESSION, which outlasts a test: start without any.
        \Yii::$app->session->removeAllFlashes();

        $this->swapService('workflowExecutionService', new class extends WorkflowExecutionService {
            public int $launchCalls = 0;
            public bool $throwOnLaunch = false;
            /** Run the real launch: team scoping, audit entries, first step. */
            public bool $real = false;
            public ?string $lastSource = null;
            public function launch(WorkflowTemplate $template, int $launchedBy, array $overrides = [], string $source = 'web'): WorkflowJob
            {
                $this->launchCalls++;
                $this->lastSource = $source;
                if ($this->real) {
                    return parent::launch($template, $launchedBy, $overrides, $source);
                }
                if ($this->throwOnLaunch) {
                    throw new \RuntimeException('Workflow template has no steps.');
                }
                $j = new WorkflowJob();
                $j->workflow_template_id = $template->id;
                $j->launched_by = $launchedBy;
                $j->status = WorkflowJob::STATUS_RUNNING;
                $j->started_at = time();
                $j->created_at = time();
                $j->updated_at = time();
                $j->save(false);
                return $j;
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
        $this->createWorkflowTemplate($user->id);

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
        $wf = $this->createWorkflowTemplate($user->id);

        $ctrl = $this->makeController();
        $result = $ctrl->actionView((int)$wf->id);

        $this->assertSame('rendered:view', $result);
        $this->assertSame($wf->id, $ctrl->capturedParams['model']->id);
    }

    public function testViewThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionView(9999999);
    }

    // ── actionCreate() ───────────────────────────────────────────────────────

    public function testCreateRendersFormOnGet(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:form', $result);
        $this->assertInstanceOf(WorkflowTemplate::class, $ctrl->capturedParams['model']);
        $this->assertTrue($ctrl->capturedParams['model']->isNewRecord);
    }

    public function testCreatePersistsAndAudits(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $this->setPost(['WorkflowTemplate' => ['name' => 'wf-new']]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertInstanceOf(Response::class, $result);
        $stored = WorkflowTemplate::findOne(['name' => 'wf-new']);
        $this->assertNotNull($stored);
        $this->assertSame($user->id, (int)$stored->created_by);
        $this->assertNotNull(AuditLog::findOne([
            'action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_CREATED,
            'object_id' => $stored->id,
        ]));
    }

    public function testCreateInvalidInputRendersForm(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $this->setPost(['WorkflowTemplate' => ['name' => '']]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:form', $result);
        $this->assertTrue($ctrl->capturedParams['model']->hasErrors());
    }

    // ── actionUpdate() ───────────────────────────────────────────────────────

    public function testUpdatePersistsChanges(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $wf = $this->createWorkflowTemplate($user->id);

        $this->setPost(['WorkflowTemplate' => ['name' => 'renamed-wf']]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$wf->id);

        $this->assertInstanceOf(Response::class, $result);
        $reloaded = WorkflowTemplate::findOne($wf->id);
        $this->assertSame('renamed-wf', $reloaded->name);
        $this->assertNotNull(AuditLog::findOne([
            'action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_UPDATED,
            'object_id' => $wf->id,
        ]));
    }

    public function testUpdateRendersFormOnGet(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $wf = $this->createWorkflowTemplate($user->id);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$wf->id);

        $this->assertSame('rendered:form', $result);
    }

    public function testUpdateThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionUpdate(9999999);
    }

    // ── actionDelete() ───────────────────────────────────────────────────────

    public function testDeleteSoftDeletesAndAudits(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $wf = $this->createWorkflowTemplate($user->id);
        $id = (int)$wf->id;

        $ctrl = $this->makeController();
        $result = $ctrl->actionDelete($id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertNotNull(AuditLog::findOne([
            'action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_DELETED,
            'object_id' => $id,
        ]));
    }

    public function testDeleteThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionDelete(9999999);
    }

    // ── actionLaunch() ───────────────────────────────────────────────────────

    public function testLaunchDelegatesToServiceViaGet(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $wf = $this->createWorkflowTemplate($user->id);

        $this->setQueryParams(['id' => (string)$wf->id]);
        $this->setPost([]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionLaunch();

        $this->assertInstanceOf(Response::class, $result);
        /** @var object{launchCalls: int, lastSource: string|null} $svc */
        $svc = \Yii::$app->get('workflowExecutionService');
        $this->assertSame(1, $svc->launchCalls);
        $this->assertSame('web', $svc->lastSource, 'audit entries tell web launches from API and trigger ones');
    }

    /**
     * Regression: dashboard quick-launch form sends id via POST body,
     * not as a GET parameter (GitHub #9).
     */
    public function testLaunchAcceptsIdFromPostBody(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $wf = $this->createWorkflowTemplate($user->id);

        $this->setQueryParams([]);
        $this->setPost(['id' => (string)$wf->id]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionLaunch();

        $this->assertInstanceOf(Response::class, $result);
        /** @var object{launchCalls: int} $svc */
        $svc = \Yii::$app->get('workflowExecutionService');
        $this->assertSame(1, $svc->launchCalls);
    }

    /**
     * Regression: RuntimeException (e.g. "no steps") must produce a flash
     * message, not an unhandled error page (GitHub #9).
     */
    public function testLaunchHandlesRuntimeException(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $wf = $this->createWorkflowTemplate($user->id);

        /** @var object{throwOnLaunch: bool} $svc */
        $svc = \Yii::$app->get('workflowExecutionService');
        $svc->throwOnLaunch = true;

        $this->setQueryParams(['id' => (string)$wf->id]);
        $this->setPost([]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionLaunch();

        $this->assertInstanceOf(Response::class, $result);
        $flashes = \Yii::$app->session->getAllFlashes();
        $this->assertArrayHasKey('danger', $flashes);
        $this->assertStringContainsString('no steps', $flashes['danger']);
    }

    public function testLaunchThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $this->setQueryParams(['id' => '9999999']);
        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionLaunch();
    }

    // ── actionAddStep() / actionRemoveStep() ─────────────────────────────────

    public function testAddStepPersistsAndRedirects(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $wf = $this->createWorkflowTemplate($user->id);
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);

        $this->setPost([
            'WorkflowStep' => [
                'name' => 'step-one',
                'step_order' => 1,
                'step_type' => WorkflowStep::TYPE_JOB,
                'job_template_id' => $tpl->id,
            ],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionAddStep((int)$wf->id);

        $this->assertInstanceOf(Response::class, $result);
        $step = WorkflowStep::findOne(['workflow_template_id' => $wf->id, 'name' => 'step-one']);
        $this->assertNotNull($step);
    }

    public function testAddStepThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionAddStep(9999999);
    }

    public function testRemoveStepDeletesStep(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $wf = $this->createWorkflowTemplate($user->id);
        $step = $this->createWorkflowStep((int)$wf->id, 1, WorkflowStep::TYPE_APPROVAL);
        $stepId = (int)$step->id;

        $this->setPost(['step_id' => (string)$stepId]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionRemoveStep((int)$wf->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertNull(WorkflowStep::findOne($stepId));
    }

    public function testRemoveStepIgnoresUnknownStep(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $wf = $this->createWorkflowTemplate($user->id);

        $this->setPost(['step_id' => '9999999']);

        $ctrl = $this->makeController();
        $result = $ctrl->actionRemoveStep((int)$wf->id);

        $this->assertInstanceOf(Response::class, $result);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function swapService(string $id, \yii\base\Component $replacement): void
    {
        /** @var \yii\base\Component $original */
        $original = \Yii::$app->get($id);
        $this->swappedServices[] = [$id, $original];
        \Yii::$app->set($id, $replacement);
    }

    private function makeController(): WorkflowTemplateController
    {
        return new class ('workflow-template', \Yii::$app) extends WorkflowTemplateController {
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

    // ── actionMoveStep() ─────────────────────────────────────────────────────

    public function testMoveStepUpSwapsWithPredecessorAndAuditsFlash(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $wf = $this->createWorkflowTemplate($user->id);
        $a = $this->createWorkflowStep($wf->id, 10);
        $b = $this->createWorkflowStep($wf->id, 20);

        $this->setQueryParams(['id' => (string)$wf->id]);
        $this->setPost(['step_id' => (string)$b->id, 'direction' => 'up']);

        $ctrl = $this->makeController();
        $result = $ctrl->actionMoveStep((int)$wf->id);

        $this->assertInstanceOf(Response::class, $result);
        $a->refresh();
        $b->refresh();
        $this->assertSame(10, (int)$b->step_order);
        $this->assertSame(20, (int)$a->step_order);
        $this->assertArrayHasKey('success', \Yii::$app->session->getAllFlashes());
    }

    public function testMoveStepDownSwapsWithSuccessor(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $wf = $this->createWorkflowTemplate($user->id);
        $a = $this->createWorkflowStep($wf->id, 10);
        $b = $this->createWorkflowStep($wf->id, 20);

        $this->setQueryParams(['id' => (string)$wf->id]);
        $this->setPost(['step_id' => (string)$a->id, 'direction' => 'down']);

        $ctrl = $this->makeController();
        $ctrl->actionMoveStep((int)$wf->id);

        $a->refresh();
        $b->refresh();
        $this->assertSame(20, (int)$a->step_order);
        $this->assertSame(10, (int)$b->step_order);
    }

    public function testMoveStepRejectsInvalidDirection(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $wf = $this->createWorkflowTemplate($user->id);
        $a = $this->createWorkflowStep($wf->id, 10);

        $this->setQueryParams(['id' => (string)$wf->id]);
        $this->setPost(['step_id' => (string)$a->id, 'direction' => 'sideways']);

        $ctrl = $this->makeController();
        $ctrl->actionMoveStep((int)$wf->id);

        $a->refresh();
        $this->assertSame(10, (int)$a->step_order, 'Bad direction must not move anything.');
        $this->assertArrayHasKey('danger', \Yii::$app->session->getAllFlashes());
    }

    public function testMoveStepThrowsForUnknownStep(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $wf = $this->createWorkflowTemplate($user->id);

        $this->setQueryParams(['id' => (string)$wf->id]);
        $this->setPost(['step_id' => '999999', 'direction' => 'up']);

        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionMoveStep((int)$wf->id);
    }

    public function testMoveStepRejectsCrossTemplateStep(): void
    {
        // A step belonging to a different workflow template must not be
        // movable via the URL of an unrelated template — confidentiality
        // and integrity guard combined.
        $user = $this->createUser();
        $this->loginAs($user);
        $other = $this->createWorkflowTemplate($user->id);
        $foreign = $this->createWorkflowStep($other->id, 10);
        $myWf = $this->createWorkflowTemplate($user->id);

        $this->setQueryParams(['id' => (string)$myWf->id]);
        $this->setPost(['step_id' => (string)$foreign->id, 'direction' => 'up']);

        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionMoveStep((int)$myWf->id);
    }

    public function testMoveStepUpAtTopIsSoftNoOp(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $wf = $this->createWorkflowTemplate($user->id);
        $a = $this->createWorkflowStep($wf->id, 10);

        $this->setQueryParams(['id' => (string)$wf->id]);
        $this->setPost(['step_id' => (string)$a->id, 'direction' => 'up']);

        $ctrl = $this->makeController();
        $result = $ctrl->actionMoveStep((int)$wf->id);

        $this->assertInstanceOf(Response::class, $result);
        $a->refresh();
        $this->assertSame(10, (int)$a->step_order);
        // No flash for soft no-op — controller must not lie about a move
        // that didn't happen.
        $this->assertEmpty(\Yii::$app->session->getAllFlashes());
    }

    // ── actionGenerateTriggerToken / actionRevokeTriggerToken ────────────────

    public function testGenerateTriggerTokenStoresHashAndFlashesRaw(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $wf = $this->createWorkflowTemplate($user->id);

        $this->setQueryParams(['id' => (string)$wf->id]);
        $this->setPost([]);
        $ctrl = $this->makeController();
        $result = $ctrl->actionGenerateTriggerToken((int)$wf->id);

        $this->assertInstanceOf(Response::class, $result);
        $wf->refresh();
        $this->assertNotNull($wf->trigger_token);
        $this->assertSame(64, strlen((string)$wf->trigger_token), 'SHA-256 hex is 64 chars.');

        $flashes = \Yii::$app->session->getAllFlashes();
        $this->assertArrayHasKey('trigger_token_raw', $flashes);
        $this->assertSame(64, strlen((string)$flashes['trigger_token_raw']), 'Raw token is 32 random bytes hex-encoded.');
        // Defence in depth: never persist the raw value.
        $this->assertNotSame($flashes['trigger_token_raw'], $wf->trigger_token);

        $audit = AuditLog::findOne([
            'action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_TRIGGER_TOKEN_GENERATED,
            'object_id' => $wf->id,
        ]);
        $this->assertNotNull($audit, 'Generation must produce an audit log entry.');
    }

    public function testRevokeTriggerTokenClearsAndAudits(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $wf = $this->createWorkflowTemplate($user->id);
        $wf->generateTriggerToken((int)$wf->created_by);
        $this->assertNotNull($wf->trigger_token);

        $this->setQueryParams(['id' => (string)$wf->id]);
        $this->setPost([]);
        $ctrl = $this->makeController();
        $ctrl->actionRevokeTriggerToken((int)$wf->id);

        $wf->refresh();
        $this->assertNull($wf->trigger_token);

        $audit = AuditLog::findOne([
            'action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_TRIGGER_TOKEN_REVOKED,
            'object_id' => $wf->id,
        ]);
        $this->assertNotNull($audit);
    }

    public function testGenerateTriggerTokenThrowsForUnknownTemplate(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $this->setQueryParams(['id' => '9999999']);
        $this->setPost([]);
        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionGenerateTriggerToken(9999999);
    }

    public function testAddStepResequencesToSparseLayout(): void
    {
        // The user types step_order=15 to wedge between 10 and 20; the
        // controller's post-save resequence normalises the layout to
        // 10/20/30 so the new step lands neatly in the middle.
        $user = $this->createUser();
        $this->loginAs($user);
        $wf = $this->createWorkflowTemplate($user->id);
        $a = $this->createWorkflowStep($wf->id, 10);
        $b = $this->createWorkflowStep($wf->id, 20);

        $this->setQueryParams(['id' => (string)$wf->id]);
        $this->setPost([
            'WorkflowStep' => [
                'name' => 'wedged',
                'step_order' => '15',
                'step_type' => WorkflowStep::TYPE_PAUSE,
            ],
        ]);

        $ctrl = $this->makeController();
        $ctrl->actionAddStep((int)$wf->id);

        $a->refresh();
        $b->refresh();
        $wedged = WorkflowStep::find()->where(['workflow_template_id' => $wf->id, 'name' => 'wedged'])->one();
        $this->assertNotNull($wedged);

        $byOrder = [(int)$a->step_order, (int)$wedged->step_order, (int)$b->step_order];
        sort($byOrder);
        $this->assertSame([10, 20, 30], $byOrder, 'Sparse 10/20/30 layout after the resequence pass.');
    }

    // ── Team scoping (regression) ────────────────────────────────────────────
    //
    // Workflows ignored team scoping: every operator could see, change and
    // launch workflows built from another team's job templates, and build
    // such workflows. A workflow now belongs to the projects of its job steps.

    public function testIndexListsOnlyWorkflowsWhoseJobStepsTheMemberMayView(): void
    {
        $s = $this->teamScope();
        $own = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $viewed = $this->createWorkflowWithJobSteps($s['admin']->id, $s['viewed']->id);
        $open = $this->createWorkflowWithJobSteps($s['admin']->id, $s['open']->id);
        $pauseOnly = $this->createWorkflowTemplate($s['admin']->id);
        $this->createWorkflowStep($pauseOnly->id, 0, WorkflowStep::TYPE_PAUSE);
        $foreign = $this->createWorkflowWithJobSteps($s['admin']->id, $s['foreign']->id);
        $mixed = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['foreign']->id);
        $purged = $this->createWorkflowWithJobSteps($s['admin']->id, 987654321);
        $this->loginAs($s['member']);

        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        $all = [$own, $viewed, $open, $pauseOnly, $foreign, $mixed, $purged];
        $this->assertSame([$own->id, $viewed->id, $open->id, $pauseOnly->id], $this->listedIds($ctrl, $all));
        /** @var list<int> $operable */
        $operable = $ctrl->capturedParams['operableIds'];
        $operable = array_values(array_intersect($operable, array_map(static fn (WorkflowTemplate $w): int => $w->id, $all)));
        sort($operable);
        $this->assertSame([$own->id, $open->id, $pauseOnly->id], $operable, 'launch buttons only where the member may launch');
    }

    public function testIndexShowsAdminsEveryWorkflow(): void
    {
        $s = $this->teamScope();
        $foreign = $this->createWorkflowWithJobSteps($s['member']->id, $s['foreign']->id);
        $mixed = $this->createWorkflowWithJobSteps($s['member']->id, $s['own']->id, $s['foreign']->id);
        $this->loginAs($s['admin']);

        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        $this->assertSame([$foreign->id, $mixed->id], $this->listedIds($ctrl, [$foreign, $mixed]));
        $this->assertNull($ctrl->capturedParams['operableIds'], 'no restriction for admins');
    }

    public function testViewOfOwnWorkflowOffersOnlyOperableJobTemplates(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->loginAs($s['member']);

        $ctrl = $this->makeController();
        $this->assertSame('rendered:view', $ctrl->actionView((int)$wf->id));

        $this->assertTrue($ctrl->capturedParams['canOperate']);
        /** @var array<int, string> $options */
        $options = $ctrl->capturedParams['jobTemplateOptions'];
        $this->assertArrayHasKey($s['own']->id, $options);
        $this->assertArrayHasKey($s['open']->id, $options);
        $this->assertArrayNotHasKey($s['viewed']->id, $options, 'viewer role: may not launch it');
        $this->assertArrayNotHasKey($s['foreign']->id, $options, 'another team\'s template');
        $this->assertSame($s['own']->name, $options[$s['own']->id]);
        $this->assertIsArray($ctrl->capturedParams['approvalRuleOptions']);
    }

    public function testViewOfViewedOnlyWorkflowIsReadOnly(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['viewed']->id);
        $this->loginAs($s['member']);

        $ctrl = $this->makeController();
        $this->assertSame('rendered:view', $ctrl->actionView((int)$wf->id));

        $this->assertFalse($ctrl->capturedParams['canOperate']);
        $this->assertSame([], $ctrl->capturedParams['jobTemplateOptions']);
        $this->assertSame([], $ctrl->capturedParams['approvalRuleOptions']);
    }

    public function testViewDeniesWorkflowWithAStepOfAnotherTeam(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['foreign']->id);
        $this->loginAs($s['member']);

        $this->expectException(ForbiddenHttpException::class);
        $this->makeController()->actionView((int)$wf->id);
    }

    public function testViewLetsAdminsSeeEveryWorkflow(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['member']->id, $s['foreign']->id);
        $this->loginAs($s['admin']);

        $ctrl = $this->makeController();
        $this->assertSame('rendered:view', $ctrl->actionView((int)$wf->id));
        $this->assertTrue($ctrl->capturedParams['canOperate']);
        $this->assertArrayHasKey($s['foreign']->id, $ctrl->capturedParams['jobTemplateOptions']);
    }

    public function testViewNamesWhomTheTriggerRunsAs(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->loginAs($s['member']);

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$wf->id);
        $this->assertNull($ctrl->capturedParams['triggerUser'], 'no token, no trigger user');

        $wf->generateTriggerToken($s['member']->id);
        $ctrl->actionView((int)$wf->id);
        $this->assertInstanceOf(User::class, $ctrl->capturedParams['triggerUser']);
        $this->assertSame($s['member']->id, $ctrl->capturedParams['triggerUser']->id, 'the user who generated the token');
    }

    public function testViewNamesTheCreatorForTriggerTokensThatPredateRecordingTheirCreator(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $wf->trigger_token = hash('sha256', 'legacy-token');
        $wf->save(false, ['trigger_token']);
        $this->loginAs($s['member']);

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$wf->id);

        $this->assertInstanceOf(User::class, $ctrl->capturedParams['triggerUser']);
        $this->assertSame($s['admin']->id, $ctrl->capturedParams['triggerUser']->id);
    }

    public function testUpdateOfViewedOnlyWorkflowIsForbiddenBeforeAnythingIsLoaded(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['viewed']->id);
        $name = $wf->name;
        $this->loginAs($s['member']);
        $this->setPost(['WorkflowTemplate' => ['name' => 'renamed-by-viewer']]);

        $this->assertForbidden(fn () => $this->makeController()->actionUpdate((int)$wf->id));

        $wf->refresh();
        $this->assertSame($name, $wf->name);
    }

    public function testUpdateFormOfWorkflowWithAForeignStepIsForbidden(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['foreign']->id);
        $this->loginAs($s['member']);

        $this->expectException(ForbiddenHttpException::class);
        $this->makeController()->actionUpdate((int)$wf->id);
    }

    public function testUpdateOfOwnWorkflowAndAdminUpdateOfForeignWorkflowSucceed(): void
    {
        $s = $this->teamScope();
        $own = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $foreign = $this->createWorkflowWithJobSteps($s['admin']->id, $s['foreign']->id);

        $this->loginAs($s['member']);
        $this->setPost(['WorkflowTemplate' => ['name' => 'renamed-by-member']]);
        $this->assertInstanceOf(Response::class, $this->makeController()->actionUpdate((int)$own->id));
        $own->refresh();
        $this->assertSame('renamed-by-member', $own->name);

        $this->loginAs($s['admin']);
        $this->setPost(['WorkflowTemplate' => ['name' => 'renamed-by-admin']]);
        $this->assertInstanceOf(Response::class, $this->makeController()->actionUpdate((int)$foreign->id));
        $foreign->refresh();
        $this->assertSame('renamed-by-admin', $foreign->name);
    }

    /**
     * Regression: created_by and trigger_token were mass-assignable, so a
     * form could pick whom the workflow and its trigger run as.
     */
    public function testCreateAndUpdateIgnorePostedCreatorAndTriggerToken(): void
    {
        $user = $this->createUser();
        $other = $this->createUser('other');
        $this->loginAs($user);
        $forged = [
            'created_by' => (string)$other->id,
            'trigger_token' => hash('sha256', 'forged'),
            'trigger_token_created_by' => (string)$other->id,
        ];

        $this->setPost(['WorkflowTemplate' => ['name' => 'wf-mass-assign'] + $forged]);
        $this->makeController()->actionCreate();
        $created = WorkflowTemplate::findOne(['name' => 'wf-mass-assign']);
        $this->assertNotNull($created);
        $this->assertSame($user->id, (int)$created->created_by);
        $this->assertNull($created->trigger_token);
        $this->assertNull($created->trigger_token_created_by);

        $this->setPost(['WorkflowTemplate' => ['name' => 'wf-mass-assign-2'] + $forged]);
        $this->makeController()->actionUpdate((int)$created->id);
        $created->refresh();
        $this->assertSame('wf-mass-assign-2', $created->name);
        $this->assertSame($user->id, (int)$created->created_by);
        $this->assertNull($created->trigger_token);
        $this->assertNull($created->trigger_token_created_by);
    }

    public function testDeleteOfWorkflowWithAForeignStepIsForbidden(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['foreign']->id);
        $this->loginAs($s['member']);

        $this->assertForbidden(fn () => $this->makeController()->actionDelete((int)$wf->id));

        $this->assertNotNull(WorkflowTemplate::findOne($wf->id), 'still there');
    }

    public function testDeleteOfViewedOnlyWorkflowIsForbidden(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['viewed']->id);
        $this->loginAs($s['member']);

        $this->assertForbidden(fn () => $this->makeController()->actionDelete((int)$wf->id));

        $this->assertNotNull(WorkflowTemplate::findOne($wf->id));
    }

    public function testAdminMayDeleteWorkflowOfAnotherTeam(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['member']->id, $s['foreign']->id);
        $this->loginAs($s['admin']);

        $this->makeController()->actionDelete((int)$wf->id);

        $this->assertNull(WorkflowTemplate::findOne($wf->id), 'soft-deleted');
    }

    public function testLaunchOfOwnWorkflowRunsAsTheMember(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['open']->id);
        $this->loginAs($s['member']);
        $this->realLaunches();
        $this->setQueryParams(['id' => (string)$wf->id]);

        $this->assertInstanceOf(Response::class, $this->makeController()->actionLaunch());

        $run = WorkflowJob::findOne(['workflow_template_id' => $wf->id]);
        $this->assertNotNull($run);
        $this->assertSame($s['member']->id, (int)$run->launched_by);
        $audit = AuditLog::findOne(['action' => AuditLog::ACTION_WORKFLOW_LAUNCHED, 'object_id' => $run->id]);
        $this->assertNotNull($audit);
        $this->assertSame('web', $this->metadata($audit)['source'] ?? null);
        $this->assertArrayHasKey('success', \Yii::$app->session->getAllFlashes());
    }

    public function testLaunchOfViewedOnlyWorkflowIsForbiddenAndAudited(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['viewed']->id);
        $this->loginAs($s['member']);
        $this->realLaunches();
        $this->setQueryParams(['id' => (string)$wf->id]);

        $this->assertForbidden(fn () => $this->makeController()->actionLaunch(), '#' . $s['viewed']->id);

        $this->assertFalse(WorkflowJob::find()->where(['workflow_template_id' => $wf->id])->exists(), 'nothing launched');
        $denied = AuditLog::findOne([
            'action' => AuditLog::ACTION_WORKFLOW_LAUNCH_DENIED,
            'object_type' => 'workflow_template',
            'object_id' => $wf->id,
        ]);
        $this->assertNotNull($denied);
        $this->assertSame(['source' => 'web', 'job_template_ids' => [$s['viewed']->id]], $this->metadata($denied));
        $this->assertArrayNotHasKey('danger', \Yii::$app->session->getAllFlashes(), 'a 403, not a failed launch');
    }

    public function testLaunchOfWorkflowWithAForeignStepIsForbidden(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['foreign']->id);
        $this->loginAs($s['member']);
        $this->realLaunches();
        $this->setPost(['id' => (string)$wf->id]);

        $this->assertForbidden(fn () => $this->makeController()->actionLaunch());

        $this->assertFalse(WorkflowJob::find()->where(['workflow_template_id' => $wf->id])->exists());
    }

    public function testLaunchByAdminAndOfOpenProjectWorkflowsIsNotBlocked(): void
    {
        $s = $this->teamScope();
        $foreign = $this->createWorkflowWithJobSteps($s['member']->id, $s['foreign']->id);
        $open = $this->createWorkflowWithJobSteps($s['admin']->id, $s['open']->id);
        $this->realLaunches();

        $this->loginAs($s['admin']);
        $this->setQueryParams(['id' => (string)$foreign->id]);
        $this->makeController()->actionLaunch();
        $this->assertTrue(WorkflowJob::find()->where(['workflow_template_id' => $foreign->id])->exists());

        $noTeam = $this->createUserWithRole('no_team', 'operator');
        $this->loginAs($noTeam);
        $this->setQueryParams(['id' => (string)$open->id]);
        $this->makeController()->actionLaunch();
        $this->assertTrue(
            WorkflowJob::find()->where(['workflow_template_id' => $open->id, 'launched_by' => $noTeam->id])->exists(),
            'a project without teams stays open to everyone'
        );
    }

    public function testAddStepWithOwnTemplateIsSavedAndAudited(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->loginAs($s['member']);
        $this->postStep(['name' => 'own-step', 'step_type' => WorkflowStep::TYPE_JOB, 'job_template_id' => (string)$s['own']->id]);

        $this->makeController()->actionAddStep((int)$wf->id);

        $step = WorkflowStep::findOne(['workflow_template_id' => $wf->id, 'name' => 'own-step']);
        $this->assertNotNull($step);
        $this->assertSame($s['own']->id, (int)$step->job_template_id);
        $audit = AuditLog::findOne(['action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_ADDED, 'object_id' => $wf->id]);
        $this->assertNotNull($audit);
        $this->assertSame($s['member']->id, (int)$audit->user_id);
        $this->assertSame([
            'step_id' => $step->id,
            'step_name' => 'own-step',
            'step_type' => WorkflowStep::TYPE_JOB,
            'job_template_id' => $s['own']->id,
        ], $this->metadata($audit));
        $this->assertArrayHasKey('success', \Yii::$app->session->getAllFlashes());
    }

    public function testAddStepWithTemplateOfAnOpenProjectIsSaved(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->loginAs($s['member']);
        $this->postStep(['name' => 'open-step', 'step_type' => WorkflowStep::TYPE_JOB, 'job_template_id' => (string)$s['open']->id]);

        $this->makeController()->actionAddStep((int)$wf->id);

        $this->assertTrue(WorkflowStep::find()->where(['workflow_template_id' => $wf->id, 'name' => 'open-step'])->exists());
    }

    public function testAddStepWithViewedOnlyTemplateIsForbidden(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->loginAs($s['member']);
        $this->postStep(['name' => 'viewed-step', 'step_type' => WorkflowStep::TYPE_JOB, 'job_template_id' => (string)$s['viewed']->id]);

        $this->assertForbidden(fn () => $this->makeController()->actionAddStep((int)$wf->id));

        $this->assertFalse(WorkflowStep::find()->where(['workflow_template_id' => $wf->id, 'name' => 'viewed-step'])->exists());
    }

    /**
     * Another team's template, an unknown ID and a deleted template all read
     * as "does not exist": the form must not reveal other teams' templates.
     */
    public function testAddStepWithTemplateTheMemberCannotSeeReportsItMissing(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $deleted = $this->createJobTemplate((int)$s['own']->project_id, (int)$s['own']->inventory_id, (int)$s['own']->runner_group_id, $s['admin']->id);
        $deleted->softDelete();
        $this->loginAs($s['member']);

        foreach (['foreign' => $s['foreign']->id, 'unknown' => 987654321, 'deleted' => $deleted->id] as $label => $templateId) {
            $this->postStep(['name' => 'step-' . $label, 'step_type' => WorkflowStep::TYPE_JOB, 'job_template_id' => (string)$templateId]);

            $this->makeController()->actionAddStep((int)$wf->id);

            $flashes = \Yii::$app->session->getAllFlashes(true);
            $this->assertSame('Step not added: The selected job template does not exist.', $flashes['danger'] ?? null, $label);
            $this->assertFalse(WorkflowStep::find()->where(['workflow_template_id' => $wf->id, 'name' => 'step-' . $label])->exists(), $label);
        }
    }

    public function testAddStepToWorkflowWithAForeignStepIsForbidden(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['foreign']->id);
        $this->loginAs($s['member']);
        $this->postStep(['name' => 'pause-in-foreign', 'step_type' => WorkflowStep::TYPE_PAUSE]);

        $this->assertForbidden(fn () => $this->makeController()->actionAddStep((int)$wf->id));

        $this->assertFalse(WorkflowStep::find()->where(['name' => 'pause-in-foreign'])->exists());
    }

    public function testAddStepToViewedOnlyWorkflowIsForbidden(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['viewed']->id);
        $this->loginAs($s['member']);
        $this->postStep(['name' => 'pause-in-viewed', 'step_type' => WorkflowStep::TYPE_PAUSE]);

        $this->assertForbidden(fn () => $this->makeController()->actionAddStep((int)$wf->id));

        $this->assertFalse(WorkflowStep::find()->where(['name' => 'pause-in-viewed'])->exists());
    }

    /**
     * Regression: workflow_template_id was mass-assignable, so a step could be
     * posted into another workflow through the URL of one the user may change.
     */
    public function testAddStepIgnoresAPostedWorkflowTemplateId(): void
    {
        $s = $this->teamScope();
        $mine = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $theirs = $this->createWorkflowWithJobSteps($s['admin']->id, $s['foreign']->id);
        $this->loginAs($s['member']);
        $this->postStep(['name' => 'smuggled', 'step_type' => WorkflowStep::TYPE_PAUSE, 'workflow_template_id' => (string)$theirs->id]);

        $this->makeController()->actionAddStep((int)$mine->id);

        $step = WorkflowStep::findOne(['name' => 'smuggled']);
        $this->assertNotNull($step);
        $this->assertSame($mine->id, (int)$step->workflow_template_id);
        $this->assertSame(1, (int)WorkflowStep::find()->where(['workflow_template_id' => $theirs->id])->count());
    }

    /**
     * Regression: an invalid step redirected without a word.
     */
    public function testAddStepReportsTheFirstValidationError(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $wf = $this->createWorkflowTemplate($user->id);

        $this->postStep(['name' => '', 'step_type' => WorkflowStep::TYPE_PAUSE]);
        $this->makeController()->actionAddStep((int)$wf->id);
        $this->assertSame('Step not added: Name cannot be blank.', \Yii::$app->session->getAllFlashes(true)['danger'] ?? null);

        $this->postStep(['name' => 'no-template', 'step_type' => WorkflowStep::TYPE_JOB, 'job_template_id' => '']);
        $this->makeController()->actionAddStep((int)$wf->id);
        $this->assertSame('Step not added: A job step needs a job template.', \Yii::$app->session->getAllFlashes(true)['danger'] ?? null);

        $this->postStep(['name' => 'zero-template', 'step_type' => WorkflowStep::TYPE_JOB, 'job_template_id' => '0']);
        $this->makeController()->actionAddStep((int)$wf->id);
        $this->assertSame('Step not added: The selected job template does not exist.', \Yii::$app->session->getAllFlashes(true)['danger'] ?? null);

        $this->assertSame(0, (int)WorkflowStep::find()->where(['workflow_template_id' => $wf->id])->count());
    }

    /**
     * The form posts the hidden job and approval dropdowns for every step
     * type; a job template left on a pause step would hide the workflow
     * from everyone who cannot see that template.
     */
    public function testAddStepKeepsOnlyTheTargetOfItsType(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $rule = $this->createApprovalRule($s['admin']->id);
        $this->loginAs($s['member']);

        $this->postStep(['name' => 'pause', 'step_type' => WorkflowStep::TYPE_PAUSE, 'job_template_id' => (string)$s['foreign']->id, 'approval_rule_id' => (string)$rule->id]);
        $this->makeController()->actionAddStep((int)$wf->id);
        $this->postStep(['name' => 'approve', 'step_type' => WorkflowStep::TYPE_APPROVAL, 'job_template_id' => (string)$s['own']->id, 'approval_rule_id' => (string)$rule->id]);
        $this->makeController()->actionAddStep((int)$wf->id);

        $pause = WorkflowStep::findOne(['workflow_template_id' => $wf->id, 'name' => 'pause']);
        $this->assertNotNull($pause);
        $this->assertNull($pause->job_template_id);
        $this->assertNull($pause->approval_rule_id);
        $approve = WorkflowStep::findOne(['workflow_template_id' => $wf->id, 'name' => 'approve']);
        $this->assertNotNull($approve);
        $this->assertNull($approve->job_template_id);
        $this->assertSame($rule->id, (int)$approve->approval_rule_id);
    }

    public function testAdminMayAddAStepWithAnyTemplate(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->loginAs($s['admin']);
        $this->postStep(['name' => 'admin-step', 'step_type' => WorkflowStep::TYPE_JOB, 'job_template_id' => (string)$s['foreign']->id]);

        $this->makeController()->actionAddStep((int)$wf->id);

        $this->assertTrue(WorkflowStep::find()->where(['workflow_template_id' => $wf->id, 'job_template_id' => $s['foreign']->id])->exists());
    }

    /**
     * Regression: the add-step check read the job template ID with
     * filter_var(), which rejects a leading zero, but the step's integer
     * rule accepts "0112709" and the column stores it as 112709. Such an ID
     * skipped the check, so another team's job template could be put into a
     * workflow. Every form the integer rule accepts is checked as the int it
     * is stored as; a sign without a leading zero always passed filter_var()
     * and stays pinned.
     *
     * @return array<string, array{0: string}>
     */
    public static function storedIdFormProvider(): array
    {
        return [
            'leading zero' => ['0%d'],
            'leading zeros' => ['000%d'],
            'plus sign and leading zero' => ['+0%d'],
            'plus sign' => ['+%d'],
            'trailing newline' => ["%d\n"],
        ];
    }

    /**
     * @dataProvider storedIdFormProvider
     */
    public function testAddStepReportsAnotherTeamsTemplateMissingInEveryIdForm(string $form): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->loginAs($s['member']);
        $this->postStep(['name' => 'padded-foreign', 'step_type' => WorkflowStep::TYPE_JOB, 'job_template_id' => sprintf($form, $s['foreign']->id)]);

        $this->makeController()->actionAddStep((int)$wf->id);

        $this->assertSame(['danger' => 'Step not added: The selected job template does not exist.'], \Yii::$app->session->getAllFlashes(true));
        $this->assertFalse(WorkflowStep::find()->where(['workflow_template_id' => $wf->id, 'name' => 'padded-foreign'])->exists());
        $this->assertFalse(WorkflowStep::find()->where(['job_template_id' => $s['foreign']->id])->exists());
    }

    /**
     * @dataProvider storedIdFormProvider
     */
    public function testAddStepForbidsATemplateTheTeamOnlyViewsInEveryIdForm(string $form): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->loginAs($s['member']);
        $this->postStep(['name' => 'padded-viewed', 'step_type' => WorkflowStep::TYPE_JOB, 'job_template_id' => sprintf($form, $s['viewed']->id)]);

        $this->assertForbidden(fn () => $this->makeController()->actionAddStep((int)$wf->id), 'needs operator access');

        $this->assertFalse(WorkflowStep::find()->where(['workflow_template_id' => $wf->id, 'name' => 'padded-viewed'])->exists());
    }

    /**
     * @dataProvider storedIdFormProvider
     */
    public function testAddStepStoresTheTeamsTemplateGivenInEveryIdForm(string $form): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->loginAs($s['member']);
        $this->postStep(['name' => 'padded-own', 'step_type' => WorkflowStep::TYPE_JOB, 'job_template_id' => sprintf($form, $s['own']->id)]);

        $this->makeController()->actionAddStep((int)$wf->id);

        $step = WorkflowStep::findOne(['workflow_template_id' => $wf->id, 'name' => 'padded-own']);
        $this->assertNotNull($step);
        $this->assertSame($s['own']->id, $step->job_template_id);
        $audit = AuditLog::findOne(['action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_ADDED, 'object_id' => $wf->id]);
        $this->assertNotNull($audit);
        $this->assertSame($s['own']->id, $this->metadata($audit)['job_template_id'] ?? null);
    }

    /**
     * Regression: remove-step never loaded the workflow, so it neither
     * answered 404 nor checked access.
     */
    public function testRemoveStepThrowsNotFoundForAnUnknownWorkflow(): void
    {
        $this->loginAs($this->createUser());
        $this->setPost(['step_id' => '1']);

        $this->expectException(NotFoundHttpException::class);
        $this->makeController()->actionRemoveStep(9999999);
    }

    public function testRemoveStepOfWorkflowWithAForeignStepIsForbidden(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowTemplate($s['admin']->id);
        $pause = $this->createWorkflowStep($wf->id, 0, WorkflowStep::TYPE_PAUSE);
        $this->createWorkflowStep($wf->id, 1, WorkflowStep::TYPE_JOB, $s['foreign']->id);
        $this->loginAs($s['member']);
        $this->setPost(['step_id' => (string)$pause->id]);

        $this->assertForbidden(fn () => $this->makeController()->actionRemoveStep((int)$wf->id));

        $this->assertNotNull(WorkflowStep::findOne($pause->id));
    }

    public function testRemoveStepOfViewedOnlyWorkflowIsForbidden(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['viewed']->id);
        $step = WorkflowStep::findOne(['workflow_template_id' => $wf->id]);
        $this->assertNotNull($step);
        $this->loginAs($s['member']);
        $this->setPost(['step_id' => (string)$step->id]);

        $this->assertForbidden(fn () => $this->makeController()->actionRemoveStep((int)$wf->id));

        $this->assertNotNull(WorkflowStep::findOne($step->id));
    }

    public function testRemoveStepIsAudited(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $step = WorkflowStep::findOne(['workflow_template_id' => $wf->id]);
        $this->assertNotNull($step);
        $this->loginAs($s['member']);
        $this->setPost(['step_id' => (string)$step->id]);

        $this->makeController()->actionRemoveStep((int)$wf->id);

        $this->assertNull(WorkflowStep::findOne($step->id));
        $audit = AuditLog::findOne(['action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_REMOVED, 'object_id' => $wf->id]);
        $this->assertNotNull($audit);
        $this->assertSame($s['own']->id, $this->metadata($audit)['job_template_id'] ?? null);
        $this->assertSame($step->id, $this->metadata($audit)['step_id'] ?? null);
    }

    public function testMoveStepOfViewedOnlyWorkflowIsForbidden(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowTemplate($s['admin']->id);
        $a = $this->createWorkflowStep($wf->id, 10, WorkflowStep::TYPE_JOB, $s['viewed']->id);
        $b = $this->createWorkflowStep($wf->id, 20, WorkflowStep::TYPE_PAUSE);
        $this->loginAs($s['member']);
        $this->setPost(['step_id' => (string)$b->id, 'direction' => 'up']);

        $this->assertForbidden(fn () => $this->makeController()->actionMoveStep((int)$wf->id));

        $a->refresh();
        $b->refresh();
        $this->assertSame([10, 20], [(int)$a->step_order, (int)$b->step_order]);
    }

    public function testMoveStepIsAudited(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowTemplate($s['admin']->id);
        $this->createWorkflowStep($wf->id, 10, WorkflowStep::TYPE_PAUSE);
        $b = $this->createWorkflowStep($wf->id, 20, WorkflowStep::TYPE_JOB, $s['own']->id);
        $this->loginAs($s['member']);
        $this->setPost(['step_id' => (string)$b->id, 'direction' => 'up']);

        $this->makeController()->actionMoveStep((int)$wf->id);

        $audit = AuditLog::findOne(['action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_MOVED, 'object_id' => $wf->id]);
        $this->assertNotNull($audit);
        $metadata = $this->metadata($audit);
        $this->assertSame('up', $metadata['direction'] ?? null);
        $this->assertSame($s['own']->id, $metadata['job_template_id'] ?? null);
    }

    public function testGenerateTriggerTokenForWorkflowWithAForeignStepIsForbidden(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['foreign']->id);
        $this->loginAs($s['member']);

        $this->assertForbidden(fn () => $this->makeController()->actionGenerateTriggerToken((int)$wf->id));

        $wf->refresh();
        $this->assertNull($wf->trigger_token, 'no token that would launch another team\'s job');
        $this->assertArrayNotHasKey('trigger_token_raw', \Yii::$app->session->getAllFlashes());
    }

    public function testGenerateTriggerTokenForViewedOnlyWorkflowIsForbidden(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['viewed']->id);
        $this->loginAs($s['member']);

        $this->assertForbidden(fn () => $this->makeController()->actionGenerateTriggerToken((int)$wf->id));

        $wf->refresh();
        $this->assertNull($wf->trigger_token);
    }

    public function testGenerateTriggerTokenRecordsWhoGeneratedIt(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->loginAs($s['member']);

        $this->makeController()->actionGenerateTriggerToken((int)$wf->id);

        $wf->refresh();
        $this->assertNotNull($wf->trigger_token);
        $this->assertSame($s['member']->id, (int)$wf->trigger_token_created_by, 'the trigger runs as the member');
    }

    public function testRevokeTriggerTokenOfWorkflowWithAForeignStepIsForbidden(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['foreign']->id);
        $wf->generateTriggerToken($s['outsider']->id);
        $this->loginAs($s['member']);

        $this->assertForbidden(fn () => $this->makeController()->actionRevokeTriggerToken((int)$wf->id));

        $wf->refresh();
        $this->assertNotNull($wf->trigger_token);
        $this->assertSame($s['outsider']->id, (int)$wf->trigger_token_created_by);
    }

    public function testAddStepKeepsTheEndWorkflowSentinelAndBranchTargets(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $wf = $this->createWorkflowTemplate($user->id);
        $existing = $this->createWorkflowStep($wf->id, 10, WorkflowStep::TYPE_PAUSE);
        $this->postStep([
            'name' => 'branching',
            'step_type' => WorkflowStep::TYPE_PAUSE,
            'on_success_step_id' => '0',
            'on_failure_step_id' => (string)$existing->id,
            'on_always_step_id' => '',
        ]);

        $this->makeController()->actionAddStep((int)$wf->id);

        $step = WorkflowStep::findOne(['workflow_template_id' => $wf->id, 'name' => 'branching']);
        $this->assertNotNull($step);
        $this->assertSame(WorkflowStep::END_WORKFLOW, $step->on_success_step_id);
        $this->assertSame($existing->id, $step->on_failure_step_id);
        $this->assertNull($step->on_always_step_id);
    }

    // ── Rendered pages ───────────────────────────────────────────────────────

    public function testViewPageOfViewedOnlyWorkflowOffersNoChanges(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['viewed']->id);
        $wf->generateTriggerToken($s['admin']->id);
        $this->loginAs($s['member']);
        $ctrl = $this->makeController();
        $ctrl->actionView((int)$wf->id);

        $html = $this->renderPage('view', $ctrl->capturedParams);

        $this->assertStringContainsString('id="wf-view-only-notice"', $html);
        $this->assertStringNotContainsString(Url::to(['/workflow-template/launch', 'id' => $wf->id]), $html);
        $this->assertStringNotContainsString(Url::to(['/workflow-template/update', 'id' => $wf->id]), $html);
        $this->assertStringNotContainsString(Url::to(['/workflow-template/delete', 'id' => $wf->id]), $html);
        $this->assertStringNotContainsString('id="add-step-form"', $html);
        $this->assertStringNotContainsString('move-step', $html);
        $this->assertStringNotContainsString('Inbound Trigger', $html);
    }

    public function testViewPageOffersOnlyOperableTemplatesAndNamesTheTriggerUserEncoded(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $tokenOwner = $this->createUserWithRole('trigger_owner', 'operator');
        $tokenOwner->username = '<b>owner</b>' . $tokenOwner->id;
        $tokenOwner->status = User::STATUS_INACTIVE;
        $tokenOwner->save(false);
        $wf->generateTriggerToken($tokenOwner->id);
        $this->loginAs($s['member']);
        $ctrl = $this->makeController();
        $ctrl->actionView((int)$wf->id);

        $html = $this->renderPage('view', $ctrl->capturedParams);

        $this->assertStringContainsString('id="add-step-form"', $html);
        $this->assertStringContainsString('<option value="' . $s['own']->id . '"', $html);
        $this->assertStringContainsString('<option value="' . $s['open']->id . '"', $html);
        $this->assertStringNotContainsString('<option value="' . $s['viewed']->id . '"', $html);
        $this->assertStringNotContainsString('<option value="' . $s['foreign']->id . '"', $html);
        $this->assertStringContainsString(Url::to(['/workflow-template/launch', 'id' => $wf->id]), $html);
        $this->assertStringNotContainsString('id="wf-view-only-notice"', $html);
        $this->assertStringContainsString('Launches run as <strong>&lt;b&gt;owner&lt;/b&gt;' . $tokenOwner->id . '</strong>', $html);
        $this->assertStringNotContainsString('<b>owner</b>', $html);
        $this->assertStringContainsString('who generated this token', $html);
        $this->assertMatchesRegularExpression('/badge text-bg-warning">disabled</', $html);
    }

    public function testViewPageExplainsWhomOlderTriggerTokensRunAs(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $wf->trigger_token = hash('sha256', 'legacy-token');
        $wf->save(false, ['trigger_token']);
        $this->loginAs($s['member']);
        $ctrl = $this->makeController();
        $ctrl->actionView((int)$wf->id);

        $html = $this->renderPage('view', $ctrl->capturedParams);

        $this->assertStringContainsString('Launches run as <strong>' . htmlspecialchars($s['admin']->username) . '</strong>', $html);
        $this->assertStringContainsString('the workflow&#039;s creator', $html);
        $this->assertStringNotContainsString('text-bg-warning">disabled<', $html);
    }

    public function testIndexPageOffersLaunchOnlyForWorkflowsTheMemberMayLaunch(): void
    {
        $s = $this->teamScope();
        $own = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $viewed = $this->createWorkflowWithJobSteps($s['admin']->id, $s['viewed']->id);
        $this->loginAs($s['member']);
        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        $html = $this->renderPage('index', $ctrl->capturedParams);

        $this->assertStringContainsString($viewed->name, $html);
        $this->assertStringContainsString(Url::to(['/workflow-template/launch', 'id' => $own->id]), $html);
        $this->assertStringNotContainsString(Url::to(['/workflow-template/launch', 'id' => $viewed->id]), $html);
    }

    // ── Team scoping helpers ─────────────────────────────────────────────────

    /**
     * The real view, rendered with what the action passed to it. The console
     * application of the tests has no web root, so asset bundles are
     * dummies; view, asset manager and controller are restored.
     *
     * @param array<string, mixed> $params
     */
    private function renderPage(string $view, array $params): string
    {
        $components = \Yii::$app->getComponents(true);
        $originals = ['view' => $components['view'] ?? null, 'assetManager' => $components['assetManager'] ?? null];
        $previousController = \Yii::$app->controller;
        \Yii::$app->set('assetManager', new AssetManager(['bundles' => false, 'basePath' => sys_get_temp_dir(), 'baseUrl' => '/assets']));
        \Yii::$app->set('view', new View());
        $ctrl = new WorkflowTemplateController('workflow-template', \Yii::$app);
        \Yii::$app->controller = $ctrl;
        try {
            return $ctrl->renderPartial($view, $params);
        } finally {
            \Yii::$app->controller = $previousController;
            foreach ($originals as $id => $definition) {
                \Yii::$app->set($id, $definition);
            }
        }
    }

    private function realLaunches(): void
    {
        /** @var object{real: bool} $svc */
        $svc = \Yii::$app->get('workflowExecutionService');
        $svc->real = true;
    }

    /**
     * @param array<string, string> $fields
     */
    private function postStep(array $fields): void
    {
        $this->setPost(['WorkflowStep' => $fields]);
    }

    /**
     * Runs $action and expects a 403.
     */
    private function assertForbidden(callable $action, string $messageContains = ''): void
    {
        try {
            $action();
            $this->fail('Expected ForbiddenHttpException.');
        } catch (ForbiddenHttpException $e) {
            $this->assertStringContainsString($messageContains, $e->getMessage());
        }
    }

    /**
     * IDs of $among on the index page, in list order.
     *
     * @param list<WorkflowTemplate> $among
     * @return list<int>
     */
    private function listedIds(WorkflowTemplateController $ctrl, array $among): array
    {
        /** @var ActiveDataProvider $dataProvider */
        $dataProvider = $ctrl->capturedParams['dataProvider'];
        $wanted = array_map(static fn (WorkflowTemplate $w): int => $w->id, $among);
        $listed = array_map(static fn (WorkflowTemplate $w): int => (int)$w->id, $dataProvider->getModels());
        $found = array_values(array_intersect($listed, $wanted));
        sort($found);

        return $found;
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(AuditLog $audit): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string)$audit->metadata, true);
        return $decoded;
    }
}
