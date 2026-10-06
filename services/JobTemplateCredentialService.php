<?php

declare(strict_types=1);

namespace app\services;

use app\components\CredentialAttachmentDiff;
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
 */
class JobTemplateCredentialService extends Component
{
    /**
     * @param list<mixed>|null $additionalIds additional credential ids in
     *        precedence order; null keeps the current additional credentials
     * @param array<string, mixed> $auditContext
     */
    public function saveWithCredentials(JobTemplate $template, ?array $additionalIds, array $auditContext = []): bool
    {
        $isNew = $template->isNewRecord;
        [$previousPrimary, $before] = $this->storedState($template);
        if (!$template->validate()) {
            return false;
        }

        $primary = self::positiveIntOrNull($template->credential_id);
        $extras = $this->resolveExtras($template, $additionalIds, $before, [$previousPrimary, $primary]);
        if ($extras === null) {
            return false;
        }
        $syncPivot = $isNew || $additionalIds !== null || $primary !== $previousPrimary;
        $after = $this->persist($template, $syncPivot ? $extras : null, $before);

        $this->audit(
            $isNew ? AuditLog::ACTION_TEMPLATE_CREATED : AuditLog::ACTION_TEMPLATE_UPDATED,
            $template,
            CredentialAttachmentDiff::between($before, $after, $previousPrimary, $primary),
            $auditContext
        );

        return true;
    }

    /**
     * Saves a clone and copies the source's credentials with their order.
     *
     * @param array<string, mixed> $auditContext
     */
    public function copyWithCredentials(JobTemplate $clone, JobTemplate $source, array $auditContext = []): bool
    {
        $ids = array_column($this->describe($source), 'id');
        $saved = false;
        $this->inTransaction(function () use ($clone, $ids, &$saved): void {
            $saved = $clone->save();
            if ($saved) {
                $primary = self::positiveIntOrNull($clone->credential_id);
                $this->writePivot((int)$clone->id, $primary, array_values(array_diff($ids, [$primary])));
            }
        });
        if (!$saved) {
            return false;
        }

        $this->audit(
            AuditLog::ACTION_TEMPLATE_CREATED,
            $clone,
            CredentialAttachmentDiff::between([], $ids, null, self::positiveIntOrNull($clone->credential_id)),
            $auditContext
        );

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
     * rows. Returns the credential ids it has afterwards, primary first.
     *
     * @param list<int>|null $extras
     * @param list<int> $before
     * @return list<int>
     */
    private function persist(JobTemplate $template, ?array $extras, array $before): array
    {
        $primary = self::positiveIntOrNull($template->credential_id);
        $this->inTransaction(function () use ($template, $extras, $primary): void {
            $template->save(false);
            if ($extras !== null) {
                $this->writePivot((int)$template->id, $primary, $extras);
            }
        });
        unset($template->jobTemplateCredentials, $template->credential);

        return $extras === null ? $before : array_values(array_filter(array_merge([$primary], $extras)));
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
     * Validates submitted additional ids: positive integers of existing
     * credentials. Adds an error to the template and returns null otherwise.
     *
     * @param list<mixed> $submitted
     * @return list<int>|null
     */
    private function checkedIds(JobTemplate $template, array $submitted, ?int $primary): ?array
    {
        $ids = [];
        foreach ($submitted as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                $template->addError('credential_ids', 'credential_ids must contain positive integer credential IDs.');
                return null;
            }
            if ($id !== $primary && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        $existing = array_map('intval', Credential::find()->select('id')->where(['id' => $ids])->column());
        foreach ($ids as $id) {
            if (!in_array($id, $existing, true)) {
                $template->addError('credential_ids', "Credential #{$id} does not exist.");
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

    private static function positiveIntOrNull(mixed $value): ?int
    {
        $int = is_numeric($value) ? (int)$value : 0;

        return $int > 0 ? $int : null;
    }
}
