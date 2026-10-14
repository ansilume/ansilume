<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eTeamScopingSeeder;
use app\commands\E2eWorkflowWaitSeeder;
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
use app\services\ApproverEligibility;
use app\services\runstate\Display;
use app\services\runstate\Viewer;
use app\services\runstate\WorkflowRunStates;
use app\services\WorkflowAccessChecker;
use app\tests\integration\DbTestCase;

/**
 * The fixtures of the E2E specs for what workflow runs wait for
 * (E2eWorkflowWaitSeeder), seeded on the teams, projects and templates of
 * E2eTeamScopingSeeder and the runner group of E2eWorkflowGateSeeder. The
 * specs find them by these names and rely on what they wait for; the test
 * database holds no "e2e-" rows outside a test, and the rollback removes them.
 */
class E2eWorkflowWaitSeederTest extends DbTestCase
{
    private int $adminId;
    private int $operatorId;
    private int $viewerId;
    /** Stands for e2e-template, the job template E2eController passes. */
    private int $templateId;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->createUser('admin');
        $admin->is_superadmin = true;
        $admin->save(false);
        $this->adminId = (int)$admin->id;
        $this->operatorId = (int)$this->namedUser('e2e-operator', 'operator')->id;
        $this->viewerId = (int)$this->namedUser('e2e-viewer', 'viewer')->id;
        $this->templateId = (int)$this->createJobTemplate(
            (int)$this->createProject($this->adminId)->id,
            (int)$this->createInventory($this->adminId)->id,
            (int)$this->createRunnerGroup($this->adminId)->id,
            $this->adminId
        )->id;
        $this->log = [];
    }

    public function testSeedsTheMixedGatedWorkflowWithItsWaitingRun(): void
    {
        $gateGroup = $this->seedPrerequisites();
        $this->seed();

        $rule = $this->ruleNamed(E2eWorkflowWaitSeeder::ALPHA_RULE);
        $this->assertSame(ApprovalRule::APPROVER_TYPE_USERS, $rule->approver_type);
        $this->assertSame([$this->adminId, $this->operatorId], $rule->getApproverUserIds());
        $this->assertSame(1, (int)$rule->required_approvals);
        $this->assertNull($rule->timeout_minutes, 'a request never expires before a spec decides it');
        $this->assertSame(ApprovalRule::TIMEOUT_ACTION_REJECT, $rule->timeout_action);
        $this->assertNull($rule->job_template_id);
        $this->assertSame($this->adminId, (int)$rule->created_by);

        $gated = $this->templateNamed(E2eWorkflowWaitSeeder::ALPHA_GATED_TEMPLATE);
        $this->assertSame($this->id(Project::findOne(['name' => 'e2e-alpha-proj'])), (int)$gated->project_id);
        $this->assertSame($this->id(Inventory::findOne(['name' => 'e2e-alpha-inv'])), (int)$gated->inventory_id);
        $this->assertSame((int)$gateGroup->id, (int)$gated->runner_group_id, 'no runner serves its group');
        $this->assertSame((int)$rule->id, (int)$gated->approval_rule_id);
        $this->assertSame('site.yml', $gated->playbook);
        $this->assertNull($gated->credential_id);
        $this->assertNull($gated->trigger_token);
        $this->assertNull($gated->deleted_at);

        $beta = $this->templateNamed('e2e-beta-tmpl');
        $this->assertSame([
            [E2eWorkflowWaitSeeder::MIXED_GATED_STEP, 10, WorkflowStep::TYPE_JOB, (int)$gated->id, null, null, null],
            [E2eWorkflowWaitSeeder::MIXED_BETA_STEP, 20, WorkflowStep::TYPE_JOB, (int)$beta->id, null, null, null],
        ], $this->stepsOf(E2eWorkflowWaitSeeder::MIXED_WORKFLOW));
        $workflow = $this->workflowNamed(E2eWorkflowWaitSeeder::MIXED_WORKFLOW);
        $this->assertSame($this->adminId, (int)$workflow->created_by);
        $this->assertNull($workflow->trigger_token);

        $run = $this->onlyRunOf(E2eWorkflowWaitSeeder::MIXED_WORKFLOW);
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status);
        $this->assertSame($this->adminId, (int)$run->launched_by, 'e2e-admin launched it, so another approver decides');
        $execution = $this->onlyExecutionOf($run);
        $this->assertSame((int)$execution->workflow_step_id, (int)$run->current_step_id);
        $this->assertSame(E2eWorkflowWaitSeeder::MIXED_GATED_STEP, $execution->stepName());
        $this->assertSame(WorkflowStep::TYPE_JOB, $execution->stepType());
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $execution->status);
        $this->assertSame((int)$run->started_at, (int)$execution->started_at);

        $job = Job::findOne($execution->job_id);
        $this->assertInstanceOf(Job::class, $job);
        $this->assertSame((int)$gated->id, (int)$job->job_template_id);
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $job->status);
        $this->assertSame($this->adminId, (int)$job->launched_by);
        $request = ApprovalRequest::findOne(['job_id' => $job->id]);
        $this->assertInstanceOf(ApprovalRequest::class, $request);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status);
        $this->assertSame((int)$rule->id, (int)$request->approval_rule_id);
        $this->assertNull($request->expires_at);
        $this->assertNotNull($request->requested_at);

        $this->assertSame([sprintf(
            E2eWorkflowWaitSeeder::RESTORED,
            'e2e-alpha-gate-rule, e2e-alpha-gated-tmpl, '
            . sprintf('e2e-mixed-gated-wf (run #%d waits for approval request #%d), ', $run->id, $request->id)
            . sprintf('e2e-long-paused-workflow (run #%d paused for 26 h), ', $this->onlyRunOf(E2eWorkflowWaitSeeder::LONG_PAUSED_WORKFLOW)->id)
            . sprintf('e2e-unreachable-job (job #%d)', $this->unreachableJob()->id)
        )], $this->log);
    }

    /**
     * What the specs read of the mixed run: it waits for its request, which
     * only e2e-operator may decide; e2e-operator sees the request through
     * the job template's project but not the workflow; e2e-viewer sees
     * neither (workflow-jobs/waiting-rbac.spec.ts).
     */
    public function testTheMixedRunWaitsForARequestOnlyTheOperatorMayDecide(): void
    {
        $this->seedPrerequisites();
        $this->seed();
        $run = $this->onlyRunOf(E2eWorkflowWaitSeeder::MIXED_WORKFLOW);
        $request = ApprovalRequest::findOne(['job_id' => $this->onlyExecutionOf($run)->job_id]);
        $this->assertInstanceOf(ApprovalRequest::class, $request);

        $state = $this->runStates()->forRun($run, Viewer::of($this->adminId), true);
        $this->assertSame(Display::STATE_AWAITING_APPROVAL, $state->state);
        $this->assertSame(Display::WAITING_FOR_APPROVAL, $state->waitingFor);
        $this->assertSame((int)$request->id, $state->current?->approval?->requestId);
        $this->assertSame(Display::RELATION_LAUNCHER, $state->current->approval->relation);
        $this->assertTrue($state->current->approval->approvable, 'e2e-operator can still decide');

        $eligibility = \Yii::$app->get('approverEligibility');
        $this->assertInstanceOf(ApproverEligibility::class, $eligibility);
        $this->assertSame([$this->operatorId], $eligibility->eligibleForRequest($request));

        $access = \Yii::$app->get('workflowAccessChecker');
        $this->assertInstanceOf(WorkflowAccessChecker::class, $access);
        $workflowId = (int)$run->workflow_template_id;
        $this->assertTrue($access->canViewApprovalRequest($this->operatorId, $request), 'through e2e-alpha-gated-tmpl');
        $this->assertFalse($access->canViewWorkflowTemplate($this->operatorId, $workflowId), 'e2e-beta-tmpl is not their team\'s');
        $this->assertFalse($access->canViewApprovalRequest($this->viewerId, $request));
        $this->assertFalse($access->canViewWorkflowTemplate($this->viewerId, $workflowId));
    }

    /**
     * The run of e2e-long-paused-workflow has been paused for 26 hours, so
     * the dashboard and the run page read days: the fixture of the E2E check
     * that "Running for" no longer wraps after 24 hours
     * (workflow-jobs/waiting-states.spec.ts).
     */
    public function testTheLongPausedRunHasBeenPausedForMoreThanADay(): void
    {
        $this->seed();

        $pause = WorkflowStep::findOne(['name' => E2eWorkflowWaitSeeder::LONG_PAUSED_PAUSE]);
        $this->assertInstanceOf(WorkflowStep::class, $pause);
        $this->assertSame([
            [E2eWorkflowWaitSeeder::LONG_PAUSED_PAUSE, 10, WorkflowStep::TYPE_PAUSE, null, null, null, null],
            [E2eWorkflowWaitSeeder::LONG_PAUSED_JOB, 20, WorkflowStep::TYPE_JOB, $this->templateId, null, null, null],
        ], $this->stepsOf(E2eWorkflowWaitSeeder::LONG_PAUSED_WORKFLOW));
        $run = $this->onlyRunOf(E2eWorkflowWaitSeeder::LONG_PAUSED_WORKFLOW);
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status);
        $this->assertSame($this->adminId, (int)$run->launched_by);
        $this->assertSame((int)$pause->id, (int)$run->current_step_id);
        $pausedFor = time() - (int)$run->started_at;
        $this->assertGreaterThanOrEqual(E2eWorkflowWaitSeeder::LONG_PAUSE_SECONDS, $pausedFor);
        $this->assertLessThan(E2eWorkflowWaitSeeder::LONG_PAUSE_SECONDS + 60, $pausedFor);
        $execution = $this->onlyExecutionOf($run);
        $this->assertNull($execution->job_id);
        $this->assertSame(E2eWorkflowWaitSeeder::LONG_PAUSED_PAUSE, $execution->stepName());
        $this->assertSame((int)$run->started_at, (int)$execution->started_at);

        $state = $this->runStates()->forRun($run, Viewer::of($this->adminId));
        $this->assertSame(Display::STATE_PAUSED, $state->state);
        $this->assertSame(Display::WAITING_FOR_RESUME, $state->waitingFor);
        $this->assertSame('waiting 1d 2h', Display::durationLabel(E2eWorkflowWaitSeeder::LONG_PAUSE_SECONDS, Display::STATE_PAUSED, false));
    }

    public function testSeedsAFailedJobWhoseOneHostWasUnreachable(): void
    {
        $this->seed();

        $job = $this->unreachableJob();
        $this->assertSame($this->templateId, (int)$job->job_template_id);
        $this->assertSame(Job::STATUS_FAILED, $job->status);
        $this->assertSame(4, (int)$job->exit_code);
        $this->assertSame($this->adminId, (int)$job->launched_by);
        $this->assertTrue($job->isFinished());
        $this->assertNotNull($job->started_at);
        $this->assertSame(
            [[E2eWorkflowWaitSeeder::UNREACHABLE_HOST, 0, 0, 0, 0, 1, 0]],
            array_map(
                static fn (JobHostSummary $s): array => [$s->host, (int)$s->ok, (int)$s->changed, (int)$s->failed, (int)$s->skipped, (int)$s->unreachable, (int)$s->rescued],
                JobHostSummary::findAll(['job_id' => $job->id])
            )
        );
        $this->assertSame(
            [[E2eWorkflowWaitSeeder::UNREACHABLE_TASK, E2eWorkflowWaitSeeder::UNREACHABLE_HOST, JobTask::STATUS_UNREACHABLE]],
            array_map(
                static fn (JobTask $t): array => [$t->task_name, $t->host, $t->status],
                JobTask::findAll(['job_id' => $job->id])
            )
        );
    }

    /**
     * Every seed restores each fixture by name, whatever the specs or an
     * admin did with it: the rule, the template and the workflows keep their
     * IDs, the runs and the job are new, and what they left behind is gone.
     */
    public function testSeedingAgainRestoresTheFixturesInsteadOfAddingThem(): void
    {
        $this->seedPrerequisites();
        $this->seed();
        $rule = $this->ruleNamed(E2eWorkflowWaitSeeder::ALPHA_RULE);
        $gated = $this->templateNamed(E2eWorkflowWaitSeeder::ALPHA_GATED_TEMPLATE);
        $mixed = $this->workflowNamed(E2eWorkflowWaitSeeder::MIXED_WORKFLOW);
        $oldRun = $this->onlyRunOf(E2eWorkflowWaitSeeder::MIXED_WORKFLOW);
        $oldJobId = (int)$this->onlyExecutionOf($oldRun)->job_id;
        $oldLongRunId = (int)$this->onlyRunOf(E2eWorkflowWaitSeeder::LONG_PAUSED_WORKFLOW)->id;
        $oldUnreachable = (int)$this->unreachableJob()->id;

        // What specs and admins may do in between.
        $rule->updateAttributes(['approver_config' => (string)json_encode(['user_ids' => [$this->viewerId]]), 'timeout_minutes' => 5]);
        $gated->updateAttributes(['deleted_at' => time(), 'approval_rule_id' => null, 'trigger_token' => hash('sha256', 'e2e')]);
        $mixed->updateAttributes(['deleted_at' => time(), 'trigger_token' => hash('sha256', 'e2e'), 'description' => 'changed']);
        $extra = new WorkflowStep();
        $extra->setAttributes(['workflow_template_id' => $mixed->id, 'name' => 'e2e-added-by-a-spec', 'step_order' => 30, 'step_type' => WorkflowStep::TYPE_PAUSE], false);
        $extra->save(false);
        $oldRun->updateAttributes(['status' => WorkflowJob::STATUS_CANCELED, 'finished_at' => time()]);
        $this->log = [];

        $this->seed();

        $this->assertSame((int)$rule->id, (int)$this->ruleNamed(E2eWorkflowWaitSeeder::ALPHA_RULE)->id);
        $this->assertSame([$this->adminId, $this->operatorId], $this->ruleNamed(E2eWorkflowWaitSeeder::ALPHA_RULE)->getApproverUserIds());
        $this->assertNull($this->ruleNamed(E2eWorkflowWaitSeeder::ALPHA_RULE)->timeout_minutes);
        $restoredTemplate = $this->templateNamed(E2eWorkflowWaitSeeder::ALPHA_GATED_TEMPLATE);
        $this->assertSame((int)$gated->id, (int)$restoredTemplate->id);
        $this->assertNull($restoredTemplate->deleted_at);
        $this->assertNull($restoredTemplate->trigger_token);
        $this->assertSame((int)$rule->id, (int)$restoredTemplate->approval_rule_id);
        $restoredWorkflow = $this->workflowNamed(E2eWorkflowWaitSeeder::MIXED_WORKFLOW);
        $this->assertSame((int)$mixed->id, (int)$restoredWorkflow->id);
        $this->assertNull($restoredWorkflow->deleted_at);
        $this->assertNull($restoredWorkflow->trigger_token);
        $this->assertSame(
            [E2eWorkflowWaitSeeder::MIXED_GATED_STEP, E2eWorkflowWaitSeeder::MIXED_BETA_STEP],
            array_column($this->stepsOf(E2eWorkflowWaitSeeder::MIXED_WORKFLOW), 0)
        );
        $this->assertSame(1, (int)WorkflowTemplate::findWithDeleted()->where(['name' => E2eWorkflowWaitSeeder::MIXED_WORKFLOW])->count());

        $run = $this->onlyRunOf(E2eWorkflowWaitSeeder::MIXED_WORKFLOW);
        $this->assertNotSame((int)$oldRun->id, (int)$run->id);
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status);
        $this->assertNull(Job::findOne($oldJobId), 'the job of the replaced run went with it');
        $this->assertNull(ApprovalRequest::findOne(['job_id' => $oldJobId]), 'and so did its request');
        $this->assertNotSame($oldLongRunId, (int)$this->onlyRunOf(E2eWorkflowWaitSeeder::LONG_PAUSED_WORKFLOW)->id);
        $this->assertNull(Job::findOne($oldUnreachable));
        $this->assertSame([], JobTask::findAll(['job_id' => $oldUnreachable]), 'its task is gone too');
        $this->assertNotSame($oldUnreachable, (int)$this->unreachableJob()->id);
        $this->assertCount(1, $this->log);
    }

    public function testSkipsTheMixedWorkflowWithoutWhatItBuildsOn(): void
    {
        $this->seed();

        $this->assertNull(ApprovalRule::findOne(['name' => E2eWorkflowWaitSeeder::ALPHA_RULE]));
        $this->assertNull(JobTemplate::findWithDeleted()->where(['name' => E2eWorkflowWaitSeeder::ALPHA_GATED_TEMPLATE])->one());
        $this->assertNull(WorkflowTemplate::findWithDeleted()->where(['name' => E2eWorkflowWaitSeeder::MIXED_WORKFLOW])->one());
        $this->assertSame([
            sprintf(E2eWorkflowWaitSeeder::SKIPPED, 'e2e-mixed-gated-wf', 'e2e-alpha-proj, e2e-alpha-inv, e2e-beta-tmpl, e2e-gate-runner-group'),
            sprintf(
                E2eWorkflowWaitSeeder::RESTORED,
                sprintf('e2e-long-paused-workflow (run #%d paused for 26 h), ', $this->onlyRunOf(E2eWorkflowWaitSeeder::LONG_PAUSED_WORKFLOW)->id)
                . sprintf('e2e-unreachable-job (job #%d)', $this->unreachableJob()->id)
            ),
        ], $this->log);
    }

    public function testSkipsTheMixedWorkflowWithoutItsSecondApprover(): void
    {
        $this->seedPrerequisites();
        User::updateAll(['username' => 'e2e-operator-renamed'], ['id' => $this->operatorId]);

        $this->seed();

        $this->assertNull(WorkflowTemplate::findWithDeleted()->where(['name' => E2eWorkflowWaitSeeder::MIXED_WORKFLOW])->one());
        $this->assertSame(sprintf(E2eWorkflowWaitSeeder::SKIPPED, 'e2e-mixed-gated-wf', 'e2e-operator'), $this->log[0]);
    }

    /**
     * The teams, projects and templates of E2eTeamScopingSeeder, and the
     * runner group no runner serves (E2eWorkflowGateSeeder): what
     * e2e-mixed-gated-wf builds on.
     */
    private function seedPrerequisites(): RunnerGroup
    {
        (new E2eTeamScopingSeeder(static function (string $msg): void {
        }))->seed($this->adminId, (int)$this->createRunnerGroup($this->adminId)->id);
        $group = $this->createRunnerGroup($this->adminId);
        $group->name = E2eWorkflowWaitSeeder::GATE_RUNNER_GROUP;
        $group->save(false);

        return $group;
    }

    private function seed(): void
    {
        (new E2eWorkflowWaitSeeder(function (string $msg): void {
            $this->log[] = $msg;
        }))->seed($this->adminId, $this->templateId);
    }

    private function namedUser(string $username, string $role): User
    {
        $user = $this->createUser($role);
        $user->username = $username;
        $user->save(false);
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $rbacRole = $auth->getRole($role);
        $this->assertNotNull($rbacRole, "RBAC role {$role}");
        $auth->assign($rbacRole, (int)$user->id);

        return $user;
    }

    private function runStates(): WorkflowRunStates
    {
        $states = \Yii::$app->get('workflowRunStates');
        $this->assertInstanceOf(WorkflowRunStates::class, $states);

        return $states;
    }

    private function ruleNamed(string $name): ApprovalRule
    {
        $rule = ApprovalRule::findOne(['name' => $name]);
        $this->assertInstanceOf(ApprovalRule::class, $rule, $name);

        return $rule;
    }

    private function templateNamed(string $name): JobTemplate
    {
        $template = JobTemplate::findWithDeleted()->where(['name' => $name])->one();
        $this->assertInstanceOf(JobTemplate::class, $template, $name);

        return $template;
    }

    private function workflowNamed(string $name): WorkflowTemplate
    {
        $workflow = WorkflowTemplate::findWithDeleted()->where(['name' => $name])->one();
        $this->assertInstanceOf(WorkflowTemplate::class, $workflow, $name);

        return $workflow;
    }

    /**
     * The steps of the workflow $name in their order: name, order, type, job
     * template, On Success, On Failure, Always.
     *
     * @return list<array{0: string, 1: int, 2: string, 3: int|null, 4: int|null, 5: int|null, 6: int|null}>
     */
    private function stepsOf(string $name): array
    {
        $steps = WorkflowStep::find()->where(['workflow_template_id' => $this->workflowNamed($name)->id])->orderBy(['step_order' => SORT_ASC])->all();

        return array_map(static fn (WorkflowStep $s): array => [
            (string)$s->name,
            (int)$s->step_order,
            (string)$s->step_type,
            $s->job_template_id === null ? null : (int)$s->job_template_id,
            $s->on_success_step_id === null ? null : (int)$s->on_success_step_id,
            $s->on_failure_step_id === null ? null : (int)$s->on_failure_step_id,
            $s->on_always_step_id === null ? null : (int)$s->on_always_step_id,
        ], $steps);
    }

    private function onlyRunOf(string $workflowName): WorkflowJob
    {
        $runs = WorkflowJob::findAll(['workflow_template_id' => $this->workflowNamed($workflowName)->id]);
        $this->assertCount(1, $runs, "the runs of {$workflowName}");

        return $runs[0];
    }

    private function onlyExecutionOf(WorkflowJob $run): WorkflowJobStep
    {
        $executions = WorkflowJobStep::findAll(['workflow_job_id' => $run->id]);
        $this->assertCount(1, $executions);

        return $executions[0];
    }

    private function unreachableJob(): Job
    {
        $jobs = Job::findAll(['execution_command' => E2eWorkflowWaitSeeder::UNREACHABLE_JOB]);
        $this->assertCount(1, $jobs, 'one job marked ' . E2eWorkflowWaitSeeder::UNREACHABLE_JOB);

        return $jobs[0];
    }

    private function id(?\yii\db\ActiveRecord $record): int
    {
        $this->assertNotNull($record);

        return (int)$record->getPrimaryKey();
    }
}
