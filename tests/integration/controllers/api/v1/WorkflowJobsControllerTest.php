<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\WorkflowJobsController;
use app\models\ApiToken;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\WorkflowAccessDeniedException;
use app\services\WorkflowExecutionService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\web\NotFoundHttpException;

/**
 * API v1 workflow jobs (runs).
 *
 * Regression: the API ignored team scoping for workflow runs. Any token with
 * workflow.view listed and read every team's runs, and could cancel or
 * resume them. A run follows its workflow template, which belongs to the
 * projects of its job steps.
 */
class WorkflowJobsControllerTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    private WorkflowJobsController $ctrl;
    private ?object $originalService = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new WorkflowJobsController('api/v1/workflow-jobs', \Yii::$app);
    }

    protected function tearDown(): void
    {
        if ($this->originalService !== null) {
            \Yii::$app->set('workflowExecutionService', $this->originalService);
            $this->originalService = null;
        }
        parent::tearDown();
    }

    // -- Index ----------------------------------------------------------------

    public function testIndexListsOnlyRunsOfWorkflowsTheCallerMayView(): void
    {
        $s = $this->teamScope();
        $visible = [];
        foreach (['own', 'viewed', 'open'] as $key) {
            $visible[] = $this->runOf($s['admin']->id, $s[$key]->id);
        }
        $hidden = [
            $this->runOf($s['admin']->id, $s['foreign']->id),
            $this->runOf($s['admin']->id, $s['own']->id, $s['foreign']->id),
        ];
        $this->authenticate($s['member']);

        $result = $this->ctrl->actionIndex();

        $ids = array_column($result['data'], 'id');
        foreach ($visible as $run) {
            $this->assertContains($run->id, $ids);
        }
        foreach ($hidden as $run) {
            $this->assertNotContains($run->id, $ids);
        }
        $this->assertSame(count($ids), $result['meta']['total'], 'the total counts visible runs only');
    }

    public function testIndexShowsAdminsEveryRun(): void
    {
        $s = $this->teamScope();
        $foreign = $this->runOf($s['admin']->id, $s['foreign']->id);
        $this->authenticate($s['admin']);

        $result = $this->ctrl->actionIndex();

        $this->assertContains($foreign->id, array_column($result['data'], 'id'));
    }

    // -- View -----------------------------------------------------------------

    public function testViewOfRunOfWorkflowWithAForeignStepIsForbidden(): void
    {
        $s = $this->teamScope();
        $run = $this->runOf($s['admin']->id, $s['own']->id, $s['foreign']->id);
        $this->authenticate($s['member']);

        $this->assertForbiddenResponse($this->ctrl->actionView($run->id));
    }

    public function testViewThroughTheRequestPipelineAnswers403(): void
    {
        $s = $this->teamScope();
        $run = $this->runOf($s['admin']->id, $s['foreign']->id);
        $this->authenticate($s['member']);

        $this->assertForbiddenResponse($this->ctrl->runAction('view', ['id' => $run->id]));
    }

    public function testViewTellsWhyAStepFailedWithoutAJob(): void
    {
        $s = $this->teamScope();
        $run = $this->runOf($s['admin']->id, $s['viewed']->id);
        $step = WorkflowStep::findOne(['workflow_template_id' => $run->workflow_template_id]);
        $this->assertNotNull($step);
        $failed = new WorkflowJobStep();
        $failed->workflow_job_id = $run->id;
        $failed->workflow_step_id = $step->id;
        $failed->status = WorkflowJobStep::STATUS_FAILED;
        $failed->error_message = 'Not launched: user #1, whom this workflow runs as, may not launch job template "x" (#2).';
        $failed->save(false);
        $this->authenticate($s['member']);

        $data = $this->data($this->ctrl->actionView($run->id));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame([$failed->error_message], array_column($data['steps'], 'error'));
    }

    public function testViewRefusesGuestsAndLetsAdminsSeeEveryRun(): void
    {
        $s = $this->teamScope();
        $run = $this->runOf($s['admin']->id, $s['foreign']->id);

        $this->assertForbiddenResponse($this->ctrl->actionView($run->id));

        $this->authenticate($s['admin']);
        $this->assertSame($run->id, $this->data($this->ctrl->actionView($run->id))['id']);
    }

    public function testViewOfUnknownRunIs404(): void
    {
        $s = $this->teamScope();
        $this->authenticate($s['member']);

        $this->expectException(NotFoundHttpException::class);
        $this->ctrl->actionView(987654321);
    }

    // -- Cancel ---------------------------------------------------------------

    public function testCancelOfRunOfViewedOnlyOrForeignWorkflowIsForbidden(): void
    {
        $s = $this->teamScope();
        $viewed = $this->runOf($s['admin']->id, $s['viewed']->id);
        $foreign = $this->runOf($s['admin']->id, $s['foreign']->id);
        $this->authenticate($s['member']);

        foreach ([$viewed, $foreign] as $run) {
            $this->assertForbiddenResponse($this->ctrl->actionCancel($run->id));
            $run->refresh();
            $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status);
        }
    }

    public function testCancelOfOwnRunCancelsIt(): void
    {
        $s = $this->teamScope();
        $run = $this->runOf($s['admin']->id, $s['own']->id);
        $this->authenticate($s['member']);

        $data = $this->data($this->ctrl->actionCancel($run->id));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $data['status']);
    }

    public function testCancelOfFinishedRunIs422(): void
    {
        $s = $this->teamScope();
        $run = $this->runOf($s['admin']->id, $s['own']->id);
        $run->status = WorkflowJob::STATUS_SUCCEEDED;
        $run->save(false);
        $this->authenticate($s['member']);

        $result = $this->ctrl->actionCancel($run->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame('Workflow is already finished.', $result['error']['message']);
    }

    public function testAdminMayCancelRunOfAnotherTeam(): void
    {
        $s = $this->teamScope();
        $run = $this->runOf($s['admin']->id, $s['foreign']->id);
        $this->authenticate($s['admin']);

        $this->assertSame(WorkflowJob::STATUS_CANCELED, $this->data($this->ctrl->actionCancel($run->id))['status']);
    }

    // -- Resume ---------------------------------------------------------------

    public function testResumeOfRunOfViewedOnlyWorkflowIsForbidden(): void
    {
        $s = $this->teamScope();
        [$run, $pause] = $this->pausedRun($s['admin']->id, $s['viewed']->id, $s['admin']->id);
        $this->authenticate($s['member']);

        $this->assertForbiddenResponse($this->ctrl->actionResume($run->id));

        $pause->refresh();
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $pause->status, 'still paused');
    }

    public function testResumeOfOwnPausedRunAdvancesIt(): void
    {
        $s = $this->teamScope();
        [$run, $pause] = $this->pausedRun($s['admin']->id, $s['own']->id, $s['member']->id);
        $this->authenticate($s['member']);

        $data = $this->data($this->ctrl->actionResume($run->id));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame($run->id, $data['id']);
        $pause->refresh();
        $this->assertSame(WorkflowJobStep::STATUS_SUCCEEDED, $pause->status);
    }

    public function testResumeWithoutAPausedStepIs422(): void
    {
        $s = $this->teamScope();
        $run = $this->runOf($s['admin']->id, $s['own']->id);
        $this->authenticate($s['member']);

        $result = $this->ctrl->actionResume($run->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame('No paused step to resume.', $result['error']['message']);
    }

    /**
     * The service checks again when it writes; its refusal is a 403 that
     * names the job templates, not a 422.
     */
    public function testARefusalByTheServiceAnswers403WithTheTemplates(): void
    {
        $s = $this->teamScope();
        $run = $this->runOf($s['admin']->id, $s['own']->id);
        $this->authenticate($s['member']);
        $this->originalService = \Yii::$app->get('workflowExecutionService');
        \Yii::$app->set('workflowExecutionService', new class extends WorkflowExecutionService {
            public function cancel(WorkflowJob $wfJob, int $userId): void
            {
                throw new WorkflowAccessDeniedException('You may not operate job template(s) #7 of this workflow.', [7]);
            }
            public function resume(WorkflowJob $wfJob, int $userId): void
            {
                throw new WorkflowAccessDeniedException('You may not operate job template(s) #7 of this workflow.', [7]);
            }
        });
        $expected = ['error' => ['message' => 'You may not operate job template(s) #7 of this workflow.', 'job_template_ids' => [7]]];

        $this->assertSame($expected, $this->ctrl->actionCancel($run->id));
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame($expected, $this->ctrl->actionResume($run->id));
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    /**
     * Regression: a refusal that names no job templates (the caller may not
     * see the workflow) answered with an empty job_template_ids list; the
     * body now leaves it out, as launch does.
     */
    public function testARefusalByTheServiceWithoutTemplatesHasNoTemplateList(): void
    {
        $s = $this->teamScope();
        $run = $this->runOf($s['admin']->id, $s['own']->id);
        $this->authenticate($s['member']);
        $this->originalService = \Yii::$app->get('workflowExecutionService');
        \Yii::$app->set('workflowExecutionService', new class extends WorkflowExecutionService {
            public function cancel(WorkflowJob $wfJob, int $userId): void
            {
                throw new WorkflowAccessDeniedException('You may not operate this workflow.');
            }
            public function resume(WorkflowJob $wfJob, int $userId): void
            {
                throw new WorkflowAccessDeniedException('You may not operate this workflow.');
            }
        });
        $expected = ['error' => ['message' => 'You may not operate this workflow.']];

        $this->assertSame($expected, $this->ctrl->actionCancel($run->id));
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame($expected, $this->ctrl->actionResume($run->id));
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    /**
     * Regression guard: access is checked before the run's state, so a
     * caller who may not see the run gets the plain 403, not 422 "Workflow
     * is already finished.".
     */
    public function testResumeAndCancelOfAFinishedRunOfAForeignWorkflowAre403(): void
    {
        $s = $this->teamScope();
        $run = $this->runOf($s['admin']->id, $s['foreign']->id);
        $run->status = WorkflowJob::STATUS_SUCCEEDED;
        $run->save(false);
        $this->authenticate($s['member']);

        $this->assertForbiddenResponse($this->ctrl->actionResume($run->id));
        $this->assertForbiddenResponse($this->ctrl->actionCancel($run->id));
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * A running run of a new workflow with one job step per template.
     */
    private function runOf(int $createdBy, int ...$jobTemplateIds): WorkflowJob
    {
        $workflow = $this->createWorkflowWithJobSteps($createdBy, ...$jobTemplateIds);
        return $this->createWorkflowJob($workflow->id, $createdBy);
    }

    /**
     * A run of a pause step followed by a job step, waiting at the pause.
     *
     * @return array{0: WorkflowJob, 1: WorkflowJobStep}
     */
    private function pausedRun(int $createdBy, int $jobTemplateId, int $launchedBy): array
    {
        $workflow = $this->createWorkflowTemplate($createdBy);
        $pauseStep = $this->createWorkflowStep($workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $this->createWorkflowStep($workflow->id, 1, WorkflowStep::TYPE_JOB, $jobTemplateId);
        $run = $this->createWorkflowJob($workflow->id, $launchedBy);
        $pause = new WorkflowJobStep();
        $pause->workflow_job_id = $run->id;
        $pause->workflow_step_id = $pauseStep->id;
        $pause->status = WorkflowJobStep::STATUS_RUNNING;
        $pause->started_at = time();
        $pause->save(false);

        return [$run, $pause];
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'workflow-jobs-api-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function data(array $result): array
    {
        $this->assertArrayHasKey('data', $result, (string)json_encode($result));
        /** @var array<string, mixed> $data */
        $data = $result['data'];
        return $data;
    }

    private function assertForbiddenResponse(mixed $result): void
    {
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $result);
    }
}
