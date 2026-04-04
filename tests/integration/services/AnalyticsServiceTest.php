<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AnalyticsQuery;
use app\models\Job;
use app\models\JobHostSummary;
use app\services\AnalyticsService;
use app\tests\integration\DbTestCase;

class AnalyticsServiceTest extends DbTestCase
{
    private AnalyticsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var AnalyticsService $s */
        $s = \Yii::$app->get('analyticsService');
        $this->service = $s;
    }

    /**
     * @return array{0: \app\models\User, 1: \app\models\JobTemplate, 2: \app\models\Project}
     */
    private function scaffold(): array
    {
        $user = $this->createUser('analytics');
        $group = $this->createRunnerGroup($user->id);
        $proj = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $tpl = $this->createJobTemplate($proj->id, $inv->id, $group->id, $user->id);
        return [$user, $tpl, $proj];
    }

    private function finishJob(Job $job, string $status, int $duration = 60): void
    {
        $job->status = $status;
        $job->started_at = time() - $duration;
        $job->finished_at = time();
        $job->exit_code = $status === 'succeeded' ? 0 : 1;
        $job->save(false);
    }

    private function makeQuery(): AnalyticsQuery
    {
        $q = new AnalyticsQuery();
        $q->date_from = date('Y-m-d', strtotime('-1 day'));
        $q->date_to = date('Y-m-d', strtotime('+1 day'));
        return $q;
    }

    // ─── summary ────────────────────────────────────────────────────────

    public function testSummaryWithNoJobs(): void
    {
        $result = $this->service->summary($this->makeQuery());
        $this->assertSame(0, $result['total_jobs']);
        $this->assertSame(0, $result['succeeded']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0.0, $result['success_rate']);
    }

    public function testSummaryCountsFinishedJobs(): void
    {
        [$user, $tpl] = $this->scaffold();

        $j1 = $this->createJob($tpl->id, $user->id);
        $this->finishJob($j1, 'succeeded', 30);

        $j2 = $this->createJob($tpl->id, $user->id);
        $this->finishJob($j2, 'failed', 90);

        $j3 = $this->createJob($tpl->id, $user->id);
        $this->finishJob($j3, 'succeeded', 60);

        $result = $this->service->summary($this->makeQuery());
        $this->assertSame(3, $result['total_jobs']);
        $this->assertSame(2, $result['succeeded']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(66.67, $result['success_rate']);
        $this->assertGreaterThan(0, $result['avg_duration_seconds']);
    }

    public function testSummaryExcludesRunningJobs(): void
    {
        [$user, $tpl] = $this->scaffold();

        $j1 = $this->createJob($tpl->id, $user->id);
        $this->finishJob($j1, 'succeeded');

        // This one is still running — should be excluded
        $this->createJob($tpl->id, $user->id, Job::STATUS_RUNNING);

        $result = $this->service->summary($this->makeQuery());
        $this->assertSame(1, $result['total_jobs']);
    }

    // ─── templateReliability ────────────────────────────────────────────

    public function testTemplateReliabilityGroupsByTemplate(): void
    {
        [$user, $tpl1, $proj] = $this->scaffold();
        $group = $this->createRunnerGroup($user->id);
        $inv = $this->createInventory($user->id);
        $tpl2 = $this->createJobTemplate($proj->id, $inv->id, $group->id, $user->id);

        $j1 = $this->createJob($tpl1->id, $user->id);
        $this->finishJob($j1, 'succeeded');

        $j2 = $this->createJob($tpl2->id, $user->id);
        $this->finishJob($j2, 'failed');

        $result = $this->service->templateReliability($this->makeQuery());
        $this->assertCount(2, $result);

        $ids = array_column($result, 'template_id');
        $this->assertContains($tpl1->id, $ids);
        $this->assertContains($tpl2->id, $ids);
    }

    public function testTemplateReliabilityCalculatesSuccessRate(): void
    {
        [$user, $tpl] = $this->scaffold();

        $j1 = $this->createJob($tpl->id, $user->id);
        $this->finishJob($j1, 'succeeded');
        $j2 = $this->createJob($tpl->id, $user->id);
        $this->finishJob($j2, 'succeeded');
        $j3 = $this->createJob($tpl->id, $user->id);
        $this->finishJob($j3, 'failed');

        $result = $this->service->templateReliability($this->makeQuery());
        $this->assertCount(1, $result);
        $this->assertSame(66.67, $result[0]['success_rate']);
        $this->assertSame(2, $result[0]['succeeded']);
        $this->assertSame(1, $result[0]['failed']);
    }

    // ─── projectActivity ────────────────────────────────────────────────

    public function testProjectActivityGroupsByProject(): void
    {
        [$user, $tpl] = $this->scaffold();

        $j1 = $this->createJob($tpl->id, $user->id);
        $this->finishJob($j1, 'succeeded');

        $result = $this->service->projectActivity($this->makeQuery());
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('project_name', $result[0]);
        $this->assertSame(1, $result[0]['total']);
    }

    // ─── userActivity ───────────────────────────────────────────────────

    public function testUserActivityGroupsByUser(): void
    {
        [$user, $tpl] = $this->scaffold();

        $j1 = $this->createJob($tpl->id, $user->id);
        $this->finishJob($j1, 'succeeded');
        $j2 = $this->createJob($tpl->id, $user->id);
        $this->finishJob($j2, 'failed');

        $result = $this->service->userActivity($this->makeQuery());
        $this->assertCount(1, $result);
        $this->assertSame($user->id, $result[0]['user_id']);
        $this->assertSame(2, $result[0]['total']);
        $this->assertSame(50.0, $result[0]['success_rate']);
    }

    // ─── hostHealth ─────────────────────────────────────────────────────

    public function testHostHealthAggregatesPerHost(): void
    {
        [$user, $tpl] = $this->scaffold();

        $j = $this->createJob($tpl->id, $user->id);
        $this->finishJob($j, 'succeeded');

        $hs = new JobHostSummary();
        $hs->job_id = $j->id;
        $hs->host = 'web01.example.com';
        $hs->ok = 10;
        $hs->changed = 2;
        $hs->failed = 1;
        $hs->skipped = 3;
        $hs->unreachable = 0;
        $hs->rescued = 0;
        $hs->created_at = time();
        $hs->save(false);

        $result = $this->service->hostHealth($this->makeQuery());
        $this->assertCount(1, $result);
        $this->assertSame('web01.example.com', $result[0]['host']);
        $this->assertSame(10, $result[0]['ok']);
        $this->assertSame(1, $result[0]['failed']);
    }

    public function testHostHealthWithNoData(): void
    {
        $result = $this->service->hostHealth($this->makeQuery());
        $this->assertSame([], $result);
    }

    // ─── jobTrend ───────────────────────────────────────────────────────

    public function testJobTrendReturnsDailyBuckets(): void
    {
        [$user, $tpl] = $this->scaffold();

        $j1 = $this->createJob($tpl->id, $user->id);
        $this->finishJob($j1, 'succeeded');
        $j2 = $this->createJob($tpl->id, $user->id);
        $this->finishJob($j2, 'failed');

        $q = $this->makeQuery();
        $q->granularity = AnalyticsQuery::GRANULARITY_DAILY;

        $result = $this->service->jobTrend($q);
        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('period', $result[0]);
        $this->assertArrayHasKey('succeeded', $result[0]);
        $this->assertArrayHasKey('failed', $result[0]);
    }

    public function testJobTrendWeeklyGranularity(): void
    {
        [$user, $tpl] = $this->scaffold();

        $j = $this->createJob($tpl->id, $user->id);
        $this->finishJob($j, 'succeeded');

        $q = $this->makeQuery();
        $q->granularity = AnalyticsQuery::GRANULARITY_WEEKLY;

        $result = $this->service->jobTrend($q);
        $this->assertNotEmpty($result);
        // Weekly periods look like "2026-W14"
        $this->assertMatchesRegularExpression('/^\d{4}-W\d{2}$/', $result[0]['period']);
    }

    // ─── filtering ──────────────────────────────────────────────────────

    public function testFilterByProject(): void
    {
        [$user, $tpl1, $proj1] = $this->scaffold();
        $group = $this->createRunnerGroup($user->id);
        $proj2 = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $tpl2 = $this->createJobTemplate($proj2->id, $inv->id, $group->id, $user->id);

        $j1 = $this->createJob($tpl1->id, $user->id);
        $this->finishJob($j1, 'succeeded');
        $j2 = $this->createJob($tpl2->id, $user->id);
        $this->finishJob($j2, 'succeeded');

        $q = $this->makeQuery();
        $q->project_id = $proj1->id;

        $result = $this->service->summary($q);
        $this->assertSame(1, $result['total_jobs']);
    }

    public function testFilterByTemplate(): void
    {
        [$user, $tpl1] = $this->scaffold();
        $group = $this->createRunnerGroup($user->id);
        $proj = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $tpl2 = $this->createJobTemplate($proj->id, $inv->id, $group->id, $user->id);

        $j1 = $this->createJob($tpl1->id, $user->id);
        $this->finishJob($j1, 'succeeded');
        $j2 = $this->createJob($tpl2->id, $user->id);
        $this->finishJob($j2, 'succeeded');

        $q = $this->makeQuery();
        $q->template_id = $tpl1->id;

        $result = $this->service->summary($q);
        $this->assertSame(1, $result['total_jobs']);
    }

    public function testFilterByUser(): void
    {
        [$user1, $tpl] = $this->scaffold();
        $user2 = $this->createUser('analytics2');

        $j1 = $this->createJob($tpl->id, $user1->id);
        $this->finishJob($j1, 'succeeded');
        $j2 = $this->createJob($tpl->id, $user2->id);
        $this->finishJob($j2, 'succeeded');

        $q = $this->makeQuery();
        $q->user_id = $user1->id;

        $result = $this->service->summary($q);
        $this->assertSame(1, $result['total_jobs']);
    }

    public function testFilterByDateRange(): void
    {
        [$user, $tpl] = $this->scaffold();

        // Job created now — should match
        $j1 = $this->createJob($tpl->id, $user->id);
        $this->finishJob($j1, 'succeeded');

        // Job with old created_at — should NOT match
        $j2 = $this->createJob($tpl->id, $user->id);
        $this->finishJob($j2, 'succeeded');
        $j2->created_at = strtotime('2020-01-01');
        $j2->save(false);

        $q = $this->makeQuery();
        $result = $this->service->summary($q);
        $this->assertSame(1, $result['total_jobs']);
    }
}
