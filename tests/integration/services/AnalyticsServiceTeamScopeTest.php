<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AnalyticsQuery;
use app\models\ApprovalRequest;
use app\models\Job;
use app\models\JobHostSummary;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\AnalyticsService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Team scoping of every analytics report.
 *
 * Regression: the reports aggregated all jobs, workflows and approval
 * requests, so a team member learned the job counts, template and project
 * names, hosts and approval outcomes of other teams.
 *
 * The scenario (see scenario()): the member's team operates "own" and views
 * "viewed", another team operates "foreign", "open" has no team. Jobs: own 2
 * succeeded, viewed 1 failed, foreign 1 failed + 2 succeeded (a recovery after
 * 300 s), open 1 succeeded. The member may see 4 of the 7 jobs.
 */
class AnalyticsServiceTeamScopeTest extends DbTestCase
{
    use TeamScopeFixtures;

    private User $member;
    private User $admin;
    /** @var array<string, JobTemplate> */
    private array $templates = [];
    /** @var array<string, int> */
    private array $runners = [];
    /** @var array<string, WorkflowTemplate> */
    private array $workflows = [];

    // ── job reports ──────────────────────────────────────────────────────────

    public function testSummaryCountsOnlyTheJobsTheUserMaySee(): void
    {
        $this->scenario();

        $member = $this->service()->summary($this->query($this->member));
        $this->assertSame(4, $member['total_jobs']);
        $this->assertSame(3, $member['succeeded']);
        $this->assertSame(1, $member['failed']);
        $this->assertSame(75.0, $member['success_rate']);

        $admin = $this->service()->summary($this->query($this->admin));
        $this->assertSame(7, $admin['total_jobs']);
        $this->assertSame(5, $admin['succeeded']);
        $this->assertSame(2, $admin['failed']);
    }

    public function testMttrOnlyLooksAtJobsTheUserMaySee(): void
    {
        $this->scenario();

        $this->assertSame(0.0, $this->service()->summary($this->query($this->member))['mttr_seconds']);
        $this->assertEqualsWithDelta(300.0, $this->service()->summary($this->query($this->admin))['mttr_seconds'], 1.0);
    }

    public function testTemplateReliabilityListsOnlyVisibleTemplates(): void
    {
        $this->scenario();

        $member = array_column($this->service()->templateReliability($this->query($this->member)), 'template_id');
        $this->assertEqualsCanonicalizing($this->templateIds('own', 'viewed', 'open'), $member);

        $admin = array_column($this->service()->templateReliability($this->query($this->admin)), 'template_id');
        $this->assertEqualsCanonicalizing($this->templateIds('own', 'viewed', 'foreign', 'open'), $admin);
    }

    public function testProjectActivityListsOnlyVisibleProjects(): void
    {
        $this->scenario();

        $member = array_column($this->service()->projectActivity($this->query($this->member)), 'project_id');
        $this->assertEqualsCanonicalizing($this->projectIds('own', 'viewed', 'open'), $member);

        $admin = array_column($this->service()->projectActivity($this->query($this->admin)), 'project_id');
        $this->assertEqualsCanonicalizing($this->projectIds('own', 'viewed', 'foreign', 'open'), $admin);
    }

    public function testUserActivityCountsOnlyVisibleJobs(): void
    {
        $this->scenario();

        $member = $this->service()->userActivity($this->query($this->member));
        $this->assertSame([[
            'user_id' => (int)$this->admin->id,
            'username' => $this->admin->username,
            'total' => 4,
            'succeeded' => 3,
            'failed' => 1,
            'success_rate' => 75.0,
        ]], $member);

        $admin = $this->service()->userActivity($this->query($this->admin));
        $this->assertSame(7, $admin[0]['total']);
    }

    public function testHostHealthListsOnlyHostsOfVisibleJobs(): void
    {
        $this->scenario();

        $member = array_column($this->service()->hostHealth($this->query($this->member)), 'host');
        $this->assertEqualsCanonicalizing(['own-host', 'viewed-host', 'open-host'], $member);

        $admin = array_column($this->service()->hostHealth($this->query($this->admin)), 'host');
        $this->assertEqualsCanonicalizing(['own-host', 'viewed-host', 'foreign-host', 'open-host'], $admin);
    }

    public function testJobTrendCountsOnlyVisibleJobs(): void
    {
        $this->scenario();

        $member = $this->service()->jobTrend($this->query($this->member));
        $this->assertSame(4, array_sum(array_column($member, 'total')));

        $admin = $this->service()->jobTrend($this->query($this->admin));
        $this->assertSame(7, array_sum(array_column($admin, 'total')));
    }

    public function testRunnerActivityListsEveryRunnerButCountsOnlyVisibleJobs(): void
    {
        $this->scenario();

        $member = $this->byRunner($this->service()->runnerActivity($this->query($this->member)));
        $this->assertSame(2, $member[$this->runners['own']]['total']);
        $this->assertSame(0, $member[$this->runners['foreign']]['total'], 'shared runner, other team\'s jobs');

        $admin = $this->byRunner($this->service()->runnerActivity($this->query($this->admin)));
        $this->assertSame(3, $admin[$this->runners['foreign']]['total']);
    }

    // ── workflow and approval reports ────────────────────────────────────────

    public function testWorkflowSummaryCountsOnlyWorkflowsWhoseEveryJobStepIsVisible(): void
    {
        $this->scenario();

        $member = $this->service()->workflowSummary($this->query($this->member));
        $this->assertSame(3, $member['total'], 'own: 2 runs, approval-only: 1 run');
        $this->assertSame(2, $member['succeeded']);
        $this->assertSame(1, $member['failed']);

        $this->assertSame(5, $this->service()->workflowSummary($this->query($this->admin))['total']);
    }

    public function testWorkflowActivityListsOnlyVisibleWorkflows(): void
    {
        $this->scenario();

        $member = array_column($this->service()->workflowActivity($this->query($this->member)), 'template_id');
        $this->assertEqualsCanonicalizing($this->workflowIds('own', 'open'), $member);

        $admin = array_column($this->service()->workflowActivity($this->query($this->admin)), 'template_id');
        $this->assertEqualsCanonicalizing($this->workflowIds('own', 'foreign', 'mixed', 'open'), $admin);
    }

    public function testApprovalSummaryCountsOnlyVisibleRequests(): void
    {
        $this->scenario();

        $member = $this->service()->approvalSummary($this->query($this->member));
        $this->assertSame(3, $member['total'], 'own job, open job, approval step of the own workflow');
        $this->assertSame(2, $member['approved']);
        $this->assertSame(0, $member['rejected']);
        $this->assertSame(1, $member['pending']);

        $admin = $this->service()->approvalSummary($this->query($this->admin));
        $this->assertSame(5, $admin['total']);
        $this->assertSame(3, $admin['approved']);
        $this->assertSame(1, $admin['rejected']);
    }

    // ── filters, fail-closed, unrestricted installs ──────────────────────────

    public function testAFilterOnAnotherTeamsProjectFindsNothing(): void
    {
        $this->scenario();

        $query = $this->query($this->member);
        $query->project_id = (int)$this->templates['foreign']->project_id;
        $this->assertSame(0, $this->service()->summary($query)['total_jobs']);

        $query = $this->query($this->member);
        $query->template_id = (int)$this->templates['foreign']->id;
        $this->assertSame([], $this->service()->templateReliability($query));

        $query = $this->query($this->admin);
        $query->project_id = (int)$this->templates['foreign']->project_id;
        $this->assertSame(3, $this->service()->summary($query)['total_jobs']);
    }

    /**
     * The scope's placeholders join the named ones of the other filters.
     */
    public function testEveryFilterCombinesWithTheScope(): void
    {
        $this->scenario();

        $query = $this->query($this->member);
        $query->project_id = (int)$this->templates['own']->project_id;
        $query->template_id = (int)$this->templates['own']->id;
        $query->user_id = (int)$this->admin->id;
        $query->runner_group_id = (int)$this->templates['own']->runner_group_id;

        $this->assertSame(2, $this->service()->summary($query)['total_jobs']);
        $this->assertSame(2, array_sum(array_column($this->service()->jobTrend($query), 'total')));
        // Workflow reports filter by user and date only: own 2 runs, approval-only 1.
        $this->assertSame(3, $this->service()->workflowSummary($query)['total']);
    }

    public function testWithoutAScopeUserNothingIsCounted(): void
    {
        $this->scenario();
        $query = $this->query(null);

        $this->assertSame(0, $this->service()->summary($query)['total_jobs']);
        $this->assertSame([], $this->service()->templateReliability($query));
        $this->assertSame([], $this->service()->projectActivity($query));
        $this->assertSame([], $this->service()->userActivity($query));
        $this->assertSame([], $this->service()->hostHealth($query));
        $this->assertSame([], $this->service()->jobTrend($query));
        $this->assertSame(0, $this->service()->workflowSummary($query)['total']);
        $this->assertSame([], $this->service()->workflowActivity($query));
        $this->assertSame(0, $this->service()->approvalSummary($query)['total']);
        $this->assertSame([0], array_unique(array_column($this->service()->runnerActivity($query), 'total')));
    }

    public function testWithoutTeamRestrictionsAUserWithoutRolesSeesEverything(): void
    {
        $owner = $this->createUser('analytics-open-owner');
        $user = $this->createUser('analytics-open-user');
        $group = $this->createRunnerGroup((int)$owner->id);
        $inventory = $this->createInventory((int)$owner->id);
        foreach ([1, 2] as $ignored) {
            $template = $this->createJobTemplate(
                (int)$this->createProject((int)$owner->id)->id,
                (int)$inventory->id,
                (int)$group->id,
                (int)$owner->id
            );
            $this->finishedJob($template, (int)$owner->id, Job::STATUS_SUCCEEDED, 60, 30);
        }
        $wt = $this->createWorkflowWithJobSteps((int)$owner->id, (int)$template->id);
        $this->createWorkflowJob((int)$wt->id, (int)$owner->id, WorkflowJob::STATUS_SUCCEEDED);

        $this->assertSame(2, $this->service()->summary($this->query($user))['total_jobs']);
        $this->assertSame(1, $this->service()->workflowSummary($this->query($user))['total']);
    }

    // ── Scenario ─────────────────────────────────────────────────────────────

    private function scenario(): void
    {
        $s = $this->teamScope();
        $this->member = $s['member'];
        $this->admin = $s['admin'];
        foreach (['own', 'viewed', 'foreign', 'open'] as $key) {
            $this->templates[$key] = $s[$key];
        }
        $adminId = (int)$this->admin->id;
        $group = (int)$s['own']->runner_group_id;
        $this->runners = [
            'own' => (int)$this->createRunner($group, $adminId)->id,
            'foreign' => (int)$this->createRunner($group, $adminId)->id,
        ];

        $own1 = $this->finishedJob($s['own'], $adminId, Job::STATUS_SUCCEEDED, 120, 90, $this->runners['own'], 'own-host');
        $this->finishedJob($s['own'], $adminId, Job::STATUS_SUCCEEDED, 80, 50, $this->runners['own']);
        $this->finishedJob($s['viewed'], $adminId, Job::STATUS_FAILED, 1000, 900, null, 'viewed-host');
        $foreign1 = $this->finishedJob($s['foreign'], $adminId, Job::STATUS_FAILED, 900, 600, $this->runners['foreign'], 'foreign-host');
        $this->finishedJob($s['foreign'], $adminId, Job::STATUS_SUCCEEDED, 300, 240, $this->runners['foreign']);
        $this->finishedJob($s['foreign'], $adminId, Job::STATUS_SUCCEEDED, 200, 100, $this->runners['foreign']);
        $open = $this->finishedJob($s['open'], $adminId, Job::STATUS_SUCCEEDED, 60, 30, null, 'open-host');

        $this->workflows = [
            'own' => $this->createWorkflowWithJobSteps($adminId, (int)$s['own']->id),
            'foreign' => $this->createWorkflowWithJobSteps($adminId, (int)$s['foreign']->id),
            'mixed' => $this->createWorkflowWithJobSteps($adminId, (int)$s['own']->id, (int)$s['foreign']->id),
            'open' => $this->createWorkflowTemplate($adminId),
        ];
        $ownRun = $this->finishedWorkflowJob('own', WorkflowJob::STATUS_SUCCEEDED);
        $this->finishedWorkflowJob('own', WorkflowJob::STATUS_FAILED);
        $foreignRun = $this->finishedWorkflowJob('foreign', WorkflowJob::STATUS_SUCCEEDED);
        $this->finishedWorkflowJob('mixed', WorkflowJob::STATUS_SUCCEEDED);
        $this->finishedWorkflowJob('open', WorkflowJob::STATUS_SUCCEEDED);

        $ruleId = (int)$this->createApprovalRule($adminId)->id;
        $this->approvalRequest((int)$own1->id, $ruleId, ApprovalRequest::STATUS_APPROVED);
        $this->approvalRequest((int)$foreign1->id, $ruleId, ApprovalRequest::STATUS_REJECTED);
        $this->approvalRequest((int)$open->id, $ruleId, ApprovalRequest::STATUS_PENDING);
        $this->approvalRequest($this->placeholderJob($ownRun, $ruleId), $ruleId, ApprovalRequest::STATUS_APPROVED);
        $this->approvalRequest($this->placeholderJob($foreignRun, $ruleId), $ruleId, ApprovalRequest::STATUS_APPROVED);
    }

    private function finishedJob(
        JobTemplate $template,
        int $launchedBy,
        string $status,
        int $startedAgo,
        int $finishedAgo,
        ?int $runnerId = null,
        ?string $host = null
    ): Job {
        $job = $this->createJob((int)$template->id, $launchedBy, $status);
        $job->started_at = time() - $startedAgo;
        $job->finished_at = time() - $finishedAgo;
        $job->runner_id = $runnerId;
        $job->save(false);
        if ($host !== null) {
            $summary = new JobHostSummary();
            $summary->job_id = $job->id;
            $summary->host = $host;
            $summary->ok = 3;
            $summary->changed = 1;
            $summary->failed = $status === Job::STATUS_FAILED ? 1 : 0;
            $summary->skipped = 0;
            $summary->unreachable = 0;
            $summary->rescued = 0;
            $summary->created_at = time();
            $summary->save(false);
        }
        return $job;
    }

    private function finishedWorkflowJob(string $workflow, string $status): WorkflowJob
    {
        $run = $this->createWorkflowJob((int)$this->workflows[$workflow]->id, (int)$this->admin->id, $status);
        $run->finished_at = time();
        $run->save(false);
        return $run;
    }

    /**
     * The placeholder job of an approval step of $run's workflow, created
     * outside the reports' date window so that it adds no job.
     */
    private function placeholderJob(WorkflowJob $run, int $ruleId): int
    {
        $step = $this->createWorkflowStep((int)$run->workflow_template_id, 8, WorkflowStep::TYPE_APPROVAL, null, $ruleId);
        $job = new Job();
        $job->job_template_id = null;
        $job->launched_by = $run->launched_by;
        $job->status = Job::STATUS_SUCCEEDED;
        $job->timeout_minutes = 0;
        $job->has_changes = 0;
        $job->save(false);
        // After the insert: TimestampBehavior sets created_at on save.
        $job->updateAttributes(['created_at' => time() - 40 * 86400]);

        $wjs = new WorkflowJobStep();
        $wjs->workflow_job_id = $run->id;
        $wjs->workflow_step_id = $step->id;
        $wjs->job_id = $job->id;
        $wjs->status = WorkflowJobStep::STATUS_SUCCEEDED;
        $wjs->created_at = time();
        $wjs->updated_at = time();
        $wjs->save(false);

        return (int)$job->id;
    }

    private function approvalRequest(int $jobId, int $ruleId, string $status): void
    {
        $request = new ApprovalRequest();
        $request->job_id = $jobId;
        $request->approval_rule_id = $ruleId;
        $request->status = $status;
        $request->requested_at = time() - 60;
        $request->resolved_at = $status === ApprovalRequest::STATUS_PENDING ? null : time();
        $request->save(false);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function service(): AnalyticsService
    {
        /** @var AnalyticsService $service */
        $service = \Yii::$app->get('analyticsService');
        return $service;
    }

    private function query(?User $user): AnalyticsQuery
    {
        $query = new AnalyticsQuery();
        $query->date_from = date('Y-m-d', strtotime('-1 day'));
        $query->date_to = date('Y-m-d', strtotime('+1 day'));
        $query->scopeUserId = $user === null ? null : (int)$user->id;
        return $query;
    }

    /**
     * @return list<int>
     */
    private function templateIds(string ...$keys): array
    {
        return array_map(fn (string $key): int => (int)$this->templates[$key]->id, $keys);
    }

    /**
     * @return list<int>
     */
    private function projectIds(string ...$keys): array
    {
        return array_map(fn (string $key): int => (int)$this->templates[$key]->project_id, $keys);
    }

    /**
     * @return list<int>
     */
    private function workflowIds(string ...$keys): array
    {
        return array_map(fn (string $key): int => (int)$this->workflows[$key]->id, $keys);
    }

    /**
     * @param array<int, array{runner_id: int, total: int}> $rows
     * @return array<int, array{runner_id: int, total: int}>
     */
    private function byRunner(array $rows): array
    {
        $byRunner = [];
        foreach ($rows as $row) {
            $byRunner[$row['runner_id']] = $row;
        }
        return $byRunner;
    }
}
