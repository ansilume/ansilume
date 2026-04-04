<?php

declare(strict_types=1);

namespace app\tests\integration\api;

use app\models\AnalyticsQuery;
use app\models\Job;
use app\models\JobHostSummary;
use app\services\AnalyticsService;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for the Analytics API data layer.
 *
 * Validates that AnalyticsService returns correct structure and values
 * when queried through the same paths used by the API controller.
 */
class AnalyticsApiTest extends DbTestCase
{
    private AnalyticsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var AnalyticsService $s */
        $s = \Yii::$app->get('analyticsService');
        $this->service = $s;
    }

    private function makeQuery(): AnalyticsQuery
    {
        $q = new AnalyticsQuery();
        $q->date_from = date('Y-m-d', strtotime('-1 day'));
        $q->date_to = date('Y-m-d', strtotime('+1 day'));
        return $q;
    }

    /**
     * @return array{0: \app\models\User, 1: \app\models\JobTemplate}
     */
    private function scaffoldWithJobs(int $succeeded = 2, int $failed = 1): array
    {
        $user = $this->createUser('api-analytics');
        $group = $this->createRunnerGroup($user->id);
        $proj = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $tpl = $this->createJobTemplate($proj->id, $inv->id, $group->id, $user->id);

        for ($i = 0; $i < $succeeded; $i++) {
            $j = $this->createJob($tpl->id, $user->id);
            $j->status = 'succeeded';
            $j->started_at = time() - 60;
            $j->finished_at = time();
            $j->exit_code = 0;
            $j->save(false);
        }
        for ($i = 0; $i < $failed; $i++) {
            $j = $this->createJob($tpl->id, $user->id);
            $j->status = 'failed';
            $j->started_at = time() - 120;
            $j->finished_at = time();
            $j->exit_code = 1;
            $j->save(false);
        }

        return [$user, $tpl];
    }

    public function testSummaryResponseStructure(): void
    {
        $this->scaffoldWithJobs();
        $result = $this->service->summary($this->makeQuery());

        $this->assertArrayHasKey('total_jobs', $result);
        $this->assertArrayHasKey('succeeded', $result);
        $this->assertArrayHasKey('failed', $result);
        $this->assertArrayHasKey('success_rate', $result);
        $this->assertArrayHasKey('avg_duration_seconds', $result);
        $this->assertArrayHasKey('mttr_seconds', $result);
    }

    public function testSummaryValues(): void
    {
        $this->scaffoldWithJobs(3, 1);
        $result = $this->service->summary($this->makeQuery());
        $this->assertSame(4, $result['total_jobs']);
        $this->assertSame(3, $result['succeeded']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(75.0, $result['success_rate']);
    }

    public function testTemplateReliabilityResponseStructure(): void
    {
        $this->scaffoldWithJobs();
        $result = $this->service->templateReliability($this->makeQuery());

        $this->assertNotEmpty($result);
        $first = $result[0];
        $this->assertArrayHasKey('template_id', $first);
        $this->assertArrayHasKey('template_name', $first);
        $this->assertArrayHasKey('total', $first);
        $this->assertArrayHasKey('succeeded', $first);
        $this->assertArrayHasKey('failed', $first);
        $this->assertArrayHasKey('success_rate', $first);
        $this->assertArrayHasKey('avg_duration_seconds', $first);
    }

    public function testProjectActivityResponseStructure(): void
    {
        $this->scaffoldWithJobs();
        $result = $this->service->projectActivity($this->makeQuery());

        $this->assertNotEmpty($result);
        $first = $result[0];
        $this->assertArrayHasKey('project_id', $first);
        $this->assertArrayHasKey('project_name', $first);
        $this->assertArrayHasKey('total', $first);
        $this->assertArrayHasKey('succeeded', $first);
        $this->assertArrayHasKey('failed', $first);
    }

    public function testUserActivityResponseStructure(): void
    {
        $this->scaffoldWithJobs();
        $result = $this->service->userActivity($this->makeQuery());

        $this->assertNotEmpty($result);
        $first = $result[0];
        $this->assertArrayHasKey('user_id', $first);
        $this->assertArrayHasKey('username', $first);
        $this->assertArrayHasKey('total', $first);
        $this->assertArrayHasKey('success_rate', $first);
    }

    public function testHostHealthResponseStructure(): void
    {
        [$user, $tpl] = $this->scaffoldWithJobs(1, 0);

        $job = Job::find()
            ->where(['job_template_id' => $tpl->id])
            ->one();
        $this->assertNotNull($job);

        $hs = new JobHostSummary();
        $hs->job_id = $job->id;
        $hs->host = 'db01.example.com';
        $hs->ok = 5;
        $hs->changed = 1;
        $hs->failed = 0;
        $hs->skipped = 2;
        $hs->unreachable = 0;
        $hs->rescued = 0;
        $hs->created_at = time();
        $hs->save(false);

        $result = $this->service->hostHealth($this->makeQuery());
        $this->assertNotEmpty($result);
        $first = $result[0];
        $this->assertArrayHasKey('host', $first);
        $this->assertArrayHasKey('ok', $first);
        $this->assertArrayHasKey('changed', $first);
        $this->assertArrayHasKey('failed', $first);
        $this->assertArrayHasKey('unreachable', $first);
        $this->assertArrayHasKey('skipped', $first);
    }

    public function testJobTrendResponseStructure(): void
    {
        $this->scaffoldWithJobs();
        $result = $this->service->jobTrend($this->makeQuery());

        $this->assertNotEmpty($result);
        $first = $result[0];
        $this->assertArrayHasKey('period', $first);
        $this->assertArrayHasKey('total', $first);
        $this->assertArrayHasKey('succeeded', $first);
        $this->assertArrayHasKey('failed', $first);
    }

    public function testEmptyResultsWithNoJobs(): void
    {
        $q = $this->makeQuery();
        $this->assertSame(0, $this->service->summary($q)['total_jobs']);
        $this->assertSame([], $this->service->templateReliability($q));
        $this->assertSame([], $this->service->projectActivity($q));
        $this->assertSame([], $this->service->userActivity($q));
        $this->assertSame([], $this->service->hostHealth($q));
        $this->assertSame([], $this->service->jobTrend($q));
    }

    public function testQueryValidationRejectsInvalidRange(): void
    {
        $q = new AnalyticsQuery();
        $q->date_from = '2026-01-01';
        $q->date_to = '2024-01-01';
        $this->assertFalse($q->validate());
    }

    public function testCsvFormatForSummary(): void
    {
        $this->scaffoldWithJobs(1, 1);
        $result = [$this->service->summary($this->makeQuery())];

        // Simulate CSV generation
        $output = fopen('php://temp', 'r+');
        $this->assertNotFalse($output);

        fputcsv($output, array_keys($result[0]));
        foreach ($result as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csv = (string)stream_get_contents($output);
        fclose($output);

        $this->assertStringContainsString('total_jobs', $csv);
        $this->assertStringContainsString('success_rate', $csv);
    }
}
