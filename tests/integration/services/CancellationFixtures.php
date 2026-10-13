<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\ApprovalService;
use app\services\JobCancellationService;
use app\services\WorkflowCancellationService;
use app\services\WorkflowExecutionService;

/**
 * Fixtures of the cancellation tests (job and workflow run cancels, the
 * approval requests they close); use in DbTestCase subclasses together with
 * TeamScopeFixtures.
 *
 * Approvers hold the operator role, so they may decide (approval.decide),
 * and are never the user who launched the job.
 */
trait CancellationFixtures
{
    private ?RecordingNotificationDispatcher $recordedNotifications = null;
    private mixed $dispatcherBeforeRecording = null;

    /**
     * Notifications are recorded, not sent, until restoreNotifications().
     */
    protected function recordNotifications(): RecordingNotificationDispatcher
    {
        $this->dispatcherBeforeRecording = \Yii::$app->getComponents(true)['notificationDispatcher'] ?? null;
        $this->recordedNotifications = new RecordingNotificationDispatcher();
        \Yii::$app->set('notificationDispatcher', $this->recordedNotifications);

        return $this->recordedNotifications;
    }

    protected function restoreNotifications(): void
    {
        if ($this->recordedNotifications !== null) {
            \Yii::$app->set('notificationDispatcher', $this->dispatcherBeforeRecording);
            $this->recordedNotifications = null;
        }
    }

    protected function cancelFixtureTemplate(int $userId): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    /**
     * A rule whose approvers are exactly $approverIds.
     */
    protected function usersRule(int $createdBy, int ...$approverIds): ApprovalRule
    {
        return $this->createApprovalRule(
            $createdBy,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => array_values($approverIds)]),
            1
        );
    }

    /**
     * A job of $template that waits for approval, with its pending request
     * created as a launch creates it.
     *
     * @return array{job: Job, request: ApprovalRequest, rule: ApprovalRule}
     */
    protected function jobWaitingForApproval(JobTemplate $template, int $launcherId, int $approverId): array
    {
        $job = $this->createJob((int)$template->id, $launcherId, Job::STATUS_PENDING_APPROVAL);
        $job->queued_at = null;
        $job->save(false);
        $rule = $this->usersRule($launcherId, $approverId);
        $request = $this->approvalService()->createRequest($job, $rule);

        return ['job' => $job, 'request' => $request, 'rule' => $rule];
    }

    /**
     * A run waiting at its first step, an approval step whose success route
     * is a job step and whose failure route is a pause step, so a route that
     * is taken leaves a running step behind.
     *
     * @return array{
     *     launcher: User,
     *     approver: User,
     *     template: JobTemplate,
     *     run: WorkflowJob,
     *     approval: WorkflowStep,
     *     onSuccess: WorkflowStep,
     *     onFailure: WorkflowStep,
     *     placeholder: Job,
     *     request: ApprovalRequest
     * }
     */
    protected function runAtApprovalStep(): array
    {
        $launcher = $this->createUserWithRole('cancel-run', 'operator');
        $approver = $this->createUserWithRole('cancel-run-approver', 'operator');
        $template = $this->cancelFixtureTemplate((int)$launcher->id);
        $workflow = $this->createWorkflowTemplate((int)$launcher->id);
        $rule = $this->usersRule((int)$launcher->id, (int)$approver->id);
        $approval = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $onSuccess = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$template->id);
        $onFailure = $this->createWorkflowStep((int)$workflow->id, 2, WorkflowStep::TYPE_PAUSE);
        $approval->on_success_step_id = $onSuccess->id;
        $approval->on_failure_step_id = $onFailure->id;
        $approval->save(false);

        $run = $this->workflowEngine()->launch($workflow, (int)$launcher->id);
        $execution = $this->executionOf($run, $approval);
        $this->assertNotNull($execution, 'the run waits at the approval step');
        $placeholder = Job::findOne($execution->job_id);
        $this->assertNotNull($placeholder);
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $placeholder->status);
        $request = ApprovalRequest::findOne(['job_id' => $placeholder->id]);
        $this->assertNotNull($request);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status);

        return [
            'launcher' => $launcher,
            'approver' => $approver,
            'template' => $template,
            'run' => $run,
            'approval' => $approval,
            'onSuccess' => $onSuccess,
            'onFailure' => $onFailure,
            'placeholder' => $placeholder,
            'request' => $request,
        ];
    }

    /**
     * The (last) execution of $step in $run; null when the step has not run.
     */
    protected function executionOf(WorkflowJob $run, WorkflowStep $step): ?WorkflowJobStep
    {
        return WorkflowJobStep::find()
            ->where(['workflow_job_id' => $run->id, 'workflow_step_id' => $step->id])
            ->orderBy(['id' => SORT_DESC])
            ->one();
    }

    /**
     * @return list<AuditLog>
     */
    protected function auditsOf(string $action, string $objectType, int $objectId): array
    {
        /** @var list<AuditLog> $entries */
        $entries = AuditLog::find()
            ->where(['action' => $action, 'object_type' => $objectType, 'object_id' => $objectId])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        return $entries;
    }

    /**
     * The one audit entry of $action for the object, which must exist.
     */
    protected function onlyAuditOf(string $action, string $objectType, int $objectId): AuditLog
    {
        $entries = $this->auditsOf($action, $objectType, $objectId);
        $this->assertCount(1, $entries, "{$action} for {$objectType} #{$objectId}");

        return $entries[0];
    }

    /**
     * @return array<string, mixed>|null the entry's context; null when it has none
     */
    protected function contextOf(AuditLog $entry): ?array
    {
        if ($entry->metadata === null) {
            return null;
        }
        $decoded = json_decode($entry->metadata, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    protected function approvalService(): ApprovalService
    {
        /** @var ApprovalService $service */
        $service = \Yii::$app->get('approvalService');

        return $service;
    }

    protected function jobCancellation(): JobCancellationService
    {
        /** @var JobCancellationService $service */
        $service = \Yii::$app->get('jobCancellationService');

        return $service;
    }

    protected function runCancellation(): WorkflowCancellationService
    {
        /** @var WorkflowCancellationService $service */
        $service = \Yii::$app->get('workflowCancellationService');

        return $service;
    }

    protected function workflowEngine(): WorkflowExecutionService
    {
        /** @var WorkflowExecutionService $service */
        $service = \Yii::$app->get('workflowExecutionService');

        return $service;
    }
}
