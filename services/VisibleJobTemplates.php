<?php

declare(strict_types=1);

namespace app\services;

use app\models\JobTemplate;
use yii\db\ActiveQuery;

/**
 * The job templates of a query that a viewer may see, for the lists of the
 * usage pages and answers (ApprovalRuleUsageService, InventoryUsageService):
 * those of the projects the viewer may see, by
 * ProjectAccessChecker::buildChildResourceFilter(), ordered by name, then
 * ID. A missing viewer sees none.
 *
 * The filter is null for an admin, a superadmin, and for everyone while no
 * project is team-restricted (a default install): then every template is
 * listed. It is applied only when it is not null, because andWhere(null)
 * builds "WHERE (...) AND ()", an SQL error.
 *
 * It reads without locks.
 */
final class VisibleJobTemplates
{
    /** The column whose project decides who may see a job template. */
    private const PROJECT_COLUMN = 'job_template.project_id';

    /**
     * The job templates of $query (JobTemplate::find(), which leaves out
     * soft-deleted ones, with conditions of the caller) that the viewer may
     * see, by name, then ID, with $relations loaded.
     *
     * @param list<string> $relations
     * @return list<JobTemplate>
     */
    public static function all(ActiveQuery $query, ?int $viewerId, array $relations): array
    {
        $query->with($relations)->orderBy(['job_template.name' => SORT_ASC, 'job_template.id' => SORT_ASC]);
        $filter = self::checker()->buildChildResourceFilter($viewerId, self::PROJECT_COLUMN);
        if ($filter !== null) {
            $query->andWhere($filter);
        }
        /** @var list<JobTemplate> $templates */
        $templates = $query->all();

        return $templates;
    }

    private static function checker(): ProjectAccessChecker
    {
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');

        return $checker;
    }
}
