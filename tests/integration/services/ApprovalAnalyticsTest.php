<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AnalyticsQuery;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\services\AnalyticsService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The average decision time of the approval statistics
 * (AnalyticsService::approvalSummary(), ApprovalAnalytics): a timed-out
 * request counts with the time until it expired, however late its timeout
 * was applied.
 */
class ApprovalAnalyticsTest extends DbTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;
    use ApprovalVoteFixtures;

    private const DAY = 86400;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordNotifications();
    }

    protected function tearDown(): void
    {
        $this->restoreNotifications();
        parent::tearDown();
    }

    /**
     * Regression: a timeout applied late counted its delay as decision
     * time. Stock installations never ran the timeouts before 2.10, so the
     * first maintenance run after the update applies requests that expired
     * weeks ago: a request that waited one day for its timeout counted 30
     * days, for good, in every period that holds it.
     */
    public function testRegressionATimeoutAppliedLateCountedItsDelayAsDecisionTime(): void
    {
        ['job' => $job, 'request' => $request] = $this->waitingRequest([$this->approverUser()]);
        $requestedAt = time() - 30 * self::DAY;
        ApprovalRequest::updateAll(
            ['requested_at' => $requestedAt, 'expires_at' => $requestedAt + self::DAY],
            ['id' => $request->id]
        );
        ApprovalRule::updateAll(['timeout_minutes' => 1440], ['id' => $request->approval_rule_id]);

        $this->assertSame(1, $this->approvals()->processTimeouts());
        $this->assertSame(ApprovalRequest::STATUS_TIMED_OUT, $this->reloadRequest($request)->status);
        $this->assertSame(Job::STATUS_REJECTED, $this->reloadJob($job)->status);

        $summary = $this->summary();

        $this->assertSame(1, $summary['timed_out']);
        $this->assertEqualsWithDelta((float)self::DAY, $summary['avg_decision_seconds'], 1.0, 'the day until it expired, not the 30 days until it was applied');
    }

    /**
     * Decided requests count until they were decided, timed-out ones until
     * they expired (or were resolved, when that came first), legacy
     * timed-out rows without expires_at until they were resolved; canceled
     * ones not at all.
     */
    public function testEachKindOfRequestCountsItsOwnTime(): void
    {
        $now = time();
        // approved after 100 s
        $this->request(ApprovalRequest::STATUS_APPROVED, $now - 100, $now, null);
        // expired after 300 s, its timeout applied two days later
        $this->request(ApprovalRequest::STATUS_TIMED_OUT, $now - 2 * self::DAY - 300, $now, $now - 2 * self::DAY);
        // resolved after 200 s, before its expires_at (as stored by older versions)
        $this->request(ApprovalRequest::STATUS_TIMED_OUT, $now - 200, $now, $now + 500);
        // no expires_at (as stored by older versions): until it was resolved, 600 s
        $this->request(ApprovalRequest::STATUS_TIMED_OUT, $now - 600, $now, null);
        // canceled after 9000 s: not counted
        $this->request(ApprovalRequest::STATUS_CANCELED, $now - 9000, $now, $now - 8000);

        $summary = $this->summary();

        $this->assertSame(5, $summary['total']);
        $this->assertSame(3, $summary['timed_out']);
        $this->assertSame(1, $summary['canceled']);
        $this->assertEqualsWithDelta((100 + 300 + 200 + 600) / 4, $summary['avg_decision_seconds'], 1.0);
    }

    // -- Helpers ----------------------------------------------------------------------

    private function request(string $status, int $requestedAt, int $resolvedAt, ?int $expiresAt): void
    {
        $user = $this->createUser('analytics-approvals');
        $template = $this->voteTemplate((int)$user->id);
        $job = $this->createJob((int)$template->id, (int)$user->id, Job::STATUS_REJECTED);
        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->approval_rule_id = (int)$this->createApprovalRule((int)$user->id)->id;
        $request->status = $status;
        $request->requested_at = $requestedAt;
        $request->resolved_at = $resolvedAt;
        $request->expires_at = $expiresAt;
        $request->save(false);
    }

    /**
     * The approval statistics of the last 31 days, as a superadmin sees them.
     *
     * @return array{total: int, approved: int, rejected: int, timed_out: int, canceled: int, pending: int, approval_rate: float, avg_decision_seconds: float}
     */
    private function summary(): array
    {
        $superadmin = $this->createUser('analytics-approvals-viewer');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $query = new AnalyticsQuery();
        $query->date_from = date('Y-m-d', strtotime('-31 days'));
        $query->date_to = date('Y-m-d', strtotime('+1 day'));
        $query->scopeUserId = (int)$superadmin->id;
        /** @var AnalyticsService $analytics */
        $analytics = \Yii::$app->get('analyticsService');

        return $analytics->approvalSummary($query);
    }
}
