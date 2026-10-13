<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\runner;

use app\controllers\api\runner\JobsController;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobLog;
use app\models\JobTask;
use app\models\JobTemplate;
use app\models\Runner;
use app\services\JobCancellationService;
use app\services\JobCompletionService;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * POST /api/runner/v1/jobs/<id>/complete ends the job and answers with the
 * status the job has afterwards. A cancel that commits after the endpoint
 * loaded the job wins: the report is ignored, and the answer says canceled.
 */
class JobsControllerCompleteTest extends WebControllerTestCase
{
    private JobTemplate $template;
    private int $userId;
    private Runner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('runner-complete')->id;
        $group = $this->createRunnerGroup($this->userId);
        $this->template = $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$group->id,
            $this->userId
        );
        $this->runner = $this->createRunner((int)$group->id, $this->userId);
        $token = Runner::generateToken();
        $this->runner->token_hash = $token['hash'];
        $this->runner->save(false);
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $token['raw']);
    }

    /** Some tests swap in a completion service that cancels first; restore the real one. */
    protected function tearDown(): void
    {
        \Yii::$app->set('jobCompletionService', ['class' => JobCompletionService::class]);
        parent::tearDown();
    }

    public function testASuccessReportEndsTheJobAndAnswersWithItsStatus(): void
    {
        $job = $this->runningJob();

        $result = $this->complete($job, ['exit_code' => 0, 'has_changes' => true]);

        $this->assertSame(['ok' => true, 'data' => ['status' => Job::STATUS_SUCCEEDED]], $result);
        $job->refresh();
        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status);
        $this->assertSame(0, (int)$job->exit_code);
        $this->assertSame(1, (int)$job->has_changes);
    }

    public function testATimeoutReportAnswersTimedOut(): void
    {
        $job = $this->runningJob();

        $result = $this->complete($job, ['exit_code' => -1, 'timed_out' => true]);

        $this->assertSame(['ok' => true, 'data' => ['status' => Job::STATUS_TIMED_OUT]], $result);
        $job->refresh();
        $this->assertSame(Job::STATUS_TIMED_OUT, $job->status);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function reportProvider(): array
    {
        return [
            'success' => [
                ['exit_code' => 0, 'has_changes' => true],
                'Runner reported completion (exit_code=0) after job was already in terminal status "canceled" — ignored.',
            ],
            'failure' => [
                ['exit_code' => 2],
                'Runner reported completion (exit_code=2) after job was already in terminal status "canceled" — ignored.',
            ],
            'timeout' => [
                ['exit_code' => -1, 'timed_out' => true],
                'Runner reported timeout after job was already in terminal status "canceled" — ignored.',
            ],
        ];
    }

    /**
     * Regression: a runner's completion overwrote a cancel that committed
     * after the runner had loaded the job. The endpoint loads the job, an
     * operator cancels it in a parallel request, then the report is
     * written: the job ended as succeeded, failed or timed out, and the
     * runner was told so, although the cancel had succeeded.
     *
     * @dataProvider reportProvider
     * @param array<string, mixed> $body
     */
    public function testACancelThatCommitsAfterTheEndpointLoadedTheJobWins(array $body, string $ignoredLine): void
    {
        $job = $this->runningJob();
        $this->cancelAfterTheLoad();

        $result = $this->complete($job, $body);

        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status, 'the cancel stays');
        $this->assertNull($job->exit_code);
        $this->assertSame(0, (int)$job->has_changes);
        $this->assertSame(['ok' => true, 'data' => ['status' => Job::STATUS_CANCELED]], $result);
        $this->assertSame([$ignoredLine], $this->logLinesOf($job));
        $this->assertFalse(
            AuditLog::find()->where(['action' => AuditLog::ACTION_JOB_FINISHED, 'object_type' => 'job', 'object_id' => $job->id])->exists(),
            'no job.finished audit entry'
        );
    }

    public function testAReportForAJobCanceledBeforeTheRequestAnswersCanceled(): void
    {
        $job = $this->runningJob();
        /** @var JobCancellationService $cancellation */
        $cancellation = \Yii::$app->get('jobCancellationService');
        $this->assertTrue($cancellation->cancel($job, $this->userId));

        $result = $this->complete($job, ['exit_code' => 0]);

        $this->assertSame(['ok' => true, 'data' => ['status' => Job::STATUS_CANCELED]], $result);
        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
    }

    // -- Helpers ------------------------------------------------------------------

    /**
     * A job this runner has claimed, with one task result, so a success
     * report counts as having run against a host.
     */
    private function runningJob(): Job
    {
        $job = $this->createJob((int)$this->template->id, $this->userId, Job::STATUS_RUNNING);
        $job->runner_id = $this->runner->id;
        $job->started_at = time();
        $job->save(false);
        $task = new JobTask();
        $task->job_id = $job->id;
        $task->sequence = 0;
        $task->task_name = 'ping';
        $task->task_action = 'ping';
        $task->host = 'localhost';
        $task->status = 'ok';
        $task->changed = 0;
        $task->duration_ms = 1;
        $task->created_at = time();
        $task->save(false);

        return $job;
    }

    /**
     * From now on an operator cancels the job (through its own copy, as a
     * parallel web request does) after the endpoint has loaded it and right
     * before the report is written.
     */
    private function cancelAfterTheLoad(): void
    {
        \Yii::$app->set('jobCompletionService', new class extends JobCompletionService {
            public function complete(Job $job, int $exitCode, bool $hasChanges = false): void
            {
                $this->cancelACopyOf($job);
                parent::complete($job, $exitCode, $hasChanges);
            }

            public function completeTimedOut(Job $job): void
            {
                $this->cancelACopyOf($job);
                parent::completeTimedOut($job);
            }

            private function cancelACopyOf(Job $job): void
            {
                $copy = Job::findOne($job->id);
                if ($copy === null) {
                    throw new \LogicException("Job #{$job->id} is gone.");
                }
                /** @var JobCancellationService $cancellation */
                $cancellation = \Yii::$app->get('jobCancellationService');
                $cancellation->cancel($copy, (int)$job->launched_by);
            }
        });
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function complete(Job $job, array $body): array
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
        $app = \Yii::$app;
        $this->assertNotNull($app, 'the test bootstrap creates the application');
        $result = (new JobsController('api/runner/v1/jobs', $app))->runAction('complete', ['id' => $job->id]);
        $this->assertIsArray($result);

        return $result;
    }

    /**
     * @return list<string>
     */
    private function logLinesOf(Job $job): array
    {
        /** @var list<JobLog> $logs */
        $logs = JobLog::find()->where(['job_id' => $job->id])->orderBy(['sequence' => SORT_ASC, 'id' => SORT_ASC])->all();

        return array_map(static fn (JobLog $log): string => (string)$log->content, $logs);
    }
}
