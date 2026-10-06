<?php

declare(strict_types=1);

namespace app\services;

use app\models\JobTemplate;
use app\models\Project;
use yii\base\Component;

/**
 * Deletes a project while honouring the job_template → project RESTRICT
 * foreign key.
 *
 * Job templates are soft-deleted (deleted_at) and hidden by
 * JobTemplate::find(), but their rows keep referencing the project. Counting
 * only visible templates therefore let the delete through and the database
 * answered with an integrity constraint violation (HTTP 500). Soft-deleted
 * templates are invisible to operators, so they are purged together with
 * the project; active templates still block the deletion with a clear error.
 */
class ProjectDeletionService extends Component
{
    /**
     * Number of active (not soft-deleted) templates that block the deletion.
     */
    public function blockingTemplateCount(Project $project): int
    {
        // andWhere(): JobTemplate::find() already carries the deleted_at IS
        // NULL scope — where() would replace it and count hidden rows again.
        return (int)JobTemplate::find()->andWhere(['project_id' => $project->id])->count();
    }

    /**
     * Operator-facing refusal message for a project that still has templates.
     */
    public function refusalMessage(Project $project, int $templateCount): string
    {
        return "Cannot delete \"{$project->name}\": {$templateCount} job template(s) still reference this project. "
            . 'Remove or reassign them first.';
    }

    /**
     * Purge soft-deleted templates of the project, then delete the project —
     * atomically. Returns false when active templates still exist.
     */
    public function delete(Project $project): bool
    {
        if ($this->blockingTemplateCount($project) > 0) {
            return false;
        }

        $db = \Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            // deleteAll() bypasses JobTemplate::find(); only soft-deleted rows
            // remain at this point because the active count is zero.
            JobTemplate::deleteAll(['project_id' => $project->id]);
            $project->delete();
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return true;
    }
}
