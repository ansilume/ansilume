<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\NotificationTemplate;
use app\services\VisibleWorkflowTemplates;
use yii\base\InvalidArgumentException;
use yii\base\InvalidCallException;

/**
 * The workflow filter of a notification template ("for these workflows"):
 * its workflow templates in notification_template_workflow_template, which
 * count while its workflow scope is selected
 * (NotificationTemplate::isForWorkflowTemplate()).
 *
 * An editor adds or removes only the workflow templates they may see
 * (VisibleWorkflowTemplates); the stored ones they may not see stay as they
 * are, deleted ones included, so a save by someone who cannot see a
 * workflow template of another team neither drops nor reveals it. Whom the
 * template delivers as decides which of its runs reach it
 * (NotificationIdentity).
 *
 * A write locks the template's row, then its filter's rows, and changes
 * them in one transaction: concurrent writes of the same filter wait for
 * each other, and a write that fails changes nothing.
 */
final class NotificationWorkflowFilter
{
    /** write() for a template not saved yet, which has no ID to file its filter under. */
    public const NOT_SAVED = 'Save the notification template before its workflow filter.';

    /** write() with an ID that is no integer: a caller must read the IDs with SubmittedId. */
    public const NOT_AN_ID = 'A workflow template ID must be an integer; read it with SubmittedId.';

    /**
     * The IDs of the workflow templates of $template's filter as stored,
     * ascending; [] for a template not saved yet. $lock reads them with
     * FOR UPDATE, inside the caller's transaction.
     *
     * @return list<int>
     */
    public static function idsOf(NotificationTemplate $template, bool $lock = false): array
    {
        if ($template->getIsNewRecord()) {
            return [];
        }
        $ids = NotificationTemplate::getDb()->createCommand(
            'SELECT [[workflow_template_id]] FROM ' . NotificationTemplate::WORKFLOW_FILTER_TABLE
                . ' WHERE [[notification_template_id]] = :id ORDER BY [[workflow_template_id]]' . ($lock ? ' FOR UPDATE' : ''),
            [':id' => (int)$template->id]
        )->queryColumn();

        return array_map(static fn (mixed $id): int => (int)$id, array_values($ids));
    }

    /**
     * Writes $template's filter as the editor $editorId sets it to $ids:
     * the stored workflow templates the editor may see are replaced by the
     * IDs of $ids the editor may see; the stored ones the editor may not
     * see stay.
     *
     * @param array<int|string, int> $ids
     * @return list<int> the IDs of $ids the editor may not see, in their
     *     order, each once: unknown, deleted, or of another team; such an
     *     ID is not added, and stays only when it was stored
     * @throws InvalidArgumentException (NOT_AN_ID) for an ID that is no integer; nothing is written
     * @throws InvalidCallException (NOT_SAVED) for a template not saved yet
     */
    public static function write(NotificationTemplate $template, array $ids, int $editorId): array
    {
        $submitted = self::submitted($ids);
        if ($template->getIsNewRecord()) {
            throw new InvalidCallException(self::NOT_SAVED);
        }
        $db = NotificationTemplate::getDb();
        $transaction = $db->beginTransaction();
        try {
            $db->createCommand(
                'SELECT [[id]] FROM ' . NotificationTemplate::tableName() . ' WHERE [[id]] = :id FOR UPDATE',
                [':id' => (int)$template->id]
            )->queryScalar();
            $stored = self::idsOf($template, true);
            $visible = VisibleWorkflowTemplates::ids($editorId, array_merge($submitted, $stored));
            $wanted = array_merge(array_diff($stored, $visible), array_intersect($submitted, $visible));
            self::remove($template, array_values(array_diff($stored, $wanted)));
            self::add($template, array_values(array_diff($wanted, $stored)));
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return self::refused($submitted, $visible);
    }

    /**
     * The IDs of $submitted the editor may not see, stored or not: a
     * caller learns nothing of the workflow templates it cannot see.
     *
     * @param list<int> $submitted
     * @param list<int> $visible
     * @return list<int>
     */
    private static function refused(array $submitted, array $visible): array
    {
        return array_values(array_diff($submitted, $visible));
    }

    /**
     * $ids, each once in its order.
     *
     * @param array<int|string, mixed> $ids
     * @return list<int>
     */
    private static function submitted(array $ids): array
    {
        $submitted = [];
        foreach ($ids as $id) {
            if (!is_int($id)) {
                throw new InvalidArgumentException(self::NOT_AN_ID);
            }
            $submitted[$id] = $id;
        }

        return array_values($submitted);
    }

    /**
     * @param list<int> $ids
     */
    private static function remove(NotificationTemplate $template, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        NotificationTemplate::getDb()->createCommand()->delete(
            NotificationTemplate::WORKFLOW_FILTER_TABLE,
            ['notification_template_id' => (int)$template->id, 'workflow_template_id' => $ids]
        )->execute();
    }

    /**
     * @param list<int> $ids
     */
    private static function add(NotificationTemplate $template, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $rows = array_map(static fn (int $id): array => [(int)$template->id, $id], $ids);
        NotificationTemplate::getDb()->createCommand()->batchInsert(
            NotificationTemplate::WORKFLOW_FILTER_TABLE,
            ['notification_template_id', 'workflow_template_id'],
            $rows
        )->execute();
    }
}
