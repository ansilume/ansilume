<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Job;
use app\models\JobHostSummary;
use app\services\JobCompletionService;
use app\tests\integration\DbTestCase;

/**
 * End-to-end integration tests for the PLAY RECAP flow.
 *
 * Verifies that after tasks are saved via JobCompletionService::saveTasks(),
 * host summaries are correctly created, the Job relation returns them,
 * and aggregate() produces the expected recap values — matching what the
 * job view would render in the "PLAY RECAP" card.
 */
class JobRecapFlowTest extends DbTestCase
{
    private JobCompletionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \Yii::$app->get('jobCompletionService');
    }

    // -------------------------------------------------------------------------
    // Multi-host realistic scenario
    // -------------------------------------------------------------------------

    public function testRecapCreatedForMultiHostMultiTaskJob(): void
    {
        $job = $this->makeRunningJob();

        // Simulate a typical Ansible run across 3 hosts
        $this->service->saveTasks($job, [
            // web1: 3 ok (1 changed), 1 skipped
            ['seq' => 1, 'name' => 'Gather facts',   'action' => 'setup',   'host' => 'web1', 'status' => 'ok',      'changed' => false, 'duration_ms' => 500],
            ['seq' => 2, 'name' => 'Install nginx',   'action' => 'apt',     'host' => 'web1', 'status' => 'ok',      'changed' => true,  'duration_ms' => 3200],
            ['seq' => 3, 'name' => 'Start nginx',     'action' => 'service', 'host' => 'web1', 'status' => 'ok',      'changed' => false, 'duration_ms' => 120],
            ['seq' => 4, 'name' => 'Config SELinux',   'action' => 'seboolean','host' => 'web1', 'status' => 'skipped', 'changed' => false, 'duration_ms' => 0],
            // web2: 2 ok, 1 changed, 1 failed
            ['seq' => 5, 'name' => 'Gather facts',   'action' => 'setup',   'host' => 'web2', 'status' => 'ok',      'changed' => false, 'duration_ms' => 480],
            ['seq' => 6, 'name' => 'Install nginx',   'action' => 'apt',     'host' => 'web2', 'status' => 'ok',      'changed' => true,  'duration_ms' => 3100],
            ['seq' => 7, 'name' => 'Start nginx',     'action' => 'service', 'host' => 'web2', 'status' => 'failed',  'changed' => false, 'duration_ms' => 80],
            // db1: unreachable
            ['seq' => 8, 'name' => 'Gather facts',   'action' => 'setup',   'host' => 'db1',  'status' => 'unreachable', 'changed' => false, 'duration_ms' => 10000],
        ]);

        // Verify host summaries exist
        $summaries = JobHostSummary::find()
            ->where(['job_id' => $job->id])
            ->indexBy('host')
            ->all();

        $this->assertCount(3, $summaries, 'Expected 3 host summaries');
        $this->assertArrayHasKey('web1', $summaries);
        $this->assertArrayHasKey('web2', $summaries);
        $this->assertArrayHasKey('db1', $summaries);
    }

    public function testRecapCountersAreCorrectPerHost(): void
    {
        $job = $this->makeRunningJob();

        $this->service->saveTasks($job, [
            // web1: 2 ok, 1 changed, 1 skipped
            ['seq' => 1, 'name' => 'Gather facts', 'action' => 'setup',    'host' => 'web1', 'status' => 'ok',      'changed' => false, 'duration_ms' => 500],
            ['seq' => 2, 'name' => 'Install pkg',  'action' => 'apt',      'host' => 'web1', 'status' => 'ok',      'changed' => true,  'duration_ms' => 3200],
            ['seq' => 3, 'name' => 'Start svc',    'action' => 'service',  'host' => 'web1', 'status' => 'ok',      'changed' => false, 'duration_ms' => 120],
            ['seq' => 4, 'name' => 'SELinux',      'action' => 'seboolean','host' => 'web1', 'status' => 'skipped', 'changed' => false, 'duration_ms' => 0],
            // web2: 1 ok, 1 changed, 1 failed
            ['seq' => 5, 'name' => 'Gather facts', 'action' => 'setup',   'host' => 'web2', 'status' => 'ok',      'changed' => false, 'duration_ms' => 480],
            ['seq' => 6, 'name' => 'Install pkg',  'action' => 'apt',     'host' => 'web2', 'status' => 'ok',      'changed' => true,  'duration_ms' => 3100],
            ['seq' => 7, 'name' => 'Start svc',    'action' => 'service', 'host' => 'web2', 'status' => 'failed',  'changed' => false, 'duration_ms' => 80],
        ]);

        $summaries = JobHostSummary::find()
            ->where(['job_id' => $job->id])
            ->indexBy('host')
            ->all();

        // web1: 2 ok (gather facts + start svc), 1 changed, 0 failed, 1 skipped
        $web1 = $summaries['web1'];
        $this->assertSame(2, (int)$web1->ok);
        $this->assertSame(1, (int)$web1->changed);
        $this->assertSame(0, (int)$web1->failed);
        $this->assertSame(1, (int)$web1->skipped);
        $this->assertSame(0, (int)$web1->unreachable);

        // web2: 1 ok (gather facts), 1 changed, 1 failed
        $web2 = $summaries['web2'];
        $this->assertSame(1, (int)$web2->ok);
        $this->assertSame(1, (int)$web2->changed);
        $this->assertSame(1, (int)$web2->failed);
        $this->assertSame(0, (int)$web2->skipped);
    }

    // -------------------------------------------------------------------------
    // Job relation returns summaries (as used by job view)
    // -------------------------------------------------------------------------

    public function testJobHostSummariesRelationReturnsSavedRecaps(): void
    {
        $job = $this->makeRunningJob();

        $this->service->saveTasks($job, [
            ['seq' => 1, 'name' => 'task1', 'action' => 'ping', 'host' => 'alpha', 'status' => 'ok', 'changed' => false, 'duration_ms' => 10],
            ['seq' => 2, 'name' => 'task2', 'action' => 'ping', 'host' => 'beta',  'status' => 'ok', 'changed' => false, 'duration_ms' => 10],
        ]);

        // Reload from DB to ensure relation works fresh
        $job->refresh();
        $hostSummaries = $job->getHostSummaries()->all();

        $this->assertCount(2, $hostSummaries);
        $hosts = array_map(fn($hs) => $hs->host, $hostSummaries);
        $this->assertContains('alpha', $hosts);
        $this->assertContains('beta', $hosts);
    }

    // -------------------------------------------------------------------------
    // Aggregate produces correct totals (as displayed in PLAY RECAP card)
    // -------------------------------------------------------------------------

    public function testAggregateMatchesExpectedRecapTotals(): void
    {
        $job = $this->makeRunningJob();

        $this->service->saveTasks($job, [
            // host-a: 3 ok, 2 changed
            ['seq' => 1, 'name' => 'a', 'action' => 'ping',    'host' => 'host-a', 'status' => 'ok', 'changed' => false, 'duration_ms' => 0],
            ['seq' => 2, 'name' => 'b', 'action' => 'copy',    'host' => 'host-a', 'status' => 'ok', 'changed' => true,  'duration_ms' => 0],
            ['seq' => 3, 'name' => 'c', 'action' => 'service', 'host' => 'host-a', 'status' => 'ok', 'changed' => true,  'duration_ms' => 0],
            ['seq' => 4, 'name' => 'd', 'action' => 'debug',   'host' => 'host-a', 'status' => 'ok', 'changed' => false, 'duration_ms' => 0],
            ['seq' => 5, 'name' => 'e', 'action' => 'debug',   'host' => 'host-a', 'status' => 'ok', 'changed' => false, 'duration_ms' => 0],
            // host-b: 1 ok, 1 failed, 1 rescued
            ['seq' => 6, 'name' => 'a', 'action' => 'ping',    'host' => 'host-b', 'status' => 'ok',      'changed' => false, 'duration_ms' => 0],
            ['seq' => 7, 'name' => 'b', 'action' => 'copy',    'host' => 'host-b', 'status' => 'failed',  'changed' => false, 'duration_ms' => 0],
            ['seq' => 8, 'name' => 'c', 'action' => 'debug',   'host' => 'host-b', 'status' => 'rescued', 'changed' => false, 'duration_ms' => 0],
        ]);

        $hostSummaries = $job->getHostSummaries()->all();
        $recap = JobHostSummary::aggregate($hostSummaries);

        $this->assertSame(2, $recap['hosts']);
        // host-a: 3 ok + host-b: 1 ok = 4
        $this->assertSame(4, $recap['ok']);
        // host-a: 2 changed
        $this->assertSame(2, $recap['changed']);
        // host-b: 1 failed
        $this->assertSame(1, $recap['failed']);
        // host-b: 1 rescued
        $this->assertSame(1, $recap['rescued']);
        $this->assertSame(0, $recap['skipped']);
        $this->assertSame(0, $recap['unreachable']);
    }

    // -------------------------------------------------------------------------
    // Incremental task batches (runner sends tasks in chunks)
    // -------------------------------------------------------------------------

    public function testRecapAccumulatesAcrossMultipleSaveTasksCalls(): void
    {
        $job = $this->makeRunningJob();

        // First batch of tasks
        $this->service->saveTasks($job, [
            ['seq' => 1, 'name' => 'Gather facts', 'action' => 'setup', 'host' => 'web1', 'status' => 'ok', 'changed' => false, 'duration_ms' => 500],
            ['seq' => 2, 'name' => 'Gather facts', 'action' => 'setup', 'host' => 'web2', 'status' => 'ok', 'changed' => false, 'duration_ms' => 480],
        ]);

        // Second batch of tasks
        $this->service->saveTasks($job, [
            ['seq' => 3, 'name' => 'Install pkg', 'action' => 'apt', 'host' => 'web1', 'status' => 'ok', 'changed' => true,  'duration_ms' => 3200],
            ['seq' => 4, 'name' => 'Install pkg', 'action' => 'apt', 'host' => 'web2', 'status' => 'ok', 'changed' => true,  'duration_ms' => 3100],
        ]);

        $summaries = JobHostSummary::find()
            ->where(['job_id' => $job->id])
            ->indexBy('host')
            ->all();

        // web1: 1 ok (gather facts) + 1 changed (install) = ok:1, changed:1
        $this->assertSame(1, (int)$summaries['web1']->ok);
        $this->assertSame(1, (int)$summaries['web1']->changed);

        // web2: 1 ok (gather facts) + 1 changed (install) = ok:1, changed:1
        $this->assertSame(1, (int)$summaries['web2']->ok);
        $this->assertSame(1, (int)$summaries['web2']->changed);

        // Aggregate should reflect cumulative totals
        $recap = JobHostSummary::aggregate(array_values($summaries));
        $this->assertSame(2, $recap['hosts']);
        $this->assertSame(2, $recap['ok']);
        $this->assertSame(2, $recap['changed']);
    }

    // -------------------------------------------------------------------------
    // Empty recap (no tasks = no PLAY RECAP card)
    // -------------------------------------------------------------------------

    public function testNoRecapWhenNoTasksSaved(): void
    {
        $job = $this->makeRunningJob();

        $this->service->saveTasks($job, []);

        $hostSummaries = $job->getHostSummaries()->all();
        $this->assertEmpty($hostSummaries, 'No host summaries should exist when no tasks are saved');

        $recap = JobHostSummary::aggregate($hostSummaries);
        $this->assertSame(0, $recap['hosts']);
    }

    // -------------------------------------------------------------------------
    // Completed job with recap (simulates the full lifecycle)
    // -------------------------------------------------------------------------

    public function testCompletedJobHasRecapAvailable(): void
    {
        $job = $this->makeRunningJob();

        // Save tasks (simulating runner POST /jobs/{id}/tasks)
        $this->service->saveTasks($job, [
            ['seq' => 1, 'name' => 'Gather facts', 'action' => 'setup',   'host' => 'server1', 'status' => 'ok', 'changed' => false, 'duration_ms' => 400],
            ['seq' => 2, 'name' => 'Deploy app',   'action' => 'copy',    'host' => 'server1', 'status' => 'ok', 'changed' => true,  'duration_ms' => 1200],
            ['seq' => 3, 'name' => 'Restart svc',  'action' => 'service', 'host' => 'server1', 'status' => 'ok', 'changed' => true,  'duration_ms' => 300],
        ]);

        // Complete job (simulating runner POST /jobs/{id}/complete)
        $this->service->complete($job, 0, hasChanges: true);

        // Reload from DB — this is what the controller does
        $job->refresh();
        $hostSummaries = $job->getHostSummaries()->all();

        // Job should be succeeded
        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status);

        // Recap should be visible
        $this->assertCount(1, $hostSummaries);
        $this->assertSame('server1', $hostSummaries[0]->host);

        $recap = JobHostSummary::aggregate($hostSummaries);
        $this->assertSame(1, $recap['hosts']);
        $this->assertSame(1, $recap['ok']);      // gather facts
        $this->assertSame(2, $recap['changed']); // deploy + restart
        $this->assertSame(0, $recap['failed']);
    }

    // -------------------------------------------------------------------------
    // Single unreachable host
    // -------------------------------------------------------------------------

    public function testUnreachableHostShowsInRecap(): void
    {
        $job = $this->makeRunningJob();

        $this->service->saveTasks($job, [
            ['seq' => 1, 'name' => 'Gather facts', 'action' => 'setup', 'host' => 'offline-host', 'status' => 'unreachable', 'changed' => false, 'duration_ms' => 10000],
        ]);

        $this->service->complete($job, 4); // Ansible exits 4 for unreachable

        $job->refresh();
        $hostSummaries = $job->getHostSummaries()->all();

        $this->assertCount(1, $hostSummaries);
        $this->assertSame(1, (int)$hostSummaries[0]->unreachable);
        $this->assertSame(0, (int)$hostSummaries[0]->ok);

        $recap = JobHostSummary::aggregate($hostSummaries);
        $this->assertSame(1, $recap['unreachable']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeRunningJob(): Job
    {
        $user     = $this->createUser();
        $group    = $this->createRunnerGroup($user->id);
        $project  = $this->createProject($user->id);
        $inv      = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);
        return $this->createJob($template->id, $user->id, Job::STATUS_RUNNING);
    }
}
