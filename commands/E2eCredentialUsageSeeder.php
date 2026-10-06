<?php

declare(strict_types=1);

namespace app\commands;

use app\models\Credential;
use app\models\Job;
use app\models\JobTemplate;
use app\services\CredentialService;

/**
 * Seeds credentials and templates for the credential specs:
 *
 * - credentials/usage.spec.ts: where a credential is used, the secret status
 *   of an incomplete and an undecryptable credential, and the forced delete
 *   (on FORCE_TEMPLATE, so a failure there cannot change TEMPLATE).
 * - credentials/validation.spec.ts: the type change of TOKEN.
 * - job-templates/credentials.spec.ts: TEMPLATE lists PRIMARY, then TOKEN.
 * - jobs/credentials.spec.ts: the job of TEMPLATE lists its credentials as
 *   launched, including REMOVED_NAME, which no longer exists.
 *
 * Every seed restores the fixtures, so specs may rely on their exact state.
 */
class E2eCredentialUsageSeeder
{
    public const TEMPLATE = 'e2e-cred-usage-template';
    public const FORCE_TEMPLATE = 'e2e-cred-force-template';
    public const PRIMARY = 'e2e-cred-usage-primary';
    public const TOKEN = 'e2e-cred-usage-token';
    public const TOKEN_ENV_VAR = 'E2E_USAGE_TOKEN';
    public const TOKEN_SECRET = 'e2e-usage-token-secret-value';
    public const INCOMPLETE = 'e2e-cred-incomplete';
    public const UNDECRYPTABLE = 'e2e-cred-undecryptable';
    public const REMOVED_NAME = 'e2e-cred-removed-key';

    /** An id no credential has: the job's snapshot names it as deleted. */
    private const REMOVED_ID = 2_000_000_000;

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    public function seed(int $userId, int $projectId, int $inventoryId, int $runnerGroupId): void
    {
        $primary = $this->ensureCredential(self::PRIMARY, Credential::TYPE_SSH_KEY, $userId, [
            'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ne2e-placeholder\n-----END OPENSSH PRIVATE KEY-----\n",
        ], 'deploy');
        $token = $this->ensureCredential(self::TOKEN, Credential::TYPE_TOKEN, $userId, ['token' => self::TOKEN_SECRET]);
        $token->env_var_name = self::TOKEN_ENV_VAR;
        $token->save(false);
        $this->ensureCredential(self::INCOMPLETE, Credential::TYPE_VAULT, $userId, null);
        $broken = $this->ensureCredential(self::UNDECRYPTABLE, Credential::TYPE_SSH_KEY, $userId, null);
        $broken->secret_data = 'not-a-ciphertext';
        $broken->save(false);

        $template = $this->ensureTemplate(self::TEMPLATE, [$projectId, $inventoryId, $runnerGroupId], $userId, $primary, [$token]);
        $this->ensureTemplate(self::FORCE_TEMPLATE, [$projectId, $inventoryId, $runnerGroupId], $userId, null, []);
        $this->ensureJob($template, $userId, $primary, $token);
        ($this->logger)("  Seeded credential usage fixtures (template ID {$template->id}).\n");
    }

    /**
     * @param array<string, string>|null $secrets null stores no secret
     */
    private function ensureCredential(string $name, string $type, int $userId, ?array $secrets, ?string $username = null): Credential
    {
        $credential = Credential::findOne(['name' => $name]) ?? new Credential();
        $credential->name = $name;
        $credential->credential_type = $type;
        $credential->username = $username;
        $credential->env_var_name = null;
        $credential->secret_data = null;
        $credential->created_by = $userId;
        $credential->save(false);
        if ($secrets !== null) {
            /** @var CredentialService $service */
            $service = \Yii::$app->get('credentialService');
            $service->storeSecrets($credential, $secrets);
        }

        return $credential;
    }

    /**
     * @param array{0: int, 1: int, 2: int} $parents project, inventory and runner group ids
     * @param list<Credential> $additional
     */
    private function ensureTemplate(string $name, array $parents, int $userId, ?Credential $primary, array $additional): JobTemplate
    {
        $template = JobTemplate::findOne(['name' => $name]) ?? new JobTemplate();
        $template->name = $name;
        $template->project_id = $parents[0];
        $template->inventory_id = $parents[1];
        $template->runner_group_id = $parents[2];
        $template->credential_id = $primary?->id;
        $template->playbook = 'site.yml';
        $template->verbosity = 0;
        $template->forks = 5;
        $template->become = false;
        $template->timeout_minutes = 30;
        $template->created_by = $userId;
        $template->save(false);

        $db = \Yii::$app->db;
        $db->createCommand()->delete('{{%job_template_credential}}', ['job_template_id' => $template->id])->execute();
        foreach ($additional as $position => $credential) {
            $db->createCommand()->insert('{{%job_template_credential}}', [
                'job_template_id' => $template->id,
                'credential_id' => $credential->id,
                'sort_order' => $position,
            ])->execute();
        }

        return $template;
    }

    private function ensureJob(JobTemplate $template, int $userId, Credential $primary, Credential $token): void
    {
        $job = Job::find()->where(['job_template_id' => $template->id])->orderBy(['id' => SORT_ASC])->one() ?? new Job();
        $job->job_template_id = $template->id;
        $job->launched_by = $userId;
        $job->status = Job::STATUS_SUCCEEDED;
        $job->exit_code = 0;
        $job->timeout_minutes = 30;
        $job->has_changes = 0;
        $job->queued_at = $job->started_at = $job->finished_at = time();
        $job->runner_payload = (string)json_encode([
            'template_id' => $template->id,
            'project_id' => $template->project_id,
            'inventory_id' => $template->inventory_id,
            'credential_id' => $primary->id,
            'credential_ids' => [$primary->id, $token->id, self::REMOVED_ID],
            Job::PAYLOAD_CREDENTIAL_SNAPSHOT => [
                ['id' => $primary->id, 'name' => $primary->name, 'credential_type' => $primary->credential_type, 'role' => Credential::ROLE_PRIMARY],
                ['id' => $token->id, 'name' => $token->name, 'credential_type' => $token->credential_type, 'role' => Credential::ROLE_ADDITIONAL],
                ['id' => self::REMOVED_ID, 'name' => self::REMOVED_NAME, 'credential_type' => Credential::TYPE_SSH_KEY, 'role' => Credential::ROLE_ADDITIONAL],
            ],
        ]);
        $job->save(false);
    }
}
