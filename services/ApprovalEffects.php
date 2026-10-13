<?php

declare(strict_types=1);

namespace app\services;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Job;
use app\models\NotificationTemplate;
use app\models\WorkflowJob;
use app\services\notification\JobPayloadBuilder;

/**
 * The side effects of approval requests, run after the transaction that
 * wrote the request committed, so they never hold its row lock and the
 * workflow engine reads the request's final status:
 * - a new request: approval.requested audit entry (attributed to the user
 *   who launched the job, whoever's session started the launch) and
 *   notification;
 * - a settlement (ApprovalOutcome): approval.decided for a recorded vote,
 *   approval.timed_out for a timeout, approval.canceled for a request
 *   closed because its job no longer waited (with the reason), and
 *   job.canceled for a job the settlement canceled; the approval.approved
 *   or approval.rejected notification once a vote decided the request (a
 *   timeout sends none); and the workflow engine's onApprovalResolved()
 *   when the outcome says so (ApprovalOutcome::workflowDecision()).
 *
 * A job the settlement canceled gets what the cancel it stands in for
 * would have written:
 * - the job of an ended workflow step or run, as the stranded-job sweep
 *   cancels it (StrandedJobSweep): job.canceled with the reason the run was
 *   canceled for (WorkflowCancellationService::cancelOf() and jobReason())
 *   and the run's ID; no notification and no workflow advance;
 * - a job of a deleted job template, as the template delete's cancel does
 *   (JobCancellationService::cancel(), REASON_TEMPLATE_DELETED): job.canceled
 *   with that reason, the job.canceled notification and, for the job of a
 *   workflow step, the workflow advance, which ends the step as a canceled
 *   job whose gate never opened (WorkflowStepRouter::closedGate()).
 */
final class ApprovalEffects
{
    /**
     * The launcher asks for the approval: the audit entry names them, also
     * when the launch ran in another user's request (a workflow step started
     * by an approver's vote) or in no request at all (a schedule).
     */
    public function requested(ApprovalRequest $request, Job $job, ApprovalRule $rule): void
    {
        $this->audit(
            AuditLog::ACTION_APPROVAL_REQUESTED,
            'approval_request',
            (int)$request->id,
            (int)$job->launched_by,
            ['job_id' => (int)$job->id, 'rule_id' => (int)$rule->id, 'rule_name' => (string)$rule->name]
        );
        $this->notify(NotificationTemplate::EVENT_APPROVAL_REQUESTED, $request, $job, $rule);
    }

    /**
     * @param int|null $actorId the voter; null for a timeout (no user)
     */
    public function settled(ApprovalOutcome $outcome, ?int $actorId): void
    {
        $this->auditOutcome($outcome, $actorId);
        $event = match ($outcome->vote === null ? null : $outcome->resolution) {
            ApprovalRequest::STATUS_APPROVED => NotificationTemplate::EVENT_APPROVAL_APPROVED,
            ApprovalRequest::STATUS_REJECTED => NotificationTemplate::EVENT_APPROVAL_REJECTED,
            default => null,
        };
        if ($event !== null) {
            $this->notify($event, $outcome->request, $outcome->job, $outcome->rule);
        }
        if ($outcome->canceledForDeletedTemplate()) {
            $this->endJobOfDeletedTemplate($outcome);
            return;
        }
        $this->advanceWorkflow($outcome);
    }

    private function auditOutcome(ApprovalOutcome $outcome, ?int $actorId): void
    {
        $request = $outcome->request;
        $requestId = (int)$request->id;
        $jobId = (int)$request->job_id;
        if ($outcome->vote !== null) {
            $this->audit(AuditLog::ACTION_APPROVAL_DECIDED, 'approval_request', $requestId, $actorId, [
                'decision' => (string)$outcome->vote->decision,
                'job_id' => $jobId,
            ]);
        }
        if ($outcome->timeout !== []) {
            $this->audit(AuditLog::ACTION_APPROVAL_TIMED_OUT, 'approval_request', $requestId, null, $outcome->timeout);
        }
        if ($outcome->resolution === ApprovalRequest::STATUS_CANCELED) {
            $this->audit(AuditLog::ACTION_APPROVAL_CANCELED, 'approval_request', $requestId, $actorId, [
                'job_id' => $jobId,
                // As stored: a rule ID, or null once the rule was deleted.
                'rule_id' => $request->getAttribute('approval_rule_id'),
                'reason' => (string)$outcome->closeReason,
            ]);
        }
        $run = $outcome->canceledRun();
        if ($run !== null) {
            // As JobCancellationService::cancelWithinWorkflow() records the cancel of a run's job.
            $this->audit(AuditLog::ACTION_JOB_CANCELED, 'job', $jobId, $actorId, [
                'reason' => $this->runJobReason($run),
                'workflow_job_id' => (int)$run->id,
            ]);
        }
        if ($outcome->canceledForDeletedTemplate()) {
            // As JobCancellationService::cancel() records the cancel of a deleted template's job.
            $this->audit(AuditLog::ACTION_JOB_CANCELED, 'job', $jobId, $actorId, [
                'reason' => JobCancellationService::REASON_TEMPLATE_DELETED,
            ]);
        }
    }

    /**
     * The reason the jobs of $run are canceled for: the reason the run was
     * canceled for (its newest workflow.canceled audit entry, else the state
     * of its workflow template), as the stranded-job sweep and a step that
     * starts in a canceled run take it.
     */
    private function runJobReason(WorkflowJob $run): string
    {
        /** @var WorkflowCancellationService $runs */
        $runs = \Yii::$app->get('workflowCancellationService');

        return WorkflowCancellationService::jobReason($runs->cancelOf($run)['reason']);
    }

    /**
     * The rest of what the template delete's cancel of the job would have
     * done after its audit entries: the job.canceled notification, and for
     * the job of a workflow step the workflow advance, as for any canceled
     * job (JobCancellationService::cancel()).
     */
    private function endJobOfDeletedTemplate(ApprovalOutcome $outcome): void
    {
        /** @var Job $job the settlement canceled it, so it exists */
        $job = $outcome->job;
        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');
        $dispatcher->dispatch(NotificationTemplate::EVENT_JOB_CANCELED, JobPayloadBuilder::build($job));
        if ($outcome->step === null) {
            return;
        }

        /** @var WorkflowExecutionService $engine */
        $engine = \Yii::$app->get('workflowExecutionService');
        $engine->onChildJobCompleted($job);
    }

    /**
     * Contract with the workflow engine: an approved placeholder of an
     * approval step succeeds its step; a rejected job of a workflow step
     * (placeholder or gated job step) closes the step's gate. The engine
     * reads the request's status (rejected, timed_out) for its message.
     */
    private function advanceWorkflow(ApprovalOutcome $outcome): void
    {
        $job = $outcome->job;
        $decision = $outcome->workflowDecision();
        if ($job === null || $decision === null) {
            return;
        }

        /** @var WorkflowExecutionService $engine */
        $engine = \Yii::$app->get('workflowExecutionService');
        $engine->onApprovalResolved($job, $decision);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function audit(string $action, string $objectType, int $objectId, ?int $userId, array $context): void
    {
        /** @var AuditService $audit */
        $audit = \Yii::$app->get('auditService');
        $audit->log($action, $objectType, $objectId, $userId, $context);
    }

    private function notify(string $event, ApprovalRequest $request, ?Job $job, ?ApprovalRule $rule): void
    {
        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');
        $dispatcher->dispatch($event, [
            'approval' => [
                'id' => (string)$request->id,
                'status' => (string)$request->status,
                'rule_id' => (string)($rule?->id ?? ''),
                'rule_name' => (string)($rule?->name ?? ''),
            ],
            'job' => [
                'id' => (string)($job?->id ?? ''),
                'status' => (string)($job?->status ?? ''),
                'template_id' => (string)($job?->job_template_id ?? ''),
            ],
        ]);
    }
}
