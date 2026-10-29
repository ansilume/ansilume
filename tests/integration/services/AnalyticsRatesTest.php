<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AnalyticsQuery;
use app\models\ApprovalRequest;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\services\AnalyticsService;
use app\tests\integration\DbTestCase;

/**
 * Every success and approval rate of the analytics reports rounds the exact
 * share, an exact half up, as the reports did on PHP 8.3: 23 of 160 is
 * 14.375 %, shown as 14.38.
 *
 * Regression: since PHP 8.4 round() has no pre-rounding, and a rate computed
 * as part / whole * 100 (14.374999999999998 for 23 of 160) showed 14.37 on
 * the analytics page, in the API and in the CSV export (app\components\Percent).
 * 160 is the smallest whole that holds such a half and is no power of two.
 */
class AnalyticsRatesTest extends DbTestCase
{
    private const SUCCEEDED = 23;
    private const FAILED = 137;
    private const RATE = 14.38;

    private AnalyticsService $service;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var AnalyticsService $service */
        $service = \Yii::$app->get('analyticsService');
        $this->service = $service;
        $this->owner = $this->createUser('analytics-rates');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function jobReportProvider(): array
    {
        return [
            'summary' => ['summary'],
            'template reliability' => ['templateReliability'],
            'user activity' => ['userActivity'],
            'runner activity' => ['runnerActivity'],
        ];
    }

    /**
     * @dataProvider jobReportProvider
     */
    public function testRegressionAJobReportRoundedAnExactHalfDown(string $report): void
    {
        $groupId = (int)$this->createRunnerGroup((int)$this->owner->id)->id;
        $template = $this->template($groupId);
        $templateId = (int)$template->id;
        $runnerId = (int)$this->createRunner($groupId, (int)$this->owner->id)->id;
        $this->finishedJobs($template, $runnerId);
        $query = $this->query();
        $query->template_id = $templateId;

        $row = match ($report) {
            'summary' => $this->service->summary($query),
            'templateReliability' => $this->rowOf(
                $this->service->templateReliability($query),
                'template_id',
                $templateId
            ),
            'userActivity' => $this->rowOf($this->service->userActivity($query), 'user_id', (int)$this->owner->id),
            'runnerActivity' => $this->rowOf($this->service->runnerActivity($query), 'runner_id', $runnerId),
        };

        $this->assertSame(self::SUCCEEDED, $row['succeeded']);
        $this->assertSame(self::FAILED, $row['failed']);
        $this->assertSame(self::RATE, $row['success_rate']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function workflowReportProvider(): array
    {
        return [
            'workflow summary' => ['workflowSummary'],
            'workflow activity' => ['workflowActivity'],
        ];
    }

    /**
     * @dataProvider workflowReportProvider
     */
    public function testRegressionAWorkflowReportRoundedAnExactHalfDown(string $report): void
    {
        $workflowId = (int)$this->createWorkflowTemplate((int)$this->owner->id)->id;
        $now = time();
        $rows = [];
        foreach ($this->outcomes(WorkflowJob::STATUS_SUCCEEDED, WorkflowJob::STATUS_FAILED) as $status) {
            $rows[] = [
                'workflow_template_id' => $workflowId,
                'launched_by' => (int)$this->owner->id,
                'status' => $status,
                'started_at' => $now - 60,
                'finished_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->insert(WorkflowJob::tableName(), $rows);
        $query = $this->query();
        $query->user_id = (int)$this->owner->id;

        $row = match ($report) {
            'workflowSummary' => $this->service->workflowSummary($query),
            'workflowActivity' => $this->rowOf($this->service->workflowActivity($query), 'template_id', $workflowId),
        };

        $this->assertSame(self::SUCCEEDED, $row['succeeded']);
        $this->assertSame(self::FAILED, $row['failed']);
        $this->assertSame(self::RATE, $row['success_rate']);
    }

    public function testRegressionTheApprovalRateRoundedAnExactHalfDown(): void
    {
        $template = $this->template((int)$this->createRunnerGroup((int)$this->owner->id)->id);
        $this->finishedJobs($template, null);
        $jobIds = Job::find()
            ->select('id')
            ->where(['job_template_id' => $template->id])
            ->orderBy(['id' => SORT_ASC])
            ->column();
        $ruleId = (int)$this->createApprovalRule((int)$this->owner->id)->id;
        $now = time();
        $rows = [];
        foreach ($this->outcomes(ApprovalRequest::STATUS_APPROVED, ApprovalRequest::STATUS_REJECTED) as $i => $status) {
            $rows[] = [
                'job_id' => (int)$jobIds[$i],
                'approval_rule_id' => $ruleId,
                'status' => $status,
                'requested_at' => $now - 60,
                'resolved_at' => $now,
            ];
        }
        $this->insert(ApprovalRequest::tableName(), $rows);

        $summary = $this->service->approvalSummary($this->query());

        $this->assertSame(self::SUCCEEDED, $summary['approved']);
        $this->assertSame(self::FAILED, $summary['rejected']);
        $this->assertSame(self::RATE, $summary['approval_rate']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * The statuses of SUCCEEDED + FAILED runs: SUCCEEDED times $success, then
     * FAILED times $failure.
     *
     * @return list<string>
     */
    private function outcomes(string $success, string $failure): array
    {
        return array_merge(array_fill(0, self::SUCCEEDED, $success), array_fill(0, self::FAILED, $failure));
    }

    /**
     * A job template of the owner, with a project and inventory of its own,
     * for the runner group $groupId.
     */
    private function template(int $groupId): JobTemplate
    {
        $ownerId = (int)$this->owner->id;

        return $this->createJobTemplate(
            (int)$this->createProject($ownerId)->id,
            (int)$this->createInventory($ownerId)->id,
            $groupId,
            $ownerId
        );
    }

    /**
     * SUCCEEDED succeeded and FAILED failed jobs of $template, launched by the
     * owner on the runner $runnerId, each finished a minute after it started.
     */
    private function finishedJobs(JobTemplate $template, ?int $runnerId): void
    {
        $now = time();
        $rows = [];
        foreach ($this->outcomes(Job::STATUS_SUCCEEDED, Job::STATUS_FAILED) as $status) {
            $rows[] = [
                'job_template_id' => (int)$template->id,
                'launched_by' => (int)$this->owner->id,
                'runner_id' => $runnerId,
                'status' => $status,
                'timeout_minutes' => 120,
                'exit_code' => $status === Job::STATUS_SUCCEEDED ? 0 : 2,
                'queued_at' => $now - 60,
                'started_at' => $now - 60,
                'finished_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->insert(Job::tableName(), $rows);
    }

    /**
     * Inserts $rows, which all have the columns of the first, into $table.
     *
     * @param list<array<string, int|string|null>> $rows
     */
    private function insert(string $table, array $rows): void
    {
        $values = array_map('array_values', $rows);
        $command = \Yii::$app->db->createCommand()->batchInsert($table, array_keys($rows[0]), $values);

        $this->assertSame(count($rows), $command->execute());
    }

    /**
     * The reports of today, as a superadmin sees them.
     */
    private function query(): AnalyticsQuery
    {
        $superadmin = $this->createUser('analytics-rates-viewer');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $query = new AnalyticsQuery();
        $query->date_from = date('Y-m-d', strtotime('-1 day'));
        $query->date_to = date('Y-m-d', strtotime('+1 day'));
        $query->scopeUserId = (int)$superadmin->id;

        return $query;
    }

    /**
     * The row of $rows whose $key is $id.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function rowOf(array $rows, string $key, int $id): array
    {
        foreach ($rows as $row) {
            if ($row[$key] === $id) {
                return $row;
            }
        }
        $this->fail(sprintf('No row with %s %d.', $key, $id));
    }
}
