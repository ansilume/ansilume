<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\Job;
use app\models\JobArtifact;
use app\models\JobHostSummary;
use app\models\JobLog;
use app\models\JobTemplate;
use app\models\Runner;
use app\models\User;
use app\tests\integration\DbTestCase;

class JobTest extends DbTestCase
{
    public function testTableName(): void
    {
        $this->assertSame('{{%job}}', Job::tableName());
    }

    public function testPersistAndRetrieve(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);
        $job = $this->createJob((int)$tpl->id, $user->id);

        $this->assertNotNull($job->id);
        $reloaded = Job::findOne($job->id);
        $this->assertNotNull($reloaded);
        $this->assertSame(Job::STATUS_QUEUED, $reloaded->status);
        $this->assertSame((int)$tpl->id, (int)$reloaded->job_template_id);
        $this->assertSame($user->id, (int)$reloaded->launched_by);
    }

    public function testStatuses(): void
    {
        $statuses = Job::statuses();
        $this->assertCount(9, $statuses);
        $this->assertContains(Job::STATUS_PENDING, $statuses);
        $this->assertContains(Job::STATUS_QUEUED, $statuses);
        $this->assertContains(Job::STATUS_RUNNING, $statuses);
        $this->assertContains(Job::STATUS_SUCCEEDED, $statuses);
        $this->assertContains(Job::STATUS_FAILED, $statuses);
        $this->assertContains(Job::STATUS_CANCELED, $statuses);
        $this->assertContains(Job::STATUS_TIMED_OUT, $statuses);
        $this->assertContains(Job::STATUS_PENDING_APPROVAL, $statuses);
        $this->assertContains(Job::STATUS_REJECTED, $statuses);
    }

    public function testValidateJsonValid(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);

        $job = new Job();
        $job->job_template_id = $tpl->id;
        $job->launched_by = $user->id;
        $job->status = Job::STATUS_PENDING;
        $job->extra_vars = '{"key":"val"}';
        $job->has_changes = 0;
        $this->assertTrue($job->validate(['extra_vars']));
        $this->assertEmpty($job->getErrors('extra_vars'));
    }

    public function testValidateJsonInvalid(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);

        $job = new Job();
        $job->job_template_id = $tpl->id;
        $job->launched_by = $user->id;
        $job->status = Job::STATUS_PENDING;
        $job->extra_vars = 'not-json';
        $job->has_changes = 0;
        $this->assertFalse($job->validate(['extra_vars']));
        $this->assertArrayHasKey('extra_vars', $job->errors);
    }

    /**
     * @dataProvider terminalStatusProvider
     */
    public function testIsFinishedForTerminal(string $status): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);
        $job = $this->createJob((int)$tpl->id, $user->id, $status);

        $this->assertTrue($job->isFinished());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function terminalStatusProvider(): array
    {
        return [
            'succeeded' => [Job::STATUS_SUCCEEDED],
            'failed' => [Job::STATUS_FAILED],
            'canceled' => [Job::STATUS_CANCELED],
            'timed_out' => [Job::STATUS_TIMED_OUT],
            'rejected' => [Job::STATUS_REJECTED],
        ];
    }

    /**
     * @dataProvider nonTerminalStatusProvider
     */
    public function testIsFinishedForNonTerminal(string $status): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);
        $job = $this->createJob((int)$tpl->id, $user->id, $status);

        $this->assertFalse($job->isFinished());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonTerminalStatusProvider(): array
    {
        return [
            'pending' => [Job::STATUS_PENDING],
            'queued' => [Job::STATUS_QUEUED],
            'running' => [Job::STATUS_RUNNING],
            'pending_approval' => [Job::STATUS_PENDING_APPROVAL],
        ];
    }

    public function testIsRunningTrue(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);
        $job = $this->createJob((int)$tpl->id, $user->id, Job::STATUS_RUNNING);

        $this->assertTrue($job->isRunning());
    }

    public function testIsRunningFalse(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);
        $job = $this->createJob((int)$tpl->id, $user->id, Job::STATUS_PENDING);

        $this->assertFalse($job->isRunning());
    }

    /**
     * @dataProvider cancelableStatusProvider
     */
    public function testIsCancelableForCancelable(string $status): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);
        $job = $this->createJob((int)$tpl->id, $user->id, $status);

        $this->assertTrue($job->isCancelable());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function cancelableStatusProvider(): array
    {
        return [
            'pending' => [Job::STATUS_PENDING],
            'queued' => [Job::STATUS_QUEUED],
            'running' => [Job::STATUS_RUNNING],
            'pending_approval' => [Job::STATUS_PENDING_APPROVAL],
        ];
    }

    /**
     * @dataProvider nonCancelableStatusProvider
     */
    public function testIsCancelableForNonCancelable(string $status): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);
        $job = $this->createJob((int)$tpl->id, $user->id, $status);

        $this->assertFalse($job->isCancelable());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonCancelableStatusProvider(): array
    {
        return [
            'succeeded' => [Job::STATUS_SUCCEEDED],
            'failed' => [Job::STATUS_FAILED],
        ];
    }

    public function testStatusLabelForAllStatuses(): void
    {
        $expected = [
            Job::STATUS_PENDING => 'Pending',
            Job::STATUS_QUEUED => 'Queued',
            Job::STATUS_RUNNING => 'Running',
            Job::STATUS_SUCCEEDED => 'Succeeded',
            Job::STATUS_FAILED => 'Failed',
            Job::STATUS_CANCELED => 'Canceled',
            Job::STATUS_TIMED_OUT => 'Timed Out',
            Job::STATUS_PENDING_APPROVAL => 'Awaiting Approval',
            Job::STATUS_REJECTED => 'Rejected',
        ];

        foreach ($expected as $status => $label) {
            $this->assertSame($label, Job::statusLabel($status), "statusLabel for {$status}");
        }

        // unknown default
        $this->assertSame('unknown_xyz', Job::statusLabel('unknown_xyz'));
    }

    public function testStatusCssClassForAllStatuses(): void
    {
        $expected = [
            Job::STATUS_PENDING => 'secondary',
            Job::STATUS_QUEUED => 'secondary',
            Job::STATUS_RUNNING => 'primary',
            Job::STATUS_SUCCEEDED => 'success',
            Job::STATUS_FAILED => 'danger',
            Job::STATUS_CANCELED => 'warning',
            Job::STATUS_TIMED_OUT => 'danger',
            Job::STATUS_PENDING_APPROVAL => 'info',
            Job::STATUS_REJECTED => 'danger',
        ];

        foreach ($expected as $status => $cssClass) {
            $this->assertSame($cssClass, Job::statusCssClass($status), "statusCssClass for {$status}");
        }

        // unknown default
        $this->assertSame('secondary', Job::statusCssClass('unknown_xyz'));
    }

    public function testJobTemplateRelation(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);
        $job = $this->createJob((int)$tpl->id, $user->id);

        $reloaded = Job::findOne($job->id);
        $this->assertNotNull($reloaded);
        $this->assertInstanceOf(JobTemplate::class, $reloaded->jobTemplate);
        $this->assertSame((int)$tpl->id, (int)$reloaded->jobTemplate->id);
    }

    public function testLauncherRelation(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);
        $job = $this->createJob((int)$tpl->id, $user->id);

        $reloaded = Job::findOne($job->id);
        $this->assertNotNull($reloaded);
        $this->assertInstanceOf(User::class, $reloaded->launcher);
        $this->assertSame($user->id, $reloaded->launcher->id);
    }

    public function testRunnerRelation(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);
        $runner = $this->createRunner((int)$group->id, $user->id);
        $job = $this->createJob((int)$tpl->id, $user->id);
        $job->runner_id = $runner->id;
        $job->save(false);

        $reloaded = Job::findOne($job->id);
        $this->assertNotNull($reloaded);
        $this->assertInstanceOf(Runner::class, $reloaded->runner);
        $this->assertSame((int)$runner->id, (int)$reloaded->runner->id);
    }

    public function testHostSummariesRelation(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);
        $job = $this->createJob((int)$tpl->id, $user->id);

        $reloaded = Job::findOne($job->id);
        $this->assertNotNull($reloaded);
        $this->assertIsArray($reloaded->hostSummaries);
        $this->assertCount(0, $reloaded->hostSummaries);
    }

    public function testLogsRelation(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);
        $job = $this->createJob((int)$tpl->id, $user->id);

        $reloaded = Job::findOne($job->id);
        $this->assertNotNull($reloaded);
        $this->assertIsArray($reloaded->logs);
        $this->assertCount(0, $reloaded->logs);
    }

    public function testArtifactsRelation(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);
        $job = $this->createJob((int)$tpl->id, $user->id);

        $reloaded = Job::findOne($job->id);
        $this->assertNotNull($reloaded);
        $this->assertIsArray($reloaded->artifacts);
        $this->assertCount(0, $reloaded->artifacts);
    }
}
