<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\models\Job;
use app\services\JobLaunchService;
use app\tests\integration\DbTestCase;

class JobLaunchServiceTest extends DbTestCase
{
    private JobLaunchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \Yii::$app->get('jobLaunchService');
    }

    // -------------------------------------------------------------------------
    // launch() — happy path
    // -------------------------------------------------------------------------

    public function testLaunchCreatesJobRecord(): void
    {
        [$template, $user] = $this->makeFixtures();

        $before = (int)Job::find()->count();
        $this->service->launch($template, $user->id);
        $this->assertSame($before + 1, (int)Job::find()->count());
    }

    public function testLaunchReturnsJobWithQueuedStatus(): void
    {
        [$template, $user] = $this->makeFixtures();

        $job = $this->service->launch($template, $user->id);

        $this->assertSame(Job::STATUS_QUEUED, $job->status);
        $this->assertNotNull($job->queued_at);
    }

    public function testLaunchPersistsCorrectTemplateAndUser(): void
    {
        [$template, $user] = $this->makeFixtures();

        $job = $this->service->launch($template, $user->id);

        $this->assertSame($template->id, $job->job_template_id);
        $this->assertSame($user->id, $job->launched_by);
    }

    public function testLaunchSnapshotsRunnerPayloadAsJson(): void
    {
        [$template, $user] = $this->makeFixtures();

        $job = $this->service->launch($template, $user->id);

        $this->assertNotNull($job->runner_payload);
        $payload = json_decode($job->runner_payload, true);
        $this->assertIsArray($payload);
        $this->assertSame($template->id, $payload['template_id']);
        $this->assertSame($template->playbook, $payload['playbook']);
        $this->assertSame(120, $payload['timeout_minutes']);
    }

    public function testLaunchCreatesAuditLogEntry(): void
    {
        [$template, $user] = $this->makeFixtures();

        $before = (int)AuditLog::find()->count();
        $this->service->launch($template, $user->id);
        $this->assertGreaterThan($before, (int)AuditLog::find()->count());
    }

    public function testLaunchWithLimitOverrideStoresLimit(): void
    {
        [$template, $user] = $this->makeFixtures();

        $job = $this->service->launch($template, $user->id, ['limit' => 'webservers']);

        $this->assertSame('webservers', $job->limit);
    }

    public function testLaunchWithVerbosityOverrideStoresVerbosity(): void
    {
        [$template, $user] = $this->makeFixtures();

        $job = $this->service->launch($template, $user->id, ['verbosity' => 3]);

        $this->assertSame(3, $job->verbosity);
    }

    public function testLaunchWithExtraVarsOverrideMergesIntoPayload(): void
    {
        [$template, $user] = $this->makeFixtures();
        $template->extra_vars = '{"env":"staging"}';

        $job = $this->service->launch($template, $user->id, [
            'extra_vars' => '{"env":"production","version":"2.0"}',
        ]);

        $this->assertNotNull($job->extra_vars);
        $vars = json_decode($job->extra_vars, true);
        $this->assertSame('production', $vars['env']);
        $this->assertSame('2.0', $vars['version']);
    }

    public function testLaunchWithSurveyOverrideMergesIntoPayload(): void
    {
        [$template, $user] = $this->makeFixtures();
        $template->extra_vars = '{"color":"blue"}';

        $job = $this->service->launch($template, $user->id, [
            'survey' => ['color' => 'red', 'size' => 'large'],
        ]);

        $vars = json_decode($job->extra_vars, true);
        $this->assertSame('red', $vars['color']);
        $this->assertSame('large', $vars['size']);
    }

    public function testLaunchWithCheckModeOverride(): void
    {
        [$template, $user] = $this->makeFixtures();

        $job = $this->service->launch($template, $user->id, ['check_mode' => 1]);

        $this->assertSame(1, (int)$job->check_mode);
        $payload = json_decode($job->runner_payload, true);
        $this->assertTrue($payload['check_mode']);
    }

    public function testLaunchWithoutCheckModeDefaultsToZero(): void
    {
        [$template, $user] = $this->makeFixtures();

        $job = $this->service->launch($template, $user->id);

        $this->assertSame(0, (int)$job->check_mode);
        $payload = json_decode($job->runner_payload, true);
        $this->assertFalse($payload['check_mode']);
    }

    public function testLaunchSnapshotsTimeoutMinutesFromTemplate(): void
    {
        [$template, $user] = $this->makeFixtures();
        $template->timeout_minutes = 45;

        $job = $this->service->launch($template, $user->id);

        $this->assertSame(45, $job->timeout_minutes);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeFixtures(): array
    {
        $user         = $this->createUser();
        $runnerGroup  = $this->createRunnerGroup($user->id);
        $project      = $this->createProject($user->id);
        $inventory    = $this->createInventory($user->id);
        $template     = $this->createJobTemplate(
            $project->id,
            $inventory->id,
            $runnerGroup->id,
            $user->id
        );
        return [$template, $user];
    }
}
