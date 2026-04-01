<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Inventory;
use app\models\Job;
use app\services\JobClaimService;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for JobClaimService::claim() — atomic job claiming.
 * These tests exercise the real DB transaction and status transition.
 */
class JobClaimServiceIntegrationTest extends DbTestCase
{
    private JobClaimService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \Yii::$app->get('jobClaimService');
    }

    // -------------------------------------------------------------------------
    // claim()
    // -------------------------------------------------------------------------

    public function testClaimReturnsNullWhenNoQueuedJobs(): void
    {
        $user  = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $runner = $this->createRunner($group->id, $user->id);

        $result = $this->service->claim($group, $runner);

        $this->assertNull($result);
    }

    public function testClaimReturnsJobWhenQueuedJobExists(): void
    {
        [$group, $runner, $job] = $this->makeQueuedJob();

        $claimed = $this->service->claim($group, $runner);

        $this->assertNotNull($claimed);
        $this->assertSame($job->id, $claimed->id);
    }

    public function testClaimedJobHasRunningStatus(): void
    {
        [$group, $runner] = $this->makeQueuedJob();

        $claimed = $this->service->claim($group, $runner);

        $this->assertNotNull($claimed);
        $this->assertSame(Job::STATUS_RUNNING, $claimed->status);
    }

    public function testClaimedJobHasRunnerIdSet(): void
    {
        [$group, $runner] = $this->makeQueuedJob();

        $claimed = $this->service->claim($group, $runner);

        $this->assertNotNull($claimed);
        $this->assertSame($runner->id, $claimed->runner_id);
    }

    public function testClaimedJobHasStartedAt(): void
    {
        $before = time();
        [$group, $runner] = $this->makeQueuedJob();

        $claimed = $this->service->claim($group, $runner);

        $this->assertNotNull($claimed);
        $this->assertGreaterThanOrEqual($before, $claimed->started_at);
    }

    public function testClaimDoesNotPickJobFromDifferentGroup(): void
    {
        $user    = $this->createUser();
        $groupA  = $this->createRunnerGroup($user->id);
        $groupB  = $this->createRunnerGroup($user->id);
        $runnerB = $this->createRunner($groupB->id, $user->id);

        // Create a job only for groupA's template
        $project  = $this->createProject($user->id);
        $inv      = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $groupA->id, $user->id);
        $this->createJob($template->id, $user->id);

        // Runner from groupB should not pick up groupA's job
        $claimed = $this->service->claim($groupB, $runnerB);

        $this->assertNull($claimed);
    }

    public function testClaimOnlyPicksQueuedJobs(): void
    {
        [$group, $runner, $job] = $this->makeQueuedJob();

        // Manually transition the job to running so it can't be claimed again
        \Yii::$app->db->createCommand()
            ->update('{{%job}}', ['status' => Job::STATUS_RUNNING], ['id' => $job->id])
            ->execute();

        $claimed = $this->service->claim($group, $runner);
        $this->assertNull($claimed);
    }

    // -------------------------------------------------------------------------
    // buildExecutionPayload() — integration (resolves real DB records)
    // -------------------------------------------------------------------------

    public function testBuildExecutionPayloadResolvesStaticInventory(): void
    {
        $user     = $this->createUser();
        $group    = $this->createRunnerGroup($user->id);
        $runner   = $this->createRunner($group->id, $user->id);
        $project  = $this->createProject($user->id);
        $inv      = $this->createInventory($user->id); // TYPE_STATIC with "localhost\n"
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        $launchService = \Yii::$app->get('jobLaunchService');
        $job = $launchService->launch($template, $user->id);

        $payload = $this->service->buildExecutionPayload($job);

        $this->assertSame('static', $payload['inventory_type']);
        $this->assertSame("localhost\n", $payload['inventory_content']);
        $this->assertNull($payload['inventory_path']);
    }

    public function testBuildExecutionPayloadResolvesFileInventory(): void
    {
        $user    = $this->createUser();
        $group   = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);

        $inv = new \app\models\Inventory();
        $inv->name           = 'test-file-inv-' . uniqid('', true);
        $inv->inventory_type = Inventory::TYPE_FILE;
        $inv->source_path    = '/etc/ansible/hosts';
        $inv->created_by     = $user->id;
        $inv->created_at     = time();
        $inv->updated_at     = time();
        $inv->save(false);

        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);
        $launchService = \Yii::$app->get('jobLaunchService');
        $job = $launchService->launch($template, $user->id);

        $payload = $this->service->buildExecutionPayload($job);

        $this->assertSame('file', $payload['inventory_type']);
        $this->assertNull($payload['inventory_content']);
        $this->assertSame('/etc/ansible/hosts', $payload['inventory_path']);
    }

    public function testBuildExecutionPayloadResolvesCredential(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);

        $credential = $this->createCredential($user->id, \app\models\Credential::TYPE_SSH_KEY);
        /** @var \app\services\CredentialService $credService */
        $credService = \Yii::$app->get('credentialService');
        $credService->storeSecrets($credential, ['private_key' => 'test-key-data']);

        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);
        $template->credential_id = $credential->id;
        $template->save(false);

        $launchService = \Yii::$app->get('jobLaunchService');
        $job = $launchService->launch($template, $user->id);

        $payload = $this->service->buildExecutionPayload($job);

        $this->assertNotNull($payload['credential']);
        $this->assertSame('ssh_key', $payload['credential']['credential_type']);
        $this->assertSame('test-key-data', $payload['credential']['secrets']['private_key']);
    }

    public function testBuildExecutionPayloadReturnsNullCredentialWhenNone(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        $launchService = \Yii::$app->get('jobLaunchService');
        $job = $launchService->launch($template, $user->id);

        $payload = $this->service->buildExecutionPayload($job);

        $this->assertNull($payload['credential']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeQueuedJob(): array
    {
        $user     = $this->createUser();
        $group    = $this->createRunnerGroup($user->id);
        $runner   = $this->createRunner($group->id, $user->id);
        $project  = $this->createProject($user->id);
        $inv      = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);
        $job      = $this->createJob($template->id, $user->id);
        return [$group, $runner, $job];
    }
}
