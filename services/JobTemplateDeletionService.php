<?php

declare(strict_types=1);

namespace app\services;

use app\components\DbErrorSummary;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use yii\base\Component;

/**
 * Deletes job templates (a soft delete, for the web UI and the REST API
 * alike) and resolves what depends on them, which a deleted template can
 * never serve again:
 *
 * - its enabled schedules are disabled, in the transaction of the soft
 *   delete (ScheduleEnablement);
 * - its waiting jobs (Job::waitingStatuses(): pending, queued,
 *   pending_approval) are canceled after the commit, through
 *   JobCancellationService with REASON_TEMPLATE_DELETED, which closes their
 *   pending approval requests and moves the workflow runs of workflow
 *   steps on. Running jobs are left alone: no runner can be told to stop,
 *   and their results are still recorded.
 *
 * The soft delete locks the template's row (SELECT ... FOR UPDATE) first,
 * as a launch does before it creates a job (JobLaunchService): a launch
 * either commits before the delete, whose cancel then finds its job, or is
 * refused because the template was deleted.
 *
 * sweep() does the same for every soft-deleted template, whatever deleted
 * it, from the maintenance run: a delete that failed half-way, templates
 * deleted before this release, or a soft delete through the model. The
 * maintenance run gives it a deadline: it starts no cancel after it, and the
 * jobs left wait for the next run. A delete has no deadline.
 *
 * cancelWaitingJobsOf() is the same cancel for ProjectDeletionService, which
 * purges the rows of a deleted project's deleted templates and cancels
 * their waiting jobs first.
 */
class JobTemplateDeletionService extends Component
{
    public const SOURCE_WEB = 'web';
    public const SOURCE_API = 'api';

    /**
     * Waiting jobs one sweep cancels at most, oldest first; the rest follow
     * on the next runs. The batch bounds the memory and the work of a sweep;
     * its deadline (MaintenanceService::$jobTaskBudgetSeconds) bounds its
     * time, which the notifications each cancel sends can stretch.
     */
    public const SWEEP_BATCH_SIZE = 100;

    /** Only when a beforeSave handler vetoes the soft delete; sprintf(): the template ID. */
    public const DELETE_REFUSED = 'Deleting job template #%d was refused.';

    /**
     * Logged when canceling a job fails; sprintf(): the job ID, the template
     * ID, then the exception's class, SQLSTATE and driver error code
     * (DbErrorSummary). Never the message: a database error's message
     * carries the SQL with its values.
     */
    public const CANCEL_FAILED = 'Canceling job #%d of deleted job template #%d failed: %s (SQLSTATE %s, '
        . 'driver error %s); the maintenance run retries while the job waits.';

    /** Reads a job template's row and locks it until the transaction ends. */
    private const LOCK_SQL = 'SELECT [[id]], [[deleted_at]] FROM {{%job_template}} WHERE [[id]] = :id FOR UPDATE';

    /**
     * Soft-delete $template, disable its schedules and cancel its waiting
     * jobs; audited as job-template.deleted with the source and both
     * counts, the disabled schedules as schedule.toggled.
     *
     * @param int|null $actorId the user who deletes it, for the audit entries
     * @param string $source SOURCE_WEB or SOURCE_API
     * @return array{deleted: bool, canceled_jobs: int, disabled_schedules: int}
     *     deleted is false, and nothing was done, when the template had been
     *     deleted already (another request came first)
     */
    public function delete(JobTemplate $template, ?int $actorId, string $source): array
    {
        $templateId = (int)$template->id;
        /** @var list<array{id: int, name: string, job_template_id: int}>|null $disabled */
        $disabled = JobTemplate::getDb()->transaction(fn (): ?array => $this->softDeleteLocked($template));
        if ($disabled === null) {
            return ['deleted' => false, 'canceled_jobs' => 0, 'disabled_schedules' => 0];
        }

        (new ScheduleEnablement())->auditDisabled($disabled, $actorId);
        $canceled = $this->cancelWaitingJobs(['job_template_id' => $templateId], $actorId, null, null);
        $result = ['deleted' => true, 'canceled_jobs' => $canceled, 'disabled_schedules' => count($disabled)];

        /** @var AuditService $audit */
        $audit = \Yii::$app->get('auditService');
        $audit->log(AuditLog::ACTION_TEMPLATE_DELETED, 'job_template', $templateId, $actorId, [
            'name' => $template->name,
            'source' => $source,
            'canceled_jobs' => $result['canceled_jobs'],
            'disabled_schedules' => $result['disabled_schedules'],
        ]);

        return $result;
    }

    /**
     * For the maintenance run: disable the enabled schedules of every
     * soft-deleted job template and cancel up to $limit of their waiting
     * jobs, oldest first, as the system, with no user. No cancel starts once
     * $deadline has passed; a cancel that started is finished.
     *
     * @param float|null $deadline a microtime(true) value; null: no bound
     * @return array{canceled_jobs: int, disabled_schedules: int, remaining_jobs: int}
     *     remaining_jobs: waiting jobs of deleted templates still left, for
     *     the next run
     */
    public function sweep(int $limit = self::SWEEP_BATCH_SIZE, ?float $deadline = null): array
    {
        $enablement = new ScheduleEnablement();
        $disabled = $enablement->disableOfDeletedTemplates();
        $enablement->auditDisabled($disabled, null);

        $condition = ['job_template_id' => JobTemplate::findWithDeleted()->select('id')->where(['not', ['deleted_at' => null]])];
        $canceled = $this->cancelWaitingJobs($condition, null, $limit, $deadline);
        $remaining = (int)Job::find()->where($condition)->andWhere(['status' => Job::waitingStatuses()])->count();

        return ['canceled_jobs' => $canceled, 'disabled_schedules' => count($disabled), 'remaining_jobs' => $remaining];
    }

    /**
     * Cancel the waiting jobs of the job templates $templateIds, as delete()
     * cancels those of the template it deletes (REASON_TEMPLATE_DELETED):
     * oldest first, all of them, each on its own; a cancel that fails is
     * logged (CANCEL_FAILED) and the others go on. For ProjectDeletionService,
     * before it purges the rows of these deleted templates.
     *
     * @param list<int> $templateIds
     * @param int|null $actorId the user, for the audit entries; null: the signed-in user, if any
     * @return int the jobs this call canceled
     */
    public function cancelWaitingJobsOf(array $templateIds, ?int $actorId): int
    {
        if ($templateIds === []) {
            return 0;
        }

        return $this->cancelWaitingJobs(['job_template_id' => $templateIds], $actorId, null, null);
    }

    /**
     * Runs inside the transaction: lock the template's row, soft-delete it
     * and disable its enabled schedules.
     *
     * @return list<array{id: int, name: string, job_template_id: int}>|null the
     *     schedules it disabled; null when the template was deleted already
     * @throws \RuntimeException when a handler vetoes the soft delete
     */
    private function softDeleteLocked(JobTemplate $template): ?array
    {
        $templateId = (int)$template->id;
        $row = JobTemplate::getDb()->createCommand(self::LOCK_SQL, [':id' => $templateId])->queryOne();
        if (!is_array($row) || $row['deleted_at'] !== null) {
            return null;
        }
        if (!$template->softDelete()) {
            throw new \RuntimeException(sprintf(self::DELETE_REFUSED, $templateId));
        }

        return (new ScheduleEnablement())->disableOfDeletedTemplates(['job_template_id' => $templateId]);
    }

    /**
     * Cancel the waiting jobs that match $condition, oldest first, at most
     * $limit (null: all), until $deadline (null: no bound): it is checked
     * before each cancel starts. A cancel that fails is logged and left to
     * the next maintenance run; the others go on.
     *
     * @param array<string, mixed> $condition on job columns
     * @return int the jobs this call canceled
     */
    private function cancelWaitingJobs(array $condition, ?int $actorId, ?int $limit, ?float $deadline): int
    {
        $query = Job::find()
            ->select('id')
            ->where($condition)
            ->andWhere(['status' => Job::waitingStatuses()])
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit);

        $canceled = 0;
        foreach ($query->column() as $jobId) {
            if ($deadline !== null && $this->now() >= $deadline) {
                break;
            }
            $job = Job::findOne((int)$jobId);
            if ($job !== null && $this->cancelJob($job, $actorId)) {
                $canceled++;
            }
        }

        return $canceled;
    }

    private function cancelJob(Job $job, ?int $actorId): bool
    {
        try {
            /** @var JobCancellationService $cancellation */
            $cancellation = \Yii::$app->get('jobCancellationService');

            return $cancellation->cancel($job, $actorId, JobCancellationService::REASON_TEMPLATE_DELETED);
        } catch (\Throwable $e) {
            \Yii::error(
                sprintf(self::CANCEL_FAILED, (int)$job->id, (int)$job->job_template_id, ...DbErrorSummary::of($e)),
                __CLASS__
            );

            return false;
        }
    }

    /**
     * The clock a sweep's deadline is checked against (microtime(true));
     * tests replace it.
     */
    protected function now(): float
    {
        return microtime(true);
    }
}
