<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Credential;
use app\models\Job;
use app\models\TeamProject;
use app\services\CredentialUsageService;
use app\tests\integration\DbTestCase;

class CredentialUsageServiceTest extends DbTestCase
{
    private CredentialUsageService $service;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CredentialUsageService();
        $admin = $this->createUser('usage-admin');
        $admin->is_superadmin = true;
        $admin->save(false);
        $this->adminId = (int)$admin->id;
    }

    private function template(?int $primary): \app\models\JobTemplate
    {
        $template = $this->createJobTemplate(
            $this->createProject($this->adminId)->id,
            $this->createInventory($this->adminId)->id,
            $this->createRunnerGroup($this->adminId)->id,
            $this->adminId
        );
        $template->credential_id = $primary;
        $template->save(false);

        return $template;
    }

    private function job(int $templateId, string $status, array $payload): void
    {
        $job = $this->createJob($templateId, $this->adminId, $status);
        $job->runner_payload = (string)json_encode($payload);
        $job->save(false);
    }

    public function testEveryKindOfUsageIsFound(): void
    {
        $credential = $this->createCredential($this->adminId);
        $asPrimary = $this->template($credential->id);
        $asExtra = $this->template(null);
        \Yii::$app->get('jobTemplateCredentialService')->saveWithCredentials($asExtra, [$credential->id]);
        $project = $this->createProject($this->adminId);
        $project->scm_credential_id = $credential->id;
        $project->save(false);
        $this->job($asPrimary->id, Job::STATUS_QUEUED, ['credential_id' => $credential->id]);
        $this->job($asPrimary->id, Job::STATUS_PENDING_APPROVAL, ['credential_ids' => [$credential->id]]);
        $this->job($asPrimary->id, Job::STATUS_RUNNING, ['credential_id' => $credential->id]);
        $this->job($asPrimary->id, Job::STATUS_PENDING, ['credential_id' => 999_999_999]);

        $usage = $this->service->forCredential($credential, $this->adminId);

        $this->assertTrue($usage->isInUse());
        $this->assertSame(2, $usage->jobTemplateTotal);
        $roles = array_column($usage->jobTemplates, 'role', 'id');
        $this->assertSame(Credential::ROLE_PRIMARY, $roles[$asPrimary->id]);
        $this->assertSame(Credential::ROLE_ADDITIONAL, $roles[$asExtra->id]);
        $this->assertSame([['id' => (int)$project->id, 'name' => $project->name]], $usage->projects);
        $this->assertSame(2, $usage->pendingJobCount, 'queued and pending_approval count, running does not');
    }

    public function testAnUnusedCredentialIsNotInUse(): void
    {
        $usage = $this->service->forCredential($this->createCredential($this->adminId), $this->adminId);

        $this->assertFalse($usage->isInUse());
        $this->assertSame([], $usage->toArray()['job_templates']);
    }

    public function testUsageInOtherTeamsIsCountedButNotNamed(): void
    {
        $credential = $this->createCredential($this->adminId);
        $hidden = $this->template($credential->id);
        $team = $this->createTeam($this->adminId);
        $this->createTeamProject($team->id, (int)$hidden->project_id, TeamProject::ROLE_OPERATOR);
        $outsider = $this->createUser('usage-outsider');

        $usage = $this->service->forCredential($credential, (int)$outsider->id);

        $this->assertSame([], $usage->jobTemplates);
        $this->assertSame(1, $usage->jobTemplateTotal);
        $this->assertSame(1, $usage->hiddenJobTemplateCount());
        $this->assertStringNotContainsString((string)$hidden->name, $usage->summary());
    }
}
