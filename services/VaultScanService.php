<?php

declare(strict_types=1);

namespace app\services;

use app\components\vault\AnsibleCfgVaultReader;
use app\components\vault\GitHeadReader;
use app\components\vault\VaultContentScanner;
use app\components\vault\VaultFindings;
use app\components\vault\VaultScanEntry;
use app\components\vault\VaultScanResult;
use app\models\AuditLog;
use app\models\Project;
use app\models\ProjectVaultEntry;
use yii\base\Component;

/**
 * Scans a project checkout for ansible-vault content without decrypting:
 * encrypted files, inline !vault values, the vault settings of the
 * repository's ansible.cfg and findings such as a committed password file.
 * The result replaces the project's previous scan; afterwards every job
 * template of the project is checked against it (VaultCheckService).
 *
 * Runs after each sync and on demand. Nothing it stores is secret: paths,
 * vault IDs and fingerprints of the encrypted content.
 */
class VaultScanService extends Component
{
    /**
     * True when the checkout was scanned. False when there is none or the
     * scan failed; the reason is then stored and the previous entries are
     * removed, so an old scan never passes for a current one.
     */
    public function scanProject(Project $project): bool
    {
        $root = $this->checkoutPath($project);
        if ($root === null || !is_dir($root)) {
            $this->storeFailure($project, self::missingCheckout($project));
            return false;
        }
        try {
            $this->scanAndStore($project, $root);
        } catch (\Throwable $e) {
            \Yii::warning("Vault scan of project #{$project->id} failed: " . $e->getMessage(), __CLASS__);
            $this->storeFailure($project, 'The scan could not be completed; the application log has the details.');
            return false;
        }
        $this->checks()->checkProject($project);

        return true;
    }

    /**
     * After a project was created or updated: audits a change of the vault
     * password source ($previousSource null for a new project) and scans
     * manual projects, which never sync.
     *
     * @param array<string, mixed> $auditContext
     */
    public function afterProjectSave(Project $project, ?string $previousSource, array $auditContext = []): void
    {
        $manual = $project->scm_type === Project::SCM_TYPE_MANUAL;
        // The scan of a manual project checks every template anyway.
        if ($previousSource !== null && $this->auditPasswordSourceChange($project, $previousSource, $auditContext) && !$manual) {
            $this->checks()->checkProject($project);
        }
        if ($manual) {
            $this->scanProject($project);
        }
    }

    /**
     * Audits a change of the project's vault password source and re-checks
     * its templates: in 'Ansilume and repository' mode the repository may
     * supply passwords Ansilume cannot check. Call after the project saved.
     *
     * @param array<string, mixed> $auditContext
     */
    public function recordPasswordSourceChange(Project $project, string $previous, array $auditContext = []): void
    {
        if ($this->auditPasswordSourceChange($project, $previous, $auditContext)) {
            $this->checks()->checkProject($project);
        }
    }

    /**
     * @param array<string, mixed> $auditContext
     * @return bool whether the source changed
     */
    private function auditPasswordSourceChange(Project $project, string $previous, array $auditContext): bool
    {
        if ($previous === $project->vault_password_source) {
            return false;
        }
        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_PROJECT_VAULT_SOURCE_CHANGED,
            'project',
            (int)$project->id,
            null,
            ['name' => $project->name, 'from' => $previous, 'to' => $project->vault_password_source] + $auditContext
        );

        return true;
    }

    /**
     * The checkout the server reads: the workspace clone of a git project,
     * the local path of a manual one.
     */
    public function checkoutPath(Project $project): ?string
    {
        if ($project->scm_type === Project::SCM_TYPE_MANUAL) {
            $stored = (string)$project->local_path;
            if ($stored === '') {
                return null;
            }
            // Like the project page: a local path may be a Yii alias such as @runtime/...
            $resolved = \Yii::getAlias($stored, false);

            return is_string($resolved) ? $resolved : $stored;
        }

        return $this->projectService()->localPath($project);
    }

    protected function scanner(): VaultContentScanner
    {
        return new VaultContentScanner();
    }

    private function scanAndStore(Project $project, string $root): void
    {
        $scan = $this->scanner()->scan($root);
        $cfg = AnsibleCfgVaultReader::read($root);
        $summary = [
            'cfg' => $cfg->toArray(),
            'findings' => VaultFindings::collect($root, $cfg, $scan),
            'truncated' => $scan->truncated,
            'files_scanned' => $scan->filesScanned,
            'entries' => count($scan->entries),
        ];
        $this->store($project, $scan, $summary, GitHeadReader::sha($root));
    }

    private static function missingCheckout(Project $project): string
    {
        if ($project->scm_type !== Project::SCM_TYPE_MANUAL) {
            return 'There is no checkout to scan yet. Sync the project first.';
        }
        if ((string)$project->local_path === '') {
            return 'This manual project has no local path, so there is nothing to scan. Set its local path in the project settings.';
        }

        return 'The local path is not a directory the server can read: ' . (string)$project->local_path;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function store(Project $project, VaultScanResult $scan, array $summary, ?string $commit): void
    {
        $transaction = \Yii::$app->db->beginTransaction();
        try {
            ProjectVaultEntry::deleteAll(['project_id' => $project->id]);
            $rows = array_map(static fn (VaultScanEntry $entry): array => self::row((int)$project->id, $entry), $scan->entries);
            if ($rows !== []) {
                \Yii::$app->db->createCommand()->batchInsert(
                    ProjectVaultEntry::tableName(),
                    ['project_id', 'path', 'kind', 'line', 'var_key', 'vault_id', 'format_version', 'fingerprint', 'error'],
                    $rows
                )->execute();
            }
            $project->vault_scanned_at = time();
            $project->vault_scan_commit = $commit;
            $project->vault_scan_summary = (string)json_encode($summary, JSON_INVALID_UTF8_SUBSTITUTE);
            $project->vault_scan_error = null;
            $project->save(false, ['vault_scanned_at', 'vault_scan_commit', 'vault_scan_summary', 'vault_scan_error']);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    private function storeFailure(Project $project, string $reason): void
    {
        $transaction = \Yii::$app->db->beginTransaction();
        try {
            ProjectVaultEntry::deleteAll(['project_id' => $project->id]);
            $project->vault_scanned_at = null;
            $project->vault_scan_commit = null;
            $project->vault_scan_summary = null;
            $project->vault_scan_error = $reason;
            $project->save(false, ['vault_scanned_at', 'vault_scan_commit', 'vault_scan_summary', 'vault_scan_error']);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        $this->checks()->checkProject($project);
    }

    /**
     * @return list<int|string|null>
     */
    private static function row(int $projectId, VaultScanEntry $entry): array
    {
        // File names and keys come from the repository and need not be UTF-8;
        // MySQL rejects such bytes, so they are replaced. Such a file cannot
        // be found again under the replaced name, so it is not checked.
        $error = mb_check_encoding($entry->path, 'UTF-8') ? $entry->error : ProjectVaultEntry::ERROR_NAME_NOT_UTF8;

        return [
            $projectId,
            mb_substr(mb_scrub($entry->path, 'UTF-8'), 0, 1024),
            $entry->kind,
            $entry->line,
            $entry->key === null ? null : mb_substr(mb_scrub($entry->key, 'UTF-8'), 0, 255),
            $entry->vaultId === null ? null : mb_substr(mb_scrub($entry->vaultId, 'UTF-8'), 0, 255),
            $entry->version === null ? null : mb_substr(mb_scrub($entry->version, 'UTF-8'), 0, 8),
            $entry->fingerprint,
            $error === null ? null : mb_substr($error, 0, 255),
        ];
    }

    private function checks(): VaultCheckService
    {
        /** @var VaultCheckService $service */
        $service = \Yii::$app->get('vaultCheckService');

        return $service;
    }

    private function projectService(): ProjectService
    {
        /** @var ProjectService $service */
        $service = \Yii::$app->get('projectService');

        return $service;
    }
}
