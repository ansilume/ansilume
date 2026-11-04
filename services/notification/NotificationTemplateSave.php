<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\NotificationTemplate;

/**
 * Saves a notification template together with its workflow filter, in one
 * transaction: the template and its filter are written together, or
 * neither. The REST API saves through it, and so may the web form.
 *
 * The filter is written as its editor sets it (NotificationWorkflowFilter):
 * of the stored workflow templates, only those the editor may see are
 * replaced; the others stay. A workflow template the editor may not see,
 * of another team, deleted or none at all, is refused as if it did not
 * exist (WORKFLOW_TEMPLATES_NOT_FOUND, an error of the template's
 * workflow_template_ids), and the whole save writes nothing.
 *
 * An update makes its editor the user the template delivers as when it
 * changes what or where the template delivers, its filter included
 * (NotificationIdentity: built before the change was loaded; its audit
 * context is the caller's to record once the save succeeded).
 *
 * The template's row is locked before its filter's rows, as every write of
 * the filter locks them.
 */
final class NotificationTemplateSave
{
    /**
     * The error of workflow_template_ids for the IDs of workflow templates
     * the editor may not see, which are not written. sprintf(): the IDs,
     * as "#4, #9".
     */
    public const WORKFLOW_TEMPLATES_NOT_FOUND = 'Workflow template(s) not found: %s.';

    /** The attribute of the template that the error of its workflow filter is added to. */
    public const FILTER_ATTRIBUTE = 'workflow_template_ids';

    /**
     * Creates $template, whose creator and identity the caller set, with
     * the workflow filter $workflowTemplateIds as the user $userId sets it
     * (null: none). Whether it was saved; otherwise the template holds the
     * errors, nothing is written, and the template is new again.
     *
     * @param list<int>|null $workflowTemplateIds
     */
    public static function create(NotificationTemplate $template, ?array $workflowTemplateIds, int $userId): bool
    {
        $saved = self::inTransaction(static fn (): bool => $template->save()
            && ($workflowTemplateIds === null || self::writeFilter($template, $workflowTemplateIds, $userId)));
        if (!$saved && !$template->getIsNewRecord()) {
            // Rolled back after the insert: the row is gone again.
            $template->setIsNewRecord(true);
            $template->setAttribute('id', null);
        }

        return $saved;
    }

    /**
     * Saves the change of $template that the user $userId made, with the
     * workflow filter $workflowTemplateIds as they set it (null: the filter
     * stays as it is), as the user it delivers as when the change makes
     * them that ($identity). Whether it was saved; otherwise the template
     * holds the errors (none when a handler vetoed the save) and nothing is
     * written.
     *
     * @param list<int>|null $workflowTemplateIds
     */
    public static function update(NotificationTemplate $template, NotificationIdentity $identity, ?array $workflowTemplateIds, int $userId): bool
    {
        if (!$template->validate()) {
            return false;
        }

        return self::inTransaction(static function () use ($template, $identity, $workflowTemplateIds, $userId): bool {
            if ($workflowTemplateIds !== null && !self::writeFilter($template, $workflowTemplateIds, $userId)) {
                return false;
            }
            if ($identity->changesIdentity()) {
                $identity->apply($userId);
            }

            return $template->save(false);
        });
    }

    /**
     * Writes the workflow filter of $template as $userId sets it to $ids;
     * false, with the template's error, when the user may not see one of
     * them.
     *
     * @param list<int> $ids
     */
    private static function writeFilter(NotificationTemplate $template, array $ids, int $userId): bool
    {
        $refused = $template->setWorkflowTemplateIds($ids, $userId);
        if ($refused === []) {
            return true;
        }
        $template->addError(self::FILTER_ATTRIBUTE, sprintf(self::WORKFLOW_TEMPLATES_NOT_FOUND, '#' . implode(', #', $refused)));

        return false;
    }

    /**
     * Runs $write in a transaction, which it commits when $write returns
     * true and rolls back otherwise, an error included.
     *
     * @param \Closure(): bool $write
     */
    private static function inTransaction(\Closure $write): bool
    {
        $transaction = NotificationTemplate::getDb()->beginTransaction();
        try {
            $saved = $write();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        if ($saved) {
            $transaction->commit();
        } else {
            $transaction->rollBack();
        }

        return $saved;
    }
}
