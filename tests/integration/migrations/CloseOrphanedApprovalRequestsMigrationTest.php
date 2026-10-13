<?php

declare(strict_types=1);

namespace app\tests\integration\migrations;

use app\models\ApprovalRequest;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Schedule;
use app\tests\integration\DbTestCase;

/**
 * m000085_000000_close_orphaned_approval_requests: pending approval requests
 * whose job no longer waits for approval are closed as canceled, resolved
 * when the job finished; enabled schedules of deleted job templates are
 * disabled. Everything else stays. The updates run inside the test's
 * transaction and are rolled back with it.
 */
class CloseOrphanedApprovalRequestsMigrationTest extends DbTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/m000085_000000_close_orphaned_approval_requests.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Start without leftovers, so every count below is this test's own.
        $this->up();
    }

    public function testUpClosesThePendingRequestsOfJobsThatNoLongerWait(): void
    {
        $template = $this->template();
        $canceled = $this->requestFor($template, Job::STATUS_CANCELED, 1700000500);
        $queued = $this->requestFor($template, Job::STATUS_QUEUED, null);
        $rejected = $this->requestFor($template, Job::STATUS_REJECTED, 1700000600);
        $waiting = $this->requestFor($template, Job::STATUS_PENDING_APPROVAL, null);
        $decided = $this->requestFor($template, Job::STATUS_CANCELED, 1700000700, ApprovalRequest::STATUS_APPROVED, 1700000650);
        $before = time();

        $output = $this->up();

        $this->assertSame([ApprovalRequest::STATUS_CANCELED, 1700000500], $this->stateOf($canceled), 'resolved when its job was canceled');
        $closedNow = $this->stateOf($queued);
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $closedNow[0]);
        $this->assertGreaterThanOrEqual($before, (int)$closedNow[1], 'a job without a finish time: resolved now');
        $this->assertSame([ApprovalRequest::STATUS_CANCELED, 1700000600], $this->stateOf($rejected));
        $this->assertSame([ApprovalRequest::STATUS_PENDING, null], $this->stateOf($waiting), 'a job that waits keeps its request');
        $this->assertSame([ApprovalRequest::STATUS_APPROVED, 1700000650], $this->stateOf($decided), 'a decided request keeps its decision');
        $this->assertStringStartsWith("    > Closed 3 pending approval request(s) whose job no longer waits for approval.\n", $output);
    }

    public function testUpDisablesTheEnabledSchedulesOfDeletedTemplates(): void
    {
        $deleted = $this->template();
        $live = $this->template();
        $enabled = $this->schedule($deleted, 1);
        $alreadyDisabled = $this->schedule($deleted, 0);
        $other = $this->schedule($live, 1);
        $deleted->softDelete();
        $untouched = [
            'disabled' => Schedule::findOne($alreadyDisabled->id)?->getAttributes(),
            'live' => Schedule::findOne($other->id)?->getAttributes(),
        ];
        $before = time();

        $output = $this->up();

        $stored = Schedule::findOne($enabled->id);
        $this->assertNotNull($stored);
        $this->assertSame(0, (int)$stored->enabled);
        $this->assertNull($stored->next_run_at);
        $this->assertGreaterThanOrEqual($before, (int)$stored->updated_at);
        $this->assertSame($untouched['disabled'], Schedule::findOne($alreadyDisabled->id)?->getAttributes());
        $this->assertSame($untouched['live'], Schedule::findOne($other->id)?->getAttributes());
        $this->assertStringEndsWith("    > Disabled 1 schedule(s) of deleted job templates.\n", $output);
    }

    public function testRunningItAgainChangesNothing(): void
    {
        $template = $this->template();
        $request = $this->requestFor($template, Job::STATUS_CANCELED, 1700000500);
        $schedule = $this->schedule($template, 1);
        $template->softDelete();
        $this->up();
        $once = [$this->stateOf($request), Schedule::findOne($schedule->id)?->getAttributes()];

        $output = $this->up();

        $this->assertSame($once, [$this->stateOf($request), Schedule::findOne($schedule->id)?->getAttributes()]);
        $this->assertSame(
            "    > Closed 0 pending approval request(s) whose job no longer waits for approval.\n"
                . "    > Disabled 0 schedule(s) of deleted job templates.\n",
            $output
        );
    }

    /**
     * Down succeeds without touching data: there is nothing to reopen.
     */
    public function testDownSucceedsAndRestoresNothing(): void
    {
        $template = $this->template();
        $request = $this->requestFor($template, Job::STATUS_CANCELED, 1700000500);
        $this->up();

        $this->assertTrue($this->migration()->safeDown());

        $this->assertSame([ApprovalRequest::STATUS_CANCELED, 1700000500], $this->stateOf($request));
    }

    // -- Helpers ----------------------------------------------------------------

    private function migration(): \m000085_000000_close_orphaned_approval_requests
    {
        return new \m000085_000000_close_orphaned_approval_requests(['db' => \Yii::$app->db, 'compact' => true]);
    }

    /**
     * Runs safeUp() and returns what it printed.
     */
    private function up(): string
    {
        ob_start();
        try {
            $this->migration()->safeUp();
        } finally {
            $output = (string)ob_get_clean();
        }

        return $output;
    }

    private function template(): JobTemplate
    {
        $owner = $this->createUser('orphan_requests');

        return $this->createJobTemplate(
            (int)$this->createProject((int)$owner->id)->id,
            (int)$this->createInventory((int)$owner->id)->id,
            (int)$this->createRunnerGroup((int)$owner->id)->id,
            (int)$owner->id
        );
    }

    /**
     * A request as an older release left it: its status as given, for a job
     * in $jobStatus.
     */
    private function requestFor(
        JobTemplate $template,
        string $jobStatus,
        ?int $finishedAt,
        string $status = ApprovalRequest::STATUS_PENDING,
        ?int $resolvedAt = null
    ): ApprovalRequest {
        $job = $this->createJob((int)$template->id, (int)$template->created_by, $jobStatus);
        $job->finished_at = $finishedAt;
        $job->save(false);
        $request = new ApprovalRequest();
        $request->job_id = $job->id;
        $request->approval_rule_id = $this->createApprovalRule((int)$template->created_by)->id;
        $request->status = $status;
        $request->requested_at = 1700000000;
        $request->resolved_at = $resolvedAt;
        $request->save(false);

        return $request;
    }

    private function schedule(JobTemplate $template, int $enabled): Schedule
    {
        $schedule = new Schedule();
        $schedule->name = 'orphan-schedule-' . uniqid('', true);
        $schedule->job_template_id = $template->id;
        $schedule->cron_expression = '0 3 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = (bool)$enabled;
        $schedule->next_run_at = 1900000000;
        $schedule->created_by = $template->created_by;
        $schedule->save(false);
        // As stored long ago: the timestamp behavior wrote the time of the save.
        Schedule::updateAll(['created_at' => 1700000000, 'updated_at' => 1700000000], ['id' => $schedule->id]);
        $schedule->refresh();

        return $schedule;
    }

    /**
     * @return array{0: string, 1: int|null} status and resolved_at as stored
     */
    private function stateOf(ApprovalRequest $request): array
    {
        $stored = ApprovalRequest::findOne($request->id);
        $this->assertNotNull($stored);

        return [$stored->status, $stored->resolved_at === null ? null : (int)$stored->resolved_at];
    }
}
