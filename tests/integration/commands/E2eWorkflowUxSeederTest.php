<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eTeardownHelper;
use app\commands\E2eWorkflowUxSeeder;
use app\components\WorkflowRouteStep;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTask;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowRouteMap;
use app\tests\integration\DbTestCase;

/**
 * The workflow fixtures of the template-page E2E specs (E2eWorkflowUxSeeder):
 * positions, routes and editing steps. The specs find them by these names;
 * the test database holds no "e2e-" rows outside a test, and the rollback
 * removes them.
 */
class E2eWorkflowUxSeederTest extends DbTestCase
{
    private const FIXTURES = [
        'e2e-routes-workflow',
        'e2e-routes-legacy-workflow',
        'e2e-routes-loop-workflow',
        'e2e-edit-steps-workflow',
        'e2e-edit-operator-workflow',
    ];

    private const RESTORED_LOG = "  Restored workflow UX fixtures: e2e-routes-workflow, e2e-routes-gone-template, "
        . "e2e-routes-legacy-workflow, e2e-routes-loop-workflow, e2e-edit-steps-workflow, e2e-edit-operator-workflow.\n";

    private int $adminId;
    /** Stands for e2e-template, the job template E2eController passes. */
    private int $templateId;
    /** Stand for E2eWorkflowGateSeeder's e2e-gate-rule and e2e-gated-job-template. */
    private int $gateRuleId;
    private int $gatedTemplateId;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->createUser('admin');
        $admin->is_superadmin = true;
        $admin->save(false);
        $this->adminId = (int)$admin->id;
        $this->templateId = $this->jobTemplate()->id;
        $rule = $this->createApprovalRule($this->adminId);
        $rule->name = 'e2e-gate-rule';
        $rule->save(false);
        $this->gateRuleId = (int)$rule->id;
        $gated = $this->jobTemplate();
        $gated->name = 'e2e-gated-job-template';
        $gated->approval_rule_id = $this->gateRuleId;
        $gated->save(false);
        $this->gatedTemplateId = (int)$gated->id;
        $this->log = [];
    }

    public function testSeedsEveryFixtureWithTheStepsTheSpecsExpect(): void
    {
        $this->seed();

        $job = $this->templateId;
        $gone = $this->goneTemplate();
        $this->assertSame([
            ['e2e-routes-build', 10, 'job', $job, null, null, 'e2e-routes-cleanup', null],
            ['e2e-routes-approve', 20, 'approval', null, $this->gateRuleId, null, null, null],
            ['e2e-routes-deploy', 30, 'job', $this->gatedTemplateId, null, null, null, null],
            ['e2e-routes-verify', 40, 'job', $job, null, null, 'e2e-routes-deploy', null],
            ['e2e-routes-wait', 50, 'pause', null, null, null, null, null],
            ['e2e-routes-cleanup', 60, 'job', $job, null, 'END', 'END', null],
            ['e2e-routes-spare', 70, 'job', $job, null, null, null, null],
        ], $this->stepsOf('e2e-routes-workflow'));
        $this->assertSame([
            ['e2e-legacy-start', 10, 'job', $job, null, '#999999999', null, null],
            ['e2e-legacy-gone', 20, 'job', (int)$gone->id, null, null, null, null],
            ['e2e-legacy-pause', 30, 'pause', null, null, null, null, 'e2e-legacy-end'],
            ['e2e-legacy-end', 40, 'job', $job, null, null, null, null],
        ], $this->stepsOf('e2e-routes-legacy-workflow'));
        $this->assertSame([
            ['e2e-loop-check', 10, 'job', $job, null, null, 'e2e-loop-check', null],
            ['e2e-loop-retry', 20, 'job', $job, null, 'e2e-loop-check', 'END', null],
        ], $this->stepsOf('e2e-routes-loop-workflow'));
        $this->assertSame([
            ['e2e-edit-build', 10, 'job', $job, null, null, 'e2e-edit-deploy', null],
            ['e2e-edit-test', 20, 'job', $job, null, null, null, null],
            ['e2e-edit-deploy', 30, 'job', $job, null, null, null, null],
        ], $this->stepsOf('e2e-edit-steps-workflow'));
        $this->assertSame([
            ['e2e-edit-operator-first', 10, 'job', $job, null, null, null, null],
            ['e2e-edit-operator-second', 20, 'job', $job, null, null, null, null],
        ], $this->stepsOf('e2e-edit-operator-workflow'));

        foreach (self::FIXTURES as $name) {
            $workflow = $this->workflow($name);
            $this->assertSame($this->adminId, (int)$workflow->created_by, $name);
            $this->assertSame('E2E fixture: step positions, routes and editing (E2eWorkflowUxSeeder)', $workflow->description, $name);
            $this->assertNull($workflow->trigger_token, $name);
            $this->assertNull($workflow->deleted_at, $name);
            $this->assertSame([], $this->runsOf($name), $name);
        }
        $this->assertGoneTemplate($gone);
        $this->assertSame([self::RESTORED_LOG], $this->log);
    }

    /**
     * What the steps tables of the specs show, as the route map works it
     * out: e2e-routes-spare is unreachable, e2e-routes-deploy is a gated job
     * step, the legacy step on the deleted template cannot run, and each
     * success path ends as the specs expect.
     */
    public function testTheRouteMapsAreTheOnesTheSpecsRead(): void
    {
        $this->seed();

        $routes = WorkflowRouteMap::forTemplate($this->workflow('e2e-routes-workflow'));
        $ids = $this->idsOf('e2e-routes-workflow');
        $this->assertSame(array_values($ids), array_map(static fn (WorkflowRouteStep $step): int => $step->id, $routes->steps()));
        $this->assertFalse($routes->isReachable($ids['e2e-routes-spare']), 'no branch leads to the spare step');
        foreach (array_slice($ids, 0, 6) as $name => $id) {
            $this->assertTrue($routes->isReachable($id), $name);
        }
        $this->assertSame('e2e-gate-rule', $routes->find($ids['e2e-routes-deploy'])?->gatedRuleName, 'a gated job step');
        $this->assertSame([
            'step_ids' => array_values(array_slice($ids, 0, 6)),
            'end' => WorkflowRouteMap::PATH_SUCCEEDS,
            'loop_to_step_id' => null,
            'missing_step_id' => null,
        ], $routes->successPath());
        $verify = $routes->find($ids['e2e-routes-verify']);
        $this->assertNotNull($verify);
        $this->assertTrue($routes->route($verify, WorkflowRouteMap::OUTCOME_FAILURE)?->loop, 'back to e2e-routes-deploy');

        $legacy = WorkflowRouteMap::forTemplate($this->workflow('e2e-routes-legacy-workflow'));
        $legacyIds = $this->idsOf('e2e-routes-legacy-workflow');
        $this->assertSame(WorkflowRouteMap::PATH_MISSING, $legacy->successPath()['end']);
        $this->assertSame(E2eWorkflowUxSeeder::MISSING_STEP_ID, $legacy->successPath()['missing_step_id']);
        $this->assertSame(WorkflowRouteStep::PROBLEM_JOB_TEMPLATE_MISSING, $legacy->find($legacyIds['e2e-legacy-gone'])?->problem);
        $pause = $legacy->find($legacyIds['e2e-legacy-pause']);
        $this->assertNotNull($pause);
        $this->assertSame($legacyIds['e2e-legacy-end'], $legacy->always($pause)?->stepId);

        $loop = WorkflowRouteMap::forTemplate($this->workflow('e2e-routes-loop-workflow'));
        $loopIds = $this->idsOf('e2e-routes-loop-workflow');
        $this->assertSame([
            'step_ids' => array_values($loopIds),
            'end' => WorkflowRouteMap::PATH_LOOP,
            'loop_to_step_id' => $loopIds['e2e-loop-check'],
            'missing_step_id' => null,
        ], $loop->successPath());
    }

    /**
     * After the specs ran, or failed halfway: steps renamed, moved, removed
     * and added, a branch changed, a run waiting for an approval with its
     * job and request, a trigger token, a deleted workflow, the deleted job
     * template restored. Seeding again restores each fixture in place.
     */
    public function testSeedingAgainRestoresEveryFixtureInPlace(): void
    {
        $this->seed();
        $workflowIds = array_map(fn (string $name): int => (int)$this->workflow($name)->id, self::FIXTURES);
        $goneId = (int)$this->goneTemplate()->id;
        $edit = $this->idsOf('e2e-edit-steps-workflow');
        WorkflowStep::updateAll(['name' => 'e2e-edit-deploy-renamed'], ['id' => $edit['e2e-edit-deploy']]);
        WorkflowStep::updateAll(['on_failure_step_id' => WorkflowStep::END_WORKFLOW], ['id' => $edit['e2e-edit-build']]);
        WorkflowStep::updateAll(['step_order' => 5], ['id' => $edit['e2e-edit-test']]);
        $operator = $this->idsOf('e2e-edit-operator-workflow');
        WorkflowStep::deleteAll(['id' => $operator['e2e-edit-operator-second']]);
        $added = $this->createWorkflowStep((int)$this->workflow('e2e-edit-operator-workflow')->id, 15, WorkflowStep::TYPE_PAUSE);
        [$run, $jobId, $taskId, $requestId] = $this->waitingRun();
        WorkflowTemplate::updateAll(['trigger_token' => hash('sha256', 'e2e-token'), 'trigger_token_created_by' => $this->adminId], [
            'id' => $this->workflow('e2e-routes-loop-workflow')->id,
        ]);
        $this->assertTrue($this->workflow('e2e-routes-legacy-workflow')->softDelete());
        JobTemplate::updateAll(['deleted_at' => null, 'approval_rule_id' => $this->gateRuleId], ['id' => $goneId]);

        $this->seed();

        foreach (self::FIXTURES as $index => $name) {
            $this->assertSame(1, (int)WorkflowTemplate::findWithDeleted()->where(['name' => $name])->count(), $name);
            $workflow = $this->workflow($name);
            $this->assertSame($workflowIds[$index], (int)$workflow->id, "{$name} is restored in place");
            $this->assertNull($workflow->deleted_at, $name);
            $this->assertNull($workflow->trigger_token, $name);
            $this->assertNull($workflow->trigger_token_created_by, $name);
            $this->assertSame([], $this->runsOf($name), $name);
        }
        $this->assertSame([
            ['e2e-edit-build', 10, 'job', $this->templateId, null, null, 'e2e-edit-deploy', null],
            ['e2e-edit-test', 20, 'job', $this->templateId, null, null, null, null],
            ['e2e-edit-deploy', 30, 'job', $this->templateId, null, null, null, null],
        ], $this->stepsOf('e2e-edit-steps-workflow'), 'the names, order and branch are back');
        $this->assertSame(['e2e-edit-operator-first', 'e2e-edit-operator-second'], array_column($this->stepsOf('e2e-edit-operator-workflow'), 0));
        $this->assertNull(WorkflowStep::findOne($added->id));
        $this->assertNull(WorkflowJob::findOne($run->id), 'the run a spec left');
        $this->assertNull(Job::findOne($jobId), 'went with the job its step launched');
        $this->assertNull(JobTask::findOne($taskId), 'and that job\'s tasks');
        $this->assertNull(ApprovalRequest::findOne($requestId), 'and its approval request');
        $gone = $this->goneTemplate();
        $this->assertSame($goneId, (int)$gone->id, 'restored in place');
        $this->assertGoneTemplate($gone);
        $this->assertSame(1, (int)JobTemplate::findWithDeleted()->where(['name' => 'e2e-routes-gone-template'])->count());
        $this->assertSame([self::RESTORED_LOG, self::RESTORED_LOG], $this->log);
    }

    /**
     * Without E2eWorkflowGateSeeder's rule or gated job template, which it
     * skips without e2e-operator, there is no e2e-routes-workflow; the other
     * fixtures are restored.
     *
     * @dataProvider gateFixtures
     */
    public function testWithoutAGateFixtureTheRoutesWorkflowIsSkipped(string $missing): void
    {
        if ($missing === 'e2e-gate-rule') {
            ApprovalRule::updateAll(['name' => 'not-the-e2e-gate-rule'], ['id' => $this->gateRuleId]);
        } else {
            JobTemplate::updateAll(['name' => 'not-the-e2e-gated-job-template'], ['id' => $this->gatedTemplateId]);
        }

        $this->seed();

        $this->assertNull(WorkflowTemplate::findOne(['name' => 'e2e-routes-workflow']));
        $this->assertSame([
            "  Workflow UX fixture e2e-routes-workflow skipped: {$missing} missing.\n",
            "  Restored workflow UX fixtures: e2e-routes-gone-template, e2e-routes-legacy-workflow, e2e-routes-loop-workflow, "
            . "e2e-edit-steps-workflow, e2e-edit-operator-workflow.\n",
        ], $this->log);
        foreach (array_slice(self::FIXTURES, 1) as $name) {
            $this->assertNotNull(WorkflowTemplate::findOne(['name' => $name]), $name);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function gateFixtures(): array
    {
        return [
            'the approval rule' => ['e2e-gate-rule'],
            'the gated job template' => ['e2e-gated-job-template'],
        ];
    }

    /**
     * The global teardown (php yii e2e/teardown) removes every fixture, the
     * deleted job template, and what the specs left: e2e-gate-rule restricts
     * the delete of the approval step that uses it.
     */
    public function testTheE2eTeardownRemovesEveryFixture(): void
    {
        // The teardown takes the rows of the users named like E2E users.
        User::updateAll(['username' => 'e2e-admin'], ['id' => $this->adminId]);
        JobTemplate::updateAll(['name' => 'e2e-template'], ['id' => $this->templateId]);
        $this->seed();
        [$run, $jobId] = $this->waitingRun();

        $problems = (new E2eTeardownHelper('e2e-', static function (string $msg): void {
        }))->teardownDatabase();

        $this->assertSame([], $problems);
        foreach (self::FIXTURES as $name) {
            $this->assertNull(WorkflowTemplate::findWithDeleted()->where(['name' => $name])->one(), $name);
        }
        $this->assertNull(JobTemplate::findWithDeleted()->where(['name' => 'e2e-routes-gone-template'])->one());
        $this->assertSame(0, (int)WorkflowStep::find()->where(['like', 'name', 'e2e-%', false])->count());
        $this->assertNull(WorkflowJob::findOne($run->id));
        $this->assertNull(Job::findOne($jobId));
    }

    public function testAJobTemplateThatDoesNotExistIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Job template #999999999 does not exist.');

        (new E2eWorkflowUxSeeder(function (string $msg): void {
            $this->log[] = $msg;
        }))->seed($this->adminId, 999999999);
    }

    private function seed(): void
    {
        (new E2eWorkflowUxSeeder(function (string $msg): void {
            $this->log[] = $msg;
        }))->seed($this->adminId, $this->templateId);
    }

    private function jobTemplate(): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($this->adminId)->id,
            (int)$this->createInventory($this->adminId)->id,
            (int)$this->createRunnerGroup($this->adminId)->id,
            $this->adminId
        );
    }

    /**
     * A run of e2e-routes-workflow that waits at its approval step, as a
     * spec could leave it: its placeholder job, with a task and a pending
     * approval request.
     *
     * @return array{0: WorkflowJob, 1: int, 2: int, 3: int} the run, the job, its task and its request
     */
    private function waitingRun(): array
    {
        $approve = $this->idsOf('e2e-routes-workflow')['e2e-routes-approve'];
        $run = new WorkflowJob();
        $run->workflow_template_id = (int)$this->workflow('e2e-routes-workflow')->id;
        $run->launched_by = $this->adminId;
        $run->status = WorkflowJob::STATUS_RUNNING;
        $run->current_step_id = $approve;
        $run->started_at = time();
        $run->save(false);
        $job = new Job();
        $job->launched_by = $this->adminId;
        $job->status = Job::STATUS_PENDING_APPROVAL;
        $job->timeout_minutes = 0;
        $job->has_changes = 0;
        $job->save(false);
        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = (int)$run->id;
        $execution->workflow_step_id = $approve;
        $execution->job_id = (int)$job->id;
        $execution->status = WorkflowJobStep::STATUS_RUNNING;
        $execution->save(false);
        $task = new JobTask();
        $task->job_id = (int)$job->id;
        $task->sequence = 1;
        $task->task_name = 'e2e-task';
        $task->task_action = 'debug';
        $task->host = 'localhost';
        $task->status = 'ok';
        $task->changed = 0;
        $task->duration_ms = 1;
        $task->save(false);
        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->approval_rule_id = $this->gateRuleId;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $request->save(false);

        return [$run, (int)$job->id, (int)$task->id, (int)$request->id];
    }

    private function workflow(string $name): WorkflowTemplate
    {
        $workflow = WorkflowTemplate::findOne(['name' => $name]);
        $this->assertNotNull($workflow, $name);

        return $workflow;
    }

    private function goneTemplate(): JobTemplate
    {
        $template = JobTemplate::findWithDeleted()->where(['name' => 'e2e-routes-gone-template'])->one();
        $this->assertInstanceOf(JobTemplate::class, $template);

        return $template;
    }

    /**
     * e2e-routes-gone-template: soft-deleted, without an approval rule or
     * trigger token, on the project, inventory, runner group and playbook of
     * the job template the seed was given.
     */
    private function assertGoneTemplate(JobTemplate $gone): void
    {
        $source = JobTemplate::findOne($this->templateId);
        $this->assertNotNull($source);
        $this->assertNotNull($gone->deleted_at, 'soft-deleted');
        $this->assertNull(JobTemplate::findOne($gone->id), 'so the job steps on it cannot run');
        $this->assertNull($gone->approval_rule_id);
        $this->assertNull($gone->trigger_token);
        $this->assertSame((int)$source->project_id, (int)$gone->project_id);
        $this->assertSame((int)$source->inventory_id, (int)$gone->inventory_id);
        $this->assertSame((int)$source->runner_group_id, (int)$gone->runner_group_id);
        $this->assertSame((string)$source->playbook, $gone->playbook);
        $this->assertSame($this->adminId, (int)$gone->created_by);
    }

    /**
     * The IDs of a fixture's steps by name, in run order.
     *
     * @return array<string, int>
     */
    private function idsOf(string $workflowName): array
    {
        $ids = [];
        foreach ($this->workflow($workflowName)->getSteps()->all() as $step) {
            $ids[(string)$step->name] = (int)$step->id;
        }

        return $ids;
    }

    /**
     * The steps of a fixture by step order: name, order, type, job template,
     * approval rule, and where its On Success, On Failure and Always branches
     * lead: the name of the step, END, or "#<ID>" for a step that does not
     * exist.
     *
     * @return list<array{0: string, 1: int, 2: string, 3: int|null, 4: int|null, 5: string|null, 6: string|null, 7: string|null}>
     */
    private function stepsOf(string $workflowName): array
    {
        /** @var list<WorkflowStep> $steps */
        $steps = WorkflowStep::find()
            ->where(['workflow_template_id' => $this->workflow($workflowName)->id])
            ->orderBy(['step_order' => SORT_ASC, 'id' => SORT_ASC])
            ->all();
        $names = [];
        foreach ($steps as $step) {
            $names[(int)$step->id] = (string)$step->name;
        }
        $target = static fn (mixed $id): ?string => match (true) {
            $id === null => null,
            (int)$id === WorkflowStep::END_WORKFLOW => 'END',
            default => $names[(int)$id] ?? '#' . (int)$id,
        };

        return array_map(static fn (WorkflowStep $s): array => [
            (string)$s->name,
            (int)$s->step_order,
            (string)$s->step_type,
            $s->job_template_id === null ? null : (int)$s->job_template_id,
            $s->approval_rule_id === null ? null : (int)$s->approval_rule_id,
            $target($s->on_success_step_id),
            $target($s->on_failure_step_id),
            $target($s->on_always_step_id),
        ], $steps);
    }

    /**
     * @return list<WorkflowJob>
     */
    private function runsOf(string $workflowName): array
    {
        return WorkflowJob::find()->where(['workflow_template_id' => $this->workflow($workflowName)->id])->all();
    }
}
