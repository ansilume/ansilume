<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\runner;

use app\controllers\api\runner\JobsController;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobLog;
use app\models\JobTemplate;
use app\models\Runner;
use app\services\JobCancellationService;
use app\services\JobClaimService;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * POST /api/runner/v1/jobs/claim keeps its contract (200 with a payload, or
 * 204) when a queued job cannot run: that job is failed on the server and
 * the runner gets the next one.
 */
class JobsControllerClaimTest extends WebControllerTestCase
{
    private JobTemplate $template;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('runner-claim')->id;
        $group = $this->createRunnerGroup($this->userId);
        $this->template = $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$group->id,
            $this->userId
        );
        $runner = $this->createRunner((int)$group->id, $this->userId);
        $token = Runner::generateToken();
        $runner->token_hash = $token['hash'];
        $runner->save(false);
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $token['raw']);
    }

    /** One test swaps in a claim service that cancels the job it claims; restore the real one. */
    protected function tearDown(): void
    {
        \Yii::$app->set('jobClaimService', ['class' => JobClaimService::class]);
        parent::tearDown();
    }

    public function testNothingQueuedIs204(): void
    {
        $this->assertSame([], $this->claim());
        $this->assertSame(204, \Yii::$app->response->statusCode);
    }

    public function testAJobWithAMissingCredentialIsFailedAndTheAnswerIs204(): void
    {
        $broken = $this->queuedJob(['credential_id' => 999999]);

        $this->assertSame([], $this->claim());

        $this->assertSame(204, \Yii::$app->response->statusCode);
        $broken->refresh();
        $this->assertSame(Job::STATUS_FAILED, $broken->status);
    }

    public function testTheRunnerGetsTheNextHealthyJob(): void
    {
        $broken = $this->queuedJob(['credential_ids' => [999999]]);
        $healthy = $this->queuedJob([]);

        $result = $this->claim();

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertTrue($result['ok']);
        $this->assertIsArray($result['data']);
        $this->assertSame($healthy->id, $result['data']['job_id']);
        $broken->refresh();
        $this->assertSame(Job::STATUS_FAILED, $broken->status);
    }

    /**
     * Regression: a failure before execution overwrote a cancel that
     * committed after the claim had loaded the job. The claim took the job,
     * an operator canceled it while the claim resolved its credentials, and
     * the credential that could not be used then ended the canceled job as
     * failed, with a job.finished audit entry, webhook and notification.
     */
    public function testACancelThatCommitsWhileTheClaimResolvesTheCredentialsWins(): void
    {
        $broken = $this->queuedJob(['credential_id' => 999999]);
        \Yii::$app->set('jobClaimService', new class ((int)$broken->id) extends JobClaimService {
            public function __construct(private readonly int $jobId)
            {
                parent::__construct();
            }

            protected function resolveTemplateCredentials(array $raw): array
            {
                // An operator cancels the claimed job in a parallel request.
                $copy = Job::findOne($this->jobId);
                if ($copy === null) {
                    throw new \LogicException("Job #{$this->jobId} is gone.");
                }
                /** @var JobCancellationService $cancellation */
                $cancellation = \Yii::$app->get('jobCancellationService');
                $cancellation->cancel($copy, (int)$copy->launched_by);

                return parent::resolveTemplateCredentials($raw);
            }
        });

        $this->assertSame([], $this->claim());

        $this->assertSame(204, \Yii::$app->response->statusCode);
        $broken->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $broken->status, 'the cancel stays');
        $this->assertNull($broken->exit_code);
        $this->assertSame([], JobLog::find()->select('content')->where(['job_id' => $broken->id])->column(), 'no reason for a job that did not fail');
        $this->assertFalse(
            AuditLog::find()->where(['action' => AuditLog::ACTION_JOB_FINISHED, 'object_type' => 'job', 'object_id' => $broken->id])->exists(),
            'no job.finished audit entry'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function claim(): array
    {
        $app = \Yii::$app;
        $this->assertNotNull($app, 'the test bootstrap creates the application');
        $result = (new JobsController('api/runner/v1/jobs', $app))->runAction('claim');
        $this->assertIsArray($result);

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function queuedJob(array $payload): Job
    {
        $job = $this->createJob((int)$this->template->id, $this->userId, Job::STATUS_QUEUED);
        $job->runner_payload = (string)json_encode($payload + [
            'project_id' => $this->template->project_id,
            'inventory_id' => $this->template->inventory_id,
        ]);
        $job->save(false);

        return $job;
    }
}
