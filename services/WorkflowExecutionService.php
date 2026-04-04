<?php

declare(strict_types=1);

namespace app\services;

use app\models\AuditLog;
use app\models\Job;
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
     * Launch a workflow from a template.
     *
     * @param array<string, mixed> $overrides
     */
    public function launch(
        WorkflowTemplate $template,
        int $launchedBy,
        array $overrides = []
    ): WorkflowJob {
        $startStep = $template->getStartStep();
        if ($startStep === null) {
            throw new \RuntimeException('Workflow template has no steps.');
        }

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
            ['template_id' => $template->id, 'template_name' => $template->name]
        );

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

        /** @var WorkflowJob|null $wfJob */
        $wfJob = $wjs->workflowJob;
        if ($wfJob === null || $wfJob->isFinished()) {
            return;
        }

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

        // Build extra vars from previous step output
        $extraVars = $this->buildStepExtraVars($wjs, $nextStep);
        $overrides = [];
        if ($extraVars !== []) {
            $overrides['extra_vars'] = $extraVars;
        }

        $this->executeStep($wfJob, $nextStep, $overrides);
    }

    /**
     * Cancel a running workflow.
     */
    public function cancel(WorkflowJob $wfJob, int $userId): void
    {
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
    }

    private function resolveNextStep(WorkflowStep $step, bool $succeeded): ?WorkflowStep
    {
        // on_always takes priority
        if ($step->on_always_step_id !== null) {
            /** @var WorkflowStep|null $s */
            $s = WorkflowStep::findOne($step->on_always_step_id);
            return $s;
        }

        $nextId = $succeeded ? $step->on_success_step_id : $step->on_failure_step_id;
        if ($nextId === null) {
            return null;
        }

        /** @var WorkflowStep|null $s */
        $s = WorkflowStep::findOne($nextId);
        return $s;
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
                $this->dispatchApprovalStep($wjs, $step);
                break;
            case WorkflowStep::TYPE_PAUSE:
                // Pause steps stay in "running" until externally resumed
                break;
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
            $wjs->status = WorkflowJobStep::STATUS_FAILED;
            $wjs->finished_at = time();
            $wjs->save(false);
            $this->completeWorkflow($wfJob, WorkflowJob::STATUS_FAILED);
            return;
        }

        /** @var JobLaunchService $launcher */
        $launcher = \Yii::$app->get('jobLaunchService');
        $job = $launcher->launch($template, $wfJob->launched_by, $overrides);

        $wjs->job_id = $job->id;
        $wjs->save(false);
    }

    private function dispatchApprovalStep(
        WorkflowJobStep $wjs,
        WorkflowStep $step
    ): void {
        $rule = $step->approvalRule;
        if ($rule === null) {
            $wjs->status = WorkflowJobStep::STATUS_FAILED;
            $wjs->finished_at = time();
            $wjs->save(false);
            return;
        }

        // Approval steps wait; they'll be advanced when the approval resolves
        // The approval is tracked externally, not via a child job
        $wjs->status = WorkflowJobStep::STATUS_RUNNING;
        $wjs->save(false);
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
