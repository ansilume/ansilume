<?php

declare(strict_types=1);

namespace app\services;

use app\components\CredentialAttachmentDiff;
use app\components\SubmittedId;
use app\components\VaultCredentialRule;
use app\models\AuditLog;
use app\models\Credential;
use app\models\JobTemplate;
use yii\base\Component;

/**
 * Saves a job template together with its credentials, for the web UI and
 * the REST API alike.
 *
 * Precedence: the primary credential (job_template.credential_id) first,
 * then the additional ones in the given order (pivot sort_order). The
 * template row and the pivot rows change in one transaction, and the audit
 * entry records which credentials were attached or detached.
 *
 * A template holds at most one vault password ({@see VaultCredentialRule}).
 * A save whose final set holds more is rejected, also when it keeps the
 * stored credentials of an older template that has two. After a save the
 * vault password is checked against the project's last vault scan
 * ({@see VaultCheckService}).
 *
 * What a gated job template runs is for admins to change, and so is giving
 * a job template an inventory with an approval rule
 * ({@see GatedJobTemplateLock}): a save the lock refuses writes nothing and
 * returns false with the lock's reason among the template's errors
 * (GatedJobTemplateLock::refused(), refusalOf()). The lock is asked before
 * validation and again inside the save's transaction, where
 * JobTemplateRuleAssignment::persistVetoes() then checks the pair of own
 * rule and inventory that the save stores (a column it writes as the
 * template holds it, the other one as the locked row stores it) when the
 * save creates the template or changes either: an own rule that differs
 * from the inventory's (JobTemplateRuleAssignment::RULES_DIFFER, an error of
 * approval_rule_id) and an inventory deleted after the validation
 * (JobTemplateRuleAssignment::INVENTORY_NOT_FOUND, an error of inventory_id,
 * instead of the foreign key's error) return false, with nothing written.
 *
 * Lock order (C16) of the save's transaction: the template row FOR UPDATE as
 * its first statement (an existing template), then in one statement the
 * stored and the new inventory LOCK IN SHARE MODE in ascending ID order
 * (JobTemplateGate::readInventoryRules()); a new template share-locks its
 * one inventory first. No later statement locks another inventory row. A
 * launch takes the same order (JobLaunchService); an inventory save locks
 * only its own row (InventoryWriteService); a project delete can deadlock
 * with a save that moves a template into that project (GatedJobTemplateLock
 * names the exception). A save without a user (a console run) asks no lock,
 * so its persist veto may share-lock the inventory before its UPDATE locks
 * the template row; no production caller saves so.
 *
 * A clone (copyWithCredentials()) asks no lock: it keeps its source's rule
 * and inventory, and so the lock. Its validation checks RULES_DIFFER without
 * a lock; the launch refuses what a race leaves.
 */
class JobTemplateCredentialService extends Component
{
    /** The error of credential_ids (422, or the form's error) when one of its entries is no positive ID. */
    public const INVALID_CREDENTIAL_IDS = 'credential_ids must contain positive integer credential IDs.';

    /** The error of credential_ids when one of its entries names no credential; sprintf(): the ID. */
    public const CREDENTIAL_NOT_FOUND = 'Credential #%d does not exist.';

    /**
     * @param list<mixed>|null $additionalIds additional credential ids in
     *        precedence order; null keeps the current additional credentials
     * @param array<string, mixed> $auditContext
     * @param int|null $actorId the user who saves, whom GatedJobTemplateLock
     *        may refuse; null: the signed-in user, whom the audit entry names
     *        (console runs have none)
     */
    public function saveWithCredentials(JobTemplate $template, ?array $additionalIds, array $auditContext = [], ?int $actorId = null): bool
    {
        $isNew = $template->isNewRecord;
        $lock = new GatedJobTemplateLock();
        // Before validation: a refused change gets that answer, not a validation error.
        if ($lock->vetoes($template, $additionalIds, $actorId, false)) {
            return false;
        }
        [$previousPrimary, $before] = $this->storedState($template);
        // validate() clears earlier errors, so it runs first; the credentials
        // are checked even when it fails, and the form shows all errors at once.
        $valid = $template->validate();
        $primary = self::positiveIntOrNull($template->credential_id);
        $extras = $this->resolveExtras($template, $additionalIds, $before, [$previousPrimary, $primary]);
        if ($extras === null || $this->rejectsSecondVault($template, $this->described($primary, $extras)) || !$valid) {
            return false;
        }
        // A new template has no pivot rows yet (no stored primary): its primary or its list writes them.
        $syncPivot = $additionalIds !== null || $primary !== $previousPrimary;
        $after = $this->persist(
            $template,
            $syncPivot ? $extras : null,
            $before,
            static fn (): bool => JobTemplateRuleAssignment::persistVetoes($lock, $template, $additionalIds, $actorId)
        );
        if ($after === null) {
            return false;
        }

        $this->audit(
            $isNew ? AuditLog::ACTION_TEMPLATE_CREATED : AuditLog::ACTION_TEMPLATE_UPDATED,
            $template,
            CredentialAttachmentDiff::between($before, $after, $previousPrimary, $primary),
            $auditContext
        );
        /** @var VaultCheckService $vaultChecks */
        $vaultChecks = \Yii::$app->get('vaultCheckService');
        $vaultChecks->checkTemplate($template);

        return true;
    }

    /**
     * Saves a clone and copies the source's credentials with their order.
     *
     * @param array<string, mixed> $auditContext
     */
    public function copyWithCredentials(JobTemplate $clone, JobTemplate $source, array $auditContext = []): bool
    {
        // The clone's own primary first, then the source's other credentials:
        // the set that is written, and so the set the vault rule checks.
        $primary = self::positiveIntOrNull($clone->credential_id);
        $extras = array_values(array_diff(array_column($this->describe($source), 'id'), [$primary]));
        if ($this->rejectsSecondVault($clone, $this->described($primary, $extras))) {
            return false;
        }
        $saved = false;
        $this->inTransaction(function () use ($clone, $primary, $extras, &$saved): void {
            $saved = $clone->save();
            if ($saved) {
                $this->writePivot((int)$clone->id, $primary, $extras);
            }
        });
        if (!$saved) {
            return false;
        }

        $this->audit(
            AuditLog::ACTION_TEMPLATE_CREATED,
            $clone,
            CredentialAttachmentDiff::between([], array_values(array_filter(array_merge([$primary], $extras))), null, $primary),
            $auditContext
        );
        /** @var VaultCheckService $vaultChecks */
        $vaultChecks = \Yii::$app->get('vaultCheckService');
        $vaultChecks->checkTemplate($clone);

        return true;
    }

    /**
     * The template's credentials in precedence order, without secrets.
     *
     * @return list<array{id: int, name: string, credential_type: string, role: string}>
     */
    public function describe(JobTemplate $template): array
    {
        return $template->credentialSnapshot();
    }

    /**
     * Ids of the additional (non-primary) credentials, in precedence order.
     *
     * @return list<int>
     */
    public function additionalIds(JobTemplate $template): array
    {
        $additional = array_filter(
            $this->describe($template),
            static fn (array $entry): bool => $entry['role'] === Credential::ROLE_ADDITIONAL
        );

        return array_values(array_column($additional, 'id'));
    }

    /**
     * The primary and all credential ids as stored before this save.
     *
     * @return array{0: int|null, 1: list<int>}
     */
    private function storedState(JobTemplate $template): array
    {
        if ($template->isNewRecord) {
            return [null, []];
        }
        $previousPrimary = self::positiveIntOrNull($template->getOldAttribute('credential_id'));

        return [$previousPrimary, $this->storedIds((int)$template->id, $previousPrimary)];
    }

    /**
     * The additional ids to store: the submitted ones, or, when none were
     * submitted, the stored ones without the old and the new primary.
     *
     * @param list<mixed>|null $additionalIds
     * @param list<int> $before
     * @param array{0: int|null, 1: int|null} $primaries the previous and the new primary
     * @return list<int>|null null when the submitted ids are invalid
     */
    private function resolveExtras(JobTemplate $template, ?array $additionalIds, array $before, array $primaries): ?array
    {
        if ($additionalIds === null) {
            return array_values(array_diff($before, array_filter($primaries)));
        }

        return $this->checkedIds($template, $additionalIds, $primaries[1]);
    }

    /**
     * Saves the template and, unless $extras is null, rewrites its pivot
     * rows, in one transaction whose first step is $vetoed: when it vetoes
     * the save (JobTemplateRuleAssignment::persistVetoes(), on the locked
     * template and inventory rows), nothing is written. Returns the
     * credential ids the template has afterwards, primary first; null when
     * vetoed.
     *
     * @param list<int>|null $extras
     * @param list<int> $before
     * @param callable(): bool $vetoed
     * @return list<int>|null
     */
    private function persist(JobTemplate $template, ?array $extras, array $before, callable $vetoed): ?array
    {
        $primary = self::positiveIntOrNull($template->credential_id);
        $saved = false;
        $this->inTransaction(function () use ($template, $extras, $primary, $vetoed, &$saved): void {
            if ($vetoed()) {
                return;
            }
            $template->save(false);
            if ($extras !== null) {
                $this->writePivot((int)$template->id, $primary, $extras);
            }
            $saved = true;
        });
        if (!$saved) {
            return null;
        }
        unset($template->jobTemplateCredentials, $template->credential);

        return $extras === null ? $before : array_values(array_filter(array_merge([$primary], $extras)));
    }

    /**
     * The primary and the additional credentials in precedence order.
     *
     * @param list<int> $extras
     * @return list<array{id: int, name: string, credential_type: string}>
     */
    private function described(?int $primary, array $extras): array
    {
        return Credential::describeInOrder(array_values(array_filter(array_merge([$primary], $extras))));
    }

    /**
     * True, with an error on credential_ids, when the credentials hold more
     * than one vault password.
     *
     * @param list<array{id: int, name: string, credential_type: string}> $ordered primary first
     */
    private function rejectsSecondVault(JobTemplate $template, array $ordered): bool
    {
        $vaults = VaultCredentialRule::vaults($ordered);
        if (count($vaults) < 2) {
            return false;
        }
        $template->addError('credential_ids', VaultCredentialRule::conflictMessage($vaults));

        return true;
    }

    /**
     * Primary first, then the pivot rows by sort_order, as stored now.
     *
     * @return list<int>
     */
    private function storedIds(int $templateId, ?int $primary): array
    {
        $pivot = (new \yii\db\Query())
            ->select('credential_id')
            ->from('{{%job_template_credential}}')
            ->where(['job_template_id' => $templateId])
            ->orderBy(['sort_order' => SORT_ASC, 'credential_id' => SORT_ASC])
            ->column();
        $ids = array_map('intval', $pivot);
        if ($primary !== null) {
            array_unshift($ids, $primary);
        }

        return array_values(array_unique($ids));
    }

    /**
     * Validates submitted additional ids: positive IDs of existing
     * credentials, each read as the integer rule reads an ID (SubmittedId:
     * "05" is 5; a fraction however close to a whole number, a boolean and
     * text with a space are none), as GatedJobTemplateLock reads them.
     * Adds an error to the template and returns null otherwise.
     *
     * @param list<mixed> $submitted
     * @return list<int>|null
     */
    private function checkedIds(JobTemplate $template, array $submitted, ?int $primary): ?array
    {
        $ids = [];
        foreach ($submitted as $value) {
            $id = SubmittedId::of($value);
            if ($id === null || $id < 1) {
                $template->addError('credential_ids', self::INVALID_CREDENTIAL_IDS);
                return null;
            }
            if ($id !== $primary && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        $existing = array_map('intval', Credential::find()->select('id')->where(['id' => $ids])->column());
        foreach ($ids as $id) {
            if (!in_array($id, $existing, true)) {
                $template->addError('credential_ids', sprintf(self::CREDENTIAL_NOT_FOUND, $id));
                return null;
            }
        }

        return $ids;
    }

    /**
     * @param list<int> $extras
     */
    private function writePivot(int $templateId, ?int $primary, array $extras): void
    {
        $db = \Yii::$app->db;
        $db->createCommand()->delete('{{%job_template_credential}}', ['job_template_id' => $templateId])->execute();
        $rows = [];
        foreach (array_values(array_filter(array_merge([$primary], $extras))) as $order => $credentialId) {
            $rows[] = [$templateId, $credentialId, $order];
        }
        if ($rows !== []) {
            $db->createCommand()
                ->batchInsert('{{%job_template_credential}}', ['job_template_id', 'credential_id', 'sort_order'], $rows)
                ->execute();
        }
    }

    private function inTransaction(callable $work): void
    {
        $transaction = \Yii::$app->db->beginTransaction();
        try {
            $work();
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function audit(string $action, JobTemplate $template, CredentialAttachmentDiff $diff, array $context): void
    {
        $entry = ['name' => $template->name] + $context;
        if (!$diff->isEmpty()) {
            $labels = Credential::find()
                ->select(['id', 'name', 'credential_type'])
                ->where(['id' => $diff->involvedIds()])
                ->indexBy('id')
                ->asArray()
                ->all();
            /** @var array<int, array{name: string, credential_type: string}> $labels */
            $entry['credentials'] = $diff->toAuditArray($labels);
        }
        \Yii::$app->get('auditService')->log($action, 'job_template', (int)$template->id, null, $entry);
    }

    /**
     * A credential ID as stored or assigned: a positive whole number as the
     * integer rule reads it (SubmittedId), as the column stores it and as
     * GatedJobTemplateLock reads the primary; null for anything else, a
     * number beyond the int range included (a JSON body's 1e30 or 1e999,
     * whose (int) cast warns since PHP 8.5), which the rule refuses.
     */
    private static function positiveIntOrNull(mixed $value): ?int
    {
        $int = SubmittedId::of($value) ?? 0;

        return $int > 0 ? $int : null;
    }
}
