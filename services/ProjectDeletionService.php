<?php

declare(strict_types=1);

namespace app\services;

use app\models\JobTemplate;
use app\models\Project;
use yii\base\Component;
use yii\db\Exception as DbException;

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
 *
 * Purging a template sets the job_template_id of its jobs to NULL (foreign
 * key ON DELETE SET NULL), and a job without a template never runs: no
 * runner claims it (JobClaimService). So the waiting jobs of the deleted
 * templates (Job::waitingStatuses()) are canceled first, as deleting a
 * template cancels its own (JobTemplateDeletionService::cancelWaitingJobsOf(),
 * REASON_TEMPLATE_DELETED): their pending approval requests are closed, the
 * workflow runs of their steps go on, and the cancels are audited as the
 * signed-in user (no user in a console run). Running jobs are left alone, as
 * the template delete leaves them. A cancel that fails is logged and the
 * purge goes on; the job it leaves without a template still never runs and
 * can never be approved (ApprovalJobState), and the maintenance run cancels
 * it (StrandedJobSweep).
 *
 * Only soft-deleted rows are purged. A template created after the count
 * stays, so the project's delete fails on the foreign key and nothing is
 * deleted (it used to be purged with the others); the delete is refused as
 * it would have been with that template counted (it used to end in HTTP
 * 500).
 */
class ProjectDeletionService extends Component
{
    /**
     * The refusal while active templates reference the project; sprintf():
     * the project's name, the number of those templates.
     */
    public const REFUSED = 'Cannot delete "%s": %d job template(s) still reference this project. '
        . 'Remove or reassign them first.';

    /**
     * Number of active (not soft-deleted) templates that block the deletion,
     * counted before anything is done.
     */
    public function blockingTemplateCount(Project $project): int
    {
        return self::activeTemplateCount($project);
    }

    /**
     * Operator-facing refusal message for a project that still has templates.
     */
    public function refusalMessage(Project $project, int $templateCount): string
    {
        return sprintf(self::REFUSED, $project->name, $templateCount);
    }

    /**
     * Cancel the waiting jobs of the project's soft-deleted templates, then
     * purge those templates and delete the project, atomically. Refused
     * while active templates reference the project: nothing is done when
     * the count finds them.
     *
     * A template created (or moved into the project) after the count makes
     * the project's delete fail in the database (the foreign key, or a
     * deadlock with that template's insert); the purge and the delete are
     * rolled back, and when active templates reference the project then,
     * the delete is refused with their number. The waiting jobs canceled
     * before stay canceled: their templates are deleted.
     *
     * @return string|null the refusal (REFUSED), or null when the project was deleted
     * @throws \Throwable when the purge or the delete fails for another reason; both are rolled back
     */
    public function delete(Project $project): ?string
    {
        $templateCount = $this->blockingTemplateCount($project);
        if ($templateCount > 0) {
            return $this->refusalMessage($project, $templateCount);
        }
        $deleted = ['and', ['project_id' => $project->id], ['not', ['deleted_at' => null]]];
        /** @var JobTemplateDeletionService $templates */
        $templates = \Yii::$app->get('jobTemplateDeletionService');
        $templates->cancelWaitingJobsOf(
            array_values(array_map('intval', JobTemplate::findWithDeleted()->select('id')->where($deleted)->column())),
            null
        );

        $db = \Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            // deleteAll() bypasses JobTemplate::find(); the condition keeps
            // it to the soft-deleted rows.
            JobTemplate::deleteAll($deleted);
            $project->delete();
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            // Counted again, after the rollback: an active template that
            // references the project now is why the database refused.
            $templateCount = $e instanceof DbException ? self::activeTemplateCount($project) : 0;
            if ($templateCount === 0) {
                throw $e;
            }

            return $this->refusalMessage($project, $templateCount);
        }

        return null;
    }

    /**
     * The project's templates that are not deleted, as stored now.
     */
    private static function activeTemplateCount(Project $project): int
    {
        // andWhere(): JobTemplate::find() already carries the deleted_at IS
        // NULL scope — where() would replace it and count hidden rows again.
        return (int)JobTemplate::find()->andWhere(['project_id' => $project->id])->count();
    }
}
