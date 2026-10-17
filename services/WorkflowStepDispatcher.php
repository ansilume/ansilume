<?php

declare(strict_types=1);

namespace app\services;

use app\components\DbErrorSummary;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;

/**
 * Starts the work of a step execution for WorkflowExecutionService: a job
 * step launches its job (with the run's extra vars), an approval step asks
 * for the decision, a pause step waits to be resumed.
 *
 * A step that cannot start is not failed here: dispatch() returns why, and
 * the engine fails the step and the run without following its branches, as
 * the steps after it depend on a step that never ran. That includes any
 * error while a job step launches its job or an approval step writes its
 * request: such a step fails with LAUNCH_FAILED or REQUEST_FAILED, and the
 * log names the error's class, SQLSTATE and driver code (never its message,
 * which can carry SQL with values), so no run stays active with a step that
 * nothing moves on. Once a step started, settleStart() squares it with what
 * happened meanwhile: its run was canceled, or its job ended before the
 * step was linked to it.
 */
final class WorkflowStepDispatcher
{
    /** A step type the model refuses, saved by older versions: the type as stored. */
    public const UNKNOWN_TYPE = 'Not run: unknown step type "%s".';

    /** A job step whose job template was deleted. */
    public const TEMPLATE_GONE = 'The job template of this step no longer exists.';

    /** A job step whose launch failed for a reason the user cannot fix; the log has it. */
    public const LAUNCH_FAILED = 'The job could not be launched; see the application log.';

    /**
     * Logged with LAUNCH_FAILED; sprintf(): the run ID, its job template's ID,
     * the step's ID (its execution, as in the warning the engine logs next),
     * then the exception's class, SQLSTATE and driver error code
     * (DbErrorSummary).
     */
    public const LAUNCH_FAILED_LOG = 'Workflow job #%d: launching job template #%d for step #%d failed: %s '
        . '(SQLSTATE %s, driver error %s).';

    /** An approval step without its rule (saved without one, or cleared by migration m000083). */
    public const RULE_GONE = 'The approval rule of this step no longer exists.';

    /** An approval step whose request could not be written; the log has why. */
    public const REQUEST_FAILED = 'The approval request could not be created; see the application log.';

    /**
     * Logged with REQUEST_FAILED; sprintf(): the run ID, the step's ID (its
     * execution), then the exception's class, SQLSTATE and driver error code
     * (DbErrorSummary).
     */
    public const REQUEST_FAILED_LOG = 'Workflow job #%d: the approval request of step #%d failed: %s '
        . '(SQLSTATE %s, driver error %s).';

    /**
     * Logged when the approval request of a step was written but announcing
     * it (its approval.requested audit entry and notification) failed; the
     * step waits for the decision all the same. sprintf(): the run ID, the
     * request ID, the step's ID (its execution), then the exception's class,
     * SQLSTATE and driver error code (DbErrorSummary).
     */
    public const REQUEST_ANNOUNCE_FAILED_LOG = 'Workflow job #%d: approval request #%d of step #%d was created, but '
        . 'its audit entry or notification failed: %s (SQLSTATE %s, driver error %s).';

    /**
     * Reads a step's job as last committed. As a locking read it first waits
     * for a transaction that is writing the job (a vote, a timeout, a cancel,
     * a runner's report) to end.
     */
    private const JOB_READ_SQL = 'SELECT * FROM {{%job}} WHERE [[id]] = :id LOCK IN SHARE MODE';

    /**
     * Start $execution, the execution of $step in $run.
     *
     * @param array<int|string, mixed> $mappedVars the step's mapping of the previous step's output
     * @return array{reason: string, denied: array<string, int>|null}|null why the
     *     step could not start (denied: the context of an access refusal, which
     *     is audited); null when it started
     */
    public function dispatch(
        WorkflowJob $run,
        WorkflowJobStep $execution,
        WorkflowStep $step,
        #[\SensitiveParameter] array $mappedVars
    ): ?array {
        return match ($step->step_type) {
            WorkflowStep::TYPE_JOB => $this->jobStep($run, $execution, $step, $mappedVars),
            WorkflowStep::TYPE_APPROVAL => $this->approvalStep($run, $execution, $step),
            // A pause step stays running until it is resumed.
            WorkflowStep::TYPE_PAUSE => null,
            // A type the model refuses, which older versions saved from a JSON
            // body (true as "1"): nothing would ever end the step.
            default => self::refusal(sprintf(self::UNKNOWN_TYPE, $step->step_type)),
        };
    }

    /**
     * Square the start of $execution, the execution of a step of $run, with
     * what happened while it started; returns the job the engine ends the
     * step with (WorkflowExecutionService::onChildJobCompleted()), or null.
     *
     * - The run was canceled meanwhile: WorkflowCancellationService claims
     *   the run first and then ends the running steps it finds, so a step
     *   that started after it looked (or whose job was linked after it
     *   looked) is ended here, with the cancel's step message, and its job
     *   canceled with its pending approval request, for the cancel's reason
     *   and by its user (WorkflowCancellationService::cancelOf()). Either the
     *   cancel sees the linked job or this sees the canceled run, so no job
     *   of a canceled run is left to run or to be decided.
     * - A job step's job ended before the step was linked to it: the launch
     *   commits the job (and its approval request, which it announces)
     *   before it returns, so a vote, a timeout, a cancel or the runner's
     *   report in that window found no step to move on, and the step would
     *   wait for ever. That job is returned. It is read after the link with a
     *   locking read, which waits for a transaction still writing it: either
     *   that writer finds the link and moves the step on itself, or this
     *   read sees what it wrote. Should both, the step's conditional
     *   transition lets only one of them route the run.
     *
     * An approval step's placeholder is linked in the transaction that writes
     * its request, so nothing can decide or cancel it before the link.
     */
    public function settleStart(WorkflowJob $run, WorkflowJobStep $execution): ?Job
    {
        if (!WorkflowJob::find()->where(['id' => $run->id, 'status' => WorkflowJob::activeStatuses()])->exists()) {
            $this->abandon($run, $execution);
            return null;
        }
        if ($execution->job_id === null || $execution->stepType() !== WorkflowStep::TYPE_JOB) {
            return null;
        }
        $job = Job::findBySql(self::JOB_READ_SQL, [':id' => $execution->job_id])->one();

        return $job instanceof Job && $job->isFinished() ? $job : null;
    }

    /**
     * End $execution of the canceled $run with the cancel's step message, and
     * cancel its job with its pending approval request, for the cancel's
     * reason and by its user, as the cancel would have (see settleStart()).
     */
    private function abandon(WorkflowJob $run, WorkflowJobStep $execution): void
    {
        /** @var WorkflowCancellationService $cancellation */
        $cancellation = \Yii::$app->get('workflowCancellationService');
        $cancel = $cancellation->cancelOf($run);
        $now = time();
        WorkflowJobStep::updateAll(
            [
                'status' => WorkflowJobStep::STATUS_FAILED,
                'finished_at' => $now,
                'error_message' => WorkflowCancellationService::stepMessage($cancel['reason']),
                'updated_at' => $now,
            ],
            ['id' => $execution->id, 'status' => WorkflowJobStep::STATUS_RUNNING]
        );
        $execution->refresh();
        /** @var Job|null $job */
        $job = $execution->job_id === null ? null : Job::findOne(['id' => $execution->job_id]);
        if ($job !== null) {
            /** @var JobCancellationService $jobs */
            $jobs = \Yii::$app->get('jobCancellationService');
            $jobs->cancelWithinWorkflow(
                $job,
                $cancel['actor_id'],
                (int)$run->id,
                WorkflowCancellationService::jobReason($cancel['reason'])
            );
        }
    }

    /**
     * Launch the step's job as the user the run runs as, after checking that
     * this user may still launch it (team membership can change while a run
     * waits). The job gets the run's launch extra vars and password answers.
     * An error anywhere in that (reading the template, the check, the launch)
     * is LAUNCH_FAILED: JobLaunchService commits nothing when it throws.
     *
     * @param array<int|string, mixed> $mappedVars
     * @return array{reason: string, denied: array<string, int>|null}|null
     */
    private function jobStep(
        WorkflowJob $run,
        WorkflowJobStep $execution,
        WorkflowStep $step,
        #[\SensitiveParameter] array $mappedVars
    ): ?array {
        try {
            /** @var JobTemplate|null $template */
            $template = $step->jobTemplate;
            if ($template === null) {
                return self::refusal(self::TEMPLATE_GONE);
            }
            $launchedBy = (int)$run->launched_by;
            /** @var WorkflowAccessChecker $access */
            $access = \Yii::$app->get('workflowAccessChecker');
            if (!$access->canDispatch($launchedBy, $template)) {
                return self::refusal(
                    sprintf(WorkflowExecutionService::JOB_STEP_REFUSAL, $launchedBy, $template->name, $template->id),
                    ['job_template_id' => (int)$template->id, 'project_id' => (int)$template->project_id]
                );
            }
            /** @var WorkflowRunVars $runVars */
            $runVars = \Yii::$app->get('workflowRunVars');
            /** @var JobLaunchService $launcher */
            $launcher = \Yii::$app->get('jobLaunchService');
            $job = $launcher->launch($template, $launchedBy, $runVars->jobOverrides($run, $mappedVars));
        } catch (JobLaunchRejectedException $e) {
            return self::refusal($e->getMessage());
        } catch (\Throwable $e) {
            \Yii::error(sprintf(
                self::LAUNCH_FAILED_LOG,
                (int)$run->id,
                (int)$step->job_template_id,
                (int)$execution->id,
                ...DbErrorSummary::of($e)
            ), __CLASS__);
            return self::refusal(self::LAUNCH_FAILED);
        }

        $execution->job_id = $job->id;
        $execution->save(false);

        return null;
    }

    /**
     * Ask for the decision: a placeholder job (never executed, it only holds
     * the request) and its approval request, written in one transaction, so
     * a cancel never finds the one without the other; the request is
     * announced (audit entry, notification) after the commit, best-effort.
     * Refused without either when the rule is gone, or when too few of its
     * approvers besides the user who started the run may decide: such a
     * request could never be approved. An error on the way (reading the
     * rule, the check, writing) is REQUEST_FAILED.
     *
     * @return array{reason: string, denied: array<string, int>|null}|null
     */
    private function approvalStep(WorkflowJob $run, WorkflowJobStep $execution, WorkflowStep $step): ?array
    {
        /** @var ApprovalService $approvals */
        $approvals = \Yii::$app->get('approvalService');
        try {
            /** @var ApprovalRule|null $rule */
            $rule = $step->approvalRule;
            if ($rule === null) {
                return self::refusal(self::RULE_GONE);
            }
            /** @var ApproverEligibility $eligibility */
            $eligibility = \Yii::$app->get('approverEligibility');
            $refusal = $eligibility->workflowStepRefusal($rule, $run);
            if ($refusal !== null) {
                return self::refusal($refusal);
            }
            /** @var array{job: Job, request: ApprovalRequest} $opened */
            $opened = Job::getDb()->transaction(static function () use ($run, $execution, $rule, $approvals): array {
                $job = new Job();
                $job->job_template_id = null;
                $job->launched_by = $run->launched_by;
                $job->status = Job::STATUS_PENDING_APPROVAL;
                $job->timeout_minutes = 0;
                $job->has_changes = 0;
                $job->created_at = time();
                $job->updated_at = time();
                $job->save(false);

                $execution->job_id = $job->id;
                $execution->save(false);

                return ['job' => $job, 'request' => $approvals->openRequest($job, $rule, ApprovalRequest::REQUIRED_BY_APPROVAL_STEP)];
            });
        } catch (\Throwable $e) {
            \Yii::error(sprintf(self::REQUEST_FAILED_LOG, (int)$run->id, (int)$execution->id, ...DbErrorSummary::of($e)), __CLASS__);
            // The placeholder and its link went with the rollback.
            $execution->job_id = null;
            return self::refusal(self::REQUEST_FAILED);
        }
        try {
            $approvals->announceRequest($opened['request'], $opened['job'], $rule);
        } catch (\Throwable $e) {
            \Yii::error(sprintf(
                self::REQUEST_ANNOUNCE_FAILED_LOG,
                (int)$run->id,
                (int)$opened['request']->id,
                (int)$execution->id,
                ...DbErrorSummary::of($e)
            ), __CLASS__);
        }

        return null;
    }

    /**
     * @param array<string, int>|null $denied
     * @return array{reason: string, denied: array<string, int>|null}
     */
    private static function refusal(string $reason, ?array $denied = null): array
    {
        return ['reason' => $reason, 'denied' => $denied];
    }
}
