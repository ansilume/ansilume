<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\SurveySecrets;
use app\components\WorkflowRunOperationFailure;
use app\controllers\api\v1\WorkflowJobsController;
use app\models\ApiToken;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\WorkflowAccessDeniedException;
use app\services\WorkflowExecutionService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\controllers\WorkflowRunDbErrorFixtures;
use app\tests\integration\services\FailingWorkflowCancellation;
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
    use WorkflowRunDbErrorFixtures;

    private WorkflowJobsController $ctrl;
    private ?object $originalService = null;

    /** @var array<string, object|null> components swapped by swap(), restored in tearDown() */
    private array $swapped = [];

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
        foreach ($this->swapped as $id => $original) {
            \Yii::$app->set($id, $original);
        }
        $this->swapped = [];
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

    /**
     * Regression: a step's name and type came from the live step only, and
     * workflow_step_id was a required link: once a step was removed, its
     * executions said nothing about what ran.
     */
    public function testViewNamesEachStepAsItRanAlsoOnceItWasRemoved(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $pause = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $job = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, $s['own']->id);
        $run = $this->createWorkflowJob((int)$workflow->id, $s['admin']->id, WorkflowJob::STATUS_SUCCEEDED);
        foreach ([$pause, $job] as $step) {
            $execution = new WorkflowJobStep();
            $execution->workflow_job_id = (int)$run->id;
            $execution->workflow_step_id = (int)$step->id;
            $execution->status = WorkflowJobStep::STATUS_SUCCEEDED;
            $execution->save(false);
        }
        $this->assertSame(1, $pause->delete());
        $this->authenticate($s['member']);

        /** @var list<array<string, mixed>> $steps */
        $steps = $this->data($this->ctrl->actionView($run->id))['steps'];

        $this->assertSame([null, (int)$job->id], array_column($steps, 'workflow_step_id'));
        $this->assertSame(['step-0', 'step-1'], array_column($steps, 'step_name'));
        $this->assertSame([WorkflowStep::TYPE_PAUSE, WorkflowStep::TYPE_JOB], array_column($steps, 'step_type'));
    }

    /**
     * The extra vars the run was launched with, which every job step
     * received: a password answer as $encrypted$, the sealed answers never.
     */
    public function testViewShowsTheLaunchExtraVarsButNeverTheSealedAnswers(): void
    {
        $s = $this->teamScope();
        $run = $this->runOf($s['admin']->id, $s['own']->id);
        $run->setLaunchExtraVars(['env' => 'prod', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER]);
        $run->setSealedSecretExtraVars(SurveySecrets::seal([self::SURVEY_PASSWORD_FIELD => self::SURVEY_PASSWORD_ANSWER]));
        $run->save(false);
        $this->authenticate($s['member']);

        $result = $this->ctrl->actionView($run->id);

        $this->assertEquals(
            (object)['env' => 'prod', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER],
            $this->data($result)['extra_vars']
        );
        $json = (string)json_encode($result);
        $this->assertStringNotContainsString(self::SURVEY_PASSWORD_ANSWER, $json);
        $this->assertStringNotContainsString('secret_extra_vars', $json);
    }

    /**
     * Vars whose keys are 0, 1, ... stay a JSON object; a run without vars
     * has none (null).
     */
    public function testViewReturnsTheExtraVarsAsAnObjectOrNull(): void
    {
        $s = $this->teamScope();
        $plain = $this->runOf($s['admin']->id, $s['own']->id);
        $numbered = $this->runOf($s['admin']->id, $s['own']->id);
        $numbered->setLaunchExtraVars(['first', 'second']);
        $numbered->save(false);
        $this->authenticate($s['member']);

        $this->assertNull($this->data($this->ctrl->actionView($plain->id))['extra_vars']);
        $this->assertSame(
            '{"0":"first","1":"second"}',
            json_encode($this->data($this->ctrl->actionView($numbered->id))['extra_vars'])
        );
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

    // -- Database errors (regression) ----------------------------------------

    /**
     * regression: a database error while a run was canceled answered 500
     * with the framework's error. It is a 500 with a message that says what
     * to do, and the log line names the error's class, SQLSTATE and driver
     * code, never its message.
     */
    public function testRegressionADatabaseErrorWhileCancelingIs500WithAMessage(): void
    {
        $s = $this->teamScope();
        $run = $this->runOf($s['admin']->id, $s['own']->id);
        $this->authenticate($s['member']);
        $this->swap('workflowCancellationService', new FailingWorkflowCancellation((int)$run->id));

        $result = [];
        $logged = $this->failuresLoggedBy(function () use ($run, &$result): void {
            $result = $this->ctrl->actionCancel($run->id);
        }, FailingWorkflowCancellation::SECRET_VALUE);

        $this->assertSame(500, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => sprintf(WorkflowRunOperationFailure::CANCEL_FAILED, $run->id)]], $result);
        $this->assertSame(
            [sprintf(WorkflowRunOperationFailure::LOG, $run->id, WorkflowRunOperationFailure::CANCEL, \yii\db\Exception::class, '40001', '1213')],
            $logged
        );
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status);
    }

    /**
     * regression: a database error while a run was resumed answered 500 with
     * the framework's error.
     */
    public function testRegressionADatabaseErrorWhileResumingIs500WithAMessage(): void
    {
        $s = $this->teamScope();
        [$run, $pause] = $this->pausedRun($s['admin']->id, $s['own']->id, $s['member']->id);
        $this->authenticate($s['member']);
        $this->swap('workflowStepRouter', $this->failingRouter());

        $result = [];
        $logged = $this->failuresLoggedBy(function () use ($run, &$result): void {
            $result = $this->ctrl->actionResume($run->id);
        });

        $this->assertSame(500, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => sprintf(WorkflowRunOperationFailure::RESUME_FAILED, $run->id)]], $result);
        $this->assertSame(
            [sprintf(WorkflowRunOperationFailure::LOG, $run->id, WorkflowRunOperationFailure::RESUME, \yii\db\Exception::class, 'HY000', '2006')],
            $logged
        );
        $pause->refresh();
        $this->assertSame(WorkflowJobStep::STATUS_SUCCEEDED, $pause->status);
    }

    /**
     * regression: a PDOException (a RuntimeException, which a commit can
     * throw) was answered 422 with its own message. It is a database error
     * too: 500 with the message.
     */
    public function testRegressionAPdoExceptionIsADatabaseErrorToo(): void
    {
        $s = $this->teamScope();
        $run = $this->runOf($s['admin']->id, $s['own']->id);
        $this->authenticate($s['member']);
        $error = new \PDOException('SQLSTATE[HY000]: General error: ' . self::DB_ERROR_SECRET);
        $this->swap('workflowExecutionService', new class ($error) extends WorkflowExecutionService {
            public function __construct(private readonly \PDOException $error)
            {
                parent::__construct();
            }

            public function cancel(WorkflowJob $wfJob, int $userId): void
            {
                throw $this->error;
            }

            public function resume(WorkflowJob $wfJob, int $userId): void
            {
                throw $this->error;
            }
        });

        $logged = $this->failuresLoggedBy(function () use ($run): void {
            $this->assertSame(['error' => ['message' => sprintf(WorkflowRunOperationFailure::CANCEL_FAILED, $run->id)]], $this->ctrl->actionCancel($run->id));
            $this->assertSame(500, \Yii::$app->response->statusCode);
            $this->assertSame(['error' => ['message' => sprintf(WorkflowRunOperationFailure::RESUME_FAILED, $run->id)]], $this->ctrl->actionResume($run->id));
            $this->assertSame(500, \Yii::$app->response->statusCode);
        });

        $this->assertSame([
            sprintf(WorkflowRunOperationFailure::LOG, $run->id, WorkflowRunOperationFailure::CANCEL, \PDOException::class, '-', '-'),
            sprintf(WorkflowRunOperationFailure::LOG, $run->id, WorkflowRunOperationFailure::RESUME, \PDOException::class, '-', '-'),
        ], $logged);
    }

    // -- Helpers --------------------------------------------------------------

    private function swap(string $id, object $replacement): void
    {
        if (!array_key_exists($id, $this->swapped)) {
            $this->swapped[$id] = \Yii::$app->getComponents(true)[$id] ?? null;
        }
        \Yii::$app->set($id, $replacement);
    }

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
