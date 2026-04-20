<?php

declare(strict_types=1);

namespace app\services;

use app\components\RunnerCommandBuilder;
use app\models\Credential;
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

            \Yii::$app->get('auditService')->log(
                AuditService::ACTION_JOB_STARTED,
                'job',
                $job->id,
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
     * @return array{job_id: int, project_path: string, playbook_path: string, scm_type: string, scm_url: string|null, scm_branch: string|null, scm_credential: array{credential_type: string, username: string|null, env_var_name: string|null, secrets: array<string, string>}|null, inventory_type: string, inventory_content: string|null, inventory_path: string|null, extra_vars: string|null, limit: string|null, verbosity: int, forks: int, become: bool, become_method: string, become_user: string, tags: string|null, skip_tags: string|null, check_mode: bool, timeout_minutes: int, credential: array{credential_type: string, username: string|null, secrets: array<string, string>}|null, command: array<int, string>}
     */
    public function buildExecutionPayload(Job $job): array
    {
        /** @var array<string, mixed> $raw */
        $raw = json_decode($job->runner_payload ?? '{}', true) ?: [];

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
            'credential' => $this->resolveCredential($raw),
            'credentials' => $this->resolveCredentials($raw),
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
     * Resolve the primary credential only — kept as a convenience for code
     * paths that still expect a single-credential shape.
     *
     * @param array<string, mixed> $payload
     * @return array{credential_type: string, username: string|null, env_var_name: string|null, secrets: array<string, string>}|null
     */
    protected function resolveCredential(array $payload): ?array
    {
        return $this->resolveCredentialById((int)($payload['credential_id'] ?? 0));
    }

    /**
     * Resolve every credential attached to the template (primary FK plus
     * pivot rows). Returns an ordered list whose elements match the
     * {@see \app\components\CredentialInjector::injectAll()} contract.
     *
     * @param array<string, mixed> $payload
     * @return list<array{credential_type: string, username: string|null, env_var_name: string|null, secrets: array<string, string>}>
     */
    protected function resolveCredentials(array $payload): array
    {
        /** @var list<int> $ids */
        $ids = array_values(array_map(
            static fn ($v): int => (int)$v,
            (array)($payload['credential_ids'] ?? [])
        ));
        if ($ids === []) {
            $primary = (int)($payload['credential_id'] ?? 0);
            if ($primary !== 0) {
                $ids[] = $primary;
            }
        }

        $out = [];
        foreach ($ids as $id) {
            $resolved = $this->resolveCredentialById($id);
            if ($resolved !== null) {
                $out[] = $resolved;
            }
        }
        return $out;
    }

    /**
     * @return array{credential_type: string, username: string|null, env_var_name: string|null, secrets: array<string, string>}|null
     */
    private function resolveCredentialById(int $credentialId): ?array
    {
        if ($credentialId === 0) {
            return null;
        }

        /** @var Credential|null $credential */
        $credential = Credential::findOne($credentialId);
        if ($credential === null) {
            return null;
        }

        /** @var CredentialService $credentialService */
        $credentialService = \Yii::$app->get('credentialService');

        try {
            $secrets = $credentialService->getSecrets($credential);
        } catch (\Exception $e) {
            \Yii::error(
                "Failed to decrypt credential #{$credentialId}: " . $e->getMessage(),
                __CLASS__
            );
            return null;
        }

        return [
            'credential_type' => $credential->credential_type,
            'username' => $credential->username,
            'env_var_name' => $credential->env_var_name,
            'secrets' => $secrets,
        ];
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
            'scm_credential' => $this->resolveCredentialById((int)($project?->scm_credential_id ?? 0)),
        ];
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
