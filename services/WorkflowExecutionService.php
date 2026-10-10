<?php

declare(strict_types=1);

namespace app\services;

use app\models\AuditLog;
use app\models\Job;
use app\models\NotificationTemplate;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use yii\base\Component;

/**
 * Orchestrates workflow execution: launching, step advancement,
 * variable passing, and completion.
 */
class WorkflowExecutionService extends Component
{
    /**
     * Why a job step was not launched, a sprintf() format: the user the
     * workflow runs as (#id) may not launch the step's job template (name,
     * #id). E2eWorkflowScopingSeeder seeds a run refused with it.
     */
    public const JOB_STEP_REFUSAL = 'Not launched: user #%d, whom this workflow runs as, may not launch job template "%s" (#%d).';

    /**
     * Launch a workflow from a template.
     *
     * @param array<string, mixed> $overrides
     * @param string $source web, api or trigger
     * @throws WorkflowAccessDeniedException when $launchedBy may not run every job step
     * @throws \RuntimeException when the template has no steps or the run cannot be saved
     */
    public function launch(
        WorkflowTemplate $template,
        int $launchedBy,
        array $overrides = [],
        string $source = 'web'
    ): WorkflowJob {
        $startStep = $template->getStartStep();
        if ($startStep === null) {
            throw new \RuntimeException('Workflow template has no steps.');
        }
        $this->access()->assertMayLaunch($launchedBy, $template, $source);

        $wfJob = new WorkflowJob();
        $wfJob->workflow_template_id = $template->id;
        $wfJob->launched_by = $launchedBy;
        $wfJob->status = WorkflowJob::STATUS_RUNNING;
        $wfJob->started_at = time();
        $wfJob->created_at = time();
        $wfJob->updated_at = time();

        if (!$wfJob->save()) {
            throw new \RuntimeException(
                'Failed to create workflow job: ' . json_encode($wfJob->errors)
            );
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_WORKFLOW_LAUNCHED,
            'workflow_job',
            $wfJob->id,
            $launchedBy,
            ['template_id' => $template->id, 'template_name' => $template->name, 'source' => $source]
        );

        $this->dispatchNotification(NotificationTemplate::EVENT_WORKFLOW_LAUNCHED, $wfJob, $template);

        $this->executeStep($wfJob, $startStep, $overrides);

        return $wfJob;
    }

    /**
     * Execute a single step within a workflow.
     *
     * @param array<string, mixed> $overrides
     */
    public function executeStep(
        WorkflowJob $wfJob,
        WorkflowStep $step,
        array $overrides = []
    ): WorkflowJobStep {
        $wjs = new WorkflowJobStep();
        $wjs->workflow_job_id = $wfJob->id;
        $wjs->workflow_step_id = $step->id;
        $wjs->status = WorkflowJobStep::STATUS_RUNNING;
        $wjs->started_at = time();
        $wjs->created_at = time();
        $wjs->updated_at = time();

        if (!$wjs->save()) {
            throw new \RuntimeException(
                'Failed to create workflow job step: ' . json_encode($wjs->errors)
            );
        }

        $wfJob->current_step_id = $step->id;
        $wfJob->save(false);

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_WORKFLOW_STEP_STARTED,
            'workflow_job_step',
            $wjs->id,
            null,
            ['step_name' => $step->name, 'step_type' => $step->step_type]
        );

        $this->dispatchStep($wfJob, $wjs, $step, $overrides);

        return $wjs;
    }

    /**
     * Called when a child job completes. Advances the workflow.
     */
    public function onChildJobCompleted(Job $job): void
    {
        /** @var WorkflowJobStep|null $wjs */
        $wjs = WorkflowJobStep::findOne(['job_id' => $job->id]);
        if ($wjs === null) {
            return;
        }

        $succeeded = $job->status === Job::STATUS_SUCCEEDED;
        $this->finalizeWorkflowStep($wjs, $job, $succeeded);

        /** @var WorkflowJob|null $wfJob */
        $wfJob = $wjs->workflowJob;
        if ($wfJob === null || $wfJob->isFinished()) {
            return;
        }

        $this->advanceAfterStep($wfJob, $wjs, $succeeded);
    }

    /**
     * Persist the finished status, emit audit, and fire the step-failed
     * notification. Extracted from onChildJobCompleted to keep the main
     * control flow below PHPMD's complexity threshold.
     */
    private function finalizeWorkflowStep(WorkflowJobStep $wjs, Job $job, bool $succeeded): void
    {
        $wjs->status = $succeeded
            ? WorkflowJobStep::STATUS_SUCCEEDED
            : WorkflowJobStep::STATUS_FAILED;
        $wjs->finished_at = time();
        $wjs->save(false);

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_WORKFLOW_STEP_COMPLETED,
            'workflow_job_step',
            $wjs->id,
            null,
            ['status' => $wjs->status, 'job_id' => $job->id]
        );

        if ($succeeded) {
            return;
        }

        $wfJob = $wjs->workflowJob;
        $this->dispatchNotification(
            NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED,
            $wfJob,
            $wfJob !== null ? $wfJob->workflowTemplate : null,
            ['step' => ['id' => (string)$wjs->id, 'job_id' => (string)$job->id]]
        );
    }

    /**
     * Resolve the next step to run, or complete the workflow if there is none.
     */
    private function advanceAfterStep(WorkflowJob $wfJob, WorkflowJobStep $wjs, bool $succeeded): void
    {
        /** @var WorkflowStep|null $step */
        $step = $wjs->workflowStep;
        if ($step === null) {
            $this->completeWorkflow($wfJob, WorkflowJob::STATUS_FAILED);
            return;
        }

        $nextStep = $this->resolveNextStep($step, $succeeded);
        if ($nextStep === null) {
            $finalStatus = $succeeded
                ? WorkflowJob::STATUS_SUCCEEDED
                : WorkflowJob::STATUS_FAILED;
            $this->completeWorkflow($wfJob, $finalStatus);
            return;
        }

        $extraVars = $this->buildStepExtraVars($wjs, $nextStep);
        $overrides = $extraVars !== [] ? ['extra_vars' => $extraVars] : [];
        $this->executeStep($wfJob, $nextStep, $overrides);
    }

    /**
     * Resume a paused workflow step. The pause step is marked as succeeded
     * and the workflow advances to the next step.
     *
     * Access is checked first, so a user who may not operate the workflow
     * learns nothing about the run's state.
     *
     * @throws WorkflowAccessDeniedException when $userId may not operate every job step
     * @throws \RuntimeException when the run is finished or has no paused step
     */
    public function resume(WorkflowJob $wfJob, int $userId): void
    {
        $this->access()->assertMayOperate($userId, (int)$wfJob->workflow_template_id);
        if ($wfJob->isFinished()) {
            throw new \RuntimeException('Workflow is already finished.');
        }

        $pauseStep = $this->findRunningPauseStep($wfJob);
        if ($pauseStep === null) {
            throw new \RuntimeException('No paused step to resume.');
        }

        $pauseStep->status = WorkflowJobStep::STATUS_SUCCEEDED;
        $pauseStep->finished_at = time();
        $pauseStep->save(false);

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_WORKFLOW_STEP_RESUMED,
            'workflow_job_step',
            $pauseStep->id,
            $userId,
            ['step_name' => $pauseStep->workflowStep?->name ?? '']
        );

        $this->advanceAfterStep($wfJob, $pauseStep, true);
    }

    /**
     * Find the currently running pause step, if any.
     */
    private function findRunningPauseStep(WorkflowJob $wfJob): ?WorkflowJobStep
    {
        /** @var WorkflowJobStep[] $running */
        $running = WorkflowJobStep::find()
            ->where([
                'workflow_job_id' => $wfJob->id,
                'status' => WorkflowJobStep::STATUS_RUNNING,
            ])
            ->all();

        foreach ($running as $wjs) {
            $step = $wjs->workflowStep;
            if ($step !== null && $step->step_type === WorkflowStep::TYPE_PAUSE) {
                return $wjs;
            }
        }

        return null;
    }

    /**
     * Cancel a running workflow. Access is checked first, as for {@see resume()}.
     *
     * @throws WorkflowAccessDeniedException when $userId may not operate every job step
     * @throws \RuntimeException when the run is already finished
     */
    public function cancel(WorkflowJob $wfJob, int $userId): void
    {
        $this->access()->assertMayOperate($userId, (int)$wfJob->workflow_template_id);
        if ($wfJob->isFinished()) {
            throw new \RuntimeException('Workflow is already finished.');
        }

        // Cancel any running child jobs
        /** @var WorkflowJobStep[] $running */
        $running = WorkflowJobStep::find()
            ->where([
                'workflow_job_id' => $wfJob->id,
                'status' => WorkflowJobStep::STATUS_RUNNING,
            ])
            ->all();

        foreach ($running as $wjs) {
            $wjs->status = WorkflowJobStep::STATUS_FAILED;
            $wjs->finished_at = time();
            $wjs->save(false);

            if ($wjs->job_id !== null) {
                /** @var Job|null $childJob */
                $childJob = Job::findOne($wjs->job_id);
                if ($childJob !== null && !$childJob->isFinished()) {
                    $childJob->status = Job::STATUS_CANCELED;
                    $childJob->finished_at = time();
                    $childJob->save(false);
                }
            }
        }

        $this->completeWorkflow($wfJob, WorkflowJob::STATUS_CANCELED);

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_WORKFLOW_CANCELED,
            'workflow_job',
            $wfJob->id,
            $userId,
            []
        );
    }

    /**
     * Complete a workflow with a final status.
     */
    public function completeWorkflow(WorkflowJob $wfJob, string $status): void
    {
        $wfJob->status = $status;
        $wfJob->finished_at = time();
        $wfJob->save(false);

        $auditAction = $status === WorkflowJob::STATUS_SUCCEEDED
            ? AuditLog::ACTION_WORKFLOW_COMPLETED
            : AuditLog::ACTION_WORKFLOW_FAILED;

        \Yii::$app->get('auditService')->log(
            $auditAction,
            'workflow_job',
            $wfJob->id,
            null,
            ['status' => $status]
        );

        $event = match ($status) {
            WorkflowJob::STATUS_SUCCEEDED => NotificationTemplate::EVENT_WORKFLOW_SUCCEEDED,
            WorkflowJob::STATUS_CANCELED => NotificationTemplate::EVENT_WORKFLOW_CANCELED,
            default => NotificationTemplate::EVENT_WORKFLOW_FAILED,
        };
        $this->dispatchNotification($event, $wfJob, $wfJob->workflowTemplate);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function dispatchNotification(
        string $event,
        ?WorkflowJob $wfJob,
        ?WorkflowTemplate $template,
        array $extra = []
    ): void {
        if ($wfJob === null) {
            return;
        }
        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');
        $dispatcher->dispatch($event, array_merge([
            'workflow' => [
                'id' => (string)$wfJob->id,
                'status' => (string)$wfJob->status,
                'template_id' => (string)($template?->id ?? ''),
                'template_name' => (string)($template?->name ?? ''),
            ],
        ], $extra));
    }

    private function resolveNextStep(WorkflowStep $step, bool $succeeded): ?WorkflowStep
    {
        // on_always takes priority
        if ($step->on_always_step_id !== null) {
            return $this->resolveStepId($step, (int)$step->on_always_step_id);
        }

        $nextId = $succeeded ? $step->on_success_step_id : $step->on_failure_step_id;
        return $this->resolveStepId($step, $nextId);
    }

    /**
     * Resolve a step reference: explicit ID, END_WORKFLOW sentinel, or
     * NULL (default = next step by step_order within the same workflow).
     */
    private function resolveStepId(WorkflowStep $current, ?int $stepId): ?WorkflowStep
    {
        // Sentinel 0 = explicit end
        if ($stepId === WorkflowStep::END_WORKFLOW) {
            return null;
        }

        // Explicit step ID — must belong to the same workflow template
        if ($stepId !== null) {
            /** @var WorkflowStep|null $s */
            $s = WorkflowStep::findOne([
                'id' => $stepId,
                'workflow_template_id' => $current->workflow_template_id,
            ]);
            return $s;
        }

        // NULL = advance to next step by step_order
        /** @var WorkflowStep|null $next */
        $next = WorkflowStep::find()
            ->where(['workflow_template_id' => $current->workflow_template_id])
            ->andWhere(['>', 'step_order', $current->step_order])
            ->orderBy(['step_order' => SORT_ASC])
            ->one();
        return $next;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function dispatchStep(
        WorkflowJob $wfJob,
        WorkflowJobStep $wjs,
        WorkflowStep $step,
        array $overrides
    ): void {
        switch ($step->step_type) {
            case WorkflowStep::TYPE_JOB:
                $this->dispatchJobStep($wfJob, $wjs, $step, $overrides);
                break;
            case WorkflowStep::TYPE_APPROVAL:
                $this->dispatchApprovalStep($wfJob, $wjs, $step);
                break;
            case WorkflowStep::TYPE_PAUSE:
                // Pause steps stay in "running" until externally resumed
                break;
            default:
                // A type the model refuses, which older versions saved from
                // a JSON body (true as "1"): nothing would ever end the step.
                $this->failStep($wfJob, $wjs, sprintf('Not run: unknown step type "%s".', $step->step_type));
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function dispatchJobStep(
        WorkflowJob $wfJob,
        WorkflowJobStep $wjs,
        WorkflowStep $step,
        array $overrides
    ): void {
        $template = $step->jobTemplate;
        if ($template === null) {
            $this->failStep($wfJob, $wjs, 'The job template of this step no longer exists.');
            return;
        }
        $launchedBy = (int)$wfJob->launched_by;
        if (!$this->access()->canDispatch($launchedBy, $template)) {
            $this->failStep($wfJob, $wjs, sprintf(
                self::JOB_STEP_REFUSAL,
                $launchedBy,
                $template->name,
                $template->id
            ), ['job_template_id' => (int)$template->id, 'project_id' => (int)$template->project_id]);
            return;
        }

        /** @var JobLaunchService $launcher */
        $launcher = \Yii::$app->get('jobLaunchService');
        try {
            $job = $launcher->launch($template, $launchedBy, $overrides);
        } catch (\RuntimeException $e) {
            \Yii::error("Workflow job #{$wfJob->id}: launching job template #{$template->id} failed: {$e->getMessage()}", __CLASS__);
            $this->failStep($wfJob, $wjs, 'The job could not be launched; see the application log.');
            return;
        }

        $wjs->job_id = $job->id;
        $wjs->save(false);
    }

    /**
     * End a step that could not start, and with it the workflow, without
     * following its branches. A denial ($denied context) is audited.
     *
     * @param array<string, int>|null $denied
     */
    private function failStep(WorkflowJob $wfJob, WorkflowJobStep $wjs, string $reason, ?array $denied = null): void
    {
        $wjs->status = WorkflowJobStep::STATUS_FAILED;
        $wjs->finished_at = time();
        $wjs->error_message = mb_substr($reason, 0, 500);
        $wjs->save(false);
        \Yii::warning("Workflow job #{$wfJob->id} step #{$wjs->id}: {$reason}", __CLASS__);

        if ($denied !== null) {
            \Yii::$app->get('auditService')->log(
                AuditLog::ACTION_WORKFLOW_STEP_DENIED,
                'workflow_job_step',
                $wjs->id,
                (int)$wfJob->launched_by,
                array_merge(['workflow_job_id' => (int)$wfJob->id, 'workflow_step_id' => (int)$wjs->workflow_step_id], $denied)
            );
        }
        $this->completeWorkflow($wfJob, WorkflowJob::STATUS_FAILED);
    }

    private function access(): WorkflowAccessChecker
    {
        /** @var WorkflowAccessChecker $checker */
        $checker = \Yii::$app->get('workflowAccessChecker');
        return $checker;
    }

    private function dispatchApprovalStep(
        WorkflowJob $wfJob,
        WorkflowJobStep $wjs,
        WorkflowStep $step
    ): void {
        $rule = $step->approvalRule;
        if ($rule === null) {
            $this->failStep($wfJob, $wjs, 'The approval rule of this step no longer exists.');
            return;
        }

        // Create a placeholder job so ApprovalRequest can link to it.
        // The job is never executed — it exists only as a foreign key target.
        $job = new Job();
        $job->job_template_id = null;
        $job->launched_by = $wfJob->launched_by;
        $job->status = Job::STATUS_PENDING_APPROVAL;
        $job->timeout_minutes = 0;
        $job->has_changes = 0;
        $job->created_at = time();
        $job->updated_at = time();
        $job->save(false);

        $wjs->job_id = $job->id;
        $wjs->status = WorkflowJobStep::STATUS_RUNNING;
        $wjs->save(false);

        /** @var ApprovalService $approvalService */
        $approvalService = \Yii::$app->get('approvalService');
        $approvalService->createRequest($job, $rule);
    }

    /**
     * Called when an approval request linked to a workflow step is resolved.
     * Advances or fails the workflow depending on the approval outcome.
     */
    public function onApprovalResolved(Job $job, bool $approved): void
    {
        // Only a step still waiting for the decision: one that already ended
        // (its run was canceled) must not take a route again.
        /** @var WorkflowJobStep|null $wjs */
        $wjs = WorkflowJobStep::findOne(['job_id' => $job->id, 'status' => WorkflowJobStep::STATUS_RUNNING]);
        if ($wjs === null) {
            return;
        }

        /** @var WorkflowJob|null $wfJob */
        $wfJob = $wjs->workflowJob;
        if ($wfJob === null || $wfJob->isFinished()) {
            return;
        }

        $wjs->status = $approved
            ? WorkflowJobStep::STATUS_SUCCEEDED
            : WorkflowJobStep::STATUS_FAILED;
        $wjs->finished_at = time();
        $wjs->save(false);

        $this->advanceAfterStep($wfJob, $wjs, $approved);
    }

    /**
     * Build extra vars for the next step from previous step output.
     *
     * @return array<string, mixed>
     */
    private function buildStepExtraVars(
        WorkflowJobStep $prevStep,
        WorkflowStep $nextStep
    ): array {
        $mapping = $nextStep->getParsedExtraVarsTemplate();
        if ($mapping === []) {
            return [];
        }

        $outputVars = $prevStep->getParsedOutputVars();
        $result = [];

        foreach ($mapping as $targetKey => $sourceExpression) {
            if (!is_string($sourceExpression)) {
                continue;
            }
            // Simple key lookup in output_vars
            $value = $outputVars[$sourceExpression] ?? null;
            if ($value !== null) {
                $result[$targetKey] = $value;
            }
        }

        return $result;
    }
}
