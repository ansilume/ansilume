<?php

declare(strict_types=1);

namespace app\tests\integration\api;

use app\models\Job;
use app\models\JobLog;
use app\models\JobTask;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for runner job API flows: claim, logs, complete, tasks.
 */
class RunnerJobsApiTest extends DbTestCase
{
    private function scaffold(): array
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $proj = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $tpl = $this->createJobTemplate($proj->id, $inv->id, $group->id, $user->id);
        $runner = $this->createRunner($group->id, $user->id);

        return [$user, $tpl, $group, $runner];
    }

    public function testClaimAssignsJobToRunner(): void
    {
        [$user, $tpl, $group, $runner] = $this->scaffold();

        /** @var \app\services\JobLaunchService $launch */
        $launch = \Yii::$app->get('jobLaunchService');
        $job = $launch->launch($tpl, $user->id);

        /** @var \app\services\JobClaimService $claim */
        $claim = \Yii::$app->get('jobClaimService');
        $claimed = $claim->claim($group, $runner);

        $this->assertNotNull($claimed);
        $this->assertSame($job->id, $claimed->id);
        $this->assertSame(Job::STATUS_RUNNING, $claimed->status);
        $this->assertSame($runner->id, (int)$claimed->runner_id);
    }

    public function testClaimReturnsNullWhenNoJobsQueued(): void
    {
        [$user, $tpl, $group, $runner] = $this->scaffold();

        /** @var \app\services\JobClaimService $claim */
        $claim = \Yii::$app->get('jobClaimService');
        $result = $claim->claim($group, $runner);

        $this->assertNull($result);
    }

    public function testCompleteJobSetsStatusAndExitCode(): void
    {
        [$user, $tpl, $group, $runner] = $this->scaffold();

        $launch = \Yii::$app->get('jobLaunchService');
        $job = $launch->launch($tpl, $user->id);

        $claim = \Yii::$app->get('jobClaimService');
        $job = $claim->claim($group, $runner);

        // Seed a task row so the no-op safeguard in complete() doesn't flip
        // this to FAILED — this test covers the normal succeeded path.
        $this->seedTaskFor($job);

        /** @var \app\services\JobCompletionService $complete */
        $complete = \Yii::$app->get('jobCompletionService');
        $complete->complete($job, 0, false);

        $job->refresh();
        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status);
        $this->assertSame(0, (int)$job->exit_code);
        $this->assertNotNull($job->finished_at);
    }

    public function testCompleteJobWithNonZeroExitCodeSetsFailedStatus(): void
    {
        [$user, $tpl, $group, $runner] = $this->scaffold();

        $launch = \Yii::$app->get('jobLaunchService');
        $job = $launch->launch($tpl, $user->id);

        $claim = \Yii::$app->get('jobClaimService');
        $job = $claim->claim($group, $runner);

        $complete = \Yii::$app->get('jobCompletionService');
        $complete->complete($job, 1, false);

        $job->refresh();
        $this->assertSame(Job::STATUS_FAILED, $job->status);
        $this->assertSame(1, (int)$job->exit_code);
    }

    /**
     * Regression: when the runner signals timed_out=true on /complete, the
     * job must land on STATUS_TIMED_OUT, not STATUS_FAILED. Otherwise the
     * dedicated "timed out" job filter would miss timeouts and operators
     * couldn't tell deadlines from real failures.
     */
    public function testCompleteJobRoutesTimedOutFlagToCompleteTimedOut(): void
    {
        [$user, $tpl, $group, $runner] = $this->scaffold();

        $launch = \Yii::$app->get('jobLaunchService');
        $job = $launch->launch($tpl, $user->id);
        $claim = \Yii::$app->get('jobClaimService');
        $job = $claim->claim($group, $runner);

        $this->invokeCompleteEndpoint($runner, $job->id, [
            'exit_code' => -1,
            'has_changes' => false,
            'timed_out' => true,
        ]);

        $job->refresh();
        $this->assertSame(
            Job::STATUS_TIMED_OUT,
            $job->status,
            'A runner signalling timed_out=true must land on STATUS_TIMED_OUT, not STATUS_FAILED.',
        );
        $this->assertSame(-1, (int)$job->exit_code);
        $this->assertNotNull($job->finished_at);
    }

    public function testCompleteJobWithoutTimedOutFlagStaysOnFailedMapping(): void
    {
        // Non-zero exit without the timed_out flag must still land on FAILED
        // — the safeguard must not accidentally reroute genuine failures.
        [$user, $tpl, $group, $runner] = $this->scaffold();

        $launch = \Yii::$app->get('jobLaunchService');
        $job = $launch->launch($tpl, $user->id);
        $claim = \Yii::$app->get('jobClaimService');
        $job = $claim->claim($group, $runner);

        $this->invokeCompleteEndpoint($runner, $job->id, [
            'exit_code' => 2,
            'has_changes' => false,
        ]);

        $job->refresh();
        $this->assertSame(Job::STATUS_FAILED, $job->status);
        $this->assertSame(2, (int)$job->exit_code);
    }

    /**
     * Drive actionComplete via a staged request so the dispatch logic
     * (timed_out→completeTimedOut vs plain→complete) is covered end-to-end.
     * Uses reflection to bypass Yii's behaviors (ContentNegotiator sets a
     * `format` property that the console Response doesn't have).
     *
     * @param array<string, mixed> $body
     */
    private function invokeCompleteEndpoint(\app\models\Runner $runner, int $jobId, array $body): void
    {
        // The /complete path reads the raw runner token from the
        // Authorization header, so we need to know it — regenerate here.
        $token = \app\models\Runner::generateToken();
        $runner->token_hash = $token['hash'];
        $runner->save(false, ['token_hash']);

        $originalRequest = \Yii::$app->has('request') ? \Yii::$app->request : null;
        \Yii::$app->set('request', new class ($token['raw'], $body) extends \yii\web\Request {
            /** @param array<string, mixed> $body */
            public function __construct(private readonly string $token, private readonly array $bodyParamsValue)
            {
                parent::__construct();
            }
            public function getBodyParams(): array
            {
                return $this->bodyParamsValue;
            }
            public function getHeaders(): \yii\web\HeaderCollection
            {
                $h = new \yii\web\HeaderCollection();
                $h->set('Authorization', 'Bearer ' . $this->token);
                return $h;
            }
        });

        try {
            $controller = new \app\controllers\api\runner\JobsController('runner-jobs', \Yii::$app);
            // authenticateRunner is private — invoke via reflection to
            // populate $currentRunner without running ContentNegotiator.
            $authRef = new \ReflectionMethod($controller, 'authenticateRunner');
            $authRef->setAccessible(true);
            $authRef->invoke($controller);
            $controller->actionComplete($jobId);
        } finally {
            if ($originalRequest !== null) {
                \Yii::$app->set('request', $originalRequest);
            }
        }
    }

    public function testAppendLogCreatesLogRecord(): void
    {
        [$user, $tpl, $group, $runner] = $this->scaffold();

        $launch = \Yii::$app->get('jobLaunchService');
        $job = $launch->launch($tpl, $user->id);

        $claim = \Yii::$app->get('jobClaimService');
        $job = $claim->claim($group, $runner);

        /** @var \app\services\JobCompletionService $svc */
        $svc = \Yii::$app->get('jobCompletionService');
        $svc->appendLog($job, JobLog::STREAM_STDOUT, 'Hello World', 1);

        $logs = JobLog::find()->where(['job_id' => $job->id])->all();
        $this->assertCount(1, $logs);
        $this->assertSame('Hello World', $logs[0]->content);
        $this->assertSame(JobLog::STREAM_STDOUT, $logs[0]->stream);
    }

    public function testCompleteJobWithChangesFlagSetsHasChanges(): void
    {
        [$user, $tpl, $group, $runner] = $this->scaffold();

        $launch = \Yii::$app->get('jobLaunchService');
        $job = $launch->launch($tpl, $user->id);

        $claim = \Yii::$app->get('jobClaimService');
        $job = $claim->claim($group, $runner);

        $complete = \Yii::$app->get('jobCompletionService');
        $complete->complete($job, 0, true);

        $job->refresh();
        $this->assertSame(1, (int)$job->has_changes);
    }

    public function testBuildExecutionPayloadContainsRequiredKeys(): void
    {
        [$user, $tpl, $group, $runner] = $this->scaffold();

        $launch = \Yii::$app->get('jobLaunchService');
        $job = $launch->launch($tpl, $user->id);

        $claim = \Yii::$app->get('jobClaimService');
        $claimed = $claim->claim($group, $runner);

        $payload = $claim->buildExecutionPayload($claimed);

        $this->assertArrayHasKey('job_id', $payload);
        $this->assertArrayHasKey('playbook_path', $payload);
        $this->assertArrayHasKey('command', $payload);
        $this->assertSame($claimed->id, $payload['job_id']);
    }

    /** Seed a minimal JobTask row — enough to clear the "did nothing" gate. */
    private function seedTaskFor(Job $job): void
    {
        $task = new JobTask();
        $task->job_id = $job->id;
        $task->sequence = 0;
        $task->task_name = 'seeded';
        $task->task_action = 'debug';
        $task->host = 'localhost';
        $task->status = 'ok';
        $task->changed = 0;
        $task->duration_ms = 0;
        $task->save(false);
    }
}
