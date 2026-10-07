<?php

declare(strict_types=1);

namespace app\services;

use app\components\VaultCheckOutcome;
use app\components\VaultCheckRun;
use app\components\VaultCredentialRule;
use app\components\vault\VaultEnvelope;
use app\models\Credential;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\JobTemplateVaultCheck;
use app\models\Project;
use yii\base\Component;

/**
 * Checks, without decrypting, whether a job template's vault password opens
 * the encrypted files and values the template probably loads: group_vars/
 * and host_vars/ next to its inventory and playbook, the inventory source,
 * and the playbook's vars_files (TemplateVaultFiles). The vault envelope's
 * HMAC is verified with the password; plaintext is never produced, and
 * neither the password nor a key derived from it is stored.
 *
 * The result is informational and never blocks a job: the scan cannot know
 * which groups a play targets, so it may name files a run never loads.
 *
 * Checks run in runs (batch(), VaultCheckRun) that share a time budget:
 * repository content is untrusted, and a check must not hold a web request
 * or the queue worker for long. A template the run cannot finish is stored
 * as incomplete and goes first in the next run. VaultCheckOutcome decides
 * the status.
 *
 * @phpstan-import-type Outcome from VaultCheckOutcome
 */
class VaultCheckService extends Component
{
    /** Entries stored per template; unopened_count holds the full number. */
    public const STORED_UNOPENED = 40;

    /**
     * Seconds one run of checks may take. Templates the run does not finish
     * are stored as incomplete.
     */
    public float $timeBudget = 30.0;

    private ?VaultCheckRun $run = null;

    public function checkProject(Project $project): void
    {
        $this->checkTemplateIds(array_map('intval', JobTemplate::find()->select('id')->andWhere(['project_id' => $project->id])->column()));
    }

    /**
     * Ids of the templates that use the credential, as primary or additional.
     * Collect them before deleting a credential: the database detaches it.
     *
     * @return list<int>
     */
    public function templateIdsUsingCredential(Credential $credential): array
    {
        return array_map('intval', JobTemplate::find()->select('id')->andWhere(['or',
            ['credential_id' => $credential->id],
            ['id' => (new \yii\db\Query())->select('job_template_id')->from('{{%job_template_credential}}')->where(['credential_id' => $credential->id])],
        ])->column());
    }

    /**
     * @return list<int>
     */
    public function templateIdsUsingInventory(Inventory $inventory): array
    {
        return array_map('intval', JobTemplate::find()->select('id')->andWhere(['inventory_id' => $inventory->id])->column());
    }

    /**
     * Checks the templates in one run, those without a current check first.
     *
     * @param list<int> $ids
     */
    public function checkTemplateIds(array $ids): void
    {
        $this->batch(function () use ($ids): void {
            foreach (JobTemplateVaultCheck::stalestFirst($ids) as $id) {
                $template = JobTemplate::findOne($id);
                if ($template !== null) {
                    $this->checkTemplate($template);
                }
            }
        });
    }

    /**
     * Stores the template's check; removes it while the project has no scan.
     * Best effort: a failure is logged and never reaches the caller, whose
     * save or scan stands.
     */
    public function checkTemplate(JobTemplate $template): void
    {
        $this->batch(function () use ($template): void {
            try {
                $this->runCheck($template);
            } catch (\Throwable $e) {
                \Yii::warning("Vault check for job template #{$template->id} failed: " . $e->getMessage(), __CLASS__);
                $this->forget($template);
            }
        });
    }

    /**
     * Runs $work as one run of checks: every check inside it shares the time
     * budget and what the run read from checkouts. A call inside a run joins
     * it. Wrap loops that save many templates one by one (bulk assignment),
     * so their checks together take no longer than one run.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function batch(callable $work): mixed
    {
        if ($this->run !== null) {
            return $work();
        }
        $this->run = new VaultCheckRun($this->timeBudget);
        try {
            return $work();
        } finally {
            $this->run = null;
        }
    }

    /**
     * Removes the template's check after a failed check, so an outdated
     * result never stays behind.
     */
    private function forget(JobTemplate $template): void
    {
        try {
            JobTemplateVaultCheck::deleteAll(['job_template_id' => $template->id]);
        } catch (\Throwable $e) {
            \Yii::warning("Vault check for job template #{$template->id} could not be removed: " . $e->getMessage(), __CLASS__);
        }
    }

    private function runCheck(JobTemplate $template): void
    {
        /** @var Project|null $project */
        $project = Project::findOne($template->project_id);
        $root = $project === null ? null : $this->scans()->checkoutPath($project);
        if ($project === null || $project->vault_scanned_at === null || $root === null) {
            JobTemplateVaultCheck::deleteAll(['job_template_id' => $template->id]);
            return;
        }

        $run = $this->currentRun();
        $relevant = $run->relevantEntries($template, $project, $root);
        $vault = VaultCredentialRule::vaults($template->credentialSnapshot())[0] ?? null;
        $credential = $vault === null ? null : Credential::findOne($vault['id']);
        // The secret is decrypted only when there is something to check.
        $password = $relevant === [] || $credential === null ? null : $this->password($credential);
        $this->store($template, $project, $credential, count($relevant), VaultCheckOutcome::decide($run, $project, $relevant, $credential, $password, $root));
    }

    /**
     * The vault password, or null when the credential cannot be used.
     */
    private function password(Credential $credential): ?string
    {
        $service = $this->credentialService();
        if ($service->secretStatus($credential) !== CredentialService::SECRET_STATUS_OK) {
            return null;
        }
        $password = $service->getSecrets($credential)['vault_password'] ?? null;

        return is_string($password) && VaultEnvelope::normalizePassword($password) !== '' ? $password : null;
    }

    /**
     * @param Outcome $outcome
     */
    private function store(JobTemplate $template, Project $project, ?Credential $credential, int $relevantCount, array $outcome): void
    {
        $check = JobTemplateVaultCheck::findOne($template->id) ?? new JobTemplateVaultCheck(['job_template_id' => $template->id]);
        $check->status = $outcome['status'];
        $check->incomplete_reason = $outcome['reason'];
        $check->credential_id = $credential === null ? null : (int)$credential->id;
        $check->relevant_count = $relevantCount;
        $check->unopened_count = count($outcome['entries']);
        // Bounded, so the list fits its column; the count stays exact.
        $check->unopened = $outcome['entries'] === []
            ? null
            : (string)json_encode(array_slice($outcome['entries'], 0, self::STORED_UNOPENED), JSON_INVALID_UTF8_SUBSTITUTE);
        $check->checked_at = time();
        // The scan the check used: a check from an older scan reads as stale.
        $check->scanned_at = (int)$project->vault_scanned_at;
        $check->save(false);
    }

    private function currentRun(): VaultCheckRun
    {
        assert($this->run !== null, 'Vault checks run inside batch().');

        return $this->run;
    }

    private function scans(): VaultScanService
    {
        /** @var VaultScanService $service */
        $service = \Yii::$app->get('vaultScanService');

        return $service;
    }

    private function credentialService(): CredentialService
    {
        /** @var CredentialService $service */
        $service = \Yii::$app->get('credentialService');

        return $service;
    }
}
