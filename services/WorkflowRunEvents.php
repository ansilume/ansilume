<?php

declare(strict_types=1);

namespace app\services;

use app\components\DbErrorSummary;
use app\models\AuditLog;
use app\models\Job;
use app\models\NotificationTemplate;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowTemplate;

/**
 * What a workflow run announces, for WorkflowExecutionService and
 * WorkflowCancellationService: the notifications of its events, all with
 * the same payload, and the end of a step that ended with its job.
 *
 * Notifications are best-effort: they are sent after the run's state was
 * written, so a notification that fails (reading the run's template or the
 * notification templates, rendering one) is logged (NOTIFY_FAILED, without
 * values) and the run goes on. A run must not stay active, with nothing
 * left to move it on, because a notification failed.
 */
final class WorkflowRunEvents
{
    /**
     * Logged when a notification of a run failed; sprintf(): the run ID, the
     * event, then the exception's class, SQLSTATE and driver error code
     * (DbErrorSummary).
     */
    public const NOTIFY_FAILED = 'Workflow job #%d: the %s notification failed: %s (SQLSTATE %s, driver error %s).';

    /**
     * $execution of $run ended with $job, through its runner's report, a
     * cancel or an approval decision: the workflow.step.completed audit entry
     * ({status, job_id}) and, when the step did not succeed, the
     * workflow.step_failed notification. $execution shows its final status.
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
        if ($execution->status === WorkflowJobStep::STATUS_SUCCEEDED) {
            return;
        }
        $this->notify(
            NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED,
            $run,
            ['step' => ['id' => (string)$execution->id, 'job_id' => (string)$job->id]]
        );
    }

    /**
     * The notification of $event for $run, with the payload of every
     * workflow event: the run's ID and status as $run shows them, its
     * template's ID and name.
     *
     * @param array<string, mixed> $extra more of the payload
     */
    public function notify(string $event, WorkflowJob $run, array $extra = []): void
    {
        try {
            /** @var WorkflowTemplate|null $template */
            $template = $run->workflowTemplate;
            /** @var NotificationDispatcher $dispatcher */
            $dispatcher = \Yii::$app->get('notificationDispatcher');
            $dispatcher->dispatch($event, array_merge([
                'workflow' => [
                    'id' => (string)$run->id,
                    'status' => (string)$run->status,
                    'template_id' => (string)($template?->id ?? ''),
                    'template_name' => (string)($template?->name ?? ''),
                ],
            ], $extra));
        } catch (\Throwable $e) {
            \Yii::error(sprintf(self::NOTIFY_FAILED, (int)$run->id, $event, ...DbErrorSummary::of($e)), __CLASS__);
        }
    }
}
