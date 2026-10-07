<?php

declare(strict_types=1);

namespace app\services;

use app\components\RunnerCommandBuilder;
use app\models\Inventory;
use app\models\Job;
use app\models\Project;
use app\models\Runner;
use app\models\RunnerGroup;
use yii\base\Component;

/**
 * Atomically claims the next queued job for a runner group.
 *
 * Uses an optimistic UPDATE … WHERE runner_id IS NULL to ensure only
 * one runner in a group can claim a given job, even under concurrent load.
 */
class JobClaimService extends Component
{
    /** How many claimed jobs one claim request may fail before it gives up. */
    public const MAX_CLAIM_ATTEMPTS = 3;

    /**
     * Claims the next job and builds its payload. A job whose credentials
     * cannot be resolved is failed with an operator message and the next
     * one is tried, so a broken job never blocks the queue.
     *
     * @return array<string, mixed>|null null when there is nothing to run
     */
    public function claimNextPayload(RunnerGroup $group, Runner $runner): ?array
    {
        for ($attempt = 0; $attempt < self::MAX_CLAIM_ATTEMPTS; $attempt++) {
            $job = $this->claim($group, $runner);
            if ($job === null) {
                return null;
            }
            try {
                return $this->buildExecutionPayload($job);
            } catch (CredentialResolutionException $e) {
                /** @var JobCompletionService $completion */
                $completion = \Yii::$app->get('jobCompletionService');
                $completion->failBeforeExecution($job, $e->getMessage());
            }
        }

        return null;
    }

    public function claim(RunnerGroup $group, Runner $runner): ?Job
    {
        $db = \Yii::$app->db;
        $tx = $db->beginTransaction();

        try {
            /** @var Job|null $job */
            $job = Job::find()
                ->innerJoin('{{%job_template}}', '{{%job_template}}.id = {{%job}}.job_template_id')
                ->where([
                    '{{%job}}.status' => Job::STATUS_QUEUED,
                    '{{%job_template}}.runner_group_id' => $group->id,
                    '{{%job_template}}.deleted_at' => null,
                    '{{%job}}.runner_id' => null,
                ])
                ->orderBy(['{{%job}}.id' => SORT_ASC])
                ->limit(1)
                ->one($db);

            if ($job === null) {
                $tx->rollBack();
                return null;
            }

            // Atomic claim: only succeeds if nobody else grabbed it first.
            $affected = $db->createCommand()->update(
                '{{%job}}',
                [
                    'runner_id' => $runner->id,
                    'worker_id' => $runner->name,
                    'status' => Job::STATUS_RUNNING,
                    'started_at' => time(),
                    'last_progress_at' => time(),
                    'updated_at' => time(),
                ],
                ['id' => $job->id, 'runner_id' => null, 'status' => Job::STATUS_QUEUED]
            )->execute();

            if ($affected !== 1) {
                $tx->rollBack();
                return null;
            }

            $tx->commit();
            $job->refresh();
            $raw = $job->decodedRunnerPayload();

            \Yii::$app->get('auditService')->log(
                AuditService::ACTION_JOB_STARTED,
                'job',
                $job->id,
                null,
                [
                    'runner_id' => $runner->id,
                    'runner_name' => $runner->name,
                    'credentials' => $this->credentialResolver()->describeForAudit($raw),
                    // Runners without the capability apply the repository's
                    // vault settings whatever the project says.
                    'vault_password_source' => $this->resolveVaultPasswordSource($raw),
                    'runner_supports_vault_password_source' => $runner->supports(Runner::CAPABILITY_VAULT_PASSWORD_SOURCE),
                ]
            );

            return $job;
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }
    }

    /**
     * Build the resolved execution payload that the runner needs to run the job.
     * Resolves IDs to actual paths/content so the runner needs no DB access.
     * Also builds and stores the canonical execution command on the job record.
     *
     * @throws CredentialResolutionException when a credential of the job was
     *     deleted or cannot be decrypted; nothing is stored in that case
     * @return array{job_id: int, project_path: string, playbook_path: string, scm_type: string, scm_url: string|null, scm_branch: string|null, scm_credential: array{credential_type: string, username: string|null, env_var_name: string|null, secrets: array<string, string>}|null, inventory_type: string, inventory_content: string|null, inventory_path: string|null, extra_vars: string|null, limit: string|null, verbosity: int, forks: int, become: bool, become_method: string, become_user: string, tags: string|null, skip_tags: string|null, check_mode: bool, timeout_minutes: int, credential: array{credential_type: string, username: string|null, env_var_name: string|null, secrets: array<string, string>}|null, credentials: list<array{credential_type: string, username: string|null, env_var_name: string|null, secrets: array<string, string>}>, vault_password_source: 'ansilume'|'repository', command: array<int, string>}
     */
    public function buildExecutionPayload(Job $job): array
    {
        $raw = $job->decodedRunnerPayload();
        // First, so a job with an unusable credential fails before anything else.
        $credentials = $this->resolveTemplateCredentials($raw);

        $projectPath = $this->resolveProjectPath($raw);
        $playbookPath = rtrim($projectPath, '/') . '/' . ltrim((string)($raw['playbook'] ?? 'site.yml'), '/');

        $inventory = $this->resolveInventory($raw);
        $scm = $this->resolveProjectScm($raw);

        $payload = [
            'job_id' => $job->id,
            'project_path' => $projectPath,
            'playbook_path' => $playbookPath,
            'scm_type' => $scm['scm_type'],
            'scm_url' => $scm['scm_url'],
            'scm_branch' => $scm['scm_branch'],
            'scm_credential' => $scm['scm_credential'],
            'inventory_type' => $inventory['type'],
            'inventory_content' => $inventory['content'], // for static
            'inventory_path' => $inventory['path'], // for file-based
            'extra_vars' => isset($raw['extra_vars']) ? (string)$raw['extra_vars'] : null,
            'limit' => isset($raw['limit']) ? (string)$raw['limit'] : null,
            'verbosity' => (int)($raw['verbosity'] ?? 0),
            'forks' => (int)($raw['forks'] ?? 5),
            'become' => !empty($raw['become']),
            'become_method' => (string)($raw['become_method'] ?? 'sudo'),
            'become_user' => (string)($raw['become_user'] ?? 'root'),
            'tags' => isset($raw['tags']) ? (string)$raw['tags'] : null,
            'skip_tags' => isset($raw['skip_tags']) ? (string)$raw['skip_tags'] : null,
            'check_mode' => !empty($raw['check_mode']),
            'timeout_minutes' => (int)($raw['timeout_minutes'] ?? $job->timeout_minutes ?? 120),
            'credential' => $credentials['credential'],
            'credentials' => $credentials['credentials'],
            // Since 2.8. Older runners ignore it and apply the repository's
            // ansible.cfg vault settings regardless.
            'vault_password_source' => $this->resolveVaultPasswordSource($raw),
        ];

        $builder = new RunnerCommandBuilder();
        $payload['command'] = $builder->build($payload);

        $this->storeExecutionCommand($job, $payload['command']);

        return $payload;
    }

    /**
     * Store the canonical command string on the job record.
     *
     * @param array<int, string> $command
     */
    protected function storeExecutionCommand(Job $job, array $command): void
    {
        $job->execution_command = implode(' ', $command);
        $job->save(false);
    }

    /**
     * The primary credential and every credential in precedence order
     * (primary first, then the additional ones by position). Elements match
     * the {@see \app\components\CredentialInjector::injectAll()} contract.
     *
     * @param array<string, mixed> $raw
     * @return array{credential: array{credential_type: string, username: string|null, env_var_name: string|null, secrets: array<string, string>}|null, credentials: list<array{credential_type: string, username: string|null, env_var_name: string|null, secrets: array<string, string>}>}
     * @throws CredentialResolutionException
     */
    protected function resolveTemplateCredentials(array $raw): array
    {
        return $this->credentialResolver()->resolveTemplateCredentials($raw);
    }

    protected function credentialResolver(): JobCredentialResolver
    {
        /** @var JobCredentialResolver $resolver */
        $resolver = \Yii::$app->get('jobCredentialResolver');

        return $resolver;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function resolveProjectPath(array $payload): string
    {
        /** @var Project|null $project */
        $project = Project::findOne($payload['project_id'] ?? 0);
        return $project?->local_path ?? '/tmp/ansilume/projects';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *     scm_type: string,
     *     scm_url: string|null,
     *     scm_branch: string|null,
     *     scm_credential: array{credential_type: string, username: string|null, env_var_name: string|null, secrets: array<string, string>}|null,
     * }
     * @throws CredentialResolutionException when the SCM credential cannot be decrypted
     */
    protected function resolveProjectScm(array $payload): array
    {
        /** @var Project|null $project */
        $project = Project::findOne($payload['project_id'] ?? 0);
        return [
            'scm_type' => $project?->scm_type ?? Project::SCM_TYPE_MANUAL,
            'scm_url' => $project?->scm_url,
            'scm_branch' => $project?->scm_branch,
            // Resolve the project-level SCM credential so the runner can
            // authenticate to git. Distinct from the ansible-execution
            // credential(s) carried under `credential` / `credentials`.
            'scm_credential' => $this->credentialResolver()->resolveScmCredential($project),
        ];
    }

    /**
     * Whether runners use only Ansilume's vault password ('ansilume') or also
     * the repository's ansible.cfg vault settings ('repository'). Read at
     * claim time like the scm fields, so a switch also reaches queued jobs.
     * A project that is gone, or a stored value this version does not know,
     * gets 'ansilume': the repository's vault settings never apply by
     * accident.
     *
     * @param array<string, mixed> $payload
     * @return 'ansilume'|'repository'
     */
    protected function resolveVaultPasswordSource(array $payload): string
    {
        /** @var Project|null $project */
        $project = Project::findOne($payload['project_id'] ?? 0);
        $source = $project?->vault_password_source;

        return in_array($source, Project::VAULT_SOURCES, true) ? $source : Project::VAULT_SOURCE_ANSILUME;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{type: string, content: string|null, path: string|null}
     */
    protected function resolveInventory(array $payload): array
    {
        /** @var Inventory|null $inventory */
        $inventory = Inventory::findOne($payload['inventory_id'] ?? 0);
        if ($inventory === null) {
            return ['type' => 'static', 'content' => "localhost\n", 'path' => null];
        }
        if ($inventory->inventory_type === Inventory::TYPE_STATIC) {
            return ['type' => 'static', 'content' => $inventory->content ?? "localhost\n", 'path' => null];
        }
        return ['type' => 'file', 'content' => null, 'path' => $inventory->source_path];
    }
}
