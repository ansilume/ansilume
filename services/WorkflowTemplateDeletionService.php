<?php

declare(strict_types=1);

namespace app\services;

use app\components\DbErrorSummary;
use app\models\AuditLog;
use app\models\WorkflowJob;
use app\models\WorkflowTemplate;
use yii\base\Component;

/**
 * Deletes a workflow template (a soft delete) and cancels its active runs.
 *
 * The soft delete is one transaction that first locks the template's row
 * (WorkflowTemplate::lockLive()), as a launch and a step change do: a launch
 * that committed before it left a run, which is canceled here; a launch
 * after it finds the template deleted and is refused. The delete also drops
 * the trigger token (WorkflowTemplate::softDelete()).
 *
 * After the commit, every run of the template that is still active is
 * canceled through WorkflowCancellationService::cancelRun() with
 * REASON_WORKFLOW_TEMPLATE_DELETED: its running steps fail with
 * WorkflowCancellationService::STEP_TEMPLATE_DELETED, their jobs are
 * canceled and the approval requests they wait for are closed; each run gets
 * its workflow.canceled audit entry and notification. A run that finished
 * meanwhile keeps its outcome and is not counted. A run whose cancel fails is
 * logged (CANCEL_FAILED, without values) and counted as failed; the other
 * runs are canceled all the same. The maintenance run cancels the ones left
 * active later (sweep()), and ends the steps and cancels the jobs that a
 * cancel which failed after claiming its run left behind (StrandedJobSweep).
 *
 * Then one workflow-template.deleted audit entry with the number of
 * canceled and failed runs, written also when the runs could not be read.
 * No access checks: the callers check.
 *
 * sweep() is for the maintenance run, which gives it a deadline: it starts
 * no cancel after it, and the runs left wait for the next sweep. A delete
 * has no deadline.
 */
class WorkflowTemplateDeletionService extends Component
{
    /** A save handler vetoed the soft delete; nothing was written. sprintf(): the template's ID. */
    public const DELETE_REFUSED = 'Deleting workflow template #%d was refused.';

    /**
     * Logged when canceling a run failed; sprintf(): the run ID, the workflow
     * template ID, then the exception's class, SQLSTATE and driver error
     * code (DbErrorSummary). Never the exception's message: a database
     * error's message carries the SQL with its values.
     *
     * A cancel that failed at or before its claim left the run active: the
     * maintenance run cancels it (sweep()). One that failed after its claim
     * (reading the run back, or its running steps) left a canceled run whose
     * steps still run and whose jobs still wait: the maintenance run ends
     * those steps and cancels those jobs (StrandedJobSweep).
     */
    public const CANCEL_FAILED = 'Canceling run #%d of deleted workflow template #%d failed: %s (SQLSTATE %s, '
        . 'driver error %s); the maintenance run cancels the run, or the jobs it left.';

    /**
     * Active runs one sweep cancels at most, oldest first; the rest follow
     * on the next sweeps. The batch bounds the memory and the work of a sweep;
     * its deadline (MaintenanceService::$jobTaskBudgetSeconds) bounds its
     * time, which the notification each cancel sends can stretch.
     */
    public const SWEEP_BATCH_SIZE = 100;

    /**
     * @param int|null $actorId the user, for the audit entries; null: the signed-in user, if any
     * @param string $source where the delete came from: web or api
     * @return array{deleted: bool, canceled_runs: int, failed_runs: int} deleted
     *     is false when the template was deleted already (by another request);
     *     nothing was done then. failed_runs: active runs whose cancel failed
     */
    public function delete(WorkflowTemplate $template, ?int $actorId, string $source): array
    {
        if (!$this->softDelete($template)) {
            return ['deleted' => false, 'canceled_runs' => 0, 'failed_runs' => 0];
        }

        $counts = ['canceled_runs' => 0, 'failed_runs' => 0];
        try {
            $counts = $this->cancelRuns($this->activeRuns(['workflow_template_id' => (int)$template->id], null), $actorId, null);
        } finally {
            /** @var AuditService $audit */
            $audit = \Yii::$app->get('auditService');
            $audit->log(
                AuditLog::ACTION_WORKFLOW_TEMPLATE_DELETED,
                'workflow_template',
                (int)$template->id,
                $actorId,
                ['name' => (string)$template->name] + $counts + ['source' => $source]
            );
        }

        return ['deleted' => true] + $counts;
    }

    /**
     * For the maintenance run: cancel the active runs of soft-deleted
     * workflow templates, oldest first, at most $limit, with no user as the
     * actor. Covers runs whose cancel failed during the delete, and runs of
     * templates deleted before deleting canceled them. No cancel starts once
     * $deadline has passed; a cancel that started is finished.
     *
     * @param float|null $deadline a microtime(true) value; null: no bound
     * @return array{canceled_runs: int, failed_runs: int, remaining_runs: int}
     *     remaining_runs: active runs of deleted templates still left, for the
     *     next run
     */
    public function sweep(int $limit = self::SWEEP_BATCH_SIZE, ?float $deadline = null): array
    {
        $condition = [
            'workflow_template_id' => WorkflowTemplate::findWithDeleted()->select('id')->where(['not', ['deleted_at' => null]]),
        ];
        $counts = $this->cancelRuns($this->activeRuns($condition, $limit), null, $deadline);
        $remaining = (int)WorkflowJob::find()->where($condition)->andWhere(['status' => WorkflowJob::activeStatuses()])->count();

        return $counts + ['remaining_runs' => $remaining];
    }

    /**
     * Lock the template's row and soft-delete it, in one transaction; false
     * when it is deleted already.
     *
     * @throws \RuntimeException (DELETE_REFUSED) when a save handler vetoes the delete
     */
    private function softDelete(WorkflowTemplate $template): bool
    {
        $transaction = \Yii::$app->db->beginTransaction();
        try {
            $live = WorkflowTemplate::lockLive((int)$template->id);
            if ($live && !$template->softDelete()) {
                throw new \RuntimeException(sprintf(self::DELETE_REFUSED, (int)$template->id));
            }
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        $transaction->commit();

        return $live;
    }

    /**
     * Cancel each of $runs until $deadline (null: no bound), which is checked
     * before each cancel starts; one that fails does not stop the others.
     *
     * @param list<WorkflowJob> $runs
     * @return array{canceled_runs: int, failed_runs: int}
     */
    private function cancelRuns(array $runs, ?int $actorId, ?float $deadline): array
    {
        $counts = ['canceled_runs' => 0, 'failed_runs' => 0];
        foreach ($runs as $run) {
            if ($deadline !== null && $this->now() >= $deadline) {
                break;
            }
            $canceled = $this->cancelRun($run, $actorId);
            if ($canceled === null) {
                $counts['failed_runs']++;
            } elseif ($canceled) {
                $counts['canceled_runs']++;
            }
        }

        return $counts;
    }

    /**
     * @return bool|null true when this call canceled the run, false when it
     *     had finished already, null when canceling it failed (logged)
     */
    private function cancelRun(WorkflowJob $run, ?int $actorId): ?bool
    {
        try {
            /** @var WorkflowCancellationService $cancellation */
            $cancellation = \Yii::$app->get('workflowCancellationService');

            return $cancellation->cancelRun($run, $actorId, JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED);
        } catch (\Throwable $e) {
            \Yii::error(
                sprintf(self::CANCEL_FAILED, (int)$run->id, (int)$run->workflow_template_id, ...DbErrorSummary::of($e)),
                __CLASS__
            );

            return null;
        }
    }

    /**
     * The active runs that match $condition, oldest first, at most $limit
     * (null: all).
     *
     * @param array<string, mixed> $condition on workflow_job columns
     * @return list<WorkflowJob>
     */
    private function activeRuns(array $condition, ?int $limit): array
    {
        /** @var list<WorkflowJob> $runs */
        $runs = WorkflowJob::find()
            ->where($condition)
            ->andWhere(['status' => WorkflowJob::activeStatuses()])
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit)
            ->all();

        return $runs;
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
