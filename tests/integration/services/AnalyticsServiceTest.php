<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AnalyticsQuery;
use app\models\ApprovalRequest;
use app\models\Job;
use app\models\JobHostSummary;
use app\models\WorkflowJob;
use app\models\WorkflowTemplate;
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

    // ─── avg_failure_duration + MTTR (real) ─────────────────────────────
    //
    // The summary exposes two duration-ish fields that answer different
    // questions. These tests pin both:
    //
    //   avg_failure_duration_seconds — mean wall-clock runtime of failed
    //   jobs (finished_at - started_at). This was mislabelled "mttr"
    //   before v2.3.2.
    //
    //   mttr_seconds — DORA-style Mean Time To Recovery: time from a
    //   failure finishing until the next succeeded run of the same
    //   template. Failures with no recovery in the window are excluded.

    public function testSummaryComputesAvgFailureDurationAcrossFailedJobsOnly(): void
    {
        [$user, $tpl] = $this->scaffold();

        // Two successes (90s, 30s) — must be ignored by the failure metric.
        $this->finishJob($this->createJob($tpl->id, $user->id), 'succeeded', 90);
        $this->finishJob($this->createJob($tpl->id, $user->id), 'succeeded', 30);

        // Three failures of durations 60s, 120s, 300s → mean 160.0s
        $this->finishJob($this->createJob($tpl->id, $user->id), 'failed', 60);
        $this->finishJob($this->createJob($tpl->id, $user->id), 'failed', 120);
        $this->finishJob($this->createJob($tpl->id, $user->id), 'timed_out', 300);

        $result = $this->service->summary($this->makeQuery());

        $this->assertEqualsWithDelta(160.0, $result['avg_failure_duration_seconds'], 0.1);
        // avg_duration covers ALL finished jobs, so it must differ.
        $this->assertNotEqualsWithDelta(
            160.0,
            $result['avg_duration_seconds'],
            0.1,
            'avg_duration must not equal avg_failure_duration when there are successes in the window.',
        );
    }

    public function testMttrAveragesTimeFromFailureToNextSuccessOnSameTemplate(): void
    {
        [$user, $tpl] = $this->scaffold();

        // Failure A: finished 300s ago, recovered by success that started 200s ago → recovery = 100s
        $failA = $this->createJob($tpl->id, $user->id);
        $failA->status = 'failed';
        $failA->started_at = time() - 400;
        $failA->finished_at = time() - 300;
        $failA->save(false);

        $recoveryA = $this->createJob($tpl->id, $user->id);
        $recoveryA->status = 'succeeded';
        $recoveryA->started_at = time() - 200;
        $recoveryA->finished_at = time() - 180;
        $recoveryA->save(false);

        // Failure B: finished 150s ago, recovered by success that started 100s ago → recovery = 50s
        $failB = $this->createJob($tpl->id, $user->id);
        $failB->status = 'timed_out';
        $failB->started_at = time() - 170;
        $failB->finished_at = time() - 150;
        $failB->save(false);

        $recoveryB = $this->createJob($tpl->id, $user->id);
        $recoveryB->status = 'succeeded';
        $recoveryB->started_at = time() - 100;
        $recoveryB->finished_at = time() - 80;
        $recoveryB->save(false);

        $result = $this->service->summary($this->makeQuery());

        // (100 + 50) / 2 = 75
        $this->assertEqualsWithDelta(75.0, $result['mttr_seconds'], 0.5);
    }

    public function testMttrExcludesFailuresThatNeverRecovered(): void
    {
        [$user, $tpl] = $this->scaffold();

        // One recoverable failure: 200s → 100s → recovery = 100s
        $failA = $this->createJob($tpl->id, $user->id);
        $failA->status = 'failed';
        $failA->started_at = time() - 300;
        $failA->finished_at = time() - 200;
        $failA->save(false);

        $recoveryA = $this->createJob($tpl->id, $user->id);
        $recoveryA->status = 'succeeded';
        $recoveryA->started_at = time() - 100;
        $recoveryA->finished_at = time() - 80;
        $recoveryA->save(false);

        // Unrecovered failure — no subsequent success. Must not pull
        // the average to infinity or inject NULL.
        $failB = $this->createJob($tpl->id, $user->id);
        $failB->status = 'failed';
        $failB->started_at = time() - 60;
        $failB->finished_at = time() - 30;
        $failB->save(false);

        $result = $this->service->summary($this->makeQuery());

        $this->assertEqualsWithDelta(
            100.0,
            $result['mttr_seconds'],
            0.5,
            'Unrecovered failures must be excluded from the MTTR average.',
        );
    }

    public function testMttrIsZeroWhenNothingRecoveredInWindow(): void
    {
        [$user, $tpl] = $this->scaffold();

        // Only failures, no successes.
        $this->finishJob($this->createJob($tpl->id, $user->id), 'failed', 30);
        $this->finishJob($this->createJob($tpl->id, $user->id), 'timed_out', 60);

        $result = $this->service->summary($this->makeQuery());
        $this->assertSame(0.0, $result['mttr_seconds']);
    }

    public function testMttrDoesNotCrossTemplates(): void
    {
        // A success on template B must not count as recovery for a failure
        // on template A. Otherwise MTTR would get artificially low on
        // stacks with many unrelated templates.
        [$user, $tplA] = $this->scaffold();
        $group = $this->createRunnerGroup($user->id);
        $inv = $this->createInventory($user->id);
        $proj = $this->createProject($user->id);
        $tplB = $this->createJobTemplate($proj->id, $inv->id, $group->id, $user->id);

        $failA = $this->createJob($tplA->id, $user->id);
        $failA->status = 'failed';
        $failA->started_at = time() - 300;
        $failA->finished_at = time() - 200;
        $failA->save(false);

        // Unrelated success on tplB — must NOT count as recovery for tplA.
        $okB = $this->createJob($tplB->id, $user->id);
        $okB->status = 'succeeded';
        $okB->started_at = time() - 100;
        $okB->finished_at = time() - 80;
        $okB->save(false);

        $result = $this->service->summary($this->makeQuery());
        $this->assertSame(
            0.0,
            $result['mttr_seconds'],
            'Cross-template successes must not count as recovery.',
        );
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

    // ─── workflow summary ───────────────────────────────────────────────

    private function makeWorkflowJob(int $templateId, int $userId, string $status, int $duration = 60): WorkflowJob
    {
        $wj = new WorkflowJob();
        $wj->workflow_template_id = $templateId;
        $wj->launched_by = $userId;
        $wj->status = $status;
        $wj->started_at = time() - $duration;
        $wj->finished_at = $status === WorkflowJob::STATUS_RUNNING ? null : time();
        $wj->created_at = time();
        $wj->updated_at = time();
        $wj->save(false);
        return $wj;
    }

    public function testWorkflowSummaryWithNoJobs(): void
    {
        $result = $this->service->workflowSummary($this->makeQuery());
        $this->assertSame(0, $result['total']);
        $this->assertSame(0.0, $result['success_rate']);
        $this->assertSame(0.0, $result['avg_duration_seconds']);
    }

    public function testWorkflowSummaryCountsByStatus(): void
    {
        [$user] = $this->scaffold();
        $wt = $this->createWorkflowTemplate($user->id);

        $this->makeWorkflowJob($wt->id, $user->id, WorkflowJob::STATUS_SUCCEEDED, 30);
        $this->makeWorkflowJob($wt->id, $user->id, WorkflowJob::STATUS_SUCCEEDED, 60);
        $this->makeWorkflowJob($wt->id, $user->id, WorkflowJob::STATUS_FAILED, 90);
        $this->makeWorkflowJob($wt->id, $user->id, WorkflowJob::STATUS_CANCELED, 10);
        $this->makeWorkflowJob($wt->id, $user->id, WorkflowJob::STATUS_RUNNING);

        $result = $this->service->workflowSummary($this->makeQuery());
        $this->assertSame(5, $result['total']);
        $this->assertSame(2, $result['succeeded']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['canceled']);
        $this->assertSame(1, $result['running']);
        // success_rate over finished (total - running) = 2/4 = 50.0
        $this->assertSame(50.0, $result['success_rate']);
        $this->assertGreaterThan(0, $result['avg_duration_seconds']);
    }

    public function testWorkflowSummaryFilteredByUser(): void
    {
        [$user] = $this->scaffold();
        $other = $this->createUser('analytics-other');
        $wt = $this->createWorkflowTemplate($user->id);

        $this->makeWorkflowJob($wt->id, $user->id, WorkflowJob::STATUS_SUCCEEDED);
        $this->makeWorkflowJob($wt->id, $other->id, WorkflowJob::STATUS_SUCCEEDED);

        $q = $this->makeQuery();
        $q->user_id = $user->id;
        $result = $this->service->workflowSummary($q);
        $this->assertSame(1, $result['total']);
    }

    // ─── workflow activity ──────────────────────────────────────────────

    public function testWorkflowActivityGroupsByTemplate(): void
    {
        [$user] = $this->scaffold();
        $wt1 = $this->createWorkflowTemplate($user->id);
        $wt2 = $this->createWorkflowTemplate($user->id);

        $this->makeWorkflowJob($wt1->id, $user->id, WorkflowJob::STATUS_SUCCEEDED);
        $this->makeWorkflowJob($wt1->id, $user->id, WorkflowJob::STATUS_SUCCEEDED);
        $this->makeWorkflowJob($wt1->id, $user->id, WorkflowJob::STATUS_FAILED);
        $this->makeWorkflowJob($wt2->id, $user->id, WorkflowJob::STATUS_SUCCEEDED);

        $rows = $this->service->workflowActivity($this->makeQuery());
        $this->assertCount(2, $rows);

        $byTemplate = [];
        foreach ($rows as $r) {
            $byTemplate[$r['template_id']] = $r;
        }
        $this->assertSame(3, $byTemplate[$wt1->id]['total']);
        $this->assertSame(2, $byTemplate[$wt1->id]['succeeded']);
        $this->assertSame(1, $byTemplate[$wt1->id]['failed']);
        // 2 succeeded / 3 finished = 66.67
        $this->assertSame(66.67, $byTemplate[$wt1->id]['success_rate']);
        $this->assertSame(1, $byTemplate[$wt2->id]['total']);
        $this->assertSame(100.0, $byTemplate[$wt2->id]['success_rate']);
    }

    public function testWorkflowActivityEmpty(): void
    {
        $this->assertSame([], $this->service->workflowActivity($this->makeQuery()));
    }

    // ─── approval summary ───────────────────────────────────────────────

    private function makeApprovalRequest(int $jobId, int $ruleId, string $status, int $decisionSeconds = 30): ApprovalRequest
    {
        $ar = new ApprovalRequest();
        $ar->job_id = $jobId;
        $ar->approval_rule_id = $ruleId;
        $ar->status = $status;
        $ar->requested_at = time() - $decisionSeconds;
        $ar->resolved_at = $status === ApprovalRequest::STATUS_PENDING ? null : time();
        $ar->save(false);
        return $ar;
    }

    public function testApprovalSummaryWithNoRequests(): void
    {
        $result = $this->service->approvalSummary($this->makeQuery());
        $this->assertSame(0, $result['total']);
        $this->assertSame(0.0, $result['approval_rate']);
        $this->assertSame(0.0, $result['avg_decision_seconds']);
    }

    public function testApprovalSummaryCountsByOutcome(): void
    {
        [$user, $tpl] = $this->scaffold();
        $rule = $this->createApprovalRule($user->id);

        $jobs = [];
        for ($i = 0; $i < 5; $i++) {
            $jobs[] = $this->createJob($tpl->id, $user->id);
        }

        $this->makeApprovalRequest($jobs[0]->id, $rule->id, ApprovalRequest::STATUS_APPROVED, 10);
        $this->makeApprovalRequest($jobs[1]->id, $rule->id, ApprovalRequest::STATUS_APPROVED, 20);
        $this->makeApprovalRequest($jobs[2]->id, $rule->id, ApprovalRequest::STATUS_REJECTED, 30);
        $this->makeApprovalRequest($jobs[3]->id, $rule->id, ApprovalRequest::STATUS_TIMED_OUT, 600);
        $this->makeApprovalRequest($jobs[4]->id, $rule->id, ApprovalRequest::STATUS_PENDING);

        $result = $this->service->approvalSummary($this->makeQuery());
        $this->assertSame(5, $result['total']);
        $this->assertSame(2, $result['approved']);
        $this->assertSame(1, $result['rejected']);
        $this->assertSame(1, $result['timed_out']);
        $this->assertSame(1, $result['pending']);
        // 2 approved / 3 decided (approved + rejected) = 66.67
        $this->assertSame(66.67, $result['approval_rate']);
        $this->assertGreaterThan(0, $result['avg_decision_seconds']);
    }

    // ─── runner activity ────────────────────────────────────────────────

    public function testRunnerActivityGroupsByRunner(): void
    {
        [$user, $tpl] = $this->scaffold();
        $group = $this->createRunnerGroup($user->id);
        $runner1 = $this->createRunner($group->id, $user->id);
        $runner2 = $this->createRunner($group->id, $user->id);

        $j1 = $this->createJob($tpl->id, $user->id);
        $j1->runner_id = $runner1->id;
        $this->finishJob($j1, 'succeeded', 30);

        $j2 = $this->createJob($tpl->id, $user->id);
        $j2->runner_id = $runner1->id;
        $this->finishJob($j2, 'failed', 60);

        $j3 = $this->createJob($tpl->id, $user->id);
        $j3->runner_id = $runner2->id;
        $this->finishJob($j3, 'succeeded', 45);

        $rows = $this->service->runnerActivity($this->makeQuery());

        $byRunner = [];
        foreach ($rows as $r) {
            $byRunner[$r['runner_id']] = $r;
        }

        $this->assertArrayHasKey($runner1->id, $byRunner);
        $this->assertSame(2, $byRunner[$runner1->id]['total']);
        $this->assertSame(1, $byRunner[$runner1->id]['succeeded']);
        $this->assertSame(1, $byRunner[$runner1->id]['failed']);
        $this->assertSame(50.0, $byRunner[$runner1->id]['success_rate']);
        $this->assertGreaterThan(0, $byRunner[$runner1->id]['avg_duration_seconds']);

        $this->assertArrayHasKey($runner2->id, $byRunner);
        $this->assertSame(1, $byRunner[$runner2->id]['total']);
        $this->assertSame(100.0, $byRunner[$runner2->id]['success_rate']);
    }

    public function testRunnerActivityIncludesIdleRunners(): void
    {
        [$user] = $this->scaffold();
        $group = $this->createRunnerGroup($user->id);
        $runner = $this->createRunner($group->id, $user->id);
        $runner->last_seen_at = time() - 120;
        $runner->save(false);

        $rows = $this->service->runnerActivity($this->makeQuery());

        $found = null;
        foreach ($rows as $r) {
            if ($r['runner_id'] === $runner->id) {
                $found = $r;
                break;
            }
        }
        $this->assertNotNull($found);
        $this->assertSame(0, $found['total']);
        $this->assertSame(0.0, $found['success_rate']);
        $this->assertNotNull($found['last_seen_at']);
    }

    public function testRunnerActivityExcludesRunningJobs(): void
    {
        [$user, $tpl] = $this->scaffold();
        $group = $this->createRunnerGroup($user->id);
        $runner = $this->createRunner($group->id, $user->id);

        $j1 = $this->createJob($tpl->id, $user->id);
        $j1->runner_id = $runner->id;
        $this->finishJob($j1, 'succeeded');

        // Running job attached to same runner — must not be counted
        $j2 = $this->createJob($tpl->id, $user->id, Job::STATUS_RUNNING);
        $j2->runner_id = $runner->id;
        $j2->save(false);

        $rows = $this->service->runnerActivity($this->makeQuery());

        $found = null;
        foreach ($rows as $r) {
            if ($r['runner_id'] === $runner->id) {
                $found = $r;
                break;
            }
        }
        $this->assertNotNull($found);
        $this->assertSame(1, $found['total']);
    }
}
