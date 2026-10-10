<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eTeamScopingSeeder;
use app\commands\E2eWorkflowScopingSeeder;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowAccessChecker;
use app\services\WorkflowExecutionService;
use app\tests\integration\DbTestCase;

/**
 * The workflow fixtures of the team scoping specs (E2eWorkflowScopingSeeder),
 * seeded on the teams, projects and templates of E2eTeamScopingSeeder.
 *
 * e2e-alpha-denied-wf carries a run whose second step was not launched; the
 * run page shows why (tests/e2e/tests/team-scoping/workflow-jobs.spec.ts).
 * The fixtures carry the names the specs look for; the test database holds
 * no "e2e-" rows outside a test, and the rollback removes them.
 */
class E2eWorkflowScopingSeederTest extends DbTestCase
{
    private const DENIED = 'e2e-alpha-denied-wf';

    private int $adminId;
    private int $operatorId;

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
        $this->namedUser('e2e-viewer', 'viewer');
        $this->log = [];
    }

    public function testSeedsAFailedRunWhoseSecondStepWasNotLaunchedAndSaysWhy(): void
    {
        $this->seed();

        $run = $this->onlyRunOf(self::DENIED);
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
        $this->assertSame($this->operatorId, (int)$run->launched_by, 'the workflow runs as e2e-operator');
        $this->assertNotNull($run->finished_at);
        [$launched, $refused] = $this->executionsOf($run);

        $this->assertSame('e2e-alpha-denied-wf-alpha', $launched->workflowStep?->name);
        $this->assertSame(WorkflowJobStep::STATUS_SUCCEEDED, $launched->status);
        $job = Job::findOne($launched->job_id);
        $this->assertNotNull($job, 'the first step started a job');
        $this->assertSame($this->templateId('e2e-alpha-tmpl'), (int)$job->job_template_id);
        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status);
        $this->assertSame($this->operatorId, (int)$job->launched_by);

        $viewed = $this->templateId('e2e-alpha-viewed-tmpl');
        $this->assertSame('e2e-alpha-denied-wf-viewed', $refused->workflowStep?->name);
        $this->assertSame($viewed, (int)$refused->workflowStep->job_template_id);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $refused->status);
        $this->assertNull($refused->job_id, 'the second step started no job');
        $this->assertNotNull($refused->finished_at);
        $this->assertSame((int)$refused->workflow_step_id, (int)$run->current_step_id, 'the run ended at the refused step');
        $this->assertSame(
            "Not launched: user #{$this->operatorId}, whom this workflow runs as, may not launch job template \"e2e-alpha-viewed-tmpl\" (#{$viewed}).",
            $refused->error_message
        );
    }

    /**
     * The fixture shows what the workflow engine does: e2e-operator may launch
     * the first step's template but not the second's, and the engine's reason
     * for refusing the second step is the seeded one, word for word. Both
     * take the wording from WorkflowExecutionService::JOB_STEP_REFUSAL; this
     * proves that the seeder fills in the user and the job template the
     * engine names for that step.
     */
    public function testTheRefusalIsTheOneTheWorkflowEngineGivesForThatStep(): void
    {
        $this->seed();
        $workflow = $this->workflowNamed(self::DENIED);
        $refusedStep = WorkflowStep::findOne(['workflow_template_id' => $workflow->id, 'name' => 'e2e-alpha-denied-wf-viewed']);
        $this->assertNotNull($refusedStep);
        $checker = \Yii::$app->get('workflowAccessChecker');
        $this->assertInstanceOf(WorkflowAccessChecker::class, $checker);
        $this->assertTrue($checker->canDispatch($this->operatorId, $this->template('e2e-alpha-tmpl')));
        $this->assertFalse($checker->canDispatch($this->operatorId, $this->template('e2e-alpha-viewed-tmpl')));

        $engineRun = new WorkflowJob();
        $engineRun->workflow_template_id = (int)$workflow->id;
        $engineRun->launched_by = $this->operatorId;
        $engineRun->status = WorkflowJob::STATUS_RUNNING;
        $engineRun->started_at = time();
        $engineRun->save(false);
        $engine = \Yii::$app->get('workflowExecutionService');
        $this->assertInstanceOf(WorkflowExecutionService::class, $engine);
        $refusedByEngine = $engine->executeStep($engineRun, $refusedStep);

        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $refusedByEngine->status);
        $seeded = $this->executionsOf($this->seededRunOf($workflow, (int)$engineRun->id))[1];
        $this->assertNotSame('', (string)$seeded->error_message);
        $this->assertSame($refusedByEngine->error_message, $seeded->error_message);
    }

    public function testSeedingAgainRestoresTheRunInsteadOfAddingOne(): void
    {
        $this->seed();
        [$launched] = $this->executionsOf($this->onlyRunOf(self::DENIED));
        $firstJobId = (int)$launched->job_id;

        $this->seed();

        $workflow = $this->workflowNamed(self::DENIED);
        $this->assertSame(1, (int)WorkflowTemplate::find()->where(['name' => self::DENIED])->count());
        $this->assertSame(
            ['e2e-alpha-denied-wf-alpha', 'e2e-alpha-denied-wf-viewed'],
            WorkflowStep::find()->select('name')->where(['workflow_template_id' => $workflow->id])->orderBy(['step_order' => SORT_ASC])->column()
        );
        [$launched, $refused] = $this->executionsOf($this->onlyRunOf(self::DENIED));
        $this->assertNull(Job::findOne($firstJobId), 'the job of the replaced run went with it');
        $this->assertNotNull(Job::findOne($launched->job_id));
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $refused->status);
        $this->assertStringStartsWith('Not launched: ', (string)$refused->error_message);
        $this->assertContains("  Restored workflow scoping fixtures (alpha, beta, alpha-viewed, alpha-denied, mixed, beta-approval, pause-only, alpha-legacy).\n", $this->log);
    }

    /**
     * e2e-alpha-legacy-wf keeps another team's job template on its pause
     * step, as older versions saved it. Only job steps count, so e2e-operator,
     * whose team operates the job step's project, may change and launch it
     * (tests/e2e/tests/team-scoping/workflow-templates.spec.ts).
     */
    public function testTheLegacyWorkflowCarriesAnotherTeamsTemplateOnItsPauseStepAndStaysOpenToTheTeam(): void
    {
        $this->seed();
        $this->seed();

        $workflow = $this->workflowNamed('e2e-alpha-legacy-wf');
        $steps = WorkflowStep::find()->where(['workflow_template_id' => $workflow->id])->orderBy(['step_order' => SORT_ASC])->all();
        $this->assertSame(
            [
                ['e2e-alpha-legacy-wf-job', WorkflowStep::TYPE_JOB, $this->templateId('e2e-alpha-tmpl')],
                ['e2e-alpha-legacy-wf-pause', WorkflowStep::TYPE_PAUSE, $this->templateId('e2e-beta-tmpl')],
            ],
            array_map(static fn (WorkflowStep $s): array => [$s->name, $s->step_type, (int)$s->job_template_id], $steps)
        );
        $checker = \Yii::$app->get('workflowAccessChecker');
        $this->assertInstanceOf(WorkflowAccessChecker::class, $checker);
        $this->assertTrue($checker->canOperateWorkflowTemplate($this->operatorId, (int)$workflow->id));
    }

    public function testSkipsTheFixturesWithoutTheTeamScopingTemplates(): void
    {
        (new E2eWorkflowScopingSeeder(function (string $msg): void {
            $this->log[] = $msg;
        }))->seed($this->adminId);

        $this->assertNull(WorkflowTemplate::findOne(['name' => self::DENIED]));
        $this->assertSame(["  Workflow scoping fixtures skipped: team scoping templates or users missing.\n"], $this->log);
    }

    private function seed(): void
    {
        $logger = function (string $msg): void {
            $this->log[] = $msg;
        };
        (new E2eTeamScopingSeeder($logger))->seed($this->adminId, (int)$this->createRunnerGroup($this->adminId)->id);
        (new E2eWorkflowScopingSeeder($logger))->seed($this->adminId);
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

    private function workflowNamed(string $name): WorkflowTemplate
    {
        $workflow = WorkflowTemplate::findOne(['name' => $name]);
        $this->assertNotNull($workflow, $name);

        return $workflow;
    }

    private function onlyRunOf(string $workflowName): WorkflowJob
    {
        $runs = WorkflowJob::findAll(['workflow_template_id' => $this->workflowNamed($workflowName)->id]);
        $this->assertCount(1, $runs, "the runs of {$workflowName}");

        return $runs[0];
    }

    /**
     * The seeded run of $workflow, besides the run with id $otherRunId.
     */
    private function seededRunOf(WorkflowTemplate $workflow, int $otherRunId): WorkflowJob
    {
        $run = WorkflowJob::find()->where(['workflow_template_id' => $workflow->id])->andWhere(['<>', 'id', $otherRunId])->one();
        $this->assertInstanceOf(WorkflowJob::class, $run);

        return $run;
    }

    /**
     * @return array{0: WorkflowJobStep, 1: WorkflowJobStep}
     */
    private function executionsOf(WorkflowJob $run): array
    {
        $executions = WorkflowJobStep::find()->where(['workflow_job_id' => $run->id])->orderBy(['id' => SORT_ASC])->all();
        $this->assertCount(2, $executions);

        return [0 => $executions[0], 1 => $executions[1]];
    }

    private function template(string $name): JobTemplate
    {
        $template = JobTemplate::findOne(['name' => $name]);
        $this->assertNotNull($template, $name);

        return $template;
    }

    private function templateId(string $name): int
    {
        return (int)$this->template($name)->id;
    }
}
