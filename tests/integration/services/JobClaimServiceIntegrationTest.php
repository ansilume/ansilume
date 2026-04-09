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
    // buildExecutionPayload() — extra_vars, limit, tags, etc.
    // -------------------------------------------------------------------------

    public function testBuildExecutionPayloadIncludesExtraVars(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);
        $template->extra_vars = '{"env":"prod"}';
        $template->save(false);

        $launchService = \Yii::$app->get('jobLaunchService');
        $job = $launchService->launch($template, $user->id);

        $payload = $this->service->buildExecutionPayload($job);

        $this->assertNotNull($payload['extra_vars']);
        $this->assertStringContainsString('env', $payload['extra_vars']);
    }

    public function testBuildExecutionPayloadWithLimitAndTags(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);
        $template->limit = 'webservers';
        $template->tags = 'deploy';
        $template->skip_tags = 'debug';
        $template->save(false);

        $launchService = \Yii::$app->get('jobLaunchService');
        $job = $launchService->launch($template, $user->id);

        $payload = $this->service->buildExecutionPayload($job);

        $this->assertSame('webservers', $payload['limit']);
        $this->assertSame('deploy', $payload['tags']);
        $this->assertSame('debug', $payload['skip_tags']);
    }

    public function testBuildExecutionPayloadDefaultValues(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        $launchService = \Yii::$app->get('jobLaunchService');
        $job = $launchService->launch($template, $user->id);

        $payload = $this->service->buildExecutionPayload($job);

        $this->assertSame(0, $payload['verbosity']);
        $this->assertSame(5, $payload['forks']);
        $this->assertFalse($payload['become']);
        $this->assertSame('sudo', $payload['become_method']);
        $this->assertSame('root', $payload['become_user']);
        $this->assertFalse($payload['check_mode']);
        $this->assertNull($payload['extra_vars']);
        $this->assertNull($payload['limit']);
        $this->assertNull($payload['tags']);
        $this->assertNull($payload['skip_tags']);
    }

    public function testBuildExecutionPayloadWithBecomeEnabled(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);
        $template->become = true;
        $template->become_method = 'su';
        $template->become_user = 'deploy';
        $template->save(false);

        $launchService = \Yii::$app->get('jobLaunchService');
        $job = $launchService->launch($template, $user->id);

        $payload = $this->service->buildExecutionPayload($job);

        $this->assertTrue($payload['become']);
        $this->assertSame('su', $payload['become_method']);
        $this->assertSame('deploy', $payload['become_user']);
    }

    public function testBuildExecutionPayloadStoresExecutionCommand(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        $launchService = \Yii::$app->get('jobLaunchService');
        $job = $launchService->launch($template, $user->id);

        $payload = $this->service->buildExecutionPayload($job);

        $job->refresh();
        $this->assertNotNull($job->execution_command);
        $this->assertStringContainsString('ansible-playbook', $job->execution_command);
        $this->assertIsArray($payload['command']);
        $this->assertSame('ansible-playbook', $payload['command'][0]);
    }

    public function testBuildExecutionPayloadResolvesProjectPath(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        $launchService = \Yii::$app->get('jobLaunchService');
        $job = $launchService->launch($template, $user->id);

        $payload = $this->service->buildExecutionPayload($job);

        $this->assertNotEmpty($payload['project_path']);
        $this->assertStringContainsString('site.yml', $payload['playbook_path']);
    }

    public function testBuildExecutionPayloadWithCheckMode(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        $launchService = \Yii::$app->get('jobLaunchService');
        $job = $launchService->launch($template, $user->id);
        // Manually set check_mode in runner_payload to exercise the branch
        $rawPayload = json_decode($job->runner_payload ?? '{}', true) ?: [];
        $rawPayload['check_mode'] = true;
        $job->runner_payload = json_encode($rawPayload);
        $job->save(false);

        $payload = $this->service->buildExecutionPayload($job);

        $this->assertTrue($payload['check_mode']);
    }

    public function testBuildExecutionPayloadWithVerbosity(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);
        $template->verbosity = 3;
        $template->save(false);

        $launchService = \Yii::$app->get('jobLaunchService');
        $job = $launchService->launch($template, $user->id);

        $payload = $this->service->buildExecutionPayload($job);

        $this->assertSame(3, $payload['verbosity']);
    }

    // -------------------------------------------------------------------------
    // claim() — additional coverage
    // -------------------------------------------------------------------------

    public function testClaimSetsWorkerIdToRunnerName(): void
    {
        [$group, $runner] = $this->makeQueuedJob();

        $claimed = $this->service->claim($group, $runner);

        $this->assertNotNull($claimed);
        $this->assertSame($runner->name, $claimed->worker_id);
    }

    public function testClaimWritesAuditLog(): void
    {
        [$group, $runner] = $this->makeQueuedJob();

        $before = \app\models\AuditLog::find()
            ->where(['action' => \app\models\AuditLog::ACTION_JOB_STARTED])
            ->count();

        $this->service->claim($group, $runner);

        $after = \app\models\AuditLog::find()
            ->where(['action' => \app\models\AuditLog::ACTION_JOB_STARTED])
            ->count();
        $this->assertSame((int)$before + 1, (int)$after);
    }

    public function testClaimPicksOldestQueuedJobFirst(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $runner = $this->createRunner($group->id, $user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        $job1 = $this->createJob($template->id, $user->id);
        $job2 = $this->createJob($template->id, $user->id);

        $claimed = $this->service->claim($group, $runner);

        $this->assertNotNull($claimed);
        $this->assertSame($job1->id, $claimed->id);
    }

    // ── Regression: issue #10 — payload must include SCM metadata for runner sync

    /**
     * Regression test for issue #10: when the runner claims a job for a git
     * project, the execution payload must include scm_type, scm_url, and
     * scm_branch so that the runner can clone/pull the repo before executing.
     *
     * Without these fields, the runner's syncProject() skips the sync and
     * the playbook runs against a non-existent or stale project directory.
     */
    public function testBuildExecutionPayloadIncludesScmMetadataForGitProject(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $project->scm_type = \app\models\Project::SCM_TYPE_GIT;
        $project->scm_url = 'https://github.com/example/repo.git';
        $project->scm_branch = 'main';
        $project->save(false);

        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        $launchService = \Yii::$app->get('jobLaunchService');
        $job = $launchService->launch($template, $user->id);

        $payload = $this->service->buildExecutionPayload($job);

        $this->assertSame('git', $payload['scm_type']);
        $this->assertSame('https://github.com/example/repo.git', $payload['scm_url']);
        $this->assertSame('main', $payload['scm_branch']);
    }

    /**
     * Regression test for issue #10: for a project that has never been synced
     * (status=new, local_path=null), the payload must still include SCM
     * metadata so the runner can perform the initial clone. The project_path
     * must not be empty — the runner skips sync when project_path is empty.
     */
    public function testBuildExecutionPayloadForUnsyncedProjectHasValidPath(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $project->scm_type = \app\models\Project::SCM_TYPE_GIT;
        $project->scm_url = 'https://github.com/example/repo.git';
        $project->scm_branch = 'develop';
        $project->status = \app\models\Project::STATUS_NEW;
        $project->local_path = null;
        $project->save(false);

        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        $launchService = \Yii::$app->get('jobLaunchService');
        $job = $launchService->launch($template, $user->id);

        $payload = $this->service->buildExecutionPayload($job);

        // project_path must not be empty — the runner skips sync if it is
        $this->assertNotEmpty(
            $payload['project_path'],
            'Payload project_path must not be empty for unsynced projects; '
            . 'the runner needs a valid path to clone into'
        );
        // SCM metadata must be present for the runner to sync
        $this->assertSame('git', $payload['scm_type']);
        $this->assertSame('https://github.com/example/repo.git', $payload['scm_url']);
        $this->assertSame('develop', $payload['scm_branch']);
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
