<?php

declare(strict_types=1);

namespace app\services;

use app\components\JobInventory;
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
 *
 * A claimed job runs only when it may: a job whose job template or
 * inventory has an approval rule needs an approval (JobClaimGate), and the
 * inventory it was launched with must exist and name its hosts
 * (resolveInventory()). Both are checked before any credential is
 * decrypted; a job that fails them fails before execution
 * (JobClaimRefusedException). The runner's payload keys stay the same.
 *
 * Once a job it claimed has failed before execution (refused, or with
 * credentials that cannot be resolved), a claim request claims no further
 * job after CLAIM_BUDGET_SECONDS, so the effects of failed jobs, which run
 * within the request, cannot make it hand out a job after its runner gave up
 * waiting for the answer.
 */
class JobClaimService extends Component
{
    /** How many claimed jobs one claim request may fail before it gives up. */
    public const MAX_CLAIM_ATTEMPTS = 3;

    /**
     * The seconds, counted from the start of a claim request, after which
     * it claims no further job once it has failed one before execution: it
     * answers that there is no job, and the runner asks again at its next
     * poll. The failure's effects run within the request (the job.failure
     * webhooks, the job.failed notifications, the next step of a workflow),
     * each up to its endpoint's timeout, while the runner waits 30 seconds
     * for the answer (RunnerHttpClient). A job claimed after the runner gave
     * up reached no runner and stayed running: JobReclaimService takes back
     * only the jobs of a runner that went offline.
     */
    public const CLAIM_BUDGET_SECONDS = 10;

    /**
     * Claims the next job and builds its payload. A job that must not run
     * (JobClaimRefusedException: it needs an approval it does not have, or
     * its inventory is gone or names no hosts) or whose credentials cannot
     * be resolved is failed with an operator message, and the next one is
     * tried, so a broken job never blocks the queue. Such failures count
     * toward MAX_CLAIM_ATTEMPTS; job.finished records the refusal's
     * auditContext(). After such a failure no further job is claimed once
     * CLAIM_BUDGET_SECONDS have passed since this call began (now()): the
     * answer is null, and the next poll claims the next job.
     *
     * @return array<string, mixed>|null null when there is nothing to run,
     *     or when the claim request has run out of time after a failure
     */
    public function claimNextPayload(RunnerGroup $group, Runner $runner): ?array
    {
        $startedAt = $this->now();
        for ($attempt = 0; $attempt < self::MAX_CLAIM_ATTEMPTS; $attempt++) {
            $job = $this->claim($group, $runner);
            if ($job === null) {
                return null;
            }
            try {
                return $this->buildExecutionPayload($job, $runner);
            } catch (CredentialResolutionException | JobClaimRefusedException $e) {
                /** @var JobCompletionService $completion */
                $completion = \Yii::$app->get('jobCompletionService');
                $completion->failBeforeExecution(
                    $job,
                    $e->getMessage(),
                    $e instanceof JobClaimRefusedException ? $e->auditContext() : []
                );
            }
            if ($this->now() - $startedAt >= self::CLAIM_BUDGET_SECONDS) {
                return null;
            }
        }

        return null;
    }

    /**
     * The time base of CLAIM_BUDGET_SECONDS, in seconds: a monotonic clock,
     * which a wall clock that is set back or forward does not move. A test
     * overrides it to let time pass without sleeping.
     */
    protected function now(): float
    {
        return hrtime(true) / 1000000000;
    }

    /**
     * Claim the oldest queued job of $group's live job templates for
     * $runner, or null when there is none. A job of a workflow step whose
     * run is no longer active is never claimed: a run cancel that failed
     * part-way left it queued, and StrandedJobSweep cancels it.
     */
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
                ->andWhere(['not exists', StrandedJobSweep::endedRunOfJob()])
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
                ] + JobClaimSecrets::auditContext($job, $raw, $runner)
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
     * Password survey answers reach the runner as {@see JobClaimSecrets}
     * describes: in `secret_extra_vars` for a runner that reports
     * Runner::CAPABILITY_SECRET_EXTRA_VARS, inline in `command` (verbosity
     * pinned) for any other runner or when $runner is null. The stored
     * command shows the placeholder instead.
     *
     * The job's approval (checkClaimable()) and its inventory
     * (resolveInventory()) are checked first, so a job that must not run
     * fails before any credential or password answer is decrypted.
     *
     * @param Runner|null $runner the claiming runner
     * @throws JobClaimRefusedException when the job needs an approval it does
     *     not have, or its inventory is gone or names no hosts; nothing is
     *     decrypted or stored in that case
     * @throws CredentialResolutionException when a credential of the job was
     *     deleted or cannot be decrypted, or its password answers cannot be
     *     decrypted; nothing is stored in that case
     * @return array{job_id: int, project_path: string, playbook_path: string, scm_type: string, scm_url: string|null, scm_branch: string|null, scm_credential: array{credential_type: string, username: string|null, env_var_name: string|null, secrets: array<string, string>}|null, inventory_type: string, inventory_content: string|null, inventory_path: string|null, extra_vars: string|null, limit: string|null, verbosity: int, forks: int, become: bool, become_method: string, become_user: string, tags: string|null, skip_tags: string|null, check_mode: bool, timeout_minutes: int, credential: array{credential_type: string, username: string|null, env_var_name: string|null, secrets: array<string, string>}|null, credentials: list<array{credential_type: string, username: string|null, env_var_name: string|null, secrets: array<string, string>}>, vault_password_source: 'ansilume'|'repository', command: array<int, string>, secret_extra_vars?: array<string, mixed>}
     */
    public function buildExecutionPayload(Job $job, ?Runner $runner = null): array
    {
        $raw = $job->decodedRunnerPayload();
        // The approval and the inventory first: a job that must not run fails
        // before any credential is decrypted.
        $this->checkClaimable($job, $raw);
        $inventory = $this->resolveInventory($raw);
        // Then the credentials, so a job with an unusable credential fails before anything else.
        $credentials = $this->resolveTemplateCredentials($raw);
        $secrets = JobClaimSecrets::resolve(
            $job,
            isset($raw['extra_vars']) ? (string)$raw['extra_vars'] : null,
            (int)($raw['verbosity'] ?? 0),
            $runner
        );

        $projectPath = $this->resolveProjectPath($raw);
        $playbookPath = rtrim($projectPath, '/') . '/' . ltrim((string)($raw['playbook'] ?? 'site.yml'), '/');

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
            'extra_vars' => $secrets['extra_vars'],
            'limit' => isset($raw['limit']) ? (string)$raw['limit'] : null,
            'verbosity' => $secrets['verbosity'],
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
        $payload['command'] = [...$secrets['prefix'], ...$builder->build($payload)];

        $this->storeExecutionCommand(
            $job,
            [...$secrets['prefix'], ...$builder->build(['extra_vars' => $secrets['stored_extra_vars']] + $payload)]
        );

        if ($secrets['secret_extra_vars'] !== null) {
            $payload['secret_extra_vars'] = $secrets['secret_extra_vars'];
        }

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
     * Refuses a job that needs an approval it does not have (JobClaimGate):
     * its job template, the inventory it was launched with or the template's
     * current inventory has an approval rule, and its approval request is
     * neither approved nor timed out.
     *
     * @param array<string, mixed> $raw the job's decoded runner payload
     * @throws JobClaimRefusedException
     */
    protected function checkClaimable(Job $job, #[\SensitiveParameter] array $raw): void
    {
        (new JobClaimGate())->check($job, $raw);
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
    protected function resolveTemplateCredentials(#[\SensitiveParameter] array $raw): array
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
    protected function resolveProjectPath(#[\SensitiveParameter] array $payload): string
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
    protected function resolveProjectScm(#[\SensitiveParameter] array $payload): array
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
    protected function resolveVaultPasswordSource(#[\SensitiveParameter] array $payload): string
    {
        /** @var Project|null $project */
        $project = Project::findOne($payload['project_id'] ?? 0);
        $source = $project?->vault_password_source;

        return in_array($source, Project::VAULT_SOURCES, true) ? $source : Project::VAULT_SOURCE_ANSILUME;
    }

    /**
     * The hosts of the inventory the job was launched with, as the runner
     * gets them: a static inventory's content, or the source path of a file
     * or dynamic one in the project's checkout. It fails closed: a job whose
     * launch record names no inventory, whose inventory is gone, whose static
     * inventory has no content or whose file inventory names no source path
     * is refused, never run against the runner itself. A dynamic inventory
     * without a source path still runs: its repository names its inventory.
     *
     * @param array<string, mixed> $payload the job's decoded runner payload
     * @return array{type: string, content: string|null, path: string|null}
     * @throws JobClaimRefusedException (REASON_INVENTORY_NOT_RECORDED, _MISSING, _EMPTY, _NO_SOURCE)
     */
    protected function resolveInventory(#[\SensitiveParameter] array $payload): array
    {
        $inventoryId = JobInventory::idOf($payload);
        if ($inventoryId === null) {
            throw JobClaimRefusedException::inventoryNotRecorded();
        }
        /** @var Inventory|null $inventory */
        $inventory = Inventory::findOne($inventoryId);
        if ($inventory === null) {
            throw JobClaimRefusedException::inventoryMissing($inventoryId);
        }
        if ($inventory->inventory_type === Inventory::TYPE_STATIC) {
            $content = (string)$inventory->content;
            if (trim($content) === '') {
                throw JobClaimRefusedException::inventoryEmpty($inventory);
            }

            return ['type' => 'static', 'content' => $content, 'path' => null];
        }
        if ($inventory->inventory_type === Inventory::TYPE_FILE && trim((string)$inventory->source_path) === '') {
            throw JobClaimRefusedException::inventoryWithoutSource($inventory);
        }

        return ['type' => 'file', 'content' => null, 'path' => $inventory->source_path];
    }
}
