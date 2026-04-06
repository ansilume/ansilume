<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\SiteController;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\Project;
use app\models\Schedule;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;

class SiteControllerActionTest extends WebControllerTestCase
{
    public function testIndexRendersAllDashboardData(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $result = $ctrl->actionIndex();

        $this->assertSame('rendered:index', $result);
        $p = $ctrl->capturedParams;

        $this->assertArrayHasKey('stats', $p);
        $this->assertArrayHasKey('jobs_today', $p['stats']);
        $this->assertArrayHasKey('jobs_today_failed', $p['stats']);
        $this->assertArrayHasKey('queued', $p['stats']);
        $this->assertArrayHasKey('running', $p['stats']);
        $this->assertArrayHasKey('pending_approvals', $p['stats']);

        $this->assertArrayHasKey('statusCounts', $p);
        $this->assertArrayHasKey('recentJobs', $p);
        $this->assertArrayHasKey('runningJobs', $p);
        $this->assertArrayHasKey('templates', $p);
        $this->assertArrayHasKey('workflowTemplates', $p);
        $this->assertArrayHasKey('onlineRunners', $p);
        $this->assertArrayHasKey('totalRunners', $p);
        $this->assertArrayHasKey('pendingApprovals', $p);
        $this->assertArrayHasKey('runningWorkflows', $p);
        $this->assertArrayHasKey('upcomingSchedules', $p);
        $this->assertArrayHasKey('failedJobs', $p);
        $this->assertArrayHasKey('syncErrors', $p);
        $this->assertArrayHasKey('hasSchedules', $p);
    }

    public function testIndexCountsJobsToday(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate(
            (int)$project->id,
            (int)$inventory->id,
            (int)$group->id,
            $user->id
        );

        $job = $this->createJob((int)$tpl->id, $user->id, Job::STATUS_SUCCEEDED);
        $job->created_at = time();
        $job->save(false);

        $ctrl = $this->makeController();
        $ctrl->actionIndex();
        $stats = $ctrl->capturedParams['stats'];

        $this->assertGreaterThanOrEqual(1, $stats['jobs_today']);
    }

    public function testIndexShowsPendingApprovals(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate(
            (int)$project->id,
            (int)$inventory->id,
            (int)$group->id,
            $user->id
        );
        $job = $this->createJob((int)$tpl->id, $user->id, Job::STATUS_PENDING_APPROVAL);

        $rule = new ApprovalRule();
        $rule->name = 'test-rule-' . uniqid('', true);
        $rule->approver_type = ApprovalRule::APPROVER_TYPE_ROLE;
        $rule->approver_config = '{"role":"admin"}';
        $rule->required_approvals = 1;
        $rule->timeout_minutes = 30;
        $rule->timeout_action = ApprovalRule::TIMEOUT_ACTION_REJECT;
        $rule->created_by = $user->id;
        $rule->created_at = time();
        $rule->updated_at = time();
        $rule->save(false);

        $ar = new ApprovalRequest();
        $ar->job_id = $job->id;
        $ar->approval_rule_id = $rule->id;
        $ar->status = ApprovalRequest::STATUS_PENDING;
        $ar->requested_at = time();
        $ar->save(false);

        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        $this->assertGreaterThanOrEqual(1, $ctrl->capturedParams['stats']['pending_approvals']);
        $this->assertNotEmpty($ctrl->capturedParams['pendingApprovals']);
    }

    public function testIndexShowsRunningWorkflows(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $wt = new WorkflowTemplate();
        $wt->name = 'test-wf-' . uniqid('', true);
        $wt->created_by = $user->id;
        $wt->created_at = time();
        $wt->updated_at = time();
        $wt->save(false);

        $wfJob = new WorkflowJob();
        $wfJob->workflow_template_id = $wt->id;
        $wfJob->launched_by = $user->id;
        $wfJob->status = WorkflowJob::STATUS_RUNNING;
        $wfJob->started_at = time();
        $wfJob->created_at = time();
        $wfJob->updated_at = time();
        $wfJob->save(false);

        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        $this->assertNotEmpty($ctrl->capturedParams['runningWorkflows']);
    }

    public function testIndexShowsUpcomingSchedules(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate(
            (int)$project->id,
            (int)$inventory->id,
            (int)$group->id,
            $user->id
        );

        $schedule = new Schedule();
        $schedule->name = 'sched-' . uniqid('', true);
        $schedule->job_template_id = $tpl->id;
        $schedule->cron_expression = '0 3 * * *';
        $schedule->enabled = true;
        $schedule->next_run_at = time() + 3600;
        $schedule->created_by = $user->id;
        $schedule->created_at = time();
        $schedule->updated_at = time();
        $schedule->save(false);

        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        $this->assertNotEmpty($ctrl->capturedParams['upcomingSchedules']);
        $this->assertTrue($ctrl->capturedParams['hasSchedules']);
    }

    public function testIndexShowsFailedJobs(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate(
            (int)$project->id,
            (int)$inventory->id,
            (int)$group->id,
            $user->id
        );

        $job = $this->createJob((int)$tpl->id, $user->id, Job::STATUS_FAILED);
        $job->finished_at = time();
        $job->save(false);

        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        $this->assertNotEmpty($ctrl->capturedParams['failedJobs']);
    }

    public function testIndexShowsSyncErrors(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $project = $this->createProject($user->id);
        $project->status = Project::STATUS_ERROR;
        $project->last_sync_error = 'Git clone failed';
        $project->save(false);

        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        $this->assertNotEmpty($ctrl->capturedParams['syncErrors']);
    }

    public function testIndexShowsWorkflowTemplatesForQuickLaunch(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $wt = new WorkflowTemplate();
        $wt->name = 'ql-wf-' . uniqid('', true);
        $wt->created_by = $user->id;
        $wt->created_at = time();
        $wt->updated_at = time();
        $wt->save(false);

        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        $this->assertNotEmpty($ctrl->capturedParams['workflowTemplates']);
    }

    public function testChartDataReturnsJson(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $ctrl = new SiteController('site', \Yii::$app);
        $result = $ctrl->actionChartData(7);

        $this->assertInstanceOf(\yii\web\Response::class, $result);
        /** @var array{labels: list<string>, jobs: array<string, mixed>, tasks: array<string, mixed>} $data */
        $data = $result->data;
        $this->assertArrayHasKey('labels', $data);
        $this->assertArrayHasKey('jobs', $data);
        $this->assertArrayHasKey('tasks', $data);
        $this->assertCount(7, $data['labels']);
    }

    public function testChartDataClampsDaysRange(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $ctrl = new SiteController('site', \Yii::$app);

        // Below minimum → clamped to 7
        $result = $ctrl->actionChartData(1);
        /** @var array{labels: list<string>} $data7 */
        $data7 = $result->data;
        $this->assertCount(7, $data7['labels']);

        // Above maximum → clamped to 365
        $result = $ctrl->actionChartData(999);
        /** @var array{labels: list<string>} $data365 */
        $data365 = $result->data;
        $this->assertCount(365, $data365['labels']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeController(): SiteController
    {
        return new class ('site', \Yii::$app) extends SiteController {
            public string $capturedView = '';
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                $this->capturedView = $view;
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }

            /** @param string|array<int|string, mixed> $url */
            public function redirect($url, $statusCode = 302): \yii\web\Response
            {
                $r = new \yii\web\Response();
                $r->content = 'redirected';
                return $r;
            }
        };
    }
}
