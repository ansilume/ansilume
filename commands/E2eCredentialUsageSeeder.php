<?php

declare(strict_types=1);

namespace app\commands;

use app\models\Credential;
use app\models\Job;
use app\models\JobTemplate;

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
        $primary = E2eFixtureHelper::credential(self::PRIMARY, Credential::TYPE_SSH_KEY, $userId, [
            'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ne2e-placeholder\n-----END OPENSSH PRIVATE KEY-----\n",
        ], 'deploy');
        $token = E2eFixtureHelper::credential(self::TOKEN, Credential::TYPE_TOKEN, $userId, ['token' => self::TOKEN_SECRET]);
        $token->env_var_name = self::TOKEN_ENV_VAR;
        $token->save(false);
        E2eFixtureHelper::credential(self::INCOMPLETE, Credential::TYPE_VAULT, $userId, null);
        $broken = E2eFixtureHelper::credential(self::UNDECRYPTABLE, Credential::TYPE_SSH_KEY, $userId, null);
        $broken->secret_data = 'not-a-ciphertext';
        $broken->save(false);

        $template = E2eFixtureHelper::template(self::TEMPLATE, [$projectId, $inventoryId, $runnerGroupId], $userId, $primary, [$token]);
        E2eFixtureHelper::template(self::FORCE_TEMPLATE, [$projectId, $inventoryId, $runnerGroupId], $userId, null, []);
        $this->ensureJob($template, $userId, $primary, $token);
        ($this->logger)("  Seeded credential usage fixtures (template ID {$template->id}).\n");
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
