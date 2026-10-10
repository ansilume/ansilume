<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\SiteController;
use app\models\ApprovalRequest;
use app\models\Job;
use app\models\JobHostSummary;
use app\models\JobTemplate;
use app\models\Project;
use app\models\Schedule;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\tests\integration\TeamScopeFixtures;

/**
 * The dashboard under team scoping.
 *
 * Regression: every list and counter of the dashboard covered all teams: job
 * tables and stat cards, quick launch, running workflows with their Resume
 * button, pending approvals, upcoming schedules, project sync errors and the
 * chart data.
 *
 * Scenario: "own" and "foreign" each have a running, a queued, a failed and
 * a pending-approval job (with a pending approval request), a schedule and a
 * project with a sync error. Workflows: "own" (job step own) and "viewed"
 * (job step viewed) are paused, "foreign" is running, "open" has no job step.
 */
class SiteControllerTeamScopeTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /** @var array<string, int> job ids by "<template>-<status>" */
    private array $jobs = [];
    /** @var array<string, WorkflowTemplate> */
    private array $workflows = [];
    /** @var array<string, int> workflow job ids */
    private array $runs = [];
    /** @var array<string, int> */
    private array $approvals = [];
    /** @var array<string, int> */
    private array $schedules = [];

    public function testStatCardsCountOnlyVisibleJobsAndApprovals(): void
    {
        $s = $this->scenario();

        $this->assertSame([
            'jobs_today' => 4,
            'jobs_today_failed' => 1,
            'queued' => 1,
            'running' => 1,
            'pending_approvals' => 1,
        ], $this->dashboardFor($s['member'])['stats']);
        $this->assertSame([
            'jobs_today' => 8,
            'jobs_today_failed' => 2,
            'queued' => 2,
            'running' => 2,
            'pending_approvals' => 2,
        ], $this->dashboardFor($s['admin'])['stats']);
    }

    public function testStatusCountsCoverOnlyVisibleJobs(): void
    {
        $s = $this->scenario();

        $member = $this->dashboardFor($s['member'])['statusCounts'];
        $this->assertSame(1, $member[Job::STATUS_RUNNING]);
        $this->assertSame(1, $member[Job::STATUS_FAILED]);
        $this->assertSame(1, $member[Job::STATUS_PENDING_APPROVAL]);

        $this->assertSame(2, $this->dashboardFor($s['admin'])['statusCounts'][Job::STATUS_RUNNING]);
    }

    public function testJobTablesListOnlyVisibleJobs(): void
    {
        $s = $this->scenario();
        $p = $this->dashboardFor($s['member']);

        $this->assertSame([$this->jobs['own-running']], $this->ids($p['runningJobs']));
        $this->assertSame([$this->jobs['own-failed']], $this->ids($p['failedJobs']));
        $recent = $this->ids($p['recentJobs']);
        $this->assertContains($this->jobs['own-queued'], $recent);
        $this->assertNotContains($this->jobs['foreign-queued'], $recent);
        $this->assertNotContains($this->jobs['foreign-running'], $recent);

        $admin = $this->dashboardFor($s['admin']);
        $this->assertContains($this->jobs['foreign-running'], $this->ids($admin['runningJobs']));
        $this->assertContains($this->jobs['foreign-failed'], $this->ids($admin['failedJobs']));
    }

    public function testQuickLaunchOffersOnlyWhatTheUserMayLaunch(): void
    {
        $s = $this->scenario();
        $p = $this->dashboardFor($s['member']);

        $this->assertEqualsCanonicalizing([(int)$s['own']->id, (int)$s['open']->id], $this->ids($p['templates']));
        $this->assertEqualsCanonicalizing(
            [(int)$this->workflows['own']->id, (int)$this->workflows['open']->id],
            $this->ids($p['workflowTemplates'])
        );

        $admin = $this->dashboardFor($s['admin']);
        $this->assertContains((int)$s['foreign']->id, $this->ids($admin['templates']));
        $this->assertContains((int)$this->workflows['foreign']->id, $this->ids($admin['workflowTemplates']));
    }

    public function testRunningWorkflowsAreScopedAndResumeNeedsOperateAccess(): void
    {
        $s = $this->scenario();
        $p = $this->dashboardFor($s['member']);

        $this->assertEqualsCanonicalizing([$this->runs['own'], $this->runs['viewed']], $this->ids($p['runningWorkflows']));
        $this->assertSame([(int)$this->workflows['own']->id], $p['resumableWorkflowTemplateIds'], 'viewed: view access only');

        $admin = $this->dashboardFor($s['admin']);
        $this->assertContains($this->runs['foreign'], $this->ids($admin['runningWorkflows']));
        $this->assertEqualsCanonicalizing(
            [(int)$this->workflows['own']->id, (int)$this->workflows['viewed']->id, (int)$this->workflows['foreign']->id],
            $admin['resumableWorkflowTemplateIds']
        );
    }

    public function testPendingApprovalsSchedulesAndSyncErrorsAreScoped(): void
    {
        $s = $this->scenario();
        $p = $this->dashboardFor($s['member']);

        $this->assertSame([$this->approvals['own']], $this->ids($p['pendingApprovals']));
        $this->assertSame([$this->schedules['own']], $this->ids($p['upcomingSchedules']));
        $this->assertTrue($p['hasSchedules']);
        $this->assertSame([(int)$s['own']->project_id], $this->ids($p['syncErrors']));

        $admin = $this->dashboardFor($s['admin']);
        $this->assertEqualsCanonicalizing(array_values($this->approvals), $this->ids($admin['pendingApprovals']));
        $this->assertEqualsCanonicalizing(array_values($this->schedules), $this->ids($admin['upcomingSchedules']));
        $this->assertContains((int)$s['foreign']->project_id, $this->ids($admin['syncErrors']));
    }

    public function testSchedulesOfOtherTeamsDoNotCountAsActiveSchedules(): void
    {
        $s = $this->teamScope();
        $this->schedule($s['foreign']);

        $p = $this->dashboardFor($s['member']);

        $this->assertSame([], $p['upcomingSchedules']);
        $this->assertFalse($p['hasSchedules']);
    }

    public function testAViewerSeesNoLaunchOrResumeAndNoSchedules(): void
    {
        $s = $this->scenario('viewer');
        $p = $this->dashboardFor($s['member']);

        $this->assertSame([], $p['templates']);
        $this->assertSame([], $p['workflowTemplates']);
        $this->assertSame([], $p['upcomingSchedules']);
        $this->assertFalse($p['hasSchedules']);
        $this->assertSame([], $p['resumableWorkflowTemplateIds']);
        $this->assertNotEmpty($p['runningWorkflows'], 'viewers hold workflow.view');
        $this->assertNotEmpty($p['pendingApprovals'], 'viewers hold approval.view');
    }

    /**
     * Team scoping shows everyone a pending approval of the open project and
     * a running workflow without job steps: only the permission checks
     * (approval.view, workflow.view) hide them from a user without roles. An
     * operator, who holds both, sees them.
     */
    public function testAUserWithoutRolesSeesNoApprovalsOrWorkflows(): void
    {
        $s = $this->scenario();
        $adminId = (int)$s['admin']->id;
        $waiting = $this->createJob((int)$s['open']->id, $adminId, Job::STATUS_PENDING_APPROVAL);
        $openApproval = $this->pendingApproval((int)$waiting->id, (int)$this->createApprovalRule($adminId)->id);
        $pauseOnlyRun = $this->pausedRun($this->workflows['open'], $adminId);

        $p = $this->dashboardFor($this->createUser('dashboard-no-role'));

        $this->assertSame([], $p['pendingApprovals']);
        $this->assertSame(0, $p['stats']['pending_approvals']);
        $this->assertSame([], $p['runningWorkflows']);
        $this->assertSame([], $p['resumableWorkflowTemplateIds']);

        $operator = $this->dashboardFor($this->createUserWithRole('dashboard-operator', 'operator'));
        $this->assertSame([$openApproval], $this->ids($operator['pendingApprovals']), 'control');
        $this->assertSame(1, $operator['stats']['pending_approvals'], 'control');
        $this->assertSame([$pauseOnlyRun], $this->ids($operator['runningWorkflows']), 'control');
    }

    public function testASuperadminWithoutRolesSeesEverything(): void
    {
        $this->scenario();
        $superadmin = $this->createUser('dashboard-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);

        $p = $this->dashboardFor($superadmin);

        $this->assertSame(2, $p['stats']['pending_approvals']);
        $this->assertCount(3, $p['runningWorkflows']);
        $this->assertCount(4, $p['templates']);
        $this->assertCount(2, $p['upcomingSchedules']);
    }

    /**
     * Runners are shared infrastructure: every user sees the same counts.
     */
    public function testRunnerCountsAreTheSameForEveryUser(): void
    {
        $s = $this->teamScope();
        $group = (int)$s['own']->runner_group_id;
        $current = $this->createRunner($group, (int)$s['admin']->id);
        $current->last_seen_at = time();
        $current->software_version = '2.0.0';
        $current->save(false);
        $outdated = $this->createRunner($group, (int)$s['admin']->id);
        $outdated->software_version = '1.0.0';
        $outdated->save(false);
        $version = \Yii::$app->params['version'] ?? null;
        \Yii::$app->params['version'] = '2.0.0';

        try {
            foreach ([$s['member'], $s['outsider'], $s['admin']] as $user) {
                $p = $this->dashboardFor($user);
                $this->assertSame(2, $p['totalRunners']);
                $this->assertSame(1, $p['onlineRunners']);
                $this->assertSame(1, $p['outdatedRunners']);
            }
        } finally {
            \Yii::$app->params['version'] = $version;
        }
    }

    public function testChartDataCountsOnlyVisibleJobs(): void
    {
        $s = $this->scenario();

        $member = $this->chartFor($s['member']);
        $this->assertSame(1, array_sum($member['jobs']['failed']));
        $this->assertSame(3, array_sum($member['tasks']['ok']), 'host recap of the own job only');

        $admin = $this->chartFor($s['admin']);
        $this->assertSame(2, array_sum($admin['jobs']['failed']));
        $this->assertSame(8, array_sum($admin['tasks']['ok']));
    }

    /**
     * The view shows the full pending count (not the five listed) and a
     * Resume button only for the workflow the user may operate.
     */
    public function testTheViewRendersTheScopedDashboard(): void
    {
        $s = $this->scenario();
        for ($i = 0; $i < 5; $i++) {
            $waiting = $this->createJob((int)$s['own']->id, (int)$s['admin']->id, Job::STATUS_PENDING_APPROVAL);
            $this->pendingApproval((int)$waiting->id, (int)$this->createApprovalRule((int)$s['admin']->id)->id);
        }

        $html = \Yii::$app->view->renderFile('@app/views/site/index.php', $this->dashboardFor($s['member']));

        $this->assertStringContainsString('Pending Approvals (6)', $html);
        $this->assertStringContainsString((string)$s['own']->name, $html);
        $this->assertStringNotContainsString((string)$s['foreign']->name, $html);
        $this->assertStringContainsString('/workflow-job/resume?id=' . $this->runs['own'], $html);
        $this->assertStringNotContainsString('/workflow-job/resume?id=' . $this->runs['viewed'], $html);
    }

    // ── Scenario ─────────────────────────────────────────────────────────────

    /**
     * @return array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate}
     */
    private function scenario(string $memberRole = 'operator'): array
    {
        $s = $this->teamScope($memberRole);
        $adminId = (int)$s['admin']->id;
        $ruleId = (int)$this->createApprovalRule($adminId)->id;

        foreach (['own' => 3, 'foreign' => 5] as $key => $okTasks) {
            $template = $s[$key];
            $this->jobs[$key . '-running'] = (int)$this->createJob((int)$template->id, $adminId, Job::STATUS_RUNNING)->id;
            $this->jobs[$key . '-queued'] = (int)$this->createJob((int)$template->id, $adminId, Job::STATUS_QUEUED)->id;
            $this->jobs[$key . '-failed'] = $this->failedJob($template, $adminId, $okTasks);
            $waiting = $this->createJob((int)$template->id, $adminId, Job::STATUS_PENDING_APPROVAL);
            $this->approvals[$key] = $this->pendingApproval((int)$waiting->id, $ruleId);
            $this->schedules[$key] = $this->schedule($template);
            $this->syncError((int)$template->project_id);
        }

        $this->workflows = [
            'own' => $this->createWorkflowWithJobSteps($adminId, (int)$s['own']->id),
            'viewed' => $this->createWorkflowWithJobSteps($adminId, (int)$s['viewed']->id),
            'foreign' => $this->createWorkflowWithJobSteps($adminId, (int)$s['foreign']->id),
            'open' => $this->createWorkflowTemplate($adminId),
        ];
        $this->runs = [
            'own' => $this->pausedRun($this->workflows['own'], $adminId),
            'viewed' => $this->pausedRun($this->workflows['viewed'], $adminId),
            'foreign' => (int)$this->createWorkflowJob((int)$this->workflows['foreign']->id, $adminId)->id,
        ];

        return $s;
    }

    private function failedJob(JobTemplate $template, int $launchedBy, int $okTasks): int
    {
        $job = $this->createJob((int)$template->id, $launchedBy, Job::STATUS_FAILED);
        $job->started_at = time() - 60;
        $job->finished_at = time();
        $job->save(false);

        $summary = new JobHostSummary();
        $summary->job_id = $job->id;
        $summary->host = 'host-' . $template->id;
        $summary->ok = $okTasks;
        $summary->changed = 0;
        $summary->failed = 1;
        $summary->skipped = 0;
        $summary->unreachable = 0;
        $summary->rescued = 0;
        $summary->created_at = time();
        $summary->save(false);

        return (int)$job->id;
    }

    private function pendingApproval(int $jobId, int $ruleId): int
    {
        $request = new ApprovalRequest();
        $request->job_id = $jobId;
        $request->approval_rule_id = $ruleId;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $request->save(false);
        return (int)$request->id;
    }

    private function schedule(JobTemplate $template): int
    {
        $schedule = new Schedule();
        $schedule->name = 'dashboard-schedule-' . uniqid('', true);
        $schedule->job_template_id = $template->id;
        $schedule->cron_expression = '0 3 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->next_run_at = time() + 3600;
        $schedule->created_by = $template->created_by;
        $schedule->created_at = time();
        $schedule->updated_at = time();
        $schedule->save(false);
        return (int)$schedule->id;
    }

    private function syncError(int $projectId): void
    {
        $project = Project::findOne($projectId);
        $this->assertNotNull($project);
        $project->status = Project::STATUS_ERROR;
        $project->save(false);
    }

    private function pausedRun(WorkflowTemplate $workflow, int $launchedBy): int
    {
        $pause = $this->createWorkflowStep((int)$workflow->id, 5, WorkflowStep::TYPE_PAUSE);
        $run = $this->createWorkflowJob((int)$workflow->id, $launchedBy);
        $run->current_step_id = $pause->id;
        $run->save(false);
        return (int)$run->id;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function dashboardFor(User $user): array
    {
        $this->loginAs($user);
        $ctrl = new class ('site', \Yii::$app) extends SiteController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }
        };
        $ctrl->actionIndex();
        return $ctrl->capturedParams;
    }

    /**
     * @return array{labels: list<string>, jobs: array{ok: list<int>, failed: list<int>}, tasks: array{ok: list<int>}}
     */
    private function chartFor(User $user): array
    {
        $this->loginAs($user);
        $response = (new SiteController('site', \Yii::$app))->actionChartData(7);
        /** @var array{labels: list<string>, jobs: array{ok: list<int>, failed: list<int>}, tasks: array{ok: list<int>}} $data */
        $data = $response->data;
        return $data;
    }

    /**
     * @param mixed $models
     * @return list<int>
     */
    private function ids(mixed $models): array
    {
        $this->assertIsArray($models);
        return array_values(array_map(static fn (\yii\db\ActiveRecord $m): int => (int)$m->getPrimaryKey(), $models));
    }
}
