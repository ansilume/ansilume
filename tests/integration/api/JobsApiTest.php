<?php

declare(strict_types=1);

namespace app\tests\integration\api;

use app\models\Job;
use app\models\JobTemplate;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for Jobs API — cancel endpoint and serialization.
 *
 * Tests the actual business logic for job creation and cancellation through
 * the service layer, simulating what the API controller would invoke.
 */
class JobsApiTest extends DbTestCase
{
    private function scaffold(): array
    {
        $user  = $this->createUser('api');
        $group = $this->createRunnerGroup($user->id);
        $proj  = $this->createProject($user->id);
        $inv   = $this->createInventory($user->id);
        $tpl   = $this->createJobTemplate($proj->id, $inv->id, $group->id, $user->id);

        return [$user, $tpl, $group];
    }

    // -------------------------------------------------------------------------
    // Job Launch (via service layer)
    // -------------------------------------------------------------------------

    public function testLaunchCreatesJobInQueuedStatus(): void
    {
        [$user, $tpl] = $this->scaffold();

        /** @var \app\services\JobLaunchService $svc */
        $svc = \Yii::$app->get('jobLaunchService');
        $job = $svc->launch($tpl, $user->id);

        $this->assertSame(Job::STATUS_QUEUED, $job->status);
        $this->assertSame($user->id, $job->launched_by);
        $this->assertSame($tpl->id, $job->job_template_id);
    }

    public function testLaunchWithOverrides(): void
    {
        [$user, $tpl] = $this->scaffold();

        $svc = \Yii::$app->get('jobLaunchService');
        $job = $svc->launch($tpl, $user->id, [
            'extra_vars' => '{"env": "staging"}',
            'limit'      => 'web-servers',
            'verbosity'  => 2,
        ]);

        $decoded = json_decode($job->extra_vars, true);
        $this->assertSame(['env' => 'staging'], $decoded);
        $this->assertSame('web-servers', $job->limit);
        $this->assertSame(2, (int)$job->verbosity);
    }

    // -------------------------------------------------------------------------
    // Job Cancel (via direct model mutation, as controller does)
    // -------------------------------------------------------------------------

    public function testCancelRunningJobSetsCorrectState(): void
    {
        [$user, $tpl] = $this->scaffold();
        $svc = \Yii::$app->get('jobLaunchService');
        $job = $svc->launch($tpl, $user->id);

        // Simulate runner claiming it
        $job->status     = Job::STATUS_RUNNING;
        $job->started_at = time();
        $job->save(false);

        // Cancel
        $this->assertTrue($job->isCancelable());
        $job->status      = Job::STATUS_CANCELED;
        $job->finished_at = time();
        $job->save(false);
        $job->refresh();

        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $this->assertNotNull($job->finished_at);
    }

    public function testCannotCancelFinishedJob(): void
    {
        [$user, $tpl] = $this->scaffold();
        $svc = \Yii::$app->get('jobLaunchService');
        $job = $svc->launch($tpl, $user->id);

        $job->status      = Job::STATUS_SUCCEEDED;
        $job->finished_at = time();
        $job->exit_code   = 0;
        $job->save(false);

        $this->assertFalse($job->isCancelable());
    }

    // -------------------------------------------------------------------------
    // Job Serialization shape
    // -------------------------------------------------------------------------

    public function testJobHasExpectedSerializableFields(): void
    {
        [$user, $tpl] = $this->scaffold();
        $svc = \Yii::$app->get('jobLaunchService');
        $job = $svc->launch($tpl, $user->id);

        // Verify all fields the API serializer expects exist
        $this->assertNotNull($job->id);
        $this->assertNotNull($job->status);
        $this->assertNotNull($job->job_template_id);
        $this->assertNotNull($job->launched_by);
        $this->assertNotNull($job->queued_at);
        $this->assertNotNull($job->created_at);
    }

    public function testLaunchWithCheckModeOverride(): void
    {
        [$user, $tpl] = $this->scaffold();

        $svc = \Yii::$app->get('jobLaunchService');
        $job = $svc->launch($tpl, $user->id, [
            'check_mode' => 1,
        ]);

        $this->assertSame(1, (int)$job->check_mode);
        $payload = json_decode($job->runner_payload, true);
        $this->assertTrue($payload['check_mode']);
    }

    public function testLaunchWithoutCheckModeDefaultsToZero(): void
    {
        [$user, $tpl] = $this->scaffold();

        $svc = \Yii::$app->get('jobLaunchService');
        $job = $svc->launch($tpl, $user->id);

        $this->assertSame(0, (int)$job->check_mode);
    }

    public function testJobTemplateRelationIsResolvable(): void
    {
        [$user, $tpl] = $this->scaffold();
        $svc = \Yii::$app->get('jobLaunchService');
        $job = $svc->launch($tpl, $user->id);

        // The API serializer accesses $job->jobTemplate->name
        $this->assertNotNull($job->jobTemplate);
        $this->assertSame($tpl->name, $job->jobTemplate->name);
    }
}
