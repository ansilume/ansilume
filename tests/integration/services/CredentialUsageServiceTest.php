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

    /**
     * @param list<int> $additionalIds
     */
    private function attach(\app\models\JobTemplate $template, array $additionalIds): void
    {
        $this->assertTrue(\Yii::$app->get('jobTemplateCredentialService')->saveWithCredentials($template, $additionalIds));
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

    public function testTemplatesWithAnotherVaultCountCountsPrimaryAndAdditionalUsages(): void
    {
        $credential = $this->createCredential($this->adminId, Credential::TYPE_TOKEN);
        $vaultA = $this->createCredential($this->adminId, Credential::TYPE_VAULT);
        $vaultB = $this->createCredential($this->adminId, Credential::TYPE_VAULT);
        $ssh = $this->createCredential($this->adminId, Credential::TYPE_SSH_KEY);
        // The other vault password is the primary, the credential an additional one.
        $this->attach($this->template($vaultA->id), [$credential->id]);
        // The credential is the primary, the other vault password an additional one.
        $this->attach($this->template($credential->id), [$ssh->id, $vaultB->id]);
        // Uses the credential, but holds no vault password.
        $this->attach($this->template($credential->id), [$ssh->id]);
        // Holds a vault password, but does not use the credential.
        $this->attach($this->template($vaultA->id), [$ssh->id]);
        // A deleted template never runs again.
        $deleted = $this->template($vaultB->id);
        $this->attach($deleted, [$credential->id]);
        $deleted->softDelete();

        $this->assertSame(2, $this->service->templatesWithAnotherVaultCount($credential));
    }

    /**
     * A template saved before the one-vault rule may hold two other vault
     * passwords; it is still one template.
     */
    public function testATemplateWithSeveralOtherVaultsCountsOnce(): void
    {
        $credential = $this->createCredential($this->adminId, Credential::TYPE_TOKEN);
        $legacy = $this->template($credential->id);
        $otherVaults = [
            $this->createCredential($this->adminId, Credential::TYPE_VAULT),
            $this->createCredential($this->adminId, Credential::TYPE_VAULT),
        ];
        foreach ($otherVaults as $order => $vault) {
            \Yii::$app->db->createCommand()->insert('{{%job_template_credential}}', [
                'job_template_id' => $legacy->id,
                'credential_id' => $vault->id,
                'sort_order' => $order + 1,
            ])->execute();
        }

        $this->assertSame(1, $this->service->templatesWithAnotherVaultCount($credential));
    }

    public function testTheCredentialItselfIsNotAnotherVault(): void
    {
        $vault = $this->createCredential($this->adminId, Credential::TYPE_VAULT);
        $ssh = $this->createCredential($this->adminId, Credential::TYPE_SSH_KEY);
        // As primary, the vault password sits in the primary slot and in the pivot.
        $this->attach($this->template($vault->id), [$ssh->id]);
        $this->attach($this->template($ssh->id), [$vault->id]);

        $this->assertSame(0, $this->service->templatesWithAnotherVaultCount($vault));
        $this->assertSame(0, $this->service->templatesWithAnotherVaultCount($this->createCredential($this->adminId)), 'unused');
    }
}
