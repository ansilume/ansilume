<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Project;
use app\models\Runner;
use app\models\RunnerGroup;
use app\models\User;
use app\services\JobClaimService;
use app\services\JobLaunchService;
use app\tests\integration\DbTestCase;

/**
 * The claim payload carries the project's vault password source, read when
 * a runner claims the job, and the job.started audit entry records it
 * together with whether the claiming runner honours it.
 */
class JobClaimVaultPasswordSourceTest extends DbTestCase
{
    private JobClaimService $service;
    private User $user;
    private RunnerGroup $group;
    private Project $project;
    private JobTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var JobClaimService $service */
        $service = \Yii::$app->get('jobClaimService');
        $this->service = $service;
        $this->user = $this->createUser('vault-source');
        $userId = (int)$this->user->id;
        $this->group = $this->createRunnerGroup($userId);
        $this->project = $this->createProject($userId);
        $this->template = $this->createJobTemplate(
            (int)$this->project->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->group->id,
            $userId
        );
    }

    /**
     * New projects default to 'ansilume' in the database.
     */
    public function testANewProjectSendsAnsilume(): void
    {
        $payload = $this->service->buildExecutionPayload($this->launch());

        $this->assertSame(Project::VAULT_SOURCE_ANSILUME, $payload['vault_password_source']);
    }

    public function testAProjectKeepingTheRepositorySettingsSendsRepository(): void
    {
        $this->setSource(Project::VAULT_SOURCE_REPOSITORY);

        $payload = $this->service->buildExecutionPayload($this->launch());

        $this->assertSame(Project::VAULT_SOURCE_REPOSITORY, $payload['vault_password_source']);
    }

    /**
     * Like the scm fields, the setting is read at claim time: a switch also
     * reaches jobs that were queued before it.
     */
    public function testASwitchReachesJobsThatAreAlreadyQueued(): void
    {
        $this->setSource(Project::VAULT_SOURCE_REPOSITORY);
        $job = $this->launch();
        $this->setSource(Project::VAULT_SOURCE_ANSILUME);

        $this->assertSame(Project::VAULT_SOURCE_ANSILUME, $this->service->buildExecutionPayload($job)['vault_password_source']);
    }

    /**
     * Fail safe: the repository's vault settings never apply by accident.
     */
    public function testAnUnknownStoredValueSendsAnsilume(): void
    {
        Project::updateAll(['vault_password_source' => 'bogus'], ['id' => $this->project->id]);

        $payload = $this->service->buildExecutionPayload($this->launch());

        $this->assertSame(Project::VAULT_SOURCE_ANSILUME, $payload['vault_password_source']);
    }

    public function testAMissingProjectSendsAnsilume(): void
    {
        $job = $this->launch();
        $raw = $job->decodedRunnerPayload();
        $raw['project_id'] = 999999999;
        $job->runner_payload = (string)json_encode($raw);
        $job->save(false);

        $this->assertSame(Project::VAULT_SOURCE_ANSILUME, $this->service->buildExecutionPayload($job)['vault_password_source']);
    }

    /**
     * Operators can tell from the audit log whether the repository's vault
     * settings were neutralised: an older runner applies them regardless.
     *
     * @return array<string, array{0: string|null, 1: 'ansilume'|'repository', 2: bool}>
     */
    public static function auditProvider(): array
    {
        return [
            'capable runner, Ansilume only' => ['vault_password_source', Project::VAULT_SOURCE_ANSILUME, true],
            'capable runner, repository' => ['vault_password_source', Project::VAULT_SOURCE_REPOSITORY, true],
            'older runner, Ansilume only' => [null, Project::VAULT_SOURCE_ANSILUME, false],
            'runner reporting other capabilities' => ['', Project::VAULT_SOURCE_ANSILUME, false],
        ];
    }

    /**
     * @dataProvider auditProvider
     * @param 'ansilume'|'repository' $source
     */
    public function testTheStartAuditRecordsTheModeAndWhetherTheRunnerHonoursIt(?string $capabilities, string $source, bool $supported): void
    {
        $this->setSource($source);
        $runner = $this->createRunner((int)$this->group->id, (int)$this->user->id);
        $runner->capabilities = $capabilities;
        $runner->save(false);
        $job = $this->launch();

        $payload = $this->service->claimNextPayload($this->group, $runner);

        $this->assertIsArray($payload);
        $this->assertSame($job->id, $payload['job_id']);
        $this->assertSame($source, $payload['vault_password_source']);
        $meta = $this->startAudit((int)$job->id);
        $this->assertSame($source, $meta['vault_password_source']);
        $this->assertSame($supported, $meta['runner_supports_vault_password_source']);
    }

    // -- Helpers ------------------------------------------------------------------

    private function setSource(string $source): void
    {
        Project::updateAll(['vault_password_source' => $source], ['id' => $this->project->id]);
    }

    private function launch(): Job
    {
        /** @var JobLaunchService $launcher */
        $launcher = \Yii::$app->get('jobLaunchService');
        $this->template->refresh();

        return $launcher->launch($this->template, (int)$this->user->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function startAudit(int $jobId): array
    {
        $log = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_JOB_STARTED, 'object_type' => 'job', 'object_id' => $jobId])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertNotNull($log, "no job.started audit entry for job #{$jobId}");
        $meta = json_decode((string)$log->metadata, true);
        $this->assertIsArray($meta);

        return $meta;
    }
}
