<?php

declare(strict_types=1);

namespace app\services;

use app\components\vault\AnsibleCfgVaultSettings;
use app\models\JobTemplate;
use app\models\JobTemplateVaultCheck;
use app\models\Project;
use app\models\ProjectVaultEntry;
use app\models\Runner;
use yii\base\Component;

/**
 * The vault overview of a project, for the project page and the REST API:
 * the last scan (encrypted files and inline values, vault IDs, the vault
 * settings of the repository's ansible.cfg, findings), how each job template
 * fits it, and, in 'Ansilume only' mode, the runners that are too old to
 * honour that setting. Nothing in it is secret.
 *
 * Project access is the caller's check. Details that belong to other areas
 * follow their permission: runner names, groups and versions need
 * runner-group.view (otherwise only their number), the names of vault
 * passwords credential.view or job-template.view (otherwise only the id).
 *
 * @phpstan-type Settings array{vault_password_file: string|null, vault_identity_list: string|null, ask_vault_pass: bool, vault_id_match: bool, vault_encrypt_salt: bool}
 * @phpstan-type Finding array{code: string, path: string|null, message: string}
 * @phpstan-type Entry array{path: string, kind: string, line: int|null, key: string|null, vault_id: string|null, format_version: string|null, error: string|null}
 * @phpstan-type TemplateFit array{id: int, name: string, status: string|null, incomplete_reason: string|null, credential: array{id: int, name: string|null}|null, relevant_count: int, unopened_count: int, unopened: list<array{path: string, line: int|null, key: string|null, reason: string|null}>, checked_at: int|null}
 * @phpstan-type OutdatedRunner array{id: int, name: string, group: string|null, software_version: string|null}
 * @phpstan-type Overview array{password_source: string, password_source_label: string, scanned_at: int|null, commit: string|null, error: string|null, truncated: bool, files_scanned: int, repository_settings: Settings|null, findings: list<Finding>, vault_ids: list<string>, entries: list<Entry>, templates: list<TemplateFit>, runners_without_support: list<OutdatedRunner>, runners_without_support_count: int}
 */
class VaultOverviewService extends Component
{
    /**
     * @param callable(string): bool $can whether the viewer holds a permission
     * @return Overview
     */
    public function forProject(Project $project, callable $can): array
    {
        $summary = json_decode((string)$project->vault_scan_summary, true);
        $summary = is_array($summary) ? $summary : [];
        $entries = array_values(array_map(static fn (ProjectVaultEntry $entry): array => self::entry($entry), $project->vaultEntries));
        $outdated = $this->runnersWithoutSupport($project);

        return [
            'password_source' => (string)$project->vault_password_source,
            'password_source_label' => Project::vaultSourceLabel((string)$project->vault_password_source),
            'scanned_at' => $project->vault_scanned_at === null ? null : (int)$project->vault_scanned_at,
            'commit' => $project->vault_scan_commit,
            'error' => $project->vault_scan_error,
            'truncated' => ($summary['truncated'] ?? false) === true,
            'files_scanned' => is_int($summary['files_scanned'] ?? null) ? $summary['files_scanned'] : 0,
            'repository_settings' => is_array($summary['cfg'] ?? null) ? AnsibleCfgVaultSettings::fromArray($summary['cfg'])->toArray() : null,
            'findings' => self::findings($summary['findings'] ?? null),
            'vault_ids' => self::vaultIds($entries),
            'entries' => $entries,
            'templates' => $this->templates($project, $can('credential.view') || $can('job-template.view')),
            'runners_without_support' => $can('runner-group.view') ? $outdated : [],
            'runners_without_support_count' => count($outdated),
        ];
    }

    /**
     * @return Entry
     */
    private static function entry(ProjectVaultEntry $entry): array
    {
        return [
            'path' => $entry->path,
            'kind' => $entry->kind,
            'line' => $entry->line === null ? null : (int)$entry->line,
            'key' => $entry->var_key,
            'vault_id' => $entry->vault_id,
            'format_version' => $entry->format_version,
            'error' => $entry->error,
        ];
    }

    /**
     * @return list<Finding>
     */
    private static function findings(mixed $stored): array
    {
        if (!is_array($stored)) {
            return [];
        }
        $findings = [];
        foreach ($stored as $finding) {
            if (is_array($finding) && is_string($finding['code'] ?? null) && is_string($finding['message'] ?? null)) {
                $findings[] = [
                    'code' => $finding['code'],
                    'path' => is_string($finding['path'] ?? null) ? $finding['path'] : null,
                    'message' => $finding['message'],
                ];
            }
        }

        return $findings;
    }

    /**
     * Vault IDs from format 1.2 headers; format 1.1 files carry none and
     * count as 'default' for Ansible.
     *
     * @param list<Entry> $entries
     * @return list<string>
     */
    private static function vaultIds(array $entries): array
    {
        $ids = array_values(array_unique(array_filter(array_column($entries, 'vault_id'), static fn (?string $id): bool => $id !== null && $id !== '')));
        sort($ids);

        return $ids;
    }

    /**
     * @param bool $credentialNames whether the viewer may see the names of vault passwords
     * @return list<TemplateFit>
     */
    private function templates(Project $project, bool $credentialNames): array
    {
        $templates = JobTemplate::find()
            ->andWhere(['project_id' => $project->id])
            ->with(['vaultCheck.credential'])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        return array_values(array_map(static function (JobTemplate $template) use ($project, $credentialNames): array {
            $check = $template->vaultCheck;
            $credential = $check?->credential;
            // A check from an older scan is stale; its details no longer apply.
            $current = $check !== null && $check->isCurrentFor($project) ? $check : null;

            return [
                'id' => (int)$template->id,
                'name' => (string)$template->name,
                'status' => $check?->effectiveStatus($project),
                'incomplete_reason' => $current?->incomplete_reason,
                'credential' => $credential === null ? null : ['id' => (int)$credential->id, 'name' => $credentialNames ? (string)$credential->name : null],
                'relevant_count' => $check === null ? 0 : (int)$check->relevant_count,
                'unopened_count' => (int)$current?->unopened_count,
                'unopened' => $current?->unopenedEntries() ?? [],
                'checked_at' => $check === null ? null : (int)$check->checked_at,
            ];
        }, $templates));
    }

    /**
     * Runners of the groups the project's templates use that do not report
     * the capability, so they apply the repository's ansible.cfg vault
     * settings anyway. Only in 'Ansilume only' mode.
     *
     * @return list<OutdatedRunner>
     */
    private function runnersWithoutSupport(Project $project): array
    {
        if ($project->vault_password_source !== Project::VAULT_SOURCE_ANSILUME) {
            return [];
        }
        $groupIds = JobTemplate::find()->select('runner_group_id')->distinct()->andWhere(['project_id' => $project->id])->column();
        $runners = Runner::find()->with('group')->where(['runner_group_id' => $groupIds])->orderBy(['name' => SORT_ASC])->all();
        $outdated = array_filter($runners, static fn (Runner $runner): bool => !$runner->supports(Runner::CAPABILITY_VAULT_PASSWORD_SOURCE));

        return array_values(array_map(static fn (Runner $runner): array => [
            'id' => (int)$runner->id,
            'name' => (string)$runner->name,
            'group' => $runner->group->name ?? null,
            'software_version' => $runner->software_version,
        ], $outdated));
    }
}
