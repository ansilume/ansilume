<?php

declare(strict_types=1);

namespace app\services;

use app\models\Job;
use app\models\JobTemplate;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;

/**
 * Whether the job of an approval request still waits for it, as
 * ApprovalSettlement finds it under the request's and the job's row locks
 * (and as the request page shows it, through ApprovalSettlement::check()).
 *
 * A job waits while it is pending_approval, its job template is not deleted
 * (an approval step's placeholder has no template), and, when it belongs to
 * a workflow step (the placeholder of an approval step, or the job of a job
 * step whose template is gated), while that step is running in an active
 * run. A pending_approval job that no longer waits must never be approved,
 * so the settlement closes its request and cancels the job:
 * - the job of an ended step or run: a run cancel that failed part-way left
 *   it, and approving it would queue the playbook of a canceled run
 *   (ApprovalSettlement::REASON_WORKFLOW_STEP_ENDED);
 * - a job of a deleted job template: deleting the template cancels its
 *   waiting jobs after its commit, and a vote or a timeout can come first,
 *   or that cancel failed (ApprovalSettlement::REASON_TEMPLATE_DELETED). No
 *   runner would ever take the job. A job whose template was purged counts
 *   as one of a deleted template: it has no template, and it is not the
 *   placeholder of an approval step (told by its step's type; a placeholder
 *   has no template by design). Deleting a project purges the rows of its
 *   deleted templates, and the foreign key sets the job_template_id of
 *   their jobs to NULL. ProjectDeletionService cancels their waiting jobs
 *   before it purges, but a purge by an older version, or a cancel that
 *   failed, left such jobs; StrandedJobSweep cancels the ones nobody
 *   decides.
 * An ended step or run comes first: such a job is canceled within its run,
 * whatever became of its template.
 *
 * The step, its run and the template's deleted state are read without a
 * lock. The settlement reads them after both row locks, so the reads see
 * every commit up to the moment the locks were granted: a template delete
 * that committed before, and a run cancel that claimed the run before.
 */
final class ApprovalJobState
{
    private function __construct(
        public readonly ?Job $job,
        public readonly ?WorkflowJobStep $step,
        private readonly bool $stepEnded,
        private readonly bool $templateDeleted
    ) {
    }

    /**
     * The state of $job (null: the job no longer exists). Its workflow step,
     * the step's run and its template's deleted state are read only while
     * the job is pending_approval: a job in any other status no longer
     * waits anyway. The template is not read for the job of an ended step,
     * nor for a job without a template.
     */
    public static function read(?Job $job): self
    {
        if ($job === null || $job->status !== Job::STATUS_PENDING_APPROVAL) {
            return new self($job, null, false, false);
        }
        /** @var WorkflowJobStep|null $step */
        $step = WorkflowJobStep::findOne(['job_id' => $job->id]);
        if ($step !== null && self::hasEnded($step)) {
            return new self($job, $step, true, false);
        }

        return new self($job, $step, false, self::isTemplateDeleted($job, $step));
    }

    /**
     * Whether the job waits for its approval: it exists, is pending_approval,
     * its template is neither deleted nor purged, and its workflow step (if
     * it has one) runs in an active run.
     */
    public function waits(): bool
    {
        return $this->job !== null
            && $this->job->status === Job::STATUS_PENDING_APPROVAL
            && !$this->stepEnded
            && !$this->templateDeleted;
    }

    /**
     * Why the request is closed when the job no longer waits, the context
     * "reason" of its approval.canceled entry:
     * ApprovalSettlement::REASON_WORKFLOW_STEP_ENDED (the job is
     * pending_approval, but its workflow step is no longer running or its
     * run no longer active), REASON_TEMPLATE_DELETED (the job is
     * pending_approval, but its job template was deleted or purged), else
     * REASON_JOB_NOT_WAITING (the job left pending_approval, or is gone).
     */
    public function closeReason(): string
    {
        if ($this->stepEnded) {
            return ApprovalSettlement::REASON_WORKFLOW_STEP_ENDED;
        }

        return $this->templateDeleted
            ? ApprovalSettlement::REASON_TEMPLATE_DELETED
            : ApprovalSettlement::REASON_JOB_NOT_WAITING;
    }

    /**
     * Whether the job is the placeholder of a workflow approval step (by the
     * step's type, never by the job's template): approved, it succeeds and
     * never runs.
     */
    public function placeholder(): bool
    {
        return $this->step !== null && $this->step->isApprovalPlaceholder();
    }

    /**
     * Cancel the job if it is still pending_approval but no longer waits
     * (its workflow step or run has ended, or its job template was deleted
     * or purged): pending_approval -> canceled, with finished_at, as a
     * conditional UPDATE inside the settlement's transaction; the job is
     * refreshed. Any other job is left as it is.
     *
     * @return bool whether this call canceled the job
     */
    public function cancelJob(int $now): bool
    {
        $job = $this->job;
        if ((!$this->stepEnded && !$this->templateDeleted) || $job === null) {
            return false;
        }
        $canceled = Job::updateAll(
            ['status' => Job::STATUS_CANCELED, 'finished_at' => $now, 'updated_at' => $now],
            ['id' => $job->id, 'status' => Job::STATUS_PENDING_APPROVAL]
        ) === 1;
        $job->refresh();

        return $canceled;
    }

    /**
     * Whether $step is no longer running or its run no longer active.
     */
    private static function hasEnded(WorkflowJobStep $step): bool
    {
        /** @var WorkflowJob|null $run */
        $run = $step->workflowJob;

        return $step->status !== WorkflowJobStep::STATUS_RUNNING || $run === null || !$run->isActive();
    }

    /**
     * Whether $job's job template is deleted: soft-deleted, or its row is
     * gone (scalar() answers false then), or purged, which leaves the job
     * without a template. The placeholder of an approval step ($step: the
     * job's step execution, if any) has no template by design and is told
     * by its step's type (WorkflowJobStep::isApprovalPlaceholder()), never
     * by the missing template; any other job without one lost it to a purge.
     * (So does the placeholder whose execution 2.9.0 and older deleted with
     * its removed step: nothing tells it apart, and approved, it would be
     * queued as an ordinary job that no runner claims.)
     */
    private static function isTemplateDeleted(Job $job, ?WorkflowJobStep $step): bool
    {
        if ($job->job_template_id === null) {
            return $step === null || !$step->isApprovalPlaceholder();
        }
        $deletedAt = JobTemplate::findWithDeleted()
            ->select('deleted_at')
            ->where(['id' => (int)$job->job_template_id])
            ->scalar();

        return $deletedAt !== null;
    }
}
