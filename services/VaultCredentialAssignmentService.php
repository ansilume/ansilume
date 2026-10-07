<?php

declare(strict_types=1);

namespace app\services;

use app\components\VaultAssignmentResult;
use app\components\VaultCredentialRule;
use app\models\Credential;
use app\models\JobTemplate;
use yii\base\Component;

/**
 * Assigns one vault password to several job templates at once.
 *
 * A template that holds another vault password gets it replaced in place
 * (the primary slot stays primary); any further vault password is detached,
 * so every template ends up with exactly this one. Each template is saved on
 * its own through JobTemplateCredentialService, which validates the template
 * and writes the audit entry. Jobs that already wait keep the credentials
 * they were launched with.
 *
 * The request is checked first, and nothing is written when it is invalid:
 * the credential must be a vault password with a usable secret, and the
 * caller must be allowed to change every listed template.
 */
class VaultCredentialAssignmentService extends Component
{
    public const MAX_TEMPLATES = 500;

    public const STATE_NONE = 'none';
    public const STATE_THIS = 'this';
    public const STATE_OTHER = 'other';
    public const STATE_MULTIPLE = 'multiple';

    /**
     * The templates the caller may change, with their vault passwords.
     *
     * @return list<array{id: int, name: string, project_id: int, project_name: string|null, current: string, vaults: list<array{id: int, name: string}>}>
     */
    public function candidates(Credential $vault, ?int $userId): array
    {
        $query = JobTemplate::find()
            ->with(['project', 'credential', 'jobTemplateCredentials.credential'])
            ->orderBy(['job_template.name' => SORT_ASC]);
        $filter = $this->checker()->buildChildOperateFilter($userId, 'job_template.project_id');
        if ($filter !== null) {
            $query->andWhere($filter);
        }

        $rows = [];
        /** @var JobTemplate $template */
        foreach ($query->all() as $template) {
            $vaults = VaultCredentialRule::vaults($template->credentialSnapshot());
            $rows[] = [
                'id' => (int)$template->id,
                'name' => (string)$template->name,
                'project_id' => (int)$template->project_id,
                'project_name' => $template->project->name ?? null,
                'current' => self::state($vaults, (int)$vault->id),
                'vaults' => array_map(static fn (array $entry): array => ['id' => $entry['id'], 'name' => (string)$entry['name']], $vaults),
            ];
        }

        return $rows;
    }

    /**
     * @param array<array-key, mixed> $templateIds
     * @param array<string, mixed> $auditContext
     * @throws VaultAssignmentException when the request is invalid; nothing is written then
     */
    public function assign(Credential $vault, array $templateIds, ?int $userId, array $auditContext = []): VaultAssignmentResult
    {
        $this->checkVault($vault);
        $items = [];
        foreach ($this->checkedTemplates($this->checkedIds($templateIds), $userId) as $template) {
            $items[] = $this->assignTo($vault, $template, $auditContext);
        }

        return new VaultAssignmentResult((int)$vault->id, (string)$vault->name, $items);
    }

    private function checkVault(Credential $vault): void
    {
        if ($vault->credential_type !== Credential::TYPE_VAULT) {
            throw new VaultAssignmentException('Only vault passwords can be assigned to several job templates at once.', 422);
        }
        if ($this->credentialService()->secretStatus($vault) !== CredentialService::SECRET_STATUS_OK) {
            throw new VaultAssignmentException(
                'The vault password has no usable secret, so jobs would fail. Enter it on the credential page first.',
                422
            );
        }
    }

    /**
     * @param array<array-key, mixed> $templateIds
     * @return list<int>
     */
    private function checkedIds(array $templateIds): array
    {
        $ids = [];
        foreach (array_is_list($templateIds) ? $templateIds : [false] as $value) {
            // Integers and digit strings only: filter_var() alone takes true and 1.0 for 1.
            $id = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            if ($id === false) {
                $ids = [];
                break;
            }
            $ids[$id] = $id;
        }
        if ($ids === []) {
            throw new VaultAssignmentException('job_template_ids must be a non-empty list of job template IDs.', 422);
        }
        if (count($ids) > self::MAX_TEMPLATES) {
            throw new VaultAssignmentException('At most ' . self::MAX_TEMPLATES . ' job templates can be assigned at once.', 422);
        }

        return array_values($ids);
    }

    /**
     * The templates in request order. Templates the caller cannot see, and
     * deleted ones, are reported as missing, so other teams' templates stay
     * hidden.
     *
     * @param list<int> $ids
     * @return list<JobTemplate>
     */
    private function checkedTemplates(array $ids, ?int $userId): array
    {
        $checker = $this->checker();
        // andWhere(): where() would replace the scope that hides deleted templates.
        $visible = JobTemplate::find()->andWhere(['job_template.id' => $ids])->indexBy('id');
        $viewFilter = $checker->buildChildResourceFilter($userId, 'job_template.project_id');
        if ($viewFilter !== null) {
            $visible->andWhere($viewFilter);
        }
        /** @var array<int, JobTemplate> $found */
        $found = $visible->all();
        $missing = array_values(array_diff($ids, array_keys($found)));
        if ($missing !== []) {
            throw new VaultAssignmentException('Job template(s) not found: #' . implode(', #', $missing) . '.', 422, $missing);
        }
        $denied = array_values(array_filter(
            $ids,
            static fn (int $id): bool => $userId === null || !$checker->canOperateChildResource($userId, $found[$id]->project_id)
        ));
        if ($denied !== []) {
            throw new VaultAssignmentException('You may not change job template(s) #' . implode(', #', $denied) . '.', 403, $denied);
        }

        return array_map(static fn (int $id): JobTemplate => $found[$id], $ids);
    }

    /**
     * @param array<string, mixed> $auditContext
     * @return array{job_template_id: int, name: string, status: string, replaced: list<array{id: int, name: string}>, error: string|null}
     */
    private function assignTo(Credential $vault, JobTemplate $template, array $auditContext): array
    {
        $vaultId = (int)$vault->id;
        $snapshot = $template->credentialSnapshot();
        $vaults = VaultCredentialRule::vaults($snapshot);
        $item = ['job_template_id' => (int)$template->id, 'name' => (string)$template->name, 'status' => VaultAssignmentResult::UNCHANGED, 'replaced' => [], 'error' => null];
        if (self::state($vaults, $vaultId) === self::STATE_THIS) {
            return $item;
        }

        $replaced = array_values(array_map(
            static fn (array $entry): array => ['id' => $entry['id'], 'name' => (string)$entry['name']],
            array_filter($vaults, static fn (array $entry): bool => $entry['id'] !== $vaultId)
        ));
        [$primary, $additional] = self::reassigned($snapshot, $vaults, $vaultId);
        $template->credential_id = $primary;
        $saved = $this->templateCredentials()->saveWithCredentials($template, $additional, $auditContext + [
            'vault_assignment' => ['credential_id' => $vaultId, 'replaced' => array_column($replaced, 'id')],
        ]);
        if (!$saved) {
            return array_merge($item, ['status' => VaultAssignmentResult::FAILED, 'error' => implode(' ', $template->getFirstErrors())]);
        }

        return array_merge($item, ['status' => $replaced === [] ? VaultAssignmentResult::ASSIGNED : VaultAssignmentResult::REPLACED, 'replaced' => $replaced]);
    }

    /**
     * The template's credentials with $vaultId in the slot of its first
     * vault password (appended when it has none) and no other vault password.
     *
     * @param list<array{id: int, name: string, credential_type: string, role: string}> $snapshot
     * @param list<array{id: int, name: string|null, credential_type: string|null}> $vaults
     * @return array{0: int|null, 1: list<int>} primary and additional ids
     */
    private static function reassigned(array $snapshot, array $vaults, int $vaultId): array
    {
        $firstVaultId = $vaults[0]['id'] ?? null;
        $primary = null;
        $additional = [];
        foreach ($snapshot as $entry) {
            $id = $entry['credential_type'] !== Credential::TYPE_VAULT ? $entry['id'] : ($entry['id'] === $firstVaultId ? $vaultId : null);
            if ($id !== null && $entry['role'] === Credential::ROLE_PRIMARY) {
                $primary = $id;
            } elseif ($id !== null) {
                $additional[] = $id;
            }
        }
        if ($firstVaultId === null) {
            $additional[] = $vaultId;
        }

        return [$primary, array_values(array_unique(array_filter($additional, static fn (int $id): bool => $id !== $primary)))];
    }

    /**
     * @param list<array{id: int, name: string|null, credential_type: string|null}> $vaults
     */
    private static function state(array $vaults, int $vaultId): string
    {
        return match (true) {
            $vaults === [] => self::STATE_NONE,
            count($vaults) > 1 => self::STATE_MULTIPLE,
            $vaults[0]['id'] === $vaultId => self::STATE_THIS,
            default => self::STATE_OTHER,
        };
    }

    private function checker(): ProjectAccessChecker
    {
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');

        return $checker;
    }

    private function templateCredentials(): JobTemplateCredentialService
    {
        /** @var JobTemplateCredentialService $service */
        $service = \Yii::$app->get('jobTemplateCredentialService');

        return $service;
    }

    private function credentialService(): CredentialService
    {
        /** @var CredentialService $service */
        $service = \Yii::$app->get('credentialService');

        return $service;
    }
}
