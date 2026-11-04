<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eRecoveredFailureSeeder;
use app\commands\E2eTeardownHelper;
use app\controllers\SiteController;
use app\models\Job;
use app\models\JobSearchForm;
use app\models\JobTemplate;
use app\models\Project;
use app\services\RecoveredFailures;
use app\tests\integration\DbTestCase;

/**
 * The fixture of the E2E specs of recovered failures
 * (tests/e2e/tests/site/dashboard-recovered.spec.ts, site/rbac.spec.ts,
 * jobs/list-view.spec.ts, jobs/rbac.spec.ts): on a project that no team
 * has, so every role sees it, e2e-recovery-template has a failed job of
 * two hours ago and the success that recovered it,
 * e2e-recovery-open-template a failed job of three hours ago that no later
 * run recovered, and e2e-recovery-old-template a failed job of three days
 * ago with the success that recovered it. The first two failures are
 * within the dashboard's last 24 hours, and the open one is the older, so
 * that the red panel lists the recovered one if it is not left out; the
 * old one is not, so the dashboard's link Show them must not list it.
 * Every seed restores them anew; the teardown removes them.
 */
class E2eRecoveredFailureSeederTest extends DbTestCase
{
    private int $adminId;

    private int $inventoryId;

    private int $runnerGroupId;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->createUser('admin');
        $admin->username = 'e2e-admin';
        $admin->is_superadmin = true;
        $admin->save(false);
        $this->adminId = (int)$admin->id;
        $this->inventoryId = (int)$this->createInventory($this->adminId)->id;
        $this->runnerGroupId = (int)$this->createRunnerGroup($this->adminId)->id;
        $this->log = [];
    }

    public function testTheRecoveredFailureIsRecoveredAndTheOpenOneIsNot(): void
    {
        $before = time();
        $this->seed();

        [$failure, $success] = $this->jobsOf(E2eRecoveredFailureSeeder::TEMPLATE);
        [$open] = $this->jobsOf(E2eRecoveredFailureSeeder::OPEN_TEMPLATE);
        [$oldFailure, $oldSuccess] = $this->jobsOf(E2eRecoveredFailureSeeder::OLD_TEMPLATE);
        $this->assertSame(
            [Job::STATUS_FAILED, Job::STATUS_SUCCEEDED, Job::STATUS_FAILED, Job::STATUS_FAILED, Job::STATUS_SUCCEEDED],
            [$failure->status, $success->status, $open->status, $oldFailure->status, $oldSuccess->status]
        );
        $this->assertSame(
            [
                E2eRecoveredFailureSeeder::RECOVERED_JOB,
                E2eRecoveredFailureSeeder::RECOVERING_JOB,
                E2eRecoveredFailureSeeder::OPEN_JOB,
                E2eRecoveredFailureSeeder::OLD_RECOVERED_JOB,
                E2eRecoveredFailureSeeder::OLD_RECOVERING_JOB,
            ],
            [$failure->execution_command, $success->execution_command, $open->execution_command, $oldFailure->execution_command, $oldSuccess->execution_command]
        );
        $this->assertSame(
            [(int)$failure->id => (int)$success->id, (int)$oldFailure->id => (int)$oldSuccess->id],
            RecoveredFailures::recoveredBy([$failure, $success, $open, $oldFailure, $oldSuccess])
        );
        $this->assertGreaterThanOrEqual($before - 86400 + 3600, (int)$open->finished_at, 'within the last 24 hours, by far');
        $this->assertLessThan((int)$failure->finished_at, (int)$open->finished_at, 'the open failure is the older');
        $this->assertLessThan($before - 2 * 86400, (int)$oldFailure->finished_at, 'before the last 24 hours, by far');
        $this->assertSame([0, 0], [(int)$success->check_mode, (int)$oldSuccess->check_mode], 'no dry run');
        $this->assertSame(
            sprintf(E2eRecoveredFailureSeeder::RESTORED, $failure->id, $success->id, $open->id, $oldFailure->id, $oldSuccess->id),
            $this->log[0]
        );
    }

    /**
     * The dashboard's link to the recovered failures of its last 24 hours
     * lists the recovered failure of two hours ago, and not the one of
     * three days ago, which the list of every recovered failure shows.
     */
    public function testTheOldRecoveredFailureIsNotOfTheDashboardsWindow(): void
    {
        $this->seed();
        [$failure] = $this->jobsOf(E2eRecoveredFailureSeeder::TEMPLATE);
        [$oldFailure] = $this->jobsOf(E2eRecoveredFailureSeeder::OLD_TEMPLATE);
        $templates = [$this->template(E2eRecoveredFailureSeeder::TEMPLATE), $this->template(E2eRecoveredFailureSeeder::OLD_TEMPLATE)];
        $ids = static fn (array $params): array => array_map(
            static fn (Job $job): int => (int)$job->id,
            array_values(array_filter(
                (new JobSearchForm())->search($params)->getModels(),
                static fn (Job $job): bool => in_array((int)$job->job_template_id, array_map(static fn (JobTemplate $t): int => (int)$t->id, $templates), true)
            ))
        );

        $this->assertSame([(int)$failure->id], $ids(['recovered' => '1', 'finished_since' => (string)(time() - SiteController::FAILED_JOBS_WINDOW)]));
        $this->assertSame([(int)$oldFailure->id, (int)$failure->id], $ids(['recovered' => '1']));
    }

    /**
     * Both templates are on one project without a team: every signed-in
     * user may see them, viewer and operator included.
     */
    public function testEveryRoleMaySeeTheFixtures(): void
    {
        $this->seed();

        $project = Project::findOne(['name' => E2eRecoveredFailureSeeder::PROJECT]);
        $this->assertNotNull($project);
        foreach ([E2eRecoveredFailureSeeder::TEMPLATE, E2eRecoveredFailureSeeder::OPEN_TEMPLATE, E2eRecoveredFailureSeeder::OLD_TEMPLATE] as $name) {
            $template = $this->template($name);
            $this->assertSame((int)$project->id, (int)$template->project_id, $name);
            $this->assertSame([$this->inventoryId, $this->runnerGroupId], [(int)$template->inventory_id, (int)$template->runner_group_id], $name);
        }
        $this->assertFalse(
            (new \yii\db\Query())->from('{{%team_project}}')->where(['project_id' => $project->id])->exists(),
            'no team has the project'
        );
    }

    /**
     * A second seed restores the same fixtures: one project, one template
     * each, and only the seeded jobs, a job a spec left on a template
     * included.
     */
    public function testSeedingAgainRestoresTheFixtures(): void
    {
        $this->seed();
        $leftover = $this->createJob((int)$this->template(E2eRecoveredFailureSeeder::TEMPLATE)->id, $this->adminId, Job::STATUS_SUCCEEDED);
        \Yii::$app->db->createCommand()->insert('{{%job_task}}', [
            'job_id' => $leftover->id,
            'sequence' => 1,
            'task_name' => 'e2e task',
            'task_action' => 'debug',
            'host' => 'localhost',
            'status' => 'ok',
            'changed' => 0,
            'duration_ms' => 1,
            'created_at' => time(),
        ])->execute();
        $this->seed();

        $this->assertNull(Job::findOne($leftover->id));
        $this->assertFalse((new \yii\db\Query())->from('{{%job_task}}')->where(['job_id' => $leftover->id])->exists(), 'with its tasks');
        $this->assertSame(1, (int)Project::find()->where(['name' => E2eRecoveredFailureSeeder::PROJECT])->count());
        foreach ([E2eRecoveredFailureSeeder::TEMPLATE => 2, E2eRecoveredFailureSeeder::OPEN_TEMPLATE => 1, E2eRecoveredFailureSeeder::OLD_TEMPLATE => 2] as $name => $jobs) {
            $this->assertSame(1, (int)JobTemplate::find()->where(['name' => $name])->count(), $name);
            $this->assertCount($jobs, $this->jobsOf($name), $name);
        }
        [$failure, $success] = $this->jobsOf(E2eRecoveredFailureSeeder::TEMPLATE);
        $this->assertSame([(int)$failure->id => (int)$success->id], RecoveredFailures::recoveredBy([$failure]));
    }

    /**
     * yii e2e/teardown removes the project, the templates and the jobs.
     */
    public function testTheTeardownRemovesTheFixtures(): void
    {
        $this->seed();
        $templateIds = array_map(
            fn (string $name): int => (int)$this->template($name)->id,
            [E2eRecoveredFailureSeeder::TEMPLATE, E2eRecoveredFailureSeeder::OPEN_TEMPLATE, E2eRecoveredFailureSeeder::OLD_TEMPLATE]
        );

        $problems = (new E2eTeardownHelper('e2e-', static function (string $msg): void {
        }))->teardownDatabase();

        $this->assertSame([], $problems);
        $this->assertNull(Project::findOne(['name' => E2eRecoveredFailureSeeder::PROJECT]));
        $this->assertSame(0, (int)JobTemplate::findWithDeleted()->where(['id' => $templateIds])->count());
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $templateIds])->count());
    }

    // -- Helpers --------------------------------------------------------------

    private function seed(): void
    {
        (new E2eRecoveredFailureSeeder(function (string $msg): void {
            $this->log[] = $msg;
        }))->seed($this->adminId, $this->inventoryId, $this->runnerGroupId);
    }

    private function template(string $name): JobTemplate
    {
        $template = JobTemplate::findOne(['name' => $name]);
        $this->assertNotNull($template, $name);

        return $template;
    }

    /**
     * @return list<Job> the jobs of the template $name, oldest first
     */
    private function jobsOf(string $name): array
    {
        /** @var list<Job> $jobs */
        $jobs = Job::find()->where(['job_template_id' => $this->template($name)->id])->orderBy(['id' => SORT_ASC])->all();

        return $jobs;
    }
}
