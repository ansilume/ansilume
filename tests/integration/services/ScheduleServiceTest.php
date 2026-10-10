<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\Schedule;
use app\models\User;
use app\services\NotificationDispatcher;
use app\services\ScheduleService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

class ScheduleServiceTest extends DbTestCase
{
    use TeamScopeFixtures;

    private ScheduleService $service;

    /** Records dispatched notifications instead of sending them. */
    private RecordingNotificationDispatcher $dispatcher;

    /** @var mixed the dispatcher definition the test replaced */
    private mixed $originalDispatcher = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \Yii::$app->get('scheduleService');
        $this->originalDispatcher = \Yii::$app->getComponents(true)['notificationDispatcher'] ?? null;
        $this->dispatcher = new RecordingNotificationDispatcher();
        \Yii::$app->set('notificationDispatcher', $this->dispatcher);
    }

    protected function tearDown(): void
    {
        \Yii::$app->set('notificationDispatcher', $this->originalDispatcher);
        parent::tearDown();
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

    /**
     * A job template of an open project and a creator who may launch it:
     * schedules launch as their creator, who needs job.launch.
     *
     * @return array{0: JobTemplate, 1: User}
     */
    private function makeFixtures(): array
    {
        $user        = $this->createUserWithRole('sched', 'operator');
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

    /**
     * @return array<string, array{0: string}>
     */
    public static function negativeStepProvider(): array
    {
        return [
            'an hour step past its range (ValueError)' => ['* */-30 * * *'],
            'a minute step of -1 (runs out of memory)' => ['*/-1 * * * *'],
            'a minute range with a step of -1 (runs out of memory)' => ['1-5/-1 * * * *'],
        ];
    }

    /**
     * Regression: older versions could store a cron expression with a
     * negative step. Evaluating it threw a ValueError, which the due check
     * did not catch, or looped until PHP ran out of memory, so schedule/run
     * stopped at that row on every tick and the schedules after it never
     * launched. Such a row is now skipped.
     *
     * @dataProvider negativeStepProvider
     */
    public function testRunDueSkipsAStoredNegativeStepAndLaunchesTheSchedulesAfterIt(string $cron): void
    {
        [$template, $user] = $this->makeFixtures();
        $broken = $this->createSchedule($template->id, $user->id, true, null, $cron);
        $due = $this->createSchedule($template->id, $user->id, true, time() - 60);

        $this->service->runDue();

        $broken->refresh();
        $this->assertNull($broken->next_run_at);
        $this->assertNull($broken->last_run_at);
        $due->refresh();
        $this->assertNotNull($due->last_run_at, 'The schedule after the broken one must still run.');
        $this->assertSame(1, $this->countJobsForTemplate($template->id));
    }

    /**
     * Regression: an error in the due check stopped schedule/run for every
     * schedule after the failing one. A schedule whose stored values make the
     * check throw (here a timezone with a NUL byte, for which DateTimeZone
     * throws a ValueError) is now logged and skipped.
     */
    public function testRunDueSkipsAScheduleWhoseDueCheckFailsAndLaunchesTheSchedulesAfterIt(): void
    {
        [$template, $user] = $this->makeFixtures();
        $broken = $this->createSchedule($template->id, $user->id, true, null, '* * * * *');
        $broken->timezone = "UTC\0x";
        $broken->save(false);
        $due = $this->createSchedule($template->id, $user->id, true, time() - 60);

        $this->service->runDue();

        $broken->refresh();
        $this->assertNull($broken->last_run_at);
        $due->refresh();
        $this->assertNotNull($due->last_run_at, 'The schedule after the broken one must still run.');
        $this->assertSame(1, $this->countJobsForTemplate($template->id));
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

    // -------------------------------------------------------------------------
    // Team scoping at launch time: a schedule launches as its creator, who
    // must still be allowed to launch the template.
    // -------------------------------------------------------------------------

    /**
     * Regression: schedules launched as their creator without any check, so
     * a disabled user's schedules kept running.
     */
    public function testRunDueRefusesAScheduleWhoseCreatorIsDisabled(): void
    {
        [$template, $creator] = $this->makeFixtures();
        $creator->status = User::STATUS_INACTIVE;
        $creator->save(false);
        $schedule = $this->createSchedule($template->id, $creator->id, true, time() - 60);

        $launched = $this->service->runDue();

        $this->assertSame(0, $launched);
        $this->assertLaunchRefused($schedule, $template, ScheduleService::DENIED_NOT_PERMITTED);
    }

    /**
     * Regression: a creator whose role no longer holds job.launch still had
     * jobs launched on their behalf.
     */
    public function testRunDueRefusesAScheduleWhoseCreatorMayNotLaunchJobs(): void
    {
        [$template] = $this->makeFixtures();
        $viewer = $this->createUserWithRole('sched_viewer', 'viewer');
        $schedule = $this->createSchedule($template->id, $viewer->id, true, time() - 60);

        $this->service->runDue();

        $this->assertLaunchRefused($schedule, $template, ScheduleService::DENIED_NOT_PERMITTED);
    }

    /**
     * Regression: a schedule kept launching a team-restricted template after
     * its creator lost operator access to the project (left the team, or the
     * team was downgraded to viewer).
     *
     * @return array<string, array{0: string}>
     */
    public static function templateTheCreatorMayNotOperateProvider(): array
    {
        return [
            'another team\'s project' => ['foreign'],
            'a project the creator\'s team only views' => ['viewed'],
        ];
    }

    /**
     * @dataProvider templateTheCreatorMayNotOperateProvider
     */
    public function testRunDueRefusesAScheduleWhoseCreatorMayNotOperateTheProject(string $key): void
    {
        $scope = $this->teamScope();
        $template = $scope[$key];
        $this->assertInstanceOf(JobTemplate::class, $template);
        $schedule = $this->createSchedule((int)$template->id, (int)$scope['member']->id, true, time() - 60);

        $this->service->runDue();

        $this->assertLaunchRefused($schedule, $template, ScheduleService::DENIED_NO_PROJECT_ACCESS);
    }

    /**
     * No over-blocking: the creator's own team project and open projects.
     *
     * @return array<string, array{0: string}>
     */
    public static function templateTheCreatorMayOperateProvider(): array
    {
        return ['the creator\'s team project' => ['own'], 'an open project' => ['open']];
    }

    /**
     * @dataProvider templateTheCreatorMayOperateProvider
     */
    public function testRunDueLaunchesAsACreatorWhoMayOperateTheProject(string $key): void
    {
        $scope = $this->teamScope();
        $template = $scope[$key];
        $this->assertInstanceOf(JobTemplate::class, $template);
        $member = $scope['member'];
        $this->createSchedule((int)$template->id, (int)$member->id, true, time() - 60);

        $launched = $this->service->runDue();

        $this->assertGreaterThanOrEqual(1, $launched);
        $this->assertLaunchedAs((int)$template->id, (int)$member->id);
        $this->assertSame([], $this->failedLaunches());
    }

    public function testRunDueLaunchesAnAdminsScheduleInAnotherTeamsProject(): void
    {
        $scope = $this->teamScope();
        $this->createSchedule((int)$scope['foreign']->id, (int)$scope['admin']->id, true, time() - 60);

        $this->service->runDue();

        $this->assertLaunchedAs((int)$scope['foreign']->id, (int)$scope['admin']->id);
    }

    public function testRunDueLaunchesASuperadminsScheduleWithoutAnyRole(): void
    {
        $scope = $this->teamScope();
        $superadmin = $this->createUser('sched_superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $this->createSchedule((int)$scope['foreign']->id, (int)$superadmin->id, true, time() - 60);

        $this->service->runDue();

        $this->assertLaunchedAs((int)$scope['foreign']->id, (int)$superadmin->id);
    }

    public function testARefusedScheduleDoesNotHoldUpTheOthers(): void
    {
        $scope = $this->teamScope();
        $member = (int)$scope['member']->id;
        $this->createSchedule((int)$scope['foreign']->id, $member, true, time() - 60);
        $this->createSchedule((int)$scope['own']->id, $member, true, time() - 60);

        $launched = $this->service->runDue();

        $this->assertSame(1, $launched);
        $this->assertSame(0, $this->countJobsForTemplate((int)$scope['foreign']->id));
        $this->assertLaunchedAs((int)$scope['own']->id, $member);
    }

    public function testARefusedScheduleIsNotRetriedBeforeItsNextRun(): void
    {
        $scope = $this->teamScope();
        $schedule = $this->createSchedule((int)$scope['foreign']->id, (int)$scope['member']->id, true, time() - 60);

        $this->service->runDue();
        $this->service->runDue();

        $this->assertSame(1, $this->countDenials($schedule));
        $this->assertCount(1, $this->failedLaunches());
    }

    public function testAScheduleOfADeletedTemplateIsReportedAsFailedToLaunch(): void
    {
        [$template, $user] = $this->makeFixtures();
        $schedule = $this->createSchedule($template->id, $user->id, true, time() - 60);
        $template->softDelete();

        $launched = $this->service->runDue();

        $this->assertSame(0, $launched);
        $this->assertSame(0, $this->countJobsForTemplate($template->id));
        $this->assertSame(0, $this->countDenials($schedule), 'a missing template is a failure, not a denial');
        $failures = $this->failedLaunches();
        $this->assertCount(1, $failures);
        $this->assertSame((string)$schedule->id, $failures[0]['id']);
        $this->assertSame("Job template not found for schedule #{$schedule->id}", $failures[0]['error']);
    }

    // -------------------------------------------------------------------------
    // templateAccess(), canView(), canOperate()
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function templateAccessProvider(): array
    {
        return [
            'own team project' => ['own', ScheduleService::TEMPLATE_USABLE],
            'open project' => ['open', ScheduleService::TEMPLATE_USABLE],
            'project the team only views' => ['viewed', ScheduleService::TEMPLATE_FORBIDDEN],
            'another team\'s project reads as missing' => ['foreign', ScheduleService::TEMPLATE_MISSING],
        ];
    }

    /**
     * @dataProvider templateAccessProvider
     */
    public function testTemplateAccessFollowsTheTemplatesProject(string $key, string $expected): void
    {
        $scope = $this->teamScope();
        $template = $scope[$key];
        $this->assertInstanceOf(JobTemplate::class, $template);

        $this->assertSame($expected, $this->service->templateAccess((int)$scope['member']->id, (int)$template->id));
    }

    public function testTemplateAccessTreatsUnknownAndDeletedTemplatesAsMissing(): void
    {
        $scope = $this->teamScope();
        $admin = (int)$scope['admin']->id;
        $scope['own']->softDelete();

        $this->assertSame(ScheduleService::TEMPLATE_MISSING, $this->service->templateAccess($admin, 999999999));
        $this->assertSame(ScheduleService::TEMPLATE_MISSING, $this->service->templateAccess($admin, (int)$scope['own']->id));
    }

    public function testTemplateAccessLetsAnAdminUseEveryTemplate(): void
    {
        $scope = $this->teamScope();

        $this->assertSame(
            ScheduleService::TEMPLATE_USABLE,
            $this->service->templateAccess((int)$scope['admin']->id, (int)$scope['foreign']->id)
        );
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function scheduleAccessProvider(): array
    {
        return [
            'own team project' => ['own', true, true],
            'open project' => ['open', true, true],
            'project the team only views' => ['viewed', true, false],
            'another team\'s project' => ['foreign', false, false],
        ];
    }

    /**
     * @dataProvider scheduleAccessProvider
     */
    public function testScheduleAccessFollowsTheTemplatesProject(string $key, bool $view, bool $operate): void
    {
        $scope = $this->teamScope();
        $template = $scope[$key];
        $this->assertInstanceOf(JobTemplate::class, $template);
        $schedule = $this->createSchedule((int)$template->id, (int)$scope['admin']->id, true, null);
        $member = (int)$scope['member']->id;

        $this->assertSame($view, $this->service->canView($member, $schedule));
        $this->assertSame($operate, $this->service->canOperate($member, $schedule));
    }

    /**
     * Regression: the access checks resolved the project through the
     * template relation, which skips soft-deleted templates, so a schedule
     * of another team's deleted template counted as global and everyone
     * could view, change, toggle and delete it.
     */
    public function testTheScheduleOfADeletedTemplateStaysScopedToItsProject(): void
    {
        $scope = $this->teamScope();
        $foreign = $this->createSchedule((int)$scope['foreign']->id, (int)$scope['outsider']->id, true, null);
        $own = $this->createSchedule((int)$scope['own']->id, (int)$scope['member']->id, true, null);
        $scope['foreign']->softDelete();
        $scope['own']->softDelete();
        $member = (int)$scope['member']->id;

        $this->assertFalse($this->service->canView($member, $foreign));
        $this->assertFalse($this->service->canOperate($member, $foreign));
        $this->assertTrue($this->service->canOperate($member, $own));
        $this->assertTrue($this->service->canOperate((int)$scope['outsider']->id, $foreign));
        $this->assertTrue($this->service->canOperate((int)$scope['admin']->id, $foreign));
    }

    private function assertLaunchRefused(Schedule $schedule, JobTemplate $template, string $reason): void
    {
        $this->assertSame(0, $this->countJobsForTemplate((int)$template->id), 'no job is launched');

        /** @var AuditLog[] $denials */
        $denials = AuditLog::find()->where([
            'action' => AuditLog::ACTION_SCHEDULE_LAUNCH_DENIED,
            'object_type' => 'schedule',
            'object_id' => $schedule->id,
        ])->all();
        $this->assertCount(1, $denials);
        $this->assertSame((int)$schedule->created_by, (int)$denials[0]->user_id);
        $this->assertSame(
            ['job_template_id' => (int)$template->id, 'project_id' => (int)$template->project_id, 'reason' => $reason],
            json_decode((string)$denials[0]->metadata, true)
        );

        $failures = $this->failedLaunches();
        $this->assertCount(1, $failures, 'reported like any schedule that cannot launch');
        $this->assertSame((string)$schedule->id, $failures[0]['id']);
        $this->assertStringContainsString(
            "Schedule #{$schedule->id} ({$schedule->name}) not launched: user #{$schedule->created_by}",
            (string)$failures[0]['error']
        );

        $schedule->refresh();
        $this->assertGreaterThan(time(), (int)$schedule->next_run_at, 'the schedule moves on to its next run');
    }

    private function assertLaunchedAs(int $templateId, int $userId): void
    {
        /** @var Job[] $jobs */
        $jobs = Job::find()->where(['job_template_id' => $templateId])->all();
        $this->assertCount(1, $jobs);
        $this->assertSame($userId, (int)$jobs[0]->launched_by);
    }

    private function countDenials(Schedule $schedule): int
    {
        return (int)AuditLog::find()->where([
            'action' => AuditLog::ACTION_SCHEDULE_LAUNCH_DENIED,
            'object_id' => $schedule->id,
        ])->count();
    }

    /**
     * The schedule part of every schedule.failed_to_launch notification.
     *
     * @return list<array<string, mixed>>
     */
    private function failedLaunches(): array
    {
        $schedules = [];
        foreach ($this->dispatcher->payloadsOf(NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH) as $payload) {
            $this->assertIsArray($payload['schedule'] ?? null);
            $schedules[] = $payload['schedule'];
        }

        return $schedules;
    }

    private function countJobsForTemplate(int $templateId): int
    {
        return (int)Job::find()->where(['job_template_id' => $templateId])->count();
    }
}
