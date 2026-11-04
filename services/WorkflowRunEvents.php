<?php

declare(strict_types=1);

namespace app\services;

use app\components\AppLinks;
use app\components\DbErrorSummary;
use app\models\AuditLog;
use app\models\Job;
use app\models\NotificationTemplate;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\services\notification\RunContext;
use app\services\runstate\Display;

/**
 * What a workflow run announces, for WorkflowExecutionService and
 * WorkflowCancellationService: the notifications of its events, and the end
 * of a step that ended with its job. Each is announced once per transition,
 * by the caller whose conditional update made the transition:
 * - stepStarted(): workflow.step_started, while the step still runs, then
 *   workflow.waiting when it waits for a person (WAITS_FOR_A_PERSON);
 * - stepSucceeded(): workflow.step_succeeded of a resumed pause step;
 * - stepEnded(): the end of a step with its job (its runner's report, a
 *   cancel, an approval decision): workflow.step_succeeded or
 *   workflow.step_failed;
 * - stepFailed(): workflow.step_failed of a step that could not start;
 * - notify(): an event of the whole run (launched, succeeded, failed,
 *   canceled);
 * - statusChanged(): no event, but a refresh of the run's status posts.
 *
 * Every event carries the run's context as it is then (RunContext): the
 * run, and the step it is about, for an event of the whole run its newest
 * step; a step that waits for an approval links the request
 * (approval.url). Its refs name the run, and for a step event also the
 * step's job and approval request.
 *
 * Notifications are best-effort: they are sent after the run's state was
 * written, so a notification that fails (reading the run's context or the
 * notification templates) is logged (NOTIFY_FAILED, without values) and the
 * run goes on. A run must not stay active, with nothing left to move it on,
 * because a notification failed.
 */
final class WorkflowRunEvents
{
    /**
     * Logged when a notification of a run failed; sprintf(): the run ID, the
     * event, then the exception's class, SQLSTATE and driver error code
     * (DbErrorSummary).
     */
    public const NOTIFY_FAILED = 'Workflow job #%d: the %s notification failed: %s (SQLSTATE %s, driver error %s).';

    /** What a run waits for that workflow.waiting announces: a person, to approve or to resume it. */
    public const WAITS_FOR_A_PERSON = [Display::WAITING_FOR_APPROVAL, Display::WAITING_FOR_RESUME];

    /**
     * $execution of $run started, and still runs: workflow.step_started,
     * then workflow.waiting when the step waits for a person.
     */
    public function stepStarted(WorkflowJob $run, WorkflowJobStep $execution): void
    {
        $context = $this->announce(NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED, $run, static fn (): RunContext => RunContext::of($run, $execution), true);
        if ($context !== null && in_array($context->waitingFor, self::WAITS_FOR_A_PERSON, true)) {
            $this->announce(NotificationTemplate::EVENT_WORKFLOW_WAITING, $run, static fn (): RunContext => $context, true);
        }
    }

    /**
     * $execution, a pause step of $run, was resumed: workflow.step_succeeded.
     */
    public function stepSucceeded(WorkflowJob $run, WorkflowJobStep $execution): void
    {
        $this->announce(NotificationTemplate::EVENT_WORKFLOW_STEP_SUCCEEDED, $run, static fn (): RunContext => RunContext::of($run, $execution), true);
    }

    /**
     * $execution of $run ended with $job, through its runner's report, a
     * cancel or an approval decision: the workflow.step.completed audit entry
     * ({status, job_id}) and workflow.step_succeeded, or workflow.step_failed
     * when the step did not succeed. $execution shows its final status.
     */
    public function stepEnded(WorkflowJob $run, WorkflowJobStep $execution, Job $job): void
    {
        /** @var AuditService $audit */
        $audit = \Yii::$app->get('auditService');
        $audit->log(
            AuditLog::ACTION_WORKFLOW_STEP_COMPLETED,
            'workflow_job_step',
            (int)$execution->id,
            null,
            ['status' => $execution->status, 'job_id' => $job->id]
        );
        $event = $execution->status === WorkflowJobStep::STATUS_SUCCEEDED
            ? NotificationTemplate::EVENT_WORKFLOW_STEP_SUCCEEDED
            : NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED;
        $this->announce($event, $run, static fn (): RunContext => RunContext::of($run, $execution, (int)$job->id), true);
    }

    /**
     * $execution of $run could not start, and failed: workflow.step_failed,
     * which says why (the step's error).
     */
    public function stepFailed(WorkflowJob $run, WorkflowJobStep $execution): void
    {
        $this->announce(NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED, $run, static fn (): RunContext => RunContext::of($run, $execution), true);
    }

    /**
     * The notification of $event, an event of the whole run $run (launched,
     * succeeded, failed, canceled), with the run as $run shows it and its
     * newest step; its refs name the run.
     */
    public function notify(string $event, WorkflowJob $run): void
    {
        $this->announce($event, $run, static fn (): RunContext => RunContext::ofRun($run), false);
    }

    /**
     * The state of $run changed without an event of its own (an approval of
     * one of its steps was settled, also by a timeout, which sends none):
     * its status posts are refreshed (NotificationDispatcher::refreshStatus()).
     */
    public function statusChanged(WorkflowJob $run): void
    {
        self::dispatcher()->refreshStatus((int)$run->id);
    }

    /**
     * Dispatches $event of $run with the context $context builds, and
     * answers that context; null when building it or dispatching failed
     * (logged, NOTIFY_FAILED). The refs of a step event ($ofStep) also name
     * the step's job and approval request.
     *
     * @param \Closure(): RunContext $context
     */
    private function announce(string $event, WorkflowJob $run, \Closure $context, bool $ofStep): ?RunContext
    {
        try {
            $built = $context();
            $payload = $built->payload();
            $refs = ['workflow_job_id' => (int)$run->id];
            if ($built->approvalRequestId !== null) {
                $payload['approval'] = ['url' => AppLinks::approval($built->approvalRequestId)];
            }
            if ($ofStep) {
                $refs += array_filter(['job_id' => $built->jobId, 'approval_request_id' => $built->approvalRequestId], static fn (?int $id): bool => $id !== null);
            }
            $payload[NotificationDispatcher::REFS] = $refs;
            self::dispatcher()->dispatch($event, $payload);
        } catch (\Throwable $e) {
            \Yii::error(sprintf(self::NOTIFY_FAILED, (int)$run->id, $event, ...DbErrorSummary::of($e)), __CLASS__);

            return null;
        }

        return $built;
    }

    private static function dispatcher(): NotificationDispatcher
    {
        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');

        return $dispatcher;
    }
}
