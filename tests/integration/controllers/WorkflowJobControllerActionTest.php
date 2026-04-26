<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\WorkflowJobController;
use app\models\WorkflowJob;
use app\services\WorkflowExecutionService;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class WorkflowJobControllerActionTest extends WebControllerTestCase
{
    /** @var list<array{string, \yii\base\Component}> */
    private array $swappedServices = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->swapService('workflowExecutionService', new class extends WorkflowExecutionService {
            public int $cancelCalls = 0;
            public function cancel(WorkflowJob $wfJob, int $userId): void
            {
                $this->cancelCalls++;
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

    public function testIndexRendersDataProvider(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $this->createWfJob($user);

        $ctrl = $this->makeController();
        $result = $ctrl->actionIndex();

        $this->assertSame('rendered:index', $result);
        $this->assertInstanceOf(ActiveDataProvider::class, $ctrl->capturedParams['dataProvider']);
    }

    public function testViewRendersModel(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $wfJob = $this->createWfJob($user);

        $ctrl = $this->makeController();
        $result = $ctrl->actionView((int)$wfJob->id);

        $this->assertSame('rendered:view', $result);
        $this->assertSame($wfJob->id, $ctrl->capturedParams['model']->id);
    }

    public function testViewThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionView(9999999);
    }

    public function testCancelDelegatesToService(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $wfJob = $this->createWfJob($user);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCancel((int)$wfJob->id);

        $this->assertInstanceOf(Response::class, $result);
        /** @var object{cancelCalls: int} $svc */
        $svc = \Yii::$app->get('workflowExecutionService');
        $this->assertSame(1, $svc->cancelCalls);
    }

    public function testCancelThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionCancel(9999999);
    }

    public function testBehaviorsWiresAccessAndVerbFilters(): void
    {
        $ctrl = new WorkflowJobController('workflow-job', \Yii::$app);
        $behaviors = $ctrl->behaviors();
        $this->assertArrayHasKey('access', $behaviors);
        $this->assertArrayHasKey('verbs', $behaviors);
    }

    // ── actionStatus() — duration field on each step ─────────────────────────

    public function testStatusReturnsDurationLabelForFinishedStep(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $wf = $this->createWorkflowTemplate($user->id);
        $step = $this->createWorkflowStep($wf->id, 10, \app\models\WorkflowStep::TYPE_PAUSE);

        $wj = $this->createWfJobForTemplate($user, $wf);
        $started = time() - 130;
        $finished = $started + 75; // 1m 15s
        $wjs = $this->seedJobStep($wj->id, $step->id, $started, $finished, \app\models\WorkflowJobStep::STATUS_SUCCEEDED);

        $ctrl = $this->makeController();
        $payload = $ctrl->actionStatus((int)$wj->id);

        $row = $this->stepRow($payload, (int)$wjs->workflow_step_id);
        $this->assertSame(75, $row['duration_seconds']);
        $this->assertSame('1m 15s', $row['duration_label']);
    }

    public function testStatusReturnsRunningPrefixForInFlightStep(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $wf = $this->createWorkflowTemplate($user->id);
        $step = $this->createWorkflowStep($wf->id, 10, \app\models\WorkflowStep::TYPE_PAUSE);

        $wj = $this->createWfJobForTemplate($user, $wf);
        $wjs = $this->seedJobStep($wj->id, $step->id, time() - 30, null, \app\models\WorkflowJobStep::STATUS_RUNNING);

        $ctrl = $this->makeController();
        $payload = $ctrl->actionStatus((int)$wj->id);

        $row = $this->stepRow($payload, (int)$wjs->workflow_step_id);
        $this->assertGreaterThanOrEqual(29, $row['duration_seconds']);
        $this->assertStringStartsWith('running ', (string)$row['duration_label']);
    }

    public function testStatusReturnsNullDurationForStepThatNeverStarted(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $wf = $this->createWorkflowTemplate($user->id);
        $step = $this->createWorkflowStep($wf->id, 10, \app\models\WorkflowStep::TYPE_PAUSE);

        $wj = $this->createWfJobForTemplate($user, $wf);
        $wjs = $this->seedJobStep($wj->id, $step->id, null, null, \app\models\WorkflowJobStep::STATUS_PENDING);

        $ctrl = $this->makeController();
        $payload = $ctrl->actionStatus((int)$wj->id);

        $row = $this->stepRow($payload, (int)$wjs->workflow_step_id);
        $this->assertNull($row['duration_seconds']);
        $this->assertNull($row['duration_label']);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function stepRow(array $payload, int $workflowStepId): array
    {
        foreach ($payload['steps'] as $row) {
            if ($row['workflow_step_id'] === $workflowStepId) {
                return $row;
            }
        }
        $this->fail('Step row for workflow_step_id ' . $workflowStepId . ' not present in status payload.');
    }

    private function createWfJobForTemplate(\app\models\User $user, \app\models\WorkflowTemplate $wf): WorkflowJob
    {
        $j = new WorkflowJob();
        $j->workflow_template_id = $wf->id;
        $j->launched_by = $user->id;
        $j->status = WorkflowJob::STATUS_RUNNING;
        $j->started_at = time();
        $j->created_at = time();
        $j->updated_at = time();
        $j->save(false);
        return $j;
    }

    private function seedJobStep(
        int $workflowJobId,
        int $workflowStepId,
        ?int $startedAt,
        ?int $finishedAt,
        string $status,
    ): \app\models\WorkflowJobStep {
        $wjs = new \app\models\WorkflowJobStep();
        $wjs->workflow_job_id = $workflowJobId;
        $wjs->workflow_step_id = $workflowStepId;
        $wjs->status = $status;
        $wjs->started_at = $startedAt;
        $wjs->finished_at = $finishedAt;
        $wjs->save(false);
        return $wjs;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function createWfJob(\app\models\User $user): WorkflowJob
    {
        $wf = $this->createWorkflowTemplate($user->id);
        $j = new WorkflowJob();
        $j->workflow_template_id = $wf->id;
        $j->launched_by = $user->id;
        $j->status = WorkflowJob::STATUS_RUNNING;
        $j->started_at = time();
        $j->created_at = time();
        $j->updated_at = time();
        $j->save(false);
        return $j;
    }

    private function swapService(string $id, \yii\base\Component $replacement): void
    {
        /** @var \yii\base\Component $original */
        $original = \Yii::$app->get($id);
        $this->swappedServices[] = [$id, $original];
        \Yii::$app->set($id, $replacement);
    }

    private function makeController(): WorkflowJobController
    {
        return new class ('workflow-job', \Yii::$app) extends WorkflowJobController {
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
