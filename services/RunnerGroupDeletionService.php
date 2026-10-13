<?php

declare(strict_types=1);

namespace app\services;

use app\models\RunnerGroup;
use yii\base\Component;

/**
 * Deletes a runner group unless it is in use, for the web UI and the REST
 * API alike.
 *
 * A group is in use while runners belong to it or job templates that are
 * not deleted run on it. Deleting it would delete those runners with it
 * (foreign key ON DELETE CASCADE) and clear the runner group of those
 * templates (ON DELETE SET NULL), whose jobs no runner claims then; a
 * template with an approval rule would change what it runs without an
 * admin (GatedJobTemplateLock). So either refuses the delete. Deleted
 * templates do not count: they never run again, and the delete clears
 * their runner group.
 *
 * The delete runs in one transaction whose first statement locks the
 * group's row (FOR UPDATE); the group is counted after that. A runner that
 * registers into the group, or a template that is moved onto it, meanwhile
 * waits for the lock (its foreign key check reads the group's row) and then
 * fails on the foreign key, or it committed first and is counted. Callers
 * run it outside any other transaction, as both controllers do.
 */
class RunnerGroupDeletionService extends Component
{
    /**
     * The refusal while runners belong to the group. sprintf(): the group's
     * name, the number of its runners.
     */
    public const RUNNERS_REFUSED = 'Cannot delete "%s": %d runner(s) still belong to this group.';

    /**
     * The refusal while job templates that are not deleted run on the
     * group. sprintf(): the group's name, the number of those templates.
     */
    public const TEMPLATES_REFUSED = 'Cannot delete "%s": %d job template(s) reference this group.';

    private const LOCK_SQL = 'SELECT [[id]] FROM {{%runner_group}} WHERE [[id]] = :id FOR UPDATE';

    /**
     * Why $group may not be deleted, or null when nothing uses it: its
     * runners first, then the job templates that run on it.
     */
    public function refusal(RunnerGroup $group): ?string
    {
        $runners = (int)$group->getRunners()->count();
        if ($runners > 0) {
            return sprintf(self::RUNNERS_REFUSED, $group->name, $runners);
        }
        // getJobTemplates() goes through JobTemplate::find(), which leaves out deleted templates.
        $templates = (int)$group->getJobTemplates()->count();

        return $templates > 0 ? sprintf(self::TEMPLATES_REFUSED, $group->name, $templates) : null;
    }

    /**
     * Delete $group unless it is in use (refusal()), decided on its row,
     * locked FOR UPDATE as the first statement of the delete's transaction.
     *
     * @return string|null the refusal (nothing is deleted then), or null when the group was deleted
     */
    public function delete(RunnerGroup $group): ?string
    {
        $refusal = null;
        RunnerGroup::getDb()->transaction(function () use ($group, &$refusal): void {
            RunnerGroup::getDb()->createCommand(self::LOCK_SQL, [':id' => $group->id])->queryScalar();
            $refusal = $this->refusal($group);
            if ($refusal === null) {
                $group->delete();
            }
        });

        return $refusal;
    }
}
