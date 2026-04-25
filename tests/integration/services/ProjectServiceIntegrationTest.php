<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Project;
use app\models\ProjectSyncLog;
use app\services\ProjectService;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for ProjectService::sync() with manual projects,
 * which requires no real git operations.
 */
class ProjectServiceIntegrationTest extends DbTestCase
{
    private ProjectService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \Yii::$app->get('projectService');
    }

    public function testSyncManualProjectSetsStatusSynced(): void
    {
        $user    = $this->createUser();
        $project = $this->createProject($user->id);
        // createProject sets scm_type = SCM_TYPE_MANUAL

        $this->service->sync($project);

        $project->refresh();
        $this->assertSame(Project::STATUS_SYNCED, $project->status);
    }

    public function testSyncManualProjectSetsLastSyncedAt(): void
    {
        $before  = time();
        $user    = $this->createUser();
        $project = $this->createProject($user->id);

        $this->service->sync($project);

        $project->refresh();
        $this->assertGreaterThanOrEqual($before, (int)$project->last_synced_at);
    }

    public function testSyncManualProjectDoesNotRequireScmUrl(): void
    {
        $user    = $this->createUser();
        $project = $this->createProject($user->id);
        $project->scm_url = null;
        $project->save(false);

        // Should not throw
        $this->service->sync($project);

        $project->refresh();
        $this->assertSame(Project::STATUS_SYNCED, $project->status);
    }

    public function testLocalPathCombinesWorkspaceAndProjectId(): void
    {
        $user    = $this->createUser();
        $project = $this->createProject($user->id);

        $path = $this->service->localPath($project);

        $this->assertStringEndsWith('/' . $project->id, $path);
    }

    public function testSyncGitProjectWithoutUrlThrowsRuntimeException(): void
    {
        $user    = $this->createUser();
        $project = $this->createProject($user->id);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url  = '';
        $project->save(false);

        $this->expectException(\RuntimeException::class);
        $this->service->sync($project);
    }

    // ── SCM credential type validation ───────────────────────────────────────

    public function testSshUrlWithSshKeyCredentialIsValid(): void
    {
        $user = $this->createUser();
        $cred = $this->createCredential($user->id, \app\models\Credential::TYPE_SSH_KEY);
        $project = $this->createProject($user->id);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url = 'git@github.com:org/repo.git';
        $project->scm_credential_id = $cred->id;

        $project->validateScmCredentialType();
        $this->assertFalse($project->hasErrors('scm_credential_id'));
    }

    public function testSshUrlWithTokenCredentialIsInvalid(): void
    {
        $user = $this->createUser();
        $cred = $this->createCredential($user->id, \app\models\Credential::TYPE_TOKEN);
        $project = $this->createProject($user->id);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url = 'git@github.com:org/repo.git';
        $project->scm_credential_id = $cred->id;

        $project->validateScmCredentialType();
        $this->assertTrue($project->hasErrors('scm_credential_id'));
    }

    public function testHttpsUrlWithTokenCredentialIsValid(): void
    {
        $user = $this->createUser();
        $cred = $this->createCredential($user->id, \app\models\Credential::TYPE_TOKEN);
        $project = $this->createProject($user->id);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url = 'https://github.com/org/repo.git';
        $project->scm_credential_id = $cred->id;

        $project->validateScmCredentialType();
        $this->assertFalse($project->hasErrors('scm_credential_id'));
    }

    public function testHttpsUrlWithUsernamePasswordCredentialIsValid(): void
    {
        $user = $this->createUser();
        $cred = $this->createCredential($user->id, \app\models\Credential::TYPE_USERNAME_PASSWORD);
        $project = $this->createProject($user->id);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url = 'https://github.com/org/repo.git';
        $project->scm_credential_id = $cred->id;

        $project->validateScmCredentialType();
        $this->assertFalse($project->hasErrors('scm_credential_id'));
    }

    public function testHttpsUrlWithSshKeyCredentialIsInvalid(): void
    {
        $user = $this->createUser();
        $cred = $this->createCredential($user->id, \app\models\Credential::TYPE_SSH_KEY);
        $project = $this->createProject($user->id);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url = 'https://github.com/org/repo.git';
        $project->scm_credential_id = $cred->id;

        $project->validateScmCredentialType();
        $this->assertTrue($project->hasErrors('scm_credential_id'));
    }

    public function testNoCredentialSkipsValidation(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url = 'https://github.com/org/repo.git';
        $project->scm_credential_id = null;

        $project->validateScmCredentialType();
        $this->assertFalse($project->hasErrors('scm_credential_id'));
    }

    // -------------------------------------------------------------------------
    // queueSync(): status flip + sync_started_at + log buffer reset
    // -------------------------------------------------------------------------

    public function testQueueSyncStampsSyncStartedAtAndClearsPreviousLogs(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);

        // Pretend a previous sync left logs around.
        $stale = new ProjectSyncLog();
        $stale->project_id = $project->id;
        $stale->stream = ProjectSyncLog::STREAM_STDOUT;
        $stale->content = 'old run\n';
        $stale->sequence = 1;
        $stale->created_at = time() - 3600;
        $stale->save(false);

        $this->withQueueStub(function () use ($project): void {
            $this->service->queueSync($project);
        });

        $project->refresh();
        $this->assertSame(Project::STATUS_SYNCING, $project->status);
        $this->assertNotNull($project->sync_started_at);
        $this->assertSame(
            0,
            (int)ProjectSyncLog::find()->where(['project_id' => $project->id])->count(),
            'queueSync must wipe the previous run\'s log buffer.',
        );
    }

    private function withQueueStub(callable $fn): void
    {
        $previous = \Yii::$app->getComponents(true)['queue'] ?? null;
        \Yii::$app->set('queue', new class extends \yii\base\Component {
            public int $pushed = 0;
            public function push($job)
            {
                $this->pushed++;
                return null;
            }
        });
        try {
            $fn();
        } finally {
            \Yii::$app->set('queue', $previous);
        }
    }
}
