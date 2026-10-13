<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\JobsController;
use app\models\ApiToken;
use app\models\ApprovalRequest;
use app\models\AuditLog;
use app\models\Job;
use app\models\User;
use app\services\JobCancellationService;
use app\services\WorkflowAccessChecker;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\CancellationFixtures;
use app\tests\integration\TeamScopeFixtures;

/**
 * POST /api/v1/jobs/{id}/cancel: the cancel closes the job's pending
 * approval request; a job that finished after the request loaded it is
 * not canceled (409, with the status it has now).
 *
 * Regression: the endpoint answered 200 whatever the cancel did, and a job
 * that finished after it was loaded was overwritten as canceled.
 */
class JobsControllerCancelTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;

    private User $operator;
    private mixed $originalChecker = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordNotifications();
        $this->originalChecker = \Yii::$app->getComponents(true)['workflowAccessChecker'] ?? null;
        $this->operator = $this->createUserWithRole('cancel-api', 'operator');
        ['raw' => $raw] = ApiToken::generate((int)$this->operator->id, 'cancel-api');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        $user->loginByAccessToken($raw);
    }

    protected function tearDown(): void
    {
        \Yii::$app->set('workflowAccessChecker', $this->originalChecker);
        $this->restoreNotifications();
        parent::tearDown();
    }

    /**
     * Regression: canceling a job left its approval request pending and
     * decidable.
     */
    public function testCancelingAJobThatWaitsForApprovalClosesItsRequest(): void
    {
        $approver = $this->createUserWithRole('cancel-api-approver', 'operator');
        ['job' => $job, 'request' => $request] = $this->jobWaitingForApproval(
            $this->cancelFixtureTemplate((int)$this->operator->id),
            (int)$this->operator->id,
            (int)$approver->id
        );

        $result = $this->controller()->actionCancel((int)$job->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $data = $result['data'] ?? null;
        $this->assertIsArray($data);
        $this->assertSame(Job::STATUS_CANCELED, $data['status'] ?? null);
        $this->assertNotNull($data['finished_at'] ?? null);
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $request->status);
        $entry = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_CANCELED, 'approval_request', (int)$request->id);
        $this->assertSame((int)$this->operator->id, (int)$entry->user_id);
    }

    /**
     * Regression: see the class description.
     */
    public function testAJobThatFinishedAfterTheRequestLoadedItIs409(): void
    {
        $job = $this->createJob((int)$this->cancelFixtureTemplate((int)$this->operator->id)->id, (int)$this->operator->id, Job::STATUS_RUNNING);
        $this->finishWhenAccessIsChecked($job);

        $result = $this->controller()->actionCancel((int)$job->id);

        $this->assertSame(409, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => [
            'message' => sprintf(JobCancellationService::NOT_CANCELED, $job->id, 'succeeded'),
            'status' => Job::STATUS_SUCCEEDED,
        ]], $result);
        $job->refresh();
        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status);
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id));
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * The job's runner reports success right after the endpoint loaded the
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

    private function controller(): JobsController
    {
        return new JobsController('api/v1/jobs', \Yii::$app);
    }
}
