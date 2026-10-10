<?php

declare(strict_types=1);

namespace app\commands;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowExecutionService;

/**
 * Workflow fixtures for the team scoping specs (tests/e2e/tests/team-scoping/).
 * They use the teams, projects and job templates of {@see E2eTeamScopingSeeder}:
 * e2e-operator's team operates e2e-alpha-proj and may only view
 * e2e-alpha-viewed-proj; e2e-viewer's team operates e2e-beta-proj.
 *
 * - e2e-alpha-wf: a job step on e2e-alpha-tmpl, with a finished run.
 * - e2e-beta-wf: a job step on e2e-beta-tmpl, with a finished run.
 * - e2e-alpha-viewed-wf: a pause step, then a job step on e2e-alpha-viewed-tmpl,
 *   with a run paused at the pause step.
 * - e2e-alpha-denied-wf: a job step on e2e-alpha-tmpl, then one on
 *   e2e-alpha-viewed-tmpl, with a failed run e2e-operator launched: the job
 *   of the first step succeeded; the second step was not launched, because
 *   e2e-operator may not launch e2e-alpha-viewed-tmpl, and its error says so.
 * - e2e-mixed-wf: job steps on e2e-alpha-tmpl and e2e-beta-tmpl. Created by
 *   e2e-operator, with a trigger token from before Ansilume recorded who
 *   generated a token, so the trigger runs as its creator.
 * - e2e-beta-approval-wf: an approval step on e2e-beta-approval-rule, then a
 *   job step on e2e-beta-tmpl, with a run waiting for that approval. The rule
 *   names e2e-viewer (who may see the request but holds no approval.decide)
 *   and e2e-operator (who holds approval.decide but may not see the request).
 * - e2e-pause-only-wf: a pause step only. Without job steps a workflow belongs
 *   to no project, so everyone with the RBAC permission sees it.
 * - e2e-alpha-legacy-wf: a job step on e2e-alpha-tmpl, then a pause step that
 *   carries e2e-beta-tmpl, as older versions saved pause steps. Only job steps
 *   count, so e2e-operator's team sees and operates it, and its page never
 *   names e2e-beta-tmpl.
 *
 * Every fixture is restored by name on every seed: attributes, steps and runs.
 * Runs and their jobs are written directly: nothing is launched.
 */
final class E2eWorkflowScopingSeeder
{
    private const RULE = 'e2e-beta-approval-rule';

    /** @var callable(string): void */
    private $logger;

    /**
     * @param callable(string): void $logger
     */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    public function seed(int $userId): void
    {
        $alpha = $this->templateId('e2e-alpha-tmpl');
        $beta = $this->templateId('e2e-beta-tmpl');
        $viewed = $this->templateId('e2e-alpha-viewed-tmpl');
        $operatorId = $this->userId('e2e-operator');
        $viewerId = $this->userId('e2e-viewer');
        if ($alpha === null || $beta === null || $viewed === null || $operatorId === null || $viewerId === null) {
            ($this->logger)("  Workflow scoping fixtures skipped: team scoping templates or users missing.\n");
            return;
        }

        [$alphaWf, $alphaSteps] = $this->workflow('e2e-alpha-wf', $userId, [
            ['e2e-alpha-wf-job', WorkflowStep::TYPE_JOB, $alpha],
        ]);
        $this->finishedRun($alphaWf, $alphaSteps[0], $userId);

        [$betaWf, $betaSteps] = $this->workflow('e2e-beta-wf', $userId, [
            ['e2e-beta-wf-job', WorkflowStep::TYPE_JOB, $beta],
        ]);
        $this->finishedRun($betaWf, $betaSteps[0], $userId);

        [$viewedWf, $viewedSteps] = $this->workflow('e2e-alpha-viewed-wf', $userId, [
            ['e2e-alpha-viewed-wf-pause', WorkflowStep::TYPE_PAUSE, null],
            ['e2e-alpha-viewed-wf-job', WorkflowStep::TYPE_JOB, $viewed],
        ]);
        $this->openRun($viewedWf, $viewedSteps[0], $userId, null);

        [$deniedWf, $deniedSteps] = $this->workflow('e2e-alpha-denied-wf', $userId, [
            ['e2e-alpha-denied-wf-alpha', WorkflowStep::TYPE_JOB, $alpha],
            ['e2e-alpha-denied-wf-viewed', WorkflowStep::TYPE_JOB, $viewed],
        ]);
        $reason = sprintf(WorkflowExecutionService::JOB_STEP_REFUSAL, $operatorId, 'e2e-alpha-viewed-tmpl', $viewed);
        $this->refusedRun($deniedWf, $deniedSteps[0], $deniedSteps[1], $operatorId, $reason);

        [$mixedWf] = $this->workflow('e2e-mixed-wf', $operatorId, [
            ['e2e-mixed-wf-alpha', WorkflowStep::TYPE_JOB, $alpha],
            ['e2e-mixed-wf-beta', WorkflowStep::TYPE_JOB, $beta],
        ]);
        // Only the hash is stored and nobody knows the raw value: the card
        // shows whom the trigger runs as, but the trigger cannot be fired.
        $mixedWf->trigger_token = hash('sha256', bin2hex(random_bytes(32)));
        $mixedWf->save(false, ['trigger_token']);

        $rule = $this->approvalRule($userId, [$viewerId, $operatorId]);
        [$approvalWf, $approvalSteps] = $this->workflow('e2e-beta-approval-wf', $userId, [
            ['e2e-beta-approval-wf-approve', WorkflowStep::TYPE_APPROVAL, (int)$rule->id],
            ['e2e-beta-approval-wf-job', WorkflowStep::TYPE_JOB, $beta],
        ]);
        // The job the workflow engine creates for an approval step: no template.
        $placeholder = $this->job(null, $userId, Job::STATUS_PENDING_APPROVAL);
        $this->openRun($approvalWf, $approvalSteps[0], $userId, (int)$placeholder->id);
        $this->pendingRequest($placeholder, $rule);

        $this->workflow('e2e-pause-only-wf', $userId, [['e2e-pause-only-wf-pause', WorkflowStep::TYPE_PAUSE, null]]);

        $this->legacyWorkflow($userId, $alpha, $beta);

        ($this->logger)("  Restored workflow scoping fixtures (alpha, beta, alpha-viewed, alpha-denied, mixed, beta-approval, pause-only, alpha-legacy).\n");
    }

    /**
     * Restores a workflow template by name with exactly $steps (name, type,
     * job template or approval rule id) and no runs.
     *
     * @param list<array{0: string, 1: string, 2: int|null}> $steps
     * @return array{0: WorkflowTemplate, 1: list<WorkflowStep>}
     */
    private function workflow(string $name, int $createdBy, array $steps): array
    {
        /** @var WorkflowTemplate|null $existing */
        $existing = WorkflowTemplate::findWithDeleted()->where(['name' => $name])->orderBy(['id' => SORT_ASC])->one();
        $workflow = $existing ?? new WorkflowTemplate();
        $workflow->name = $name;
        $workflow->description = 'E2E team scoping fixture';
        $workflow->created_by = $createdBy;
        $workflow->deleted_at = null;
        $workflow->trigger_token = null;
        $workflow->trigger_token_created_by = null;
        $workflow->save(false);

        $this->deleteRuns((int)$workflow->id);
        WorkflowStep::deleteAll(['workflow_template_id' => $workflow->id]);
        $created = [];
        foreach ($steps as $position => [$stepName, $type, $targetId]) {
            $step = new WorkflowStep();
            $step->workflow_template_id = (int)$workflow->id;
            $step->name = $stepName;
            $step->step_order = ($position + 1) * 10;
            $step->step_type = $type;
            $step->job_template_id = $type === WorkflowStep::TYPE_JOB ? $targetId : null;
            $step->approval_rule_id = $type === WorkflowStep::TYPE_APPROVAL ? $targetId : null;
            // A rejected approval ends the workflow.
            $step->on_failure_step_id = $type === WorkflowStep::TYPE_APPROVAL ? WorkflowStep::END_WORKFLOW : null;
            $step->save(false);
            $created[] = $step;
        }

        return [$workflow, $created];
    }

    /**
     * e2e-alpha-legacy-wf: a job step on $alpha, then a pause step that also
     * carries the job template $beta, as older versions saved pause steps.
     * Migration m000078 clears such leftovers on update, so the fixture
     * writes one afterwards.
     */
    private function legacyWorkflow(int $userId, int $alpha, int $beta): void
    {
        [, $steps] = $this->workflow('e2e-alpha-legacy-wf', $userId, [
            ['e2e-alpha-legacy-wf-job', WorkflowStep::TYPE_JOB, $alpha],
            ['e2e-alpha-legacy-wf-pause', WorkflowStep::TYPE_PAUSE, null],
        ]);
        $steps[1]->job_template_id = $beta;
        $steps[1]->save(false, ['job_template_id']);
    }

    /**
     * Deletes the runs of a workflow template with the jobs their steps
     * started; approval requests, logs and artifacts cascade with the jobs.
     */
    private function deleteRuns(int $workflowTemplateId): void
    {
        $runIds = WorkflowJob::find()->select('id')->where(['workflow_template_id' => $workflowTemplateId])->column();
        if ($runIds === []) {
            return;
        }
        $jobIds = WorkflowJobStep::find()
            ->select('job_id')
            ->where(['workflow_job_id' => $runIds])
            ->andWhere(['not', ['job_id' => null]])
            ->column();
        if ($jobIds !== []) {
            \Yii::$app->db->createCommand()->delete('{{%job_task}}', ['job_id' => $jobIds])->execute();
            Job::deleteAll(['id' => $jobIds]);
        }
        WorkflowJob::deleteAll(['id' => $runIds]);
    }

    /**
     * A succeeded run of a one-step workflow, with the succeeded job its step started.
     */
    private function finishedRun(WorkflowTemplate $workflow, WorkflowStep $step, int $userId): void
    {
        $run = $this->endedRun($workflow, $userId, WorkflowJob::STATUS_SUCCEEDED, null);
        $this->succeededStep($run, $step, $userId);
    }

    /**
     * A failed run that $userId launched, as the workflow engine leaves it
     * when it may not launch a step: the job of the $launched step
     * succeeded, the $refused step failed without a job, with $reason.
     */
    private function refusedRun(
        WorkflowTemplate $workflow,
        WorkflowStep $launched,
        WorkflowStep $refused,
        int $userId,
        string $reason
    ): void {
        $run = $this->endedRun($workflow, $userId, WorkflowJob::STATUS_FAILED, (int)$refused->id);
        $this->succeededStep($run, $launched, $userId);

        $execution = $this->stepExecution($run, $refused, WorkflowJobStep::STATUS_FAILED, null);
        $execution->finished_at = (int)$run->finished_at;
        $execution->error_message = $reason;
        $execution->save(false, ['finished_at', 'error_message']);
    }

    /**
     * A run that finished a minute ago with $status, ended at $currentStepId.
     */
    private function endedRun(WorkflowTemplate $workflow, int $userId, string $status, ?int $currentStepId): WorkflowJob
    {
        $run = $this->run($workflow, $userId, $status, $currentStepId);
        $run->finished_at = time() - 60;
        $run->save(false, ['finished_at']);

        return $run;
    }

    /**
     * The execution of a job step of $run, with the succeeded job it started.
     */
    private function succeededStep(WorkflowJob $run, WorkflowStep $step, int $userId): void
    {
        $finishedAt = (int)$run->finished_at;
        $job = $this->job((int)$step->job_template_id, $userId, Job::STATUS_SUCCEEDED);
        $job->exit_code = 0;
        $job->finished_at = $finishedAt;
        $job->save(false, ['exit_code', 'finished_at']);

        $execution = $this->stepExecution($run, $step, WorkflowJobStep::STATUS_SUCCEEDED, (int)$job->id);
        $execution->finished_at = $finishedAt;
        $execution->save(false, ['finished_at']);
    }

    /**
     * A running run whose current step is $step: paused at a pause step, or
     * waiting at an approval step for the placeholder job $jobId.
     */
    private function openRun(WorkflowTemplate $workflow, WorkflowStep $step, int $userId, ?int $jobId): void
    {
        $run = $this->run($workflow, $userId, WorkflowJob::STATUS_RUNNING, (int)$step->id);
        $this->stepExecution($run, $step, WorkflowJobStep::STATUS_RUNNING, $jobId);
    }

    private function run(WorkflowTemplate $workflow, int $userId, string $status, ?int $currentStepId): WorkflowJob
    {
        $run = new WorkflowJob();
        $run->workflow_template_id = (int)$workflow->id;
        $run->launched_by = $userId;
        $run->status = $status;
        $run->current_step_id = $currentStepId;
        $run->started_at = time() - 120;
        $run->save(false);

        return $run;
    }

    private function stepExecution(WorkflowJob $run, WorkflowStep $step, string $status, ?int $jobId): WorkflowJobStep
    {
        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = (int)$run->id;
        $execution->workflow_step_id = (int)$step->id;
        $execution->job_id = $jobId;
        $execution->status = $status;
        $execution->started_at = time() - 110;
        $execution->save(false);

        return $execution;
    }

    private function job(?int $templateId, int $userId, string $status): Job
    {
        $job = new Job();
        $job->job_template_id = $templateId;
        $job->launched_by = $userId;
        $job->status = $status;
        $job->timeout_minutes = $templateId === null ? 0 : 30;
        $job->has_changes = 0;
        $job->queued_at = $templateId === null ? null : time() - 115;
        $job->started_at = $templateId === null ? null : time() - 110;
        $job->save(false);

        return $job;
    }

    private function pendingRequest(Job $placeholder, ApprovalRule $rule): void
    {
        $request = new ApprovalRequest();
        $request->job_id = (int)$placeholder->id;
        $request->approval_rule_id = (int)$rule->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time() - 100;
        // Never expires: the request stays pending for every spec of the run.
        $request->expires_at = null;
        $request->save(false);
    }

    /**
     * @param list<int> $approverIds
     */
    private function approvalRule(int $userId, array $approverIds): ApprovalRule
    {
        $rule = ApprovalRule::findOne(['name' => self::RULE]) ?? new ApprovalRule();
        $rule->name = self::RULE;
        $rule->description = 'E2E approval rule of e2e-beta-approval-wf';
        $rule->job_template_id = null;
        $rule->approver_type = ApprovalRule::APPROVER_TYPE_USERS;
        $rule->approver_config = (string)json_encode(['user_ids' => $approverIds]);
        $rule->required_approvals = 1;
        $rule->timeout_minutes = null;
        $rule->timeout_action = ApprovalRule::TIMEOUT_ACTION_REJECT;
        $rule->created_by = $userId;
        $rule->save(false);

        return $rule;
    }

    private function templateId(string $name): ?int
    {
        $template = JobTemplate::findOne(['name' => $name]);

        return $template === null ? null : (int)$template->id;
    }

    private function userId(string $username): ?int
    {
        $user = User::findOne(['username' => $username]);

        return $user === null ? null : (int)$user->id;
    }
}
