<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\WorkflowRunOperationFailure;
use app\controllers\WorkflowJobController;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\WorkflowExecutionService;
use app\tests\integration\services\FailingWorkflowCancellation;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Module;
use yii\web\Response;

/**
 * A database error while a workflow run is canceled or resumed in the web
 * UI.
 *
 * Regression: the error escaped both actions, which caught RuntimeException
 * only, and the user got an error page (500). A PDOException, which is a
 * RuntimeException, was shown with its own message. Both are a danger flash
 * now that says what to do (WorkflowRunOperationFailure), and the log line
 * names the error's class, SQLSTATE and driver code, never its message.
 */
class WorkflowJobControllerDbErrorTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use WorkflowRunDbErrorFixtures;

    /** @var array<string, object|null> components swapped by swap(), restored in tearDown() */
    private array $swapped = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Flashes live in $_SESSION, which outlasts a test: start without any.
        \Yii::$app->session->removeAllFlashes();
    }

    protected function tearDown(): void
    {
        \Yii::$app->session->removeAllFlashes();
        foreach ($this->swapped as $id => $original) {
            \Yii::$app->set($id, $original);
        }
        $this->swapped = [];
        parent::tearDown();
    }

    /**
     * regression: a database error while a run was canceled answered with
     * an error page. Here the cancel fails at its claim: the run still runs.
     */
    public function testRegressionADatabaseErrorWhileCancelingIsAMessageNotAnErrorPage(): void
    {
        $s = $this->teamScope();
        $run = $this->createWorkflowJob($this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id)->id, $s['member']->id);
        $this->loginAs($s['member']);
        $this->swap('workflowCancellationService', new FailingWorkflowCancellation((int)$run->id));

        $logged = $this->failuresLoggedBy(function () use ($run): void {
            $this->assertInstanceOf(Response::class, $this->makeController()->actionCancel((int)$run->id));
        }, FailingWorkflowCancellation::SECRET_VALUE);

        $this->assertSame(['danger' => sprintf(WorkflowRunOperationFailure::CANCEL_FAILED, $run->id)], \Yii::$app->session->getAllFlashes());
        $this->assertSame(
            [sprintf(WorkflowRunOperationFailure::LOG, $run->id, WorkflowRunOperationFailure::CANCEL, \yii\db\Exception::class, '40001', '1213')],
            $logged
        );
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status, 'a run that still runs can be canceled again');
    }

    /**
     * regression: a database error while a run was resumed answered with an
     * error page. Here the resume fails after it moved the pause step on,
     * while the run goes on to its next step.
     */
    public function testRegressionADatabaseErrorWhileResumingIsAMessageNotAnErrorPage(): void
    {
        $s = $this->teamScope();
        [$run, $pause] = $this->pausedRun($s['admin']->id, $s['own']->id, $s['member']->id);
        $this->loginAs($s['member']);
        $this->swap('workflowStepRouter', $this->failingRouter());

        $logged = $this->failuresLoggedBy(function () use ($run): void {
            $this->assertInstanceOf(Response::class, $this->makeController()->actionResume((int)$run->id));
        });

        $this->assertSame(['danger' => sprintf(WorkflowRunOperationFailure::RESUME_FAILED, $run->id)], \Yii::$app->session->getAllFlashes());
        $this->assertSame(
            [sprintf(WorkflowRunOperationFailure::LOG, $run->id, WorkflowRunOperationFailure::RESUME, \yii\db\Exception::class, 'HY000', '2006')],
            $logged
        );
        $pause->refresh();
        $this->assertSame(WorkflowJobStep::STATUS_SUCCEEDED, $pause->status);
    }

    /**
     * regression: a PDOException (a RuntimeException, which a commit can
     * throw) was shown with its own message. It is a database error too.
     */
    public function testRegressionAPdoExceptionIsADatabaseErrorToo(): void
    {
        $s = $this->teamScope();
        [$run] = $this->pausedRun($s['admin']->id, $s['own']->id, $s['member']->id);
        $this->loginAs($s['member']);
        $this->swap('workflowExecutionService', $this->failingEngine(new \PDOException('SQLSTATE[HY000]: General error: ' . self::DB_ERROR_SECRET)));

        $logged = $this->failuresLoggedBy(function () use ($run): void {
            $this->makeController()->actionCancel((int)$run->id);
            $this->assertSame(['danger' => sprintf(WorkflowRunOperationFailure::CANCEL_FAILED, $run->id)], \Yii::$app->session->getAllFlashes(true));
            $this->makeController()->actionResume((int)$run->id);
            $this->assertSame(['danger' => sprintf(WorkflowRunOperationFailure::RESUME_FAILED, $run->id)], \Yii::$app->session->getAllFlashes(true));
        });

        $this->assertSame([
            sprintf(WorkflowRunOperationFailure::LOG, $run->id, WorkflowRunOperationFailure::CANCEL, \PDOException::class, '-', '-'),
            sprintf(WorkflowRunOperationFailure::LOG, $run->id, WorkflowRunOperationFailure::RESUME, \PDOException::class, '-', '-'),
        ], $logged);
    }

    /**
     * Any other error is no database error: it is not caught there, and the
     * error handler shows and logs it as before.
     */
    public function testAnErrorThatIsNoDatabaseErrorIsNotCaught(): void
    {
        $s = $this->teamScope();
        [$run] = $this->pausedRun($s['admin']->id, $s['own']->id, $s['member']->id);
        $this->loginAs($s['member']);
        $this->swap('workflowExecutionService', $this->failingEngine(new \LogicException('a bug')));

        foreach (['cancel' => fn () => $this->makeController()->actionCancel((int)$run->id), 'resume' => fn () => $this->makeController()->actionResume((int)$run->id)] as $action => $call) {
            try {
                $call();
                $this->fail("{$action}: expected the LogicException.");
            } catch (\LogicException $e) {
                $this->assertSame('a bug', $e->getMessage(), $action);
            }
        }
        $this->assertSame([], \Yii::$app->session->getAllFlashes());
    }

    // -- Helpers --------------------------------------------------------------

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

    /**
     * An engine whose cancel() and resume() throw $error.
     */
    private function failingEngine(\Throwable $error): WorkflowExecutionService
    {
        return new class ($error) extends WorkflowExecutionService {
            public function __construct(private readonly \Throwable $error)
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
        };
    }

    private function swap(string $id, object $replacement): void
    {
        if (!array_key_exists($id, $this->swapped)) {
            $this->swapped[$id] = \Yii::$app->getComponents(true)[$id] ?? null;
        }
        \Yii::$app->set($id, $replacement);
    }

    private function makeController(): WorkflowJobController
    {
        return new class ('workflow-job', $this->app()) extends WorkflowJobController {
            /**
             * @param array<int|string, mixed>|string $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                return new Response();
            }
        };
    }

    private function app(): Module
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);

        return $app;
    }
}
