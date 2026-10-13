<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eSoftDeletedTemplateSeeder;
use app\commands\E2eTeardownHelper;
use app\models\Job;
use app\models\JobLog;
use app\models\JobTemplate;
use app\models\Project;
use app\services\ProjectDeletionService;
use app\tests\integration\DbTestCase;
use yii\db\Query;

/**
 * The fixture of the E2E spec that deletes a project whose only job
 * template is soft-deleted (E2eSoftDeletedTemplateSeeder,
 * tests/e2e/tests/projects/crud.spec.ts). Right before the delete, the spec
 * gives the template a queued job through /e2e/create-cancelable-job, which
 * the delete must cancel. The spec finds both by name; the test database
 * holds no "e2e-" rows outside a test, and the rollback removes them.
 */
class E2eSoftDeletedTemplateSeederTest extends DbTestCase
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

    /**
     * The project's only template is soft-deleted, so nothing blocks the
     * project's delete. A second seed without jobs recreates both.
     */
    public function testTheProjectsOnlyTemplateIsSoftDeleted(): void
    {
        $this->seed();
        $this->seed();

        $project = $this->project();
        $template = $this->template();
        $this->assertSame((int)$project->id, (int)$template->project_id);
        $this->assertNotNull($template->deleted_at);
        $this->assertSame([$this->inventoryId, $this->runnerGroupId], [(int)$template->inventory_id, (int)$template->runner_group_id]);
        $this->assertSame(0, (new ProjectDeletionService())->blockingTemplateCount($project));
        $this->assertSame(1, (int)Project::find()->where(['name' => E2eSoftDeletedTemplateSeeder::PROJECT_NAME])->count());
        $this->assertSame(1, (int)JobTemplate::findWithDeleted()->where(['name' => E2eSoftDeletedTemplateSeeder::TEMPLATE_NAME])->count());
        $this->assertSame(
            "  Created project e2e-softdel-project (ID {$project->id}) with soft-deleted template.\n",
            $this->log[1]
        );
    }

    /**
     * Regression: a job that the spec gave the template stayed behind when
     * the next seed deleted the template, queued without a template. Every
     * seed now removes the template's jobs first, with their tasks and logs.
     */
    public function testSeedingAgainRemovesTheJobsOfTheTemplate(): void
    {
        $this->seed();
        $job = $this->createJob((int)$this->template()->id, $this->adminId);
        $db = \Yii::$app->db;
        $db->createCommand()->insert('{{%job_task}}', [
            'job_id' => $job->id,
            'sequence' => 1,
            'task_name' => 'e2e task',
            'task_action' => 'debug',
            'host' => 'localhost',
            'status' => 'ok',
            'changed' => 0,
            'duration_ms' => 1,
            'created_at' => time(),
        ])->execute();
        $log = new JobLog();
        $log->job_id = (int)$job->id;
        $log->stream = JobLog::STREAM_STDOUT;
        $log->content = "e2e\n";
        $log->sequence = 1;
        $log->created_at = time();
        $log->save(false);

        $this->seed();

        $this->assertNull(Job::findOne($job->id));
        $this->assertSame(0, (int)(new Query())->from('{{%job_task}}')->where(['job_id' => $job->id])->count('*', $db));
        $this->assertSame(0, (int)JobLog::find()->where(['job_id' => $job->id])->count());
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $this->template()->id])->count());
    }

    /**
     * What the spec does: a queued job of the deleted template, as the hook
     * makes it, is canceled by the project's delete, which purges the
     * template.
     */
    public function testTheProjectsDeleteCancelsAQueuedJobOfTheTemplate(): void
    {
        $this->seed();
        $template = $this->template();
        $job = $this->createJob((int)$template->id, (int)$template->created_by);

        (new ProjectDeletionService())->delete($this->project());

        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $this->assertNull($job->job_template_id, 'the template was purged');
        $this->assertNull(Project::findOne(['name' => E2eSoftDeletedTemplateSeeder::PROJECT_NAME]));
    }

    /**
     * yii e2e/teardown removes the project, the template and the job the
     * spec made, also when the spec stopped before the delete.
     */
    public function testTheTeardownRemovesTheProjectTheTemplateAndItsJob(): void
    {
        $this->seed();
        $job = $this->createJob((int)$this->template()->id, $this->adminId);

        $problems = (new E2eTeardownHelper('e2e-', static function (string $msg): void {
        }))->teardownDatabase();

        $this->assertSame([], $problems);
        $this->assertNull(Project::findOne(['name' => E2eSoftDeletedTemplateSeeder::PROJECT_NAME]));
        $this->assertSame(0, (int)JobTemplate::findWithDeleted()->where(['name' => E2eSoftDeletedTemplateSeeder::TEMPLATE_NAME])->count());
        $this->assertNull(Job::findOne($job->id));
    }

    private function seed(): void
    {
        (new E2eSoftDeletedTemplateSeeder(function (string $msg): void {
            $this->log[] = $msg;
        }))->seed($this->adminId, $this->inventoryId, $this->runnerGroupId);
    }

    private function project(): Project
    {
        $project = Project::findOne(['name' => E2eSoftDeletedTemplateSeeder::PROJECT_NAME]);
        $this->assertInstanceOf(Project::class, $project);

        return $project;
    }

    private function template(): JobTemplate
    {
        $template = JobTemplate::findWithDeleted()->where(['name' => E2eSoftDeletedTemplateSeeder::TEMPLATE_NAME])->one();
        $this->assertInstanceOf(JobTemplate::class, $template);

        return $template;
    }
}
