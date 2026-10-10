<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\WorkflowJobController;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowAccessDeniedException;
use app\services\WorkflowExecutionService;
use app\tests\integration\TeamScopeFixtures;
use yii\data\ActiveDataProvider;
use yii\helpers\Url;
use yii\web\AssetManager;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\View;

class WorkflowJobControllerActionTest extends WebControllerTestCase
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
            public int $cancelCalls = 0;
            public int $resumeCalls = 0;
            /** Run the real cancel: team scoping, state checks, audit entry. */
            public bool $real = false;
            /** Thrown by cancel() and resume(), e.g. a refusal the controller's own check missed. */
            public ?\RuntimeException $throw = null;
            public function cancel(WorkflowJob $wfJob, int $userId): void
            {
                $this->cancelCalls++;
                if ($this->throw !== null) {
                    throw $this->throw;
                }
                if ($this->real) {
                    parent::cancel($wfJob, $userId);
                }
            }
            public function resume(WorkflowJob $wfJob, int $userId): void
            {
                $this->resumeCalls++;
                if ($this->throw !== null) {
                    throw $this->throw;
                }
                parent::resume($wfJob, $userId);
            }
        });
    }

    protected function tearDown(): void
    {
        // Leave no flash for later tests in the process: $_SESSION outlasts a test.
        \Yii::$app->session->removeAllFlashes();
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

    public function testStatusIncludesStepNameAndIndexForLiveRowAppending(): void
    {
        // Workflow steps are created lazily — when a workflow advances to a
        // step that wasn't rendered server-side, the polling JS has to
        // build a brand-new <tr> from the JSON. step_name + step_index are
        // the fields that make that possible without a page reload.
        $user = $this->createUser();
        $this->loginAs($user);

        $wf = $this->createWorkflowTemplate($user->id);
        $stepA = $this->createWorkflowStep($wf->id, 10, \app\models\WorkflowStep::TYPE_PAUSE);
        $stepB = $this->createWorkflowStep($wf->id, 20, \app\models\WorkflowStep::TYPE_PAUSE);

        $wj = $this->createWfJobForTemplate($user, $wf);
        $this->seedJobStep($wj->id, $stepA->id, time() - 30, time() - 10, \app\models\WorkflowJobStep::STATUS_SUCCEEDED);
        $this->seedJobStep($wj->id, $stepB->id, time() - 5, null, \app\models\WorkflowJobStep::STATUS_RUNNING);

        $ctrl = $this->makeController();
        $payload = $ctrl->actionStatus((int)$wj->id);

        $rowA = $this->stepRow($payload, (int)$stepA->id);
        $rowB = $this->stepRow($payload, (int)$stepB->id);

        $this->assertSame($stepA->name, $rowA['step_name']);
        $this->assertSame($stepB->name, $rowB['step_name']);
        $this->assertSame(1, $rowA['step_index']);
        $this->assertSame(2, $rowB['step_index']);
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

    // ── Team scoping (regression) ────────────────────────────────────────────
    //
    // Workflow runs ignored team scoping: every user with workflow.view saw
    // every team's runs, and could cancel or resume them. A run follows its
    // workflow template, which belongs to the projects of its job steps.

    public function testIndexListsOnlyRunsOfWorkflowsTheMemberMayView(): void
    {
        $s = $this->teamScope();
        $runs = [];
        foreach (['own', 'viewed', 'open', 'foreign'] as $key) {
            $runs[$key] = $this->createWorkflowJob($this->createWorkflowWithJobSteps($s['admin']->id, $s[$key]->id)->id, $s['admin']->id);
        }
        $runs['mixed'] = $this->createWorkflowJob(
            $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['foreign']->id)->id,
            $s['admin']->id
        );
        $this->loginAs($s['member']);

        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        $this->assertSame(
            [$runs['own']->id, $runs['viewed']->id, $runs['open']->id],
            $this->listedRunIds($ctrl, $runs)
        );
    }

    public function testIndexShowsAdminsEveryRun(): void
    {
        $s = $this->teamScope();
        $foreign = $this->createWorkflowJob($this->createWorkflowWithJobSteps($s['admin']->id, $s['foreign']->id)->id, $s['outsider']->id);
        $this->loginAs($s['admin']);

        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        $this->assertSame([$foreign->id], $this->listedRunIds($ctrl, [$foreign]));
    }

    public function testViewOfRunOfWorkflowWithAForeignStepIsForbidden(): void
    {
        $s = $this->teamScope();
        $run = $this->createWorkflowJob(
            $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['foreign']->id)->id,
            $s['admin']->id
        );
        $this->loginAs($s['member']);

        $this->expectException(ForbiddenHttpException::class);
        $this->makeController()->actionView((int)$run->id);
    }

    public function testViewTellsWhetherTheMemberMayCancelOrResume(): void
    {
        $s = $this->teamScope();
        $own = $this->createWorkflowJob($this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id)->id, $s['admin']->id);
        $viewed = $this->createWorkflowJob($this->createWorkflowWithJobSteps($s['admin']->id, $s['viewed']->id)->id, $s['admin']->id);
        $this->loginAs($s['member']);

        $ctrl = $this->makeController();
        $this->assertSame('rendered:view', $ctrl->actionView((int)$own->id));
        $this->assertTrue($ctrl->capturedParams['canOperate']);

        $this->assertSame('rendered:view', $ctrl->actionView((int)$viewed->id));
        $this->assertFalse($ctrl->capturedParams['canOperate'], 'viewer role: no Cancel or Resume button');
    }

    /**
     * Runs keep their scope when their workflow template is soft-deleted:
     * the check goes by workflow_template_id.
     */
    public function testRunsOfDeletedWorkflowsKeepTheirScope(): void
    {
        $s = $this->teamScope();
        $foreignWf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['foreign']->id);
        $ownWf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $foreignRun = $this->createWorkflowJob($foreignWf->id, $s['admin']->id, WorkflowJob::STATUS_SUCCEEDED);
        $ownRun = $this->createWorkflowJob($ownWf->id, $s['admin']->id, WorkflowJob::STATUS_SUCCEEDED);
        $foreignWf->softDelete();
        $ownWf->softDelete();
        $this->loginAs($s['member']);

        $ctrl = $this->makeController();
        $this->assertSame('rendered:view', $ctrl->actionView((int)$ownRun->id));
        $ctrl->actionIndex();
        $this->assertSame([$ownRun->id], $this->listedRunIds($ctrl, [$foreignRun, $ownRun]));

        $this->expectException(ForbiddenHttpException::class);
        $ctrl->actionView((int)$foreignRun->id);
    }

    public function testStatusOfRunOfForeignWorkflowIsForbidden(): void
    {
        $s = $this->teamScope();
        $run = $this->createWorkflowJob($this->createWorkflowWithJobSteps($s['admin']->id, $s['foreign']->id)->id, $s['admin']->id);
        $this->loginAs($s['member']);

        $this->expectException(ForbiddenHttpException::class);
        $this->makeController()->actionStatus((int)$run->id);
    }

    public function testStatusCarriesWhyAStepFailedWithoutAJob(): void
    {
        $s = $this->teamScope();
        $wf = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $step = WorkflowStep::findOne(['workflow_template_id' => $wf->id]);
        $this->assertNotNull($step);
        $run = $this->createWorkflowJob($wf->id, $s['admin']->id, WorkflowJob::STATUS_FAILED);
        $failed = $this->seedJobStep($run->id, $step->id, time() - 5, time(), WorkflowJobStep::STATUS_FAILED);
        $failed->error_message = 'Not launched: user #1, whom this workflow runs as, may not launch job template "x" (#2).';
        $failed->save(false);
        $this->loginAs($s['member']);

        $payload = $this->makeController()->actionStatus((int)$run->id);

        $row = $this->stepRow($payload, (int)$step->id);
        $this->assertSame($failed->error_message, $row['error_message']);
    }

    public function testCancelOfRunOfViewedOnlyWorkflowIsForbidden(): void
    {
        $s = $this->teamScope();
        $run = $this->createWorkflowJob($this->createWorkflowWithJobSteps($s['admin']->id, $s['viewed']->id)->id, $s['admin']->id);
        $this->loginAs($s['member']);
        $svc = $this->service();
        $svc->real = true;

        $this->assertForbidden(fn () => $this->makeController()->actionCancel((int)$run->id));

        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status);
        $this->assertSame(0, $svc->cancelCalls, 'refused before the service is asked');
    }

    public function testCancelOfRunOfForeignWorkflowIsForbidden(): void
    {
        $s = $this->teamScope();
        $run = $this->createWorkflowJob($this->createWorkflowWithJobSteps($s['admin']->id, $s['foreign']->id)->id, $s['outsider']->id);
        $this->loginAs($s['member']);
        $this->service()->real = true;

        $this->assertForbidden(fn () => $this->makeController()->actionCancel((int)$run->id));

        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status);
    }

    public function testCancelOfOwnRunAndAdminCancelOfForeignRunSucceed(): void
    {
        $s = $this->teamScope();
        $own = $this->createWorkflowJob($this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id)->id, $s['member']->id);
        $foreign = $this->createWorkflowJob($this->createWorkflowWithJobSteps($s['admin']->id, $s['foreign']->id)->id, $s['outsider']->id);
        $this->service()->real = true;

        $this->loginAs($s['member']);
        $this->makeController()->actionCancel((int)$own->id);
        $this->assertSame('Workflow canceled.', \Yii::$app->session->getAllFlashes(true)['success'] ?? null);

        $this->loginAs($s['admin']);
        $this->makeController()->actionCancel((int)$foreign->id);

        $own->refresh();
        $foreign->refresh();
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $own->status);
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $foreign->status);
    }

    /**
     * Regression: canceling a finished run let the service's exception
     * through, a server error instead of a message.
     */
    public function testCancelOfFinishedRunShowsAMessage(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $run = $this->createWorkflowJob($this->createWorkflowTemplate($user->id)->id, $user->id, WorkflowJob::STATUS_SUCCEEDED);
        $this->service()->real = true;

        $result = $this->makeController()->actionCancel((int)$run->id);

        $this->assertInstanceOf(Response::class, $result);
        $flashes = \Yii::$app->session->getAllFlashes();
        $this->assertSame('Workflow is already finished.', $flashes['danger'] ?? null);
        $this->assertArrayNotHasKey('success', $flashes);
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_SUCCEEDED, $run->status);
    }

    /**
     * The service checks again, at the moment it writes: its refusal is a
     * 403 as well, not a flash.
     */
    public function testCancelAndResumeRefusedByTheServiceAnswer403(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $run = $this->createWorkflowJob($this->createWorkflowTemplate($user->id)->id, $user->id);
        $this->service()->throw = new WorkflowAccessDeniedException('You may not operate job template(s) #7 of this workflow.', [7]);

        $this->assertForbidden(fn () => $this->makeController()->actionCancel((int)$run->id), '#7');
        $this->assertForbidden(fn () => $this->makeController()->actionResume((int)$run->id), '#7');
        $this->assertSame([], \Yii::$app->session->getAllFlashes());
    }

    public function testResumeOfRunOfViewedOnlyWorkflowIsForbidden(): void
    {
        $s = $this->teamScope();
        [$run, $pause] = $this->pausedRun($s['admin']->id, $s['viewed']->id, $s['admin']->id);
        $this->loginAs($s['member']);

        $this->assertForbidden(fn () => $this->makeController()->actionResume((int)$run->id));

        $pause->refresh();
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $pause->status, 'still paused');
        $this->assertSame(0, $this->service()->resumeCalls, 'refused before the service is asked');
    }

    /**
     * Regression guard: the controller refuses before the run's state is
     * looked at, so a member who may not see the run learns neither that it
     * is finished nor that it waits at no pause step.
     */
    public function testResumeAndCancelOfAFinishedRunOfAForeignWorkflowAre403WithoutAMessage(): void
    {
        $s = $this->teamScope();
        $run = $this->createWorkflowJob(
            $this->createWorkflowWithJobSteps($s['admin']->id, $s['foreign']->id)->id,
            $s['outsider']->id,
            WorkflowJob::STATUS_SUCCEEDED
        );
        $this->loginAs($s['member']);
        $svc = $this->service();
        $svc->real = true;

        $this->assertForbidden(fn () => $this->makeController()->actionResume((int)$run->id));
        $this->assertForbidden(fn () => $this->makeController()->actionCancel((int)$run->id));

        $this->assertSame([], \Yii::$app->session->getAllFlashes(), 'no "Workflow is already finished."');
        $this->assertSame(0, $svc->resumeCalls);
        $this->assertSame(0, $svc->cancelCalls);
    }

    public function testResumeOfOwnPausedRunAdvancesIt(): void
    {
        $s = $this->teamScope();
        [$run, $pause] = $this->pausedRun($s['admin']->id, $s['own']->id, $s['member']->id);
        $this->loginAs($s['member']);

        $this->makeController()->actionResume((int)$run->id);

        $pause->refresh();
        $this->assertSame(WorkflowJobStep::STATUS_SUCCEEDED, $pause->status);
        $this->assertSame('Paused step resumed.', \Yii::$app->session->getAllFlashes()['success'] ?? null);
    }

    public function testResumeWithoutAPausedStepShowsAMessage(): void
    {
        $s = $this->teamScope();
        $run = $this->createWorkflowJob($this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id)->id, $s['member']->id);
        $this->loginAs($s['member']);

        $this->makeController()->actionResume((int)$run->id);

        $this->assertSame('No paused step to resume.', \Yii::$app->session->getAllFlashes()['danger'] ?? null);
    }

    // ── Rendered page ────────────────────────────────────────────────────────

    public function testRunPageShowsStepErrorsEncodedAndNoButtonsToViewers(): void
    {
        $s = $this->teamScope();
        [$run] = $this->pausedRun($s['admin']->id, $s['viewed']->id, $s['admin']->id);
        $failedStep = $this->createWorkflowStep((int)$run->workflow_template_id, 2, WorkflowStep::TYPE_JOB, $s['viewed']->id);
        $failed = $this->seedJobStep($run->id, $failedStep->id, time() - 5, time(), WorkflowJobStep::STATUS_FAILED);
        $failed->error_message = 'Not launched: <script>alert(1)</script> may not launch it.';
        $failed->save(false);
        $this->loginAs($s['member']);
        $ctrl = $this->makeController();
        $ctrl->actionView((int)$run->id);

        $html = $this->renderPage($ctrl->capturedParams);

        $this->assertStringContainsString(
            '<div class="small text-danger mt-1" data-wjs-error>Not launched: &lt;script&gt;alert(1)&lt;/script&gt; may not launch it.</div>',
            $html
        );
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString(Url::to(['/workflow-job/cancel', 'id' => $run->id]), $html);
        $this->assertStringNotContainsString(Url::to(['/workflow-job/resume', 'id' => $run->id]), $html);
        $this->assertSame(1, substr_count($html, 'data-wjs-error>'), 'only the failed step has an error');
    }

    public function testRunPageOffersCancelAndResumeToOperators(): void
    {
        $s = $this->teamScope();
        [$run] = $this->pausedRun($s['admin']->id, $s['own']->id, $s['member']->id);
        $this->loginAs($s['member']);
        $ctrl = $this->makeController();
        $ctrl->actionView((int)$run->id);

        $html = $this->renderPage($ctrl->capturedParams);

        $this->assertStringContainsString(Url::to(['/workflow-job/cancel', 'id' => $run->id]), $html);
        $this->assertStringContainsString(Url::to(['/workflow-job/resume', 'id' => $run->id]), $html);
    }

    // ── Team scoping helpers ─────────────────────────────────────────────────

    /**
     * The real run page, rendered with what actionView passed to it, with
     * dummy asset bundles; view, asset manager and controller are restored.
     *
     * @param array<string, mixed> $params
     */
    private function renderPage(array $params): string
    {
        $components = \Yii::$app->getComponents(true);
        $originals = ['view' => $components['view'] ?? null, 'assetManager' => $components['assetManager'] ?? null];
        $previousController = \Yii::$app->controller;
        \Yii::$app->set('assetManager', new AssetManager(['bundles' => false, 'basePath' => sys_get_temp_dir(), 'baseUrl' => '/assets']));
        \Yii::$app->set('view', new View());
        $ctrl = new WorkflowJobController('workflow-job', \Yii::$app);
        \Yii::$app->controller = $ctrl;
        try {
            return $ctrl->renderPartial('view', $params);
        } finally {
            \Yii::$app->controller = $previousController;
            foreach ($originals as $id => $definition) {
                \Yii::$app->set($id, $definition);
            }
        }
    }

    /**
     * @return object{cancelCalls: int, resumeCalls: int, real: bool, throw: \RuntimeException|null}
     */
    private function service(): object
    {
        /** @var object{cancelCalls: int, resumeCalls: int, real: bool, throw: \RuntimeException|null} $svc */
        $svc = \Yii::$app->get('workflowExecutionService');
        return $svc;
    }

    /**
     * A run of a pause step followed by a job step, waiting at the pause.
     *
     * @return array{0: WorkflowJob, 1: WorkflowJobStep}
     */
    private function pausedRun(int $createdBy, int $jobTemplateId, int $launchedBy): array
    {
        $wf = $this->createWorkflowTemplate($createdBy);
        $pauseStep = $this->createWorkflowStep($wf->id, 0, WorkflowStep::TYPE_PAUSE);
        $this->createWorkflowStep($wf->id, 1, WorkflowStep::TYPE_JOB, $jobTemplateId);
        $run = $this->createWorkflowJob($wf->id, $launchedBy);
        $pause = $this->seedJobStep($run->id, $pauseStep->id, time(), null, WorkflowJobStep::STATUS_RUNNING);

        return [$run, $pause];
    }

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
     * IDs of $among on the index page, ascending.
     *
     * @param array<array-key, WorkflowJob> $among
     * @return list<int>
     */
    private function listedRunIds(WorkflowJobController $ctrl, array $among): array
    {
        /** @var ActiveDataProvider $dataProvider */
        $dataProvider = $ctrl->capturedParams['dataProvider'];
        $wanted = array_map(static fn (WorkflowJob $run): int => $run->id, array_values($among));
        $listed = array_map(static fn (WorkflowJob $run): int => (int)$run->id, $dataProvider->getModels());
        $found = array_values(array_intersect($listed, $wanted));
        sort($found);

        return $found;
    }
}
