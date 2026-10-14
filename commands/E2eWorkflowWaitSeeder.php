<?php

declare(strict_types=1);

namespace app\commands;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\Job;
use app\models\JobHostSummary;
use app\models\JobTask;
use app\models\JobTemplate;
use app\models\Project;
use app\models\RunnerGroup;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;

/**
 * Fixtures of the E2E specs for what workflow runs wait for
 * (tests/e2e/tests/workflow-jobs/, approvals/, jobs/ and site/):
 *
 * - e2e-alpha-gate-rule: e2e-admin and e2e-operator approve, one approval is
 *   needed, and a request never expires.
 * - e2e-alpha-gated-tmpl: a job template of e2e-alpha-proj, which
 *   e2e-operator's team operates, gated by e2e-alpha-gate-rule, in the
 *   runner group e2e-gate-runner-group, which no runner serves.
 * - e2e-mixed-gated-wf: the job step e2e-mixed-gated-wf-gated on
 *   e2e-alpha-gated-tmpl, then the job step e2e-mixed-gated-wf-beta on
 *   e2e-beta-tmpl, with a run e2e-admin launched whose first step's job waits
 *   for approval. Only users who may view both projects see the workflow:
 *   e2e-operator sees the request through the job template's project and
 *   learns nothing of the workflow (the team scoping disclosure check);
 *   e2e-viewer sees neither.
 * - e2e-long-paused-workflow: the pause step e2e-long-paused-pause, then the
 *   job step e2e-long-paused-job on the job template given to seed()
 *   (e2e-template), with a run e2e-admin launched LONG_PAUSE_SECONDS ago,
 *   paused at the pause step ever since: times and durations past a day.
 * - a finished job of e2e-template, marked by its execution command
 *   e2e-unreachable-job, whose one host was unreachable: a host summary and
 *   a task in the status unreachable (jobs/badge-contrast.spec.ts).
 *
 * The specs' watcher is e2e-viewer, a plain viewer member of e2e-team-beta
 * (E2eController, E2eTeamScopingSeeder): it may view the runs of
 * e2e-beta-approval-wf, never decide on their requests.
 *
 * Registered in E2eController::seedData() after E2eWorkflowGateSeeder and
 * the team scoping seeders, whose runner group, teams, projects and job
 * templates these fixtures use. Every seed restores each fixture by name,
 * soft-deleted ones too: its attributes, its steps and exactly the runs
 * above, whose jobs and approval requests go with them. Runs and jobs are
 * written directly: nothing is launched. A fixture whose prerequisites are
 * missing is skipped, with a line in the log.
 */
final class E2eWorkflowWaitSeeder
{
    public const ALPHA_RULE = 'e2e-alpha-gate-rule';
    public const ALPHA_GATED_TEMPLATE = 'e2e-alpha-gated-tmpl';
    public const MIXED_WORKFLOW = 'e2e-mixed-gated-wf';
    public const MIXED_GATED_STEP = 'e2e-mixed-gated-wf-gated';
    public const MIXED_BETA_STEP = 'e2e-mixed-gated-wf-beta';
    public const LONG_PAUSED_WORKFLOW = 'e2e-long-paused-workflow';
    public const LONG_PAUSED_PAUSE = 'e2e-long-paused-pause';
    public const LONG_PAUSED_JOB = 'e2e-long-paused-job';

    /** How long the run of e2e-long-paused-workflow has been paused: 26 hours. */
    public const LONG_PAUSE_SECONDS = 93600;

    /** The execution command that marks the job whose host was unreachable. */
    public const UNREACHABLE_JOB = 'e2e-unreachable-job';
    public const UNREACHABLE_HOST = 'e2e-unreachable-host';
    public const UNREACHABLE_TASK = 'e2e-unreachable-task';

    /** The second approver of e2e-alpha-gate-rule, besides the seeding user. */
    public const APPROVER = 'e2e-operator';

    /** The fixtures of the team scoping and gate seeders that e2e-mixed-gated-wf builds on. */
    public const ALPHA_PROJECT = 'e2e-alpha-proj';
    public const ALPHA_INVENTORY = 'e2e-alpha-inv';
    public const BETA_TEMPLATE = 'e2e-beta-tmpl';
    public const GATE_RUNNER_GROUP = 'e2e-gate-runner-group';

    /** The log line of a seed; sprintf(): what was restored, joined with ', '. */
    public const RESTORED = "  Restored workflow wait fixtures: %s.\n";

    /** sprintf(): the fixture, what is missing (joined with ', '). */
    public const SKIPPED = "  Workflow wait fixture %s skipped: %s missing.\n";

    /** sprintf(): the run, the approval request. */
    public const MIXED_RESTORED = '%s (run #%d waits for approval request #%d)';

    /** sprintf(): the run. */
    public const LONG_PAUSED_RESTORED = '%s (run #%d paused for 26 h)';

    /** sprintf(): the job. */
    public const UNREACHABLE_RESTORED = '%s (job #%d)';

    private const DESCRIPTION = 'E2E fixture: what workflow runs wait for (E2eWorkflowWaitSeeder)';

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    public function seed(int $userId, int $jobTemplateId): void
    {
        $restored = array_merge(
            $this->seedMixed($userId),
            [$this->seedLongPaused($userId, $jobTemplateId), $this->seedUnreachableJob($userId, $jobTemplateId)]
        );
        ($this->logger)(sprintf(self::RESTORED, implode(', ', $restored)));
    }

    /**
     * e2e-alpha-gate-rule, e2e-alpha-gated-tmpl and e2e-mixed-gated-wf with
     * its waiting run, unless what they build on is missing.
     *
     * @return list<string> what was restored
     */
    private function seedMixed(int $userId): array
    {
        $project = Project::findOne(['name' => self::ALPHA_PROJECT]);
        $inventory = Inventory::findOne(['name' => self::ALPHA_INVENTORY]);
        $beta = JobTemplate::findOne(['name' => self::BETA_TEMPLATE]);
        $approver = User::findOne(['username' => self::APPROVER]);
        $group = RunnerGroup::findOne(['name' => self::GATE_RUNNER_GROUP]);
        if ($project === null || $inventory === null || $beta === null || $approver === null || $group === null) {
            $missing = array_keys(array_filter([
                self::ALPHA_PROJECT => $project === null,
                self::ALPHA_INVENTORY => $inventory === null,
                self::BETA_TEMPLATE => $beta === null,
                self::APPROVER => $approver === null,
                self::GATE_RUNNER_GROUP => $group === null,
            ]));
            ($this->logger)(sprintf(self::SKIPPED, self::MIXED_WORKFLOW, implode(', ', $missing)));

            return [];
        }
        $rule = $this->restoreRule($userId, [$userId, (int)$approver->id]);
        $gated = $this->restoreGatedTemplate($userId, (int)$rule->id, [(int)$project->id, (int)$inventory->id, (int)$group->id]);
        $steps = $this->restoreWorkflow(self::MIXED_WORKFLOW, $userId, [
            [self::MIXED_GATED_STEP, WorkflowStep::TYPE_JOB, (int)$gated->id],
            [self::MIXED_BETA_STEP, WorkflowStep::TYPE_JOB, (int)$beta->id],
        ]);
        [$runId, $requestId] = $this->approvalWait($steps[0], $rule, $userId);

        return [self::ALPHA_RULE, self::ALPHA_GATED_TEMPLATE, sprintf(self::MIXED_RESTORED, self::MIXED_WORKFLOW, $runId, $requestId)];
    }

    /**
     * Restores e2e-alpha-gate-rule: $approverIds approve, one approval is
     * needed, and a request never expires, so it waits until a spec decides
     * it.
     *
     * @param list<int> $approverIds
     */
    private function restoreRule(int $userId, array $approverIds): ApprovalRule
    {
        $rule = ApprovalRule::findOne(['name' => self::ALPHA_RULE]) ?? new ApprovalRule();
        $rule->setAttributes([
            'name' => self::ALPHA_RULE,
            'description' => 'E2E: e2e-admin and e2e-operator approve the jobs of e2e-alpha-gated-tmpl; never expires',
            'approver_type' => ApprovalRule::APPROVER_TYPE_USERS,
            'approver_config' => (string)json_encode(['user_ids' => $approverIds]),
            'required_approvals' => 1,
            'timeout_action' => ApprovalRule::TIMEOUT_ACTION_REJECT,
            'timeout_minutes' => null,
            'job_template_id' => null,
            'created_by' => $userId,
        ], false);
        $rule->save(false);

        return $rule;
    }

    /**
     * Restores e2e-alpha-gated-tmpl, soft-deleted or not, gated by rule
     * #$ruleId.
     *
     * @param array{0: int, 1: int, 2: int} $parents project, inventory and runner group IDs
     */
    private function restoreGatedTemplate(int $userId, int $ruleId, array $parents): JobTemplate
    {
        /** @var JobTemplate|null $existing */
        $existing = JobTemplate::findWithDeleted()->where(['name' => self::ALPHA_GATED_TEMPLATE])->orderBy(['id' => SORT_ASC])->one();
        $template = $existing ?? new JobTemplate();
        $template->setAttributes([
            'name' => self::ALPHA_GATED_TEMPLATE,
            'description' => 'E2E: e2e-alpha-gate-rule gates every launch; no runner serves its group',
            'project_id' => $parents[0],
            'inventory_id' => $parents[1],
            'runner_group_id' => $parents[2],
            'approval_rule_id' => $ruleId,
            'playbook' => 'site.yml',
            'credential_id' => null,
            'extra_vars' => null,
            'survey_fields' => null,
            'limit' => null,
            'become' => false,
            'verbosity' => 0,
            'forks' => 5,
            'timeout_minutes' => 30,
            'trigger_token' => null,
            'trigger_token_created_by' => null,
            'created_by' => $userId,
            'deleted_at' => null,
        ], false);
        $template->save(false);

        return $template;
    }

    /**
     * Restores the workflow template $name, soft-deleted or not, with
     * exactly $steps in this order (step order 10, 20, ...), no branches, no
     * trigger token and no runs.
     *
     * @param list<array{0: string, 1: string, 2: int|null}> $steps name, type, and the job template of a job step
     * @return list<WorkflowStep>
     */
    private function restoreWorkflow(string $name, int $userId, array $steps): array
    {
        /** @var WorkflowTemplate|null $existing */
        $existing = WorkflowTemplate::findWithDeleted()->where(['name' => $name])->orderBy(['id' => SORT_ASC])->one();
        $workflow = $existing ?? new WorkflowTemplate();
        $workflow->setAttributes([
            'name' => $name,
            'description' => self::DESCRIPTION,
            'trigger_token' => null,
            'trigger_token_created_by' => null,
            'deleted_at' => null,
            'created_by' => $userId,
        ], false);
        $workflow->save(false);
        $workflowId = (int)$workflow->id;

        self::deleteRunsOf($workflowId);
        WorkflowStep::deleteAll(['workflow_template_id' => $workflowId]);
        $restored = [];
        foreach ($steps as $index => [$stepName, $type, $jobTemplateId]) {
            $step = new WorkflowStep();
            $step->setAttributes([
                'workflow_template_id' => $workflowId,
                'name' => $stepName,
                'step_order' => 10 * ($index + 1),
                'step_type' => $type,
                'job_template_id' => $jobTemplateId,
            ], false);
            $step->save(false);
            $restored[] = $step;
        }

        return $restored;
    }

    /**
     * Deletes the runs of workflow template #$workflowId with the jobs their
     * steps started; approval requests, logs and host summaries go with
     * their job (foreign keys), tasks do not.
     */
    private static function deleteRunsOf(int $workflowId): void
    {
        $runIds = WorkflowJob::find()->select('id')->where(['workflow_template_id' => $workflowId])->column();
        if ($runIds === []) {
            return;
        }
        $jobIds = WorkflowJobStep::find()->select('job_id')
            ->where(['workflow_job_id' => $runIds])
            ->andWhere(['not', ['job_id' => null]])
            ->column();
        self::deleteJobs(array_map('intval', $jobIds));
        // The executions go with their run.
        WorkflowJob::deleteAll(['id' => $runIds]);
    }

    /**
     * @param list<int> $jobIds
     */
    private static function deleteJobs(array $jobIds): void
    {
        if ($jobIds === []) {
            return;
        }
        JobTask::deleteAll(['job_id' => $jobIds]);
        Job::deleteAll(['id' => $jobIds]);
    }

    /**
     * A run of the workflow of the job step $step that $userId launched two
     * minutes ago, waiting at $step: the step's job waits for its pending
     * approval request on $rule, which never expires. The run's and the
     * request's IDs.
     *
     * @return array{0: int, 1: int}
     */
    private function approvalWait(WorkflowStep $step, ApprovalRule $rule, int $userId): array
    {
        $now = time();
        $job = new Job();
        $job->setAttributes([
            'job_template_id' => (int)$step->job_template_id,
            'launched_by' => $userId,
            'status' => Job::STATUS_PENDING_APPROVAL,
            'timeout_minutes' => 30,
            'has_changes' => 0,
        ], false);
        $job->save(false);

        $run = $this->openRun($step, $userId, $now - 120, (int)$job->id);

        $request = new ApprovalRequest();
        $request->setAttributes([
            'job_id' => (int)$job->id,
            'approval_rule_id' => (int)$rule->id,
            'status' => ApprovalRequest::STATUS_PENDING,
            'requested_at' => $now - 110,
            'expires_at' => null,
        ], false);
        $request->save(false);

        return [(int)$run->id, (int)$request->id];
    }

    /**
     * A running run of the workflow of $step that $userId launched at
     * $startedAt, whose current step is $step: its execution runs since
     * then, with the job $jobId (null for a pause step).
     */
    private function openRun(WorkflowStep $step, int $userId, int $startedAt, ?int $jobId): WorkflowJob
    {
        $run = new WorkflowJob();
        $run->setAttributes([
            'workflow_template_id' => (int)$step->workflow_template_id,
            'launched_by' => $userId,
            'status' => WorkflowJob::STATUS_RUNNING,
            'current_step_id' => (int)$step->id,
            'started_at' => $startedAt,
        ], false);
        $run->save(false);

        $execution = new WorkflowJobStep();
        $execution->setAttributes([
            'workflow_job_id' => (int)$run->id,
            'workflow_step_id' => (int)$step->id,
            'job_id' => $jobId,
            'status' => WorkflowJobStep::STATUS_RUNNING,
            'started_at' => $startedAt,
        ], false);
        $execution->snapshotStep($step);
        $execution->save(false);

        return $run;
    }

    /**
     * e2e-long-paused-workflow, with its run paused for LONG_PAUSE_SECONDS.
     *
     * @return string what was restored
     */
    private function seedLongPaused(int $userId, int $jobTemplateId): string
    {
        $steps = $this->restoreWorkflow(self::LONG_PAUSED_WORKFLOW, $userId, [
            [self::LONG_PAUSED_PAUSE, WorkflowStep::TYPE_PAUSE, null],
            [self::LONG_PAUSED_JOB, WorkflowStep::TYPE_JOB, $jobTemplateId],
        ]);
        $run = $this->openRun($steps[0], $userId, time() - self::LONG_PAUSE_SECONDS, null);

        return sprintf(self::LONG_PAUSED_RESTORED, self::LONG_PAUSED_WORKFLOW, (int)$run->id);
    }

    /**
     * The failed job of job template #$jobTemplateId whose one host was
     * unreachable, in place of those an earlier seed left.
     *
     * @return string what was restored
     */
    private function seedUnreachableJob(int $userId, int $jobTemplateId): string
    {
        self::deleteJobs(array_map('intval', Job::find()->select('id')
            ->where(['execution_command' => self::UNREACHABLE_JOB])
            ->column()));

        $finishedAt = time() - 60;
        $job = new Job();
        $job->setAttributes([
            'job_template_id' => $jobTemplateId,
            'launched_by' => $userId,
            'status' => Job::STATUS_FAILED,
            'execution_command' => self::UNREACHABLE_JOB,
            'exit_code' => 4,
            'timeout_minutes' => 30,
            'has_changes' => 0,
            'queued_at' => $finishedAt - 30,
            'started_at' => $finishedAt - 20,
            'finished_at' => $finishedAt,
        ], false);
        $job->save(false);

        $summary = new JobHostSummary();
        $summary->setAttributes([
            'job_id' => (int)$job->id,
            'host' => self::UNREACHABLE_HOST,
            'unreachable' => 1,
            'created_at' => $finishedAt,
        ], false);
        $summary->save(false);

        $task = new JobTask();
        $task->setAttributes([
            'job_id' => (int)$job->id,
            'sequence' => 0,
            'task_name' => self::UNREACHABLE_TASK,
            'task_action' => 'ansible.builtin.ping',
            'host' => self::UNREACHABLE_HOST,
            'status' => JobTask::STATUS_UNREACHABLE,
            'duration_ms' => 1500,
            'created_at' => $finishedAt,
        ], false);
        $task->save(false);

        return sprintf(self::UNREACHABLE_RESTORED, self::UNREACHABLE_JOB, (int)$job->id);
    }
}
