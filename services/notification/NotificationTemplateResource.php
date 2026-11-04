<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\NotificationTemplate;
use app\models\User;
use app\services\VisibleWorkflowTemplates;
use yii\db\Query;

/**
 * The fields of a notification template's API resource that depend on its
 * reader or on other rows, read for a page of templates at once:
 * - workflow_template_ids: the workflow templates of its filter that the
 *   reader may see (VisibleWorkflowTemplates: live ones, of projects the
 *   reader may view), ascending;
 * - workflow_templates_hidden: how many others its filter holds, of
 *   another team or deleted, so the reader learns that the filter holds
 *   more without learning which;
 * - delivers_as_inactive: whether the user it delivers as is no active
 *   account, so it delivers nothing.
 * Spec answer 5: a reader sees only what they may see; a PUT that sends
 * the IDs back as read keeps the hidden ones (NotificationWorkflowFilter).
 */
final class NotificationTemplateResource
{
    /**
     * The fields of each of $templates for the reader $viewerId, by
     * template ID: one read of the filters and one of the users, however
     * many templates there are.
     *
     * @param list<NotificationTemplate> $templates
     * @return array<int, array{workflow_template_ids: list<int>, workflow_templates_hidden: int, delivers_as_inactive: bool}>
     */
    public static function extrasOf(array $templates, int $viewerId): array
    {
        if ($templates === []) {
            return [];
        }
        $filters = self::filtersOf(array_map(static fn (NotificationTemplate $t): int => (int)$t->id, $templates));
        $visible = VisibleWorkflowTemplates::ids($viewerId, array_merge(...array_values($filters)));
        $active = self::activeUsers(array_map(static fn (NotificationTemplate $t): int => (int)$t->delivers_as, $templates));
        $extras = [];
        foreach ($templates as $template) {
            $stored = $filters[(int)$template->id] ?? [];
            $shown = array_values(array_intersect($stored, $visible));
            $extras[(int)$template->id] = [
                'workflow_template_ids' => $shown,
                'workflow_templates_hidden' => count($stored) - count($shown),
                'delivers_as_inactive' => !in_array((int)$template->delivers_as, $active, true),
            ];
        }

        return $extras;
    }

    /**
     * The stored workflow filter of each template of $templateIds that has
     * one, ascending.
     *
     * @param list<int> $templateIds
     * @return array<int, list<int>>
     */
    private static function filtersOf(array $templateIds): array
    {
        $rows = (new Query())
            ->select(['notification_template_id', 'workflow_template_id'])
            ->from(NotificationTemplate::WORKFLOW_FILTER_TABLE)
            ->where(['notification_template_id' => $templateIds])
            ->orderBy(['notification_template_id' => SORT_ASC, 'workflow_template_id' => SORT_ASC])
            ->all(NotificationTemplate::getDb());
        $filters = [];
        foreach ($rows as $row) {
            $filters[(int)$row['notification_template_id']][] = (int)$row['workflow_template_id'];
        }

        return $filters;
    }

    /**
     * The users of $userIds that are active accounts.
     *
     * @param list<int> $userIds
     * @return list<int>
     */
    private static function activeUsers(array $userIds): array
    {
        $ids = User::find()->select('id')->where(['id' => array_values(array_unique($userIds)), 'status' => User::STATUS_ACTIVE])->column();

        return array_map(static fn (mixed $id): int => (int)$id, array_values($ids));
    }
}
