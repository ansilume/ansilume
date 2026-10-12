<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eSurveySecretSeeder;
use app\components\SurveySecrets;
use app\models\AuditLog;
use app\models\Inventory;
use app\models\Job;
use app\models\JobLog;
use app\models\JobTask;
use app\models\JobTemplate;
use app\models\Project;
use app\models\Runner;
use app\models\RunnerGroup;
use app\models\Schedule;
use app\models\TeamProject;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\tests\integration\DbTestCase;

/**
 * The fixtures of the password survey answer specs (E2eSurveySecretSeeder):
 * jobs launched with an answer and claimed on the server, by a runner that
 * takes the answers as a file and by one that gets them inline. The test
 * database holds no "e2e-" rows outside a test; the rollback removes them.
 */
class E2eSurveySecretSeederTest extends DbTestCase
{
    /** The public extra vars of the seeded jobs, as their rows and commands show them. */
    private const SHOWN_EXTRA_VARS = '{"app_env":"staging","db_password":"$encrypted$"}';

    private int $adminId;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->createUser('admin');
        $admin->is_superadmin = true;
        $admin->save(false);
        $this->adminId = (int)$admin->id;
        $this->log = [];
    }

    public function testTheJobOfTheCurrentRunnerKeepsRunningAndItsCommandShowsThePlaceholder(): void
    {
        $this->seed();

        $job = $this->job(Job::STATUS_RUNNING);
        $this->assertSame(E2eSurveySecretSeeder::CURRENT_RUNNER, $job->runner?->name);
        $command = (string)$job->execution_command;
        $this->assertStringStartsWith('ansible-playbook ', $command);
        $this->assertStringContainsString(' -vvvv ', $command);
        $this->assertStringEndsWith('--extra-vars ' . self::SHOWN_EXTRA_VARS, $command);
    }

    public function testTheJobOfTheLegacyRunnerRanPinnedAtVerbosityThreeAndItsLogEndsWithTheNote(): void
    {
        $this->seed();

        $job = $this->job(Job::STATUS_SUCCEEDED);
        $this->assertSame(E2eSurveySecretSeeder::LEGACY_RUNNER, $job->runner?->name);
        $command = (string)$job->execution_command;
        $this->assertStringStartsWith('/usr/bin/env ANSIBLE_VERBOSITY=0 ansible-playbook ', $command);
        $this->assertStringContainsString(' -vvv ', $command);
        $this->assertStringNotContainsString('-vvvv', $command);
        $this->assertStringEndsWith('--extra-vars ' . self::SHOWN_EXTRA_VARS, $command);
        $this->assertSame(0, $job->exit_code);

        /** @var list<JobLog> $logs */
        $logs = $job->getLogs()->all();
        $this->assertCount(2, $logs);
        $this->assertStringContainsString('<!--<script> e2e: </script><script>window.__e2eLogXss = 1</script>', $logs[0]->content);
        $this->assertSame('stderr', $logs[1]->stream);
        $this->assertSame(
            '[ansilume] Verbosity was lowered from 4 to 3: this runner does not support encrypted survey answers '
            . 'and would print them at higher verbosity. Update the runner image.',
            $logs[1]->content
        );
        $this->assertSame([E2eSurveySecretSeeder::LEGACY_TASK], JobTask::find()->select('task_name')->where(['job_id' => $job->id])->column());
    }

    public function testTheAnswerIsStoredOnlyEncrypted(): void
    {
        $this->seed();

        foreach ([Job::STATUS_RUNNING, Job::STATUS_SUCCEEDED] as $status) {
            $job = $this->job($status);
            $this->assertSame(self::SHOWN_EXTRA_VARS, $job->extra_vars, $status);
            $this->assertSame(
                [E2eSurveySecretSeeder::PASSWORD_FIELD => E2eSurveySecretSeeder::JOB_ANSWER],
                SurveySecrets::open($job->getSealedSecretExtraVars()),
                $status
            );
            $stored = $job->extra_vars . $job->runner_payload . $job->execution_command
                . implode('', JobLog::find()->select('content')->where(['job_id' => $job->id])->column());
            $this->assertStringNotContainsString(E2eSurveySecretSeeder::JOB_ANSWER, $stored, $status);
        }
    }

    public function testTheClaimsRecordWhetherTheRunnerTakesTheAnswersAsAFile(): void
    {
        $this->seed();

        $this->assertSame(
            ['runner_supports_secret_extra_vars' => true],
            array_intersect_key($this->startedAudit($this->job(Job::STATUS_RUNNING)), ['runner_supports_secret_extra_vars' => 0, 'verbosity_capped_from' => 0])
        );
        $this->assertSame(
            ['runner_supports_secret_extra_vars' => false, 'verbosity_capped_from' => 4],
            array_intersect_key($this->startedAudit($this->job(Job::STATUS_SUCCEEDED)), ['runner_supports_secret_extra_vars' => 0, 'verbosity_capped_from' => 0])
        );
    }

    public function testEveryRoleSeesTheTemplateAndNoRealRunnerServesItsGroup(): void
    {
        $this->seed();

        $template = $this->template();
        $project = Project::findOne((int)$template->project_id);
        $this->assertInstanceOf(Project::class, $project);
        $this->assertSame(E2eSurveySecretSeeder::PROJECT, $project->name);
        $this->assertFalse(TeamProject::find()->where(['project_id' => $project->id])->exists(), 'a project without team is open to every role');
        $inventory = Inventory::findOne((int)$template->inventory_id);
        $this->assertInstanceOf(Inventory::class, $inventory);
        $this->assertSame([E2eSurveySecretSeeder::INVENTORY, Inventory::TYPE_STATIC], [$inventory->name, $inventory->inventory_type]);

        $group = RunnerGroup::findOne((int)$template->runner_group_id);
        $this->assertInstanceOf(RunnerGroup::class, $group);
        $this->assertSame(E2eSurveySecretSeeder::GROUP, $group->name);
        $runners = Runner::find()->where(['runner_group_id' => $group->id])->orderBy('name')->all();
        $this->assertSame(
            [
                [E2eSurveySecretSeeder::CURRENT_RUNNER, 'vault_password_source,secret_extra_vars'],
                [E2eSurveySecretSeeder::LEGACY_RUNNER, 'vault_password_source'],
            ],
            array_map(static fn (Runner $runner): array => [$runner->name, $runner->capabilities], $runners)
        );
        $this->assertSame([E2eSurveySecretSeeder::PASSWORD_FIELD], $template->passwordFieldNames());
        $this->assertSame(E2eSurveySecretSeeder::VERBOSITY, (int)$template->verbosity);
    }

    public function testTheWorkflowHasOneJobStepOfTheTemplate(): void
    {
        $this->seed();

        $workflow = WorkflowTemplate::findOne(['name' => E2eSurveySecretSeeder::WORKFLOW]);
        $this->assertInstanceOf(WorkflowTemplate::class, $workflow);
        $steps = WorkflowStep::find()->where(['workflow_template_id' => $workflow->id])->all();
        $this->assertCount(1, $steps);
        $this->assertSame(WorkflowStep::TYPE_JOB, $steps[0]->step_type);
        $this->assertSame((int)$this->template()->id, (int)$steps[0]->job_template_id);
        $this->assertFalse($workflow->hasTriggerToken());
    }

    public function testTheScheduleIsDisabledAndStoresItsAnswerEncrypted(): void
    {
        $this->seed();

        $schedule = Schedule::findOne(['name' => E2eSurveySecretSeeder::SCHEDULE]);
        $this->assertInstanceOf(Schedule::class, $schedule);
        $this->assertFalse((bool)$schedule->enabled);
        $this->assertSame((int)$this->template()->id, (int)$schedule->job_template_id);
        $this->assertSame(
            ['app_env' => 'prod', 'db_password' => SurveySecrets::PLACEHOLDER],
            json_decode((string)$schedule->extra_vars, true)
        );
        $this->assertSame(
            [E2eSurveySecretSeeder::PASSWORD_FIELD => E2eSurveySecretSeeder::SCHEDULE_ANSWER],
            SurveySecrets::open($schedule->getSealedSecretExtraVars())
        );
    }

    public function testSeedingAgainRestoresEveryFixture(): void
    {
        $this->seed();
        $template = $this->template();
        $oldJobIds = array_map('intval', Job::find()->select('id')->where(['job_template_id' => $template->id])->column());
        // What the specs and a broken run leave behind.
        $queued = $this->createJob((int)$template->id, $this->adminId);
        $template->generateTriggerToken($this->adminId);
        $template->updateAttributes(['verbosity' => 0, 'survey_fields' => '[]']);
        $workflow = WorkflowTemplate::findOne(['name' => E2eSurveySecretSeeder::WORKFLOW]);
        $this->assertInstanceOf(WorkflowTemplate::class, $workflow);
        $workflow->generateTriggerToken($this->adminId);
        $this->createWorkflowStep((int)$workflow->id, 20, WorkflowStep::TYPE_PAUSE);
        $run = new WorkflowJob();
        $run->workflow_template_id = (int)$workflow->id;
        $run->launched_by = $this->adminId;
        $run->status = WorkflowJob::STATUS_RUNNING;
        $run->save(false);
        Runner::updateAll(['capabilities' => null], ['name' => E2eSurveySecretSeeder::CURRENT_RUNNER]);
        Schedule::updateAll(['enabled' => 1, 'extra_vars' => null], ['name' => E2eSurveySecretSeeder::SCHEDULE]);

        $this->seed();

        $restored = $this->template();
        $this->assertSame((int)$template->id, (int)$restored->id);
        $this->assertSame(1, (int)JobTemplate::findWithDeleted()->where(['name' => E2eSurveySecretSeeder::TEMPLATE])->count());
        $this->assertFalse($restored->hasTriggerToken());
        $this->assertSame([E2eSurveySecretSeeder::PASSWORD_FIELD], $restored->passwordFieldNames());
        $jobs = Job::find()->where(['job_template_id' => $restored->id])->all();
        $this->assertSame([Job::STATUS_RUNNING, Job::STATUS_SUCCEEDED], array_map(static fn (Job $job): string => $job->status, $jobs));
        $this->assertSame([], array_intersect($oldJobIds, array_map(static fn (Job $job): int => (int)$job->id, $jobs)));
        $this->assertNull(Job::findOne($queued->id));
        $this->assertFalse(JobLog::find()->where(['job_id' => $oldJobIds])->exists());
        $this->assertFalse(JobTask::find()->where(['job_id' => $oldJobIds])->exists());

        $workflow->refresh();
        $this->assertFalse($workflow->hasTriggerToken());
        $this->assertSame(1, (int)WorkflowStep::find()->where(['workflow_template_id' => $workflow->id])->count());
        $this->assertNull(WorkflowJob::findOne($run->id));
        $this->assertSame(1, (int)WorkflowTemplate::findWithDeleted()->where(['name' => E2eSurveySecretSeeder::WORKFLOW])->count());

        $this->assertSame(1, (int)RunnerGroup::find()->where(['name' => E2eSurveySecretSeeder::GROUP])->count());
        $this->assertSame(2, (int)Runner::find()->where(['name' => [E2eSurveySecretSeeder::CURRENT_RUNNER, E2eSurveySecretSeeder::LEGACY_RUNNER]])->count());
        $this->assertSame('vault_password_source,secret_extra_vars', Runner::findOne(['name' => E2eSurveySecretSeeder::CURRENT_RUNNER])?->capabilities);

        $schedule = Schedule::findOne(['name' => E2eSurveySecretSeeder::SCHEDULE]);
        $this->assertInstanceOf(Schedule::class, $schedule);
        $this->assertFalse((bool)$schedule->enabled);
        $this->assertSame(
            [E2eSurveySecretSeeder::PASSWORD_FIELD => E2eSurveySecretSeeder::SCHEDULE_ANSWER],
            SurveySecrets::open($schedule->getSealedSecretExtraVars())
        );
    }

    public function testASoftDeletedTemplateIsRestoredNotDuplicated(): void
    {
        $this->seed();
        $template = $this->template();
        $template->softDelete();

        $this->seed();

        $this->assertSame((int)$template->id, (int)$this->template()->id);
        $this->assertSame(1, (int)JobTemplate::findWithDeleted()->where(['name' => E2eSurveySecretSeeder::TEMPLATE])->count());
    }

    public function testTheSeedStopsWhenTheClaimWouldTakeAJobItDidNotLaunch(): void
    {
        // A queued job of another template in the group is older than the
        // seeded one, so the claim takes it: the fixtures would be wrong.
        $group = $this->createRunnerGroup($this->adminId);
        $group->name = E2eSurveySecretSeeder::GROUP;
        $group->save(false);
        $other = $this->createJobTemplate(
            (int)$this->createProject($this->adminId)->id,
            (int)$this->createInventory($this->adminId)->id,
            (int)$group->id,
            $this->adminId
        );
        $foreign = $this->createJob((int)$other->id, $this->adminId);

        try {
            $this->seed();
            $this->fail('the seed went on with a claim of another job');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression(
                '/^E2E survey secret fixtures: ' . E2eSurveySecretSeeder::CURRENT_RUNNER . ' did not claim job #\d+; '
                . 'remove the other queued jobs of ' . E2eSurveySecretSeeder::GROUP . '\.$/',
                $e->getMessage()
            );
        }
        $foreign->refresh();
        $this->assertSame(Job::STATUS_RUNNING, $foreign->status, 'the claim took the older job');
        $this->assertSame(E2eSurveySecretSeeder::CURRENT_RUNNER, $foreign->runner?->name);
    }

    public function testWithoutAUsableSecretKeyTheSeedStopsWithTheReasonAndLaunchesNothing(): void
    {
        $saved = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = 'short';
        try {
            $this->seed();
            $this->fail('the seed went on without a usable APP_SECRET_KEY');
        } catch (\RuntimeException $e) {
            $this->assertSame(
                'E2E survey secret fixtures: the schedule answer was not stored: '
                . 'The password answers cannot be encrypted. Check APP_SECRET_KEY.',
                $e->getMessage()
            );
        } finally {
            if ($saved === null) {
                unset($_ENV['APP_SECRET_KEY']);
            } else {
                $_ENV['APP_SECRET_KEY'] = $saved;
            }
        }
        $this->assertFalse(Job::find()->where(['job_template_id' => $this->template()->id])->exists());
        $this->assertSame([], $this->log);
    }

    public function testTheSeedLogNamesNoAnswer(): void
    {
        $this->seed();

        $this->assertCount(1, $this->log);
        $this->assertStringStartsWith("  Restored survey secret fixtures: template 'e2e-secret-template'", $this->log[0]);
        $this->assertStringNotContainsString(E2eSurveySecretSeeder::JOB_ANSWER, $this->log[0]);
        $this->assertStringNotContainsString(E2eSurveySecretSeeder::SCHEDULE_ANSWER, $this->log[0]);
    }

    private function seed(): void
    {
        (new E2eSurveySecretSeeder(function (string $msg): void {
            $this->log[] = $msg;
        }))->seed($this->adminId);
    }

    private function template(): JobTemplate
    {
        $template = JobTemplate::findOne(['name' => E2eSurveySecretSeeder::TEMPLATE]);
        $this->assertInstanceOf(JobTemplate::class, $template);

        return $template;
    }

    /**
     * The only job of the template in $status.
     */
    private function job(string $status): Job
    {
        $jobs = Job::find()->where(['job_template_id' => $this->template()->id, 'status' => $status])->all();
        $this->assertCount(1, $jobs, "one {$status} job");

        return $jobs[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function startedAudit(Job $job): array
    {
        $entry = AuditLog::find()->where(['action' => AuditLog::ACTION_JOB_STARTED, 'object_type' => 'job', 'object_id' => $job->id])->one();
        $this->assertInstanceOf(AuditLog::class, $entry);
        $context = json_decode((string)$entry->metadata, true);
        $this->assertIsArray($context);

        return $context;
    }
}
