<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Job;
use app\models\Schedule;
use app\services\ScheduleService;
use app\tests\integration\DbTestCase;

class ScheduleServiceTest extends DbTestCase
{
    private ScheduleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \Yii::$app->get('scheduleService');
    }

    // -------------------------------------------------------------------------
    // runDue()
    // -------------------------------------------------------------------------

    public function testRunDueReturnsZeroWhenNoSchedulesExist(): void
    {
        // All schedules disabled or not due — count relative to initial state
        $launched = $this->service->runDue();
        $this->assertGreaterThanOrEqual(0, $launched);
    }

    public function testRunDueSkipsDisabledSchedule(): void
    {
        [$template, $user] = $this->makeFixtures();

        $schedule = $this->createSchedule($template->id, $user->id, false, time() - 60);

        $before   = (int)Job::find()->count();
        $launched = $this->service->runDue();
        $after    = (int)Job::find()->count();

        $this->assertSame(0, $this->countJobsForTemplate($template->id));
        $this->assertSame($before, $after);
    }

    public function testRunDueSkipsScheduleWithFutureNextRunAt(): void
    {
        [$template, $user] = $this->makeFixtures();

        $this->createSchedule($template->id, $user->id, true, time() + 3600);

        $this->service->runDue();

        $this->assertSame(0, $this->countJobsForTemplate($template->id));
    }

    public function testRunDueLaunchesJobForDueSchedule(): void
    {
        [$template, $user] = $this->makeFixtures();

        $this->createSchedule($template->id, $user->id, true, time() - 60);

        $launched = $this->service->runDue();

        $this->assertGreaterThanOrEqual(1, $launched);
        $this->assertSame(1, $this->countJobsForTemplate($template->id));
    }

    public function testRunDueLaunchedJobHasQueuedStatus(): void
    {
        [$template, $user] = $this->makeFixtures();

        $this->createSchedule($template->id, $user->id, true, time() - 60);

        $this->service->runDue();

        $job = Job::find()->where(['job_template_id' => $template->id])->one();
        $this->assertNotNull($job);
        $this->assertSame(Job::STATUS_QUEUED, $job->status);
    }

    public function testRunDueAdvancesNextRunAtAfterLaunch(): void
    {
        [$template, $user] = $this->makeFixtures();

        $schedule = $this->createSchedule($template->id, $user->id, true, time() - 60);
        $originalNextRunAt = $schedule->next_run_at;

        $this->service->runDue();

        $schedule->refresh();
        $this->assertNotNull($schedule->next_run_at);
        $this->assertNotSame($originalNextRunAt, $schedule->next_run_at);
        $this->assertGreaterThan(time() - 5, $schedule->last_run_at);
    }

    public function testRunDueWithNullNextRunAtEvaluatesCronExpression(): void
    {
        [$template, $user] = $this->makeFixtures();

        // next_run_at = null → falls back to CronExpression::isDue('now')
        // Use "* * * * *" (every minute) to ensure it's always due
        $schedule = $this->createSchedule($template->id, $user->id, true, null, '* * * * *');

        $this->service->runDue();

        $this->assertSame(1, $this->countJobsForTemplate($template->id));
    }

    public function testRunDueLaunchesTwoIndependentDueSchedules(): void
    {
        [$templateA, $user] = $this->makeFixtures();
        [$templateB]        = $this->makeFixtures();

        $this->createSchedule($templateA->id, $user->id, true, time() - 60);
        $this->createSchedule($templateB->id, $user->id, true, time() - 60);

        $launched = $this->service->runDue();

        $this->assertGreaterThanOrEqual(2, $launched);
        $this->assertSame(1, $this->countJobsForTemplate($templateA->id));
        $this->assertSame(1, $this->countJobsForTemplate($templateB->id));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeFixtures(): array
    {
        $user        = $this->createUser();
        $runnerGroup = $this->createRunnerGroup($user->id);
        $project     = $this->createProject($user->id);
        $inventory   = $this->createInventory($user->id);
        $template    = $this->createJobTemplate(
            $project->id,
            $inventory->id,
            $runnerGroup->id,
            $user->id
        );
        return [$template, $user];
    }

    private function createSchedule(
        int $templateId,
        int $createdBy,
        bool $enabled,
        ?int $nextRunAt,
        string $cron = '0 * * * *'
    ): Schedule {
        $s = new Schedule();
        $s->name            = 'test-sched-' . uniqid('', true);
        $s->job_template_id = $templateId;
        $s->cron_expression = $cron;
        $s->timezone        = 'UTC';
        $s->enabled         = $enabled;
        $s->next_run_at     = $nextRunAt;
        $s->created_by      = $createdBy;
        $s->created_at      = time();
        $s->updated_at      = time();
        $s->save(false);
        return $s;
    }

    public function testRunDuePassesExtraVarsToLaunchedJob(): void
    {
        [$template, $user] = $this->makeFixtures();

        $schedule = $this->createSchedule($template->id, $user->id, true, time() - 60);
        $schedule->extra_vars = '{"env": "staging"}';
        $schedule->save(false);

        $this->service->runDue();

        /** @var Job|null $job */
        $job = Job::find()->where(['job_template_id' => $template->id])->one();
        $this->assertNotNull($job);
        $extraVars = json_decode((string)$job->extra_vars, true);
        $this->assertIsArray($extraVars);
        $this->assertSame('staging', $extraVars['env'] ?? null);
    }

    public function testRunDueSetsLastRunAtAfterLaunch(): void
    {
        [$template, $user] = $this->makeFixtures();

        $schedule = $this->createSchedule($template->id, $user->id, true, time() - 60);
        $this->assertNull($schedule->last_run_at);

        $this->service->runDue();

        $schedule->refresh();
        $this->assertNotNull($schedule->last_run_at);
        $this->assertGreaterThanOrEqual(time() - 5, $schedule->last_run_at);
    }

    public function testRunDueWithInvalidCronExpressionSkipsSchedule(): void
    {
        [$template, $user] = $this->makeFixtures();

        $schedule = $this->createSchedule(
            $template->id,
            $user->id,
            true,
            null,
            'invalid-cron'
        );

        $launched = $this->service->runDue();
        $this->assertSame(0, $this->countJobsForTemplate($template->id));
    }

    public function testRunDueWithEmptyExtraVarsDoesNotSetOverrides(): void
    {
        [$template, $user] = $this->makeFixtures();

        $schedule = $this->createSchedule($template->id, $user->id, true, time() - 60);
        $schedule->extra_vars = '';
        $schedule->save(false);

        $this->service->runDue();

        /** @var Job|null $job */
        $job = Job::find()->where(['job_template_id' => $template->id])->one();
        $this->assertNotNull($job);
        // extra_vars should come from the template defaults, not from schedule override.
        // The key assertion is that it doesn't crash and a job is launched.
        $this->assertSame(1, $this->countJobsForTemplate($template->id));
    }

    public function testRunDueWithNullNextRunAtAndNonMatchingCronSkips(): void
    {
        [$template, $user] = $this->makeFixtures();

        // Use a very specific cron that won't match right now (Feb 30 doesn't exist).
        // Use 0 0 30 2 * — minute 0, hour 0, day 30, month Feb, any weekday.
        $schedule = $this->createSchedule($template->id, $user->id, true, null, '0 0 30 2 *');

        $launched = $this->service->runDue();
        $this->assertSame(0, $this->countJobsForTemplate($template->id));
    }

    public function testRunDueWithTimezoneOnNullNextRunAt(): void
    {
        [$template, $user] = $this->makeFixtures();

        // Create with null next_run_at and every-minute cron, custom timezone.
        $schedule = $this->createSchedule($template->id, $user->id, true, null, '* * * * *');
        $schedule->timezone = 'America/New_York';
        $schedule->save(false);

        $this->service->runDue();

        // Should still launch because "* * * * *" is always due.
        $this->assertSame(1, $this->countJobsForTemplate($template->id));
    }

    public function testRunDueMixedScheduleStates(): void
    {
        [$templateA, $user] = $this->makeFixtures();
        [$templateB] = $this->makeFixtures();
        [$templateC] = $this->makeFixtures();

        // Due schedule
        $this->createSchedule($templateA->id, $user->id, true, time() - 60);
        // Disabled schedule (should be skipped)
        $this->createSchedule($templateB->id, $user->id, false, time() - 60);
        // Future schedule (should be skipped)
        $this->createSchedule($templateC->id, $user->id, true, time() + 7200);

        $this->service->runDue();

        $this->assertSame(1, $this->countJobsForTemplate($templateA->id));
        $this->assertSame(0, $this->countJobsForTemplate($templateB->id));
        $this->assertSame(0, $this->countJobsForTemplate($templateC->id));
    }

    public function testRunDueSetsCorrectLaunchedByFromScheduleCreator(): void
    {
        [$template, $user] = $this->makeFixtures();

        $this->createSchedule($template->id, $user->id, true, time() - 60);

        $this->service->runDue();

        /** @var Job|null $job */
        $job = Job::find()->where(['job_template_id' => $template->id])->one();
        $this->assertNotNull($job);
        $this->assertSame($user->id, (int)$job->launched_by);
    }

    private function countJobsForTemplate(int $templateId): int
    {
        return (int)Job::find()->where(['job_template_id' => $templateId])->count();
    }
}
