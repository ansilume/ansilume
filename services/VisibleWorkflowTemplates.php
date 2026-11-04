<?php

declare(strict_types=1);

namespace app\services;

use app\models\WorkflowTemplate;
use app\services\runstate\Viewer;

/**
 * The workflow templates a user may see, by ID: the live ones (not
 * deleted) whose job steps' projects the user may view
 * (WorkflowAccessChecker::buildWorkflowTemplateFilter()), and only while
 * the user is active and holds PERMISSION (Viewer: a superadmin holds every
 * permission). For the workflow filter of a notification template, whose
 * editor adds or removes only the workflow templates they may see
 * (NotificationWorkflowFilter).
 *
 * The filter is null for an admin, a superadmin, and for everyone while no
 * project is team-restricted; it is applied only when it is not null. It
 * reads without locks.
 */
final class VisibleWorkflowTemplates
{
    /** The permission without which a user sees no workflow template. */
    public const PERMISSION = 'workflow.view';

    /**
     * The IDs of $ids that are live workflow templates $userId may see,
     * ascending, each once.
     *
     * @param array<int|string, int> $ids
     * @return list<int>
     */
    public static function ids(int $userId, array $ids): array
    {
        if ($ids === [] || !Viewer::of($userId)->may(self::PERMISSION)) {
            return [];
        }
        $query = WorkflowTemplate::find()
            ->select('workflow_template.id')
            ->andWhere(['workflow_template.id' => array_values(array_unique($ids))])
            ->orderBy(['workflow_template.id' => SORT_ASC]);
        $filter = self::checker()->buildWorkflowTemplateFilter($userId, 'workflow_template.id');
        if ($filter !== null) {
            $query->andWhere($filter);
        }

        return array_map(static fn (mixed $id): int => (int)$id, array_values($query->column()));
    }

    /**
     * Every live workflow template $userId may see, ID => name, by name:
     * what the workflow filter of a notification template's form offers its
     * editor.
     *
     * @return array<int, string>
     */
    public static function options(int $userId): array
    {
        if (!Viewer::of($userId)->may(self::PERMISSION)) {
            return [];
        }
        $query = WorkflowTemplate::find()
            ->select(['workflow_template.id', 'workflow_template.name'])
            ->orderBy(['workflow_template.name' => SORT_ASC, 'workflow_template.id' => SORT_ASC])
            ->asArray();
        $filter = self::checker()->buildWorkflowTemplateFilter($userId, 'workflow_template.id');
        if ($filter !== null) {
            $query->andWhere($filter);
        }
        $options = [];
        foreach ($query->all() as $row) {
            $options[(int)$row['id']] = (string)$row['name'];
        }

        return $options;
    }

    private static function checker(): WorkflowAccessChecker
    {
        /** @var WorkflowAccessChecker $checker */
        $checker = \Yii::$app->get('workflowAccessChecker');

        return $checker;
    }
}
