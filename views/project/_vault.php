<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Project $project */
/** @var array{password_source: string, password_source_label: string, scanned_at: int|null, commit: string|null, error: string|null, truncated: bool, files_scanned: int, repository_settings: array<string, mixed>|null, findings: list<array{code: string, path: string|null, message: string}>, vault_ids: list<string>, entries: list<array{path: string, kind: string, line: int|null, key: string|null, vault_id: string|null, format_version: string|null, error: string|null}>, templates: list<array{id: int, name: string, status: string|null, incomplete_reason: string|null, credential: array{id: int, name: string|null}|null, relevant_count: int, unopened_count: int, unopened: list<array{path: string, line: int|null, key: string|null, reason: string|null}>, checked_at: int|null}>, runners_without_support: list<array{id: int, name: string, group: string|null, software_version: string|null}>, runners_without_support_count: int} $vault */

use app\helpers\ConfirmHelper;
use app\models\JobTemplateVaultCheck;
use app\models\Project;
use yii\helpers\Html;
use yii\helpers\Url;

$statusLabels = [
    JobTemplateVaultCheck::STATUS_OK => ['opens all', 'success'],
    JobTemplateVaultCheck::STATUS_MISMATCH => ['does not open all', 'danger'],
    JobTemplateVaultCheck::STATUS_MISSING_PASSWORD => ['no vault password', 'danger'],
    JobTemplateVaultCheck::STATUS_UNUSABLE_PASSWORD => ['password unusable', 'danger'],
    JobTemplateVaultCheck::STATUS_REPO_MANAGED => ['repository supplies passwords', 'secondary'],
    JobTemplateVaultCheck::STATUS_NO_FILES => ['no encrypted files', 'light'],
    JobTemplateVaultCheck::STATUS_STALE => ['rescan needed', 'warning'],
    JobTemplateVaultCheck::STATUS_DAMAGED => ['damaged vault files', 'danger'],
    JobTemplateVaultCheck::STATUS_INCOMPLETE => ['not fully checked', 'warning'],
];
$incompleteReasons = [
    JobTemplateVaultCheck::REASON_SCAN_LIMIT => 'the scan stopped at a limit',
    JobTemplateVaultCheck::REASON_TIME_LIMIT => 'the check ran out of time',
    JobTemplateVaultCheck::REASON_FILE_NAME => 'file names that are not UTF-8:',
];
$canRescan = (bool)\Yii::$app->user->can('project.update');
?>
<div class="row g-3 mt-1" id="vault">
    <div class="col-12">
        <div class="card" data-testid="project-vault">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Vault files <small class="text-muted fw-normal">(checked without decrypting)</small></span>
                <span>
                    <?php if ($vault['scanned_at'] !== null) : ?>
                        <small class="text-muted" data-testid="project-vault-scanned-at">
                            <?= Html::encode('scanned ' . date('Y-m-d H:i', $vault['scanned_at']) . ($vault['commit'] !== null ? ' at ' . substr($vault['commit'], 0, 12) : '')) ?>
                        </small>
                    <?php endif; ?>
                    <?php if ($canRescan) : ?>
                        <form method="post" action="<?= Html::encode(Url::to(['/project-vault/scan', 'id' => $project->id])) ?>" style="display:inline"
                              onsubmit="<?= ConfirmHelper::attribute('Rescan the checkout of "' . $project->name . '" for vault files?') ?>">
                            <input type="hidden" name="<?= Html::encode(\Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(\Yii::$app->request->getCsrfToken()) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary ms-2" data-testid="project-vault-rescan">Rescan</button>
                        </form>
                    <?php endif; ?>
                </span>
            </div>
            <div class="card-body">
                <p class="small mb-2">
                    <?= Html::encode('Vault passwords on runners: ' . $vault['password_source_label'] . '. ') ?>
                    <?php if ($vault['password_source'] === Project::VAULT_SOURCE_ANSILUME) : ?>
                        <span class="text-muted">Runners use only the vault password of the job template; the vault settings of the repository's ansible.cfg are ignored.</span>
                    <?php else : ?>
                        <span class="text-muted">Runners also apply the vault settings of the repository's ansible.cfg.</span>
                    <?php endif; ?>
                </p>

                <?php if ($vault['runners_without_support'] !== []) : ?>
                    <div class="alert alert-warning small" data-testid="project-vault-runner-warning">
                        These runners are older than 2.8 and apply the repository's ansible.cfg vault settings anyway; update their image:
                        <?= Html::encode(implode(', ', array_map(
                            static fn (array $runner): string => $runner['name'] . ' (' . ($runner['group'] ?? '?') . ', ' . ($runner['software_version'] ?? 'unknown version') . ')',
                            $vault['runners_without_support']
                        ))) ?>
                    </div>
                <?php elseif ($vault['runners_without_support_count'] > 0) : ?>
                    <div class="alert alert-warning small" data-testid="project-vault-runner-warning">
                        <?= Html::encode($vault['runners_without_support_count'] . ' runner(s) of this project\'s runner groups are older than 2.8 and apply the repository\'s ansible.cfg vault settings anyway; ask an administrator to update them.') ?>
                    </div>
                <?php endif; ?>

                <?php if ($vault['error'] !== null) : ?>
                    <div class="alert alert-secondary small mb-0" data-testid="project-vault-error"><?= Html::encode($vault['error']) ?></div>
                <?php elseif ($vault['scanned_at'] === null) : ?>
                    <p class="text-muted small mb-0" data-testid="project-vault-never-scanned">
                        <?php if ($project->scm_type === Project::SCM_TYPE_MANUAL) : ?>
                            Not scanned yet. Manual projects are scanned when they are saved or on "Rescan".
                        <?php else : ?>
                            Not scanned yet. The next sync scans the checkout<?= $canRescan ? ', or click "Rescan"' : '' // xss-ok: literal?>.
                        <?php endif; ?>
                    </p>
                <?php else : ?>
                    <?php foreach ($vault['findings'] as $finding) : ?>
                        <div class="alert alert-warning small py-2" data-testid="project-vault-finding" data-code="<?= Html::encode($finding['code']) ?>">
                            <?= Html::encode(($finding['path'] !== null ? $finding['path'] . ': ' : '') . $finding['message']) ?>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($vault['entries'] === []) : ?>
                        <p class="text-muted small" data-testid="project-vault-no-entries">No encrypted files or inline vault values in the checkout.</p>
                    <?php else : ?>
                        <p class="small mb-1">
                            <?= Html::encode(count($vault['entries']) . ' encrypted files and inline values' . ($vault['vault_ids'] !== [] ? '; vault IDs: ' . implode(', ', $vault['vault_ids']) : '') . '.') ?>
                        </p>
                        <div class="table-responsive">
                            <table class="table table-sm small mb-3" id="project-vault-entries">
                                <thead class="table-light"><tr><th>File</th><th>Kind</th><th>Vault ID</th><th>Problem</th></tr></thead>
                                <tbody>
                                <?php foreach ($vault['entries'] as $entry) : ?>
                                    <tr data-testid="project-vault-entry">
                                        <td><code><?= Html::encode($entry['path'] . ($entry['line'] !== null ? ':' . $entry['line'] : '')) ?></code></td>
                                        <td><?= Html::encode($entry['kind'] === 'inline' ? 'inline value' . ($entry['key'] !== null ? ' ' . $entry['key'] : '') : 'encrypted file') ?></td>
                                        <td><?= Html::encode($entry['vault_id'] ?? 'default') ?></td>
                                        <td class="text-danger"><?= Html::encode($entry['error'] ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php if ($vault['templates'] !== []) : ?>
                        <table class="table table-sm small mb-0" id="project-vault-templates">
                            <thead class="table-light"><tr><th>Job template</th><th>Vault password</th><th>Fit</th></tr></thead>
                            <tbody>
                            <?php foreach ($vault['templates'] as $row) : ?>
                                <?php [$label, $color] = $statusLabels[$row['status'] ?? ''] ?? ['not checked', 'light']; ?>
                                <tr data-testid="project-vault-template" data-status="<?= Html::encode($row['status'] ?? 'unchecked') ?>">
                                    <td><?= Html::a(Html::encode($row['name']), ['/job-template/view', 'id' => $row['id']]) ?></td>
                                    <td><?= Html::encode($row['credential'] === null ? '—' : ($row['credential']['name'] ?? 'vault password #' . $row['credential']['id'])) ?></td>
                                    <td>
                                        <span class="badge text-bg-<?= Html::encode($color) ?>"><?= Html::encode($label) ?></span>
                                        <?php if ($row['incomplete_reason'] !== null) : ?>
                                            <span class="text-muted" data-testid="project-vault-incomplete-reason"><?= Html::encode($incompleteReasons[$row['incomplete_reason']] ?? $row['incomplete_reason']) ?></span>
                                        <?php endif; ?>
                                        <?php if ($row['unopened'] !== []) : ?>
                                            <?php $more = $row['unopened_count'] - count($row['unopened']); ?>
                                            <span class="text-muted"><?= Html::encode(implode(', ', array_map(
                                                static fn (array $entry): string => $entry['path'] . ($entry['line'] !== null ? ':' . $entry['line'] : '')
                                                    . ($entry['reason'] === JobTemplateVaultCheck::ENTRY_VAULT_ID_MATCH ? ' (vault_id_match)' : ''),
                                                $row['unopened']
                                            )) . ($more > 0 ? " and {$more} more" : '')) ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
