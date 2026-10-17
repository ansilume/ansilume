<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\JobController;
use app\models\ApprovalRequest;
use app\models\Job;
use app\models\User;
use app\services\JobCancellationService;
use app\services\WorkflowAccessChecker;
use app\tests\integration\RestoresComponents;
use app\tests\integration\services\CancellationFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\web\Response;

/**
 * The web cancel action: the cancel closes the job's pending approval
 * request, and the flash says what happened.
 *
 * Regression: the action flashed "canceled" whatever the cancel did. A job
 * that finished after the action loaded it (its runner reported) was
 * overwritten as canceled; now it keeps its outcome and the flash says so.
 */
class JobControllerCancelTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;
    use RestoresComponents;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        // Tests replace it; tearDown() puts back only what setUp() kept.
        $this->keepComponent('workflowAccessChecker');
        $this->recordNotifications();
        $this->operator = $this->createUserWithRole('cancel-web', 'operator');
        $this->loginAs($this->operator);
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        $this->restoreNotifications();
        parent::tearDown();
    }

    /**
     * Regression: canceling a job left its approval request pending and
     * decidable.
     */
    public function testCancelingAJobThatWaitsForApprovalClosesItsRequest(): void
    {
        $approver = $this->createUserWithRole('cancel-web-approver', 'operator');
        ['job' => $job, 'request' => $request] = $this->jobWaitingForApproval(
            $this->cancelFixtureTemplate((int)$this->operator->id),
            (int)$this->operator->id,
            (int)$approver->id
        );

        $this->assertInstanceOf(Response::class, $this->controller()->actionCancel((int)$job->id));

        $this->assertSame(['success' => "Job #{$job->id} canceled."], \Yii::$app->session->getAllFlashes());
        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $request->status);
    }

    /**
     * Regression: see the class description.
     */
    public function testAJobThatFinishedAfterTheActionLoadedItIsNotCanceled(): void
    {
        $job = $this->createJob((int)$this->cancelFixtureTemplate((int)$this->operator->id)->id, (int)$this->operator->id, Job::STATUS_RUNNING);
        $this->finishWhenAccessIsChecked($job);

        $this->controller()->actionCancel((int)$job->id);

        $this->assertSame(
            ['warning' => sprintf(JobCancellationService::NOT_CANCELED, $job->id, 'succeeded')],
            \Yii::$app->session->getAllFlashes()
        );
        $job->refresh();
        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status);
        $this->assertSame(0, (int)$job->exit_code);
    }

    public function testAFinishedJobIsRefusedBeforeTheCancel(): void
    {
        $job = $this->createJob((int)$this->cancelFixtureTemplate((int)$this->operator->id)->id, (int)$this->operator->id, Job::STATUS_FAILED);

        $this->controller()->actionCancel((int)$job->id);

        $this->assertSame(
            ['warning' => "Job #{$job->id} cannot be canceled in status \"failed\"."],
            \Yii::$app->session->getAllFlashes()
        );
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * The job's runner reports success right after the action loaded the
     * job and while it checks access, before it cancels.
     */
    private function finishWhenAccessIsChecked(Job $job): void
    {
        $finish = static function () use ($job): void {
            Job::updateAll(['status' => Job::STATUS_SUCCEEDED, 'exit_code' => 0, 'finished_at' => time()], ['id' => $job->id]);
        };
        \Yii::$app->set('workflowAccessChecker', new class ($finish) extends WorkflowAccessChecker {
            public function __construct(private ?\Closure $finish)
            {
                parent::__construct();
            }

            public function canAccessJob(int $userId, Job $job, bool $operate): bool
            {
                $finish = $this->finish;
                $this->finish = null;
                if ($finish !== null) {
                    $finish();
                }

                return parent::canAccessJob($userId, $job, $operate);
            }
        });
    }

    private function controller(): JobController
    {
        return new class ('job', \Yii::$app) extends JobController {
            public function render($view, $params = []): string
            {
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): Response
            {
                $r = new Response();
                $r->content = 'redirected';
                return $r;
            }
        };
    }
}
