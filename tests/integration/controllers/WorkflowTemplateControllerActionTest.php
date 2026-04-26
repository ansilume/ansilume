<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\WorkflowTemplateController;
use app\models\AuditLog;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowExecutionService;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class WorkflowTemplateControllerActionTest extends WebControllerTestCase
{
    /** @var list<array{string, \yii\base\Component}> */
    private array $swappedServices = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->swapService('workflowExecutionService', new class extends WorkflowExecutionService {
            public int $launchCalls = 0;
            public bool $throwOnLaunch = false;
            public function launch(WorkflowTemplate $template, int $launchedBy, array $overrides = []): WorkflowJob
            {
                $this->launchCalls++;
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
        /** @var object{launchCalls: int} $svc */
        $svc = \Yii::$app->get('workflowExecutionService');
        $this->assertSame(1, $svc->launchCalls);
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
}
