<?php

declare(strict_types=1);

namespace app\tests\integration\api;

use app\models\Job;
use app\models\JobLog;
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
}
