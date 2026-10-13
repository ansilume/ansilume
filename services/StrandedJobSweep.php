<?php

declare(strict_types=1);

namespace app\services;

use app\components\DbErrorSummary;
use app\models\ApprovalRequest;
use app\models\Job;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use yii\base\Component;
use yii\db\ActiveQuery;
use yii\db\Expression;
use yii\db\Query;

/**
 * For the maintenance run: cancels the waiting jobs that nothing else would
 * ever end, and ends the steps still running in runs that ended.
 *
 * - A waiting job (Job::waitingStatuses()) of a step of a workflow run that
 *   is no longer active: a run cancel or a template delete that failed
 *   part-way (WorkflowCancellationService::STEP_CANCEL_FAILED) left it
 *   queued or waiting for approval. No runner claims it meanwhile
 *   (JobClaimService). It is canceled within its run
 *   (JobCancellationService::cancelWithinWorkflow(), which closes its pending
 *   approval request) for the reason the run was canceled for
 *   (WorkflowCancellationService::cancelOf() and jobReason()): as the run's
 *   newest workflow.canceled audit entry says, REASON_WORKFLOW_CANCELED when
 *   a user canceled it and REASON_WORKFLOW_TEMPLATE_DELETED when deleting its
 *   workflow template did; a run without such an entry (it ended otherwise)
 *   goes by its template's state, REASON_WORKFLOW_TEMPLATE_DELETED when the
 *   template is deleted, else REASON_WORKFLOW_CANCELED. So a run a user
 *   canceled before its template was deleted keeps REASON_WORKFLOW_CANCELED.
 *   The job is canceled with no user, and its step, if it still runs, ends
 *   with that cancel's message.
 * - A job that waits for approval without any approval request: 2.9.0 and
 *   older deleted the requests of a deleted approval rule, so nobody could
 *   ever decide it. It is canceled with
 *   JobCancellationService::REASON_APPROVAL_MISSING, which moves the run of
 *   its workflow step on as any cancel does; its gate never opened, so only
 *   an explicit On Failure branch goes on (WorkflowStepRouter). Only a job
 *   created ORPHAN_MIN_AGE_SECONDS ago or earlier: a writer that creates a
 *   job and its request one after the other is never taken for one.
 * - A waiting job whose job template was purged: it has no template and is
 *   not the placeholder of an approval step (told by its step's type, the
 *   snapshot, else the live step, as WorkflowJobStep::stepType() reads it;
 *   a placeholder has no template by design). Deleting a project purges
 *   the rows of its deleted templates, and the foreign key sets the
 *   job_template_id of their jobs to NULL; ProjectDeletionService cancels
 *   the waiting ones before it purges, but a purge by an older version, or
 *   a cancel that failed, left them. No runner claims a job without a
 *   template (JobClaimService). It is canceled as the template delete
 *   cancels its jobs (JobCancellationService::REASON_TEMPLATE_DELETED, also
 *   when its approval request is gone): its pending request is closed, and
 *   the run of its workflow step goes on as for any cancel. Only a job
 *   created ORPHAN_MIN_AGE_SECONDS ago or earlier, so a writer that creates
 *   an approval step's placeholder and links its step one after the other
 *   is never taken for one. A placeholder whose execution 2.9.0 and older
 *   deleted with its removed step is taken for such a job: nothing tells
 *   it apart, and no decision on it could move its run on any more.
 * - Then the steps still running in runs that are no longer active (a step
 *   whose end failed during its run's cancel, a pause step among them) end
 *   with the message of the run's cancel, or STEP_RUN_ENDED when the run was
 *   not canceled.
 *
 * Oldest job first, at most $limit jobs and $limit steps, and nothing started
 * after $deadline (an item that started is finished). Each job and each step
 * is on its own: one that fails is logged (JOB_FAILED, STEP_FAILED, without
 * values) and left for the next sweep, and the others go on.
 */
class StrandedJobSweep extends Component
{
    /** Jobs (and steps) one sweep handles at most, oldest first; the rest follow on the next sweeps. */
    public const BATCH_SIZE = 100;

    /**
     * A job waiting for approval without a request, or a waiting job whose
     * job template was purged, is canceled only this many seconds after it
     * was created.
     */
    public const ORPHAN_MIN_AGE_SECONDS = 300;

    /**
     * Logged when a stranded job could not be canceled; sprintf(): the job
     * ID, then the exception's class, SQLSTATE and driver error code
     * (DbErrorSummary).
     */
    public const JOB_FAILED = 'Canceling stranded job #%d failed: %s (SQLSTATE %s, driver error %s); '
        . 'the next maintenance run retries.';

    /**
     * Logged when a step still running in a run that ended could not be
     * ended; sprintf(): the step's ID (its execution), the run ID, then the
     * exception's class, SQLSTATE and driver error code (DbErrorSummary).
     */
    public const STEP_FAILED = 'Ending step #%d of ended run #%d failed: %s (SQLSTATE %s, driver error %s); '
        . 'the next maintenance run retries.';

    /** The error message of a step still running in a run that ended without a cancel. */
    public const STEP_RUN_ENDED = 'Not finished: the run had ended.';

    /**
     * Cancel up to $limit stranded jobs, oldest first, then end up to $limit
     * steps still running in runs that ended (see the class description).
     *
     * @param float|null $deadline microtime(true) after which no job or step
     *     is started; null: no bound
     * @return array{stranded_jobs_canceled: int, stranded_jobs_failed: int, stranded_jobs_remaining: int}
     *     stranded_jobs_failed: jobs whose cancel failed (logged);
     *     stranded_jobs_remaining: stranded jobs left for the next sweep
     */
    public function sweep(int $limit = self::BATCH_SIZE, ?float $deadline = null): array
    {
        $canceled = 0;
        $failed = 0;
        $jobIds = $this->strandedJobs()->select('id')->orderBy(['id' => SORT_ASC])->limit($limit)->column();
        foreach ($jobIds as $jobId) {
            if ($this->pastDeadline($deadline)) {
                break;
            }
            try {
                $canceled += $this->cancelJob((int)$jobId) ? 1 : 0;
            } catch (\Throwable $e) {
                $failed++;
                \Yii::error(sprintf(self::JOB_FAILED, (int)$jobId, ...DbErrorSummary::of($e)), __CLASS__);
            }
        }
        $this->endRunningSteps($limit, $deadline);

        return [
            'stranded_jobs_canceled' => $canceled,
            'stranded_jobs_failed' => $failed,
            'stranded_jobs_remaining' => (int)$this->strandedJobs()->count(),
        ];
    }

    /**
     * The EXISTS subquery for {{%job}} that finds a step execution linking
     * the job to a workflow run that is no longer active. JobClaimService
     * claims no job for which it finds one.
     */
    public static function endedRunOfJob(): Query
    {
        return self::endedRunExecutions()
            ->select(new Expression('1'))
            ->andWhere('[[wjs]].[[job_id]] = {{%job}}.[[id]]');
    }

    /**
     * The stranded jobs: waiting, and either linked to a run that ended, or
     * (old enough) waiting for approval without any request, or without
     * their purged job template.
     */
    private function strandedJobs(): ActiveQuery
    {
        $request = (new Query())
            ->select(new Expression('1'))
            ->from(['ar' => ApprovalRequest::tableName()])
            ->where('[[ar]].[[job_id]] = {{%job}}.[[id]]');

        return Job::find()
            ->where(['status' => Job::waitingStatuses()])
            ->andWhere([
                'or',
                ['exists', self::endedRunOfJob()],
                [
                    'and',
                    ['<', 'created_at', time() - self::ORPHAN_MIN_AGE_SECONDS],
                    [
                        'or',
                        ['and', ['status' => Job::STATUS_PENDING_APPROVAL], ['not exists', $request]],
                        self::lostTemplate(),
                    ],
                ],
            ]);
    }

    /**
     * The condition on {{%job}} of a job whose job template was purged: it
     * has none, and no step execution of an approval step links it (the
     * step's type as WorkflowJobStep::stepType() reads it: the snapshot
     * unless empty, else the live step's; compared exactly, as
     * isApprovalPlaceholder() compares it).
     *
     * @return array<int, mixed>
     */
    private static function lostTemplate(): array
    {
        $placeholder = (new Query())
            ->select(new Expression('1'))
            ->from(['pwjs' => WorkflowJobStep::tableName()])
            ->leftJoin(['pws' => WorkflowStep::tableName()], '[[pws]].[[id]] = [[pwjs]].[[workflow_step_id]]')
            ->where('[[pwjs]].[[job_id]] = {{%job}}.[[id]]')
            ->andWhere(new Expression(
                "CAST(COALESCE(NULLIF([[pwjs]].[[step_type]], ''), [[pws]].[[step_type]]) AS BINARY) = :approvalType",
                [':approvalType' => WorkflowStep::TYPE_APPROVAL]
            ));

        return ['and', ['job_template_id' => null], ['not exists', $placeholder]];
    }

    /**
     * Cancel job #$jobId (see the class description); whether this call
     * canceled it (false: it is gone or ended meanwhile).
     */
    private function cancelJob(int $jobId): bool
    {
        /** @var Job|null $job */
        $job = Job::findOne($jobId);
        if ($job === null) {
            return false;
        }
        /** @var WorkflowJobStep|null $execution */
        $execution = self::endedRunExecutions()->andWhere(['wjs.job_id' => $jobId])->orderBy(['wjs.id' => SORT_DESC])->one();
        if ($execution === null) {
            return $this->jobs()->cancel($job, null, $this->orphanReason($jobId));
        }

        /** @var WorkflowJob $run the run exists: its executions go with it (foreign key) */
        $run = $execution->workflowJob;
        $reason = $this->runs()->cancelOf($run)['reason'];
        $this->endStep($execution, $run, $reason);

        return $this->jobs()->cancelWithinWorkflow(
            $job,
            null,
            (int)$run->id,
            WorkflowCancellationService::jobReason($reason)
        );
    }

    /**
     * Why stranded job #$jobId, which belongs to no run that ended, is
     * canceled: REASON_TEMPLATE_DELETED when its job template was purged
     * (lostTemplate(), whether or not its approval request is gone too),
     * else REASON_APPROVAL_MISSING.
     */
    private function orphanReason(int $jobId): string
    {
        return Job::find()->where(['id' => $jobId])->andWhere(self::lostTemplate())->exists()
            ? JobCancellationService::REASON_TEMPLATE_DELETED
            : JobCancellationService::REASON_APPROVAL_MISSING;
    }

    /**
     * End up to $limit steps still running in runs that ended, oldest first.
     */
    private function endRunningSteps(int $limit, ?float $deadline): void
    {
        /** @var list<WorkflowJobStep> $executions */
        $executions = self::endedRunExecutions()
            ->andWhere(['wjs.status' => WorkflowJobStep::STATUS_RUNNING])
            ->orderBy(['wjs.id' => SORT_ASC])
            ->limit($limit)
            ->all();
        foreach ($executions as $execution) {
            if ($this->pastDeadline($deadline)) {
                return;
            }
            try {
                /** @var WorkflowJob $run */
                $run = $execution->workflowJob;
                $this->endStep($execution, $run, $this->runs()->cancelOf($run)['reason']);
            } catch (\Throwable $e) {
                \Yii::error(
                    sprintf(self::STEP_FAILED, (int)$execution->id, (int)$execution->workflow_job_id, ...DbErrorSummary::of($e)),
                    __CLASS__
                );
            }
        }
    }

    /**
     * Fail $execution of $run, if it still runs, with the message of the
     * run's cancel for $reason, or STEP_RUN_ENDED when the run was not
     * canceled.
     */
    private function endStep(WorkflowJobStep $execution, WorkflowJob $run, string $reason): void
    {
        $now = time();
        WorkflowJobStep::updateAll(
            [
                'status' => WorkflowJobStep::STATUS_FAILED,
                'finished_at' => $now,
                'error_message' => $run->status === WorkflowJob::STATUS_CANCELED
                    ? WorkflowCancellationService::stepMessage($reason)
                    : self::STEP_RUN_ENDED,
                'updated_at' => $now,
            ],
            ['id' => $execution->id, 'status' => WorkflowJobStep::STATUS_RUNNING]
        );
    }

    /**
     * Step executions (alias wjs) of workflow runs (alias wj) that are no
     * longer active.
     */
    private static function endedRunExecutions(): ActiveQuery
    {
        return WorkflowJobStep::find()
            ->alias('wjs')
            ->innerJoin(['wj' => WorkflowJob::tableName()], '[[wj]].[[id]] = [[wjs]].[[workflow_job_id]]')
            ->andWhere(['not', ['wj.status' => WorkflowJob::activeStatuses()]]);
    }

    private function pastDeadline(?float $deadline): bool
    {
        return $deadline !== null && $this->now() >= $deadline;
    }

    /**
     * The time a deadline is compared with: microtime(true).
     */
    protected function now(): float
    {
        return microtime(true);
    }

    private function jobs(): JobCancellationService
    {
        /** @var JobCancellationService $jobs */
        $jobs = \Yii::$app->get('jobCancellationService');

        return $jobs;
    }

    private function runs(): WorkflowCancellationService
    {
        /** @var WorkflowCancellationService $runs */
        $runs = \Yii::$app->get('workflowCancellationService');

        return $runs;
    }
}
