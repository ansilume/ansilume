<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\AnalyticsController;
use app\models\AnalyticsQuery;
use app\models\ApprovalRequest;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\services\ApprovalAnalytics;
use app\tests\integration\TeamScopeFixtures;
use yii\helpers\Html;
use yii\web\Response;

/**
 * Analytics web UI under team scoping.
 *
 * Regression: the reports, the CSV/JSON export and the project and template
 * filter lists covered every team.
 */
class AnalyticsControllerActionTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    public function testIndexReportsOnlyWhatTheUserMaySee(): void
    {
        $s = $this->scopeWithJobs();
        $this->loginAs($s['member']);

        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        $data = $ctrl->capturedParams['data'];
        $this->assertSame(3, $data['summary']['total_jobs']);
        $this->assertNotContains((int)$s['foreign']->id, array_column($data['templateReliability'], 'template_id'));
        $query = $ctrl->capturedParams['query'];
        $this->assertInstanceOf(AnalyticsQuery::class, $query);
        $this->assertSame((int)$s['member']->id, $query->scopeUserId);
    }

    public function testIndexOffersOnlyTheVisibleProjectsAndTemplates(): void
    {
        $s = $this->scopeWithJobs();
        $this->loginAs($s['member']);

        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        $projects = array_keys($ctrl->capturedParams['projects']);
        $templates = array_keys($ctrl->capturedParams['templates']);
        foreach (['own', 'viewed', 'open'] as $key) {
            $this->assertContains((int)$s[$key]->project_id, $projects);
            $this->assertContains((int)$s[$key]->id, $templates);
        }
        $this->assertNotContains((int)$s['foreign']->project_id, $projects);
        $this->assertNotContains((int)$s['foreign']->id, $templates);
    }

    public function testIndexReportsAndOffersEverythingToAnAdmin(): void
    {
        $s = $this->scopeWithJobs();
        $this->loginAs($s['admin']);

        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        $this->assertSame(4, $ctrl->capturedParams['data']['summary']['total_jobs']);
        $this->assertContains((int)$s['foreign']->project_id, array_keys($ctrl->capturedParams['projects']));
        $this->assertContains((int)$s['foreign']->id, array_keys($ctrl->capturedParams['templates']));
    }

    /**
     * The scope user has no validation rule, so a request cannot widen the
     * report to another user's view.
     */
    public function testTheRequestCannotChooseTheScopeUser(): void
    {
        $s = $this->scopeWithJobs();
        $this->loginAs($s['member']);
        $this->setQueryParams(['scopeUserId' => (string)$s['admin']->id, 'scope_user_id' => (string)$s['admin']->id]);

        $ctrl = $this->makeController();
        $ctrl->actionIndex();

        $this->assertSame(3, $ctrl->capturedParams['data']['summary']['total_jobs']);
    }

    public function testJsonExportIsScoped(): void
    {
        $s = $this->scopeWithJobs();
        $this->loginAs($s['member']);
        $this->setQueryParams(['report' => 'template-reliability']);

        $response = $this->makeController()->actionExport();

        $this->assertIsArray($response->data);
        $ids = array_column($response->data['data'], 'template_id');
        $this->assertContains((int)$s['own']->id, $ids);
        $this->assertNotContains((int)$s['foreign']->id, $ids);
    }

    /**
     * Regression: an export with invalid filters flashed its refusal with
     * the type "error", which the layout renders as the unstyled class
     * alert-error. It is a danger flash.
     */
    public function testRegressionAnInvalidExportFilterFlashedTheUnstyledErrorType(): void
    {
        $this->loginAs($this->createUser('analytics-invalid'));
        $this->setQueryParams(['report' => 'summary', 'granularity' => 'hourly']);

        $response = $this->makeController()->actionExport();

        $this->assertSame('redirected', $response->content);
        $this->assertSame(['danger' => 'Invalid filter parameters.'], \Yii::$app->session->getAllFlashes());
    }

    public function testCsvExportIsScoped(): void
    {
        $s = $this->scopeWithJobs();
        $this->loginAs($s['member']);
        $this->setQueryParams(['report' => 'template-reliability', 'format' => 'csv']);

        $response = $this->makeController()->actionExport();

        $csv = (string)$response->data;
        $this->assertStringContainsString((string)$s['own']->name, $csv);
        $this->assertStringNotContainsString((string)$s['foreign']->name, $csv);
    }

    /**
     * The approval summary export has a column for canceled requests, which
     * count apart from the decided ones.
     */
    public function testTheApprovalSummaryExportCountsCanceledRequests(): void
    {
        $s = $this->teamScope();
        $ruleId = (int)$this->createApprovalRule((int)$s['admin']->id)->id;
        foreach ([ApprovalRequest::STATUS_CANCELED, ApprovalRequest::STATUS_APPROVED] as $status) {
            $job = $this->createJob((int)$s['own']->id, (int)$s['admin']->id, Job::STATUS_CANCELED);
            $request = new ApprovalRequest();
            $request->job_id = $job->id;
            $request->approval_rule_id = $ruleId;
            $request->status = $status;
            $request->requested_at = time() - 60;
            $request->resolved_at = time();
            $request->save(false);
        }
        $this->loginAs($s['member']);
        $this->setQueryParams(['report' => 'approval-summary', 'format' => 'csv']);

        $response = $this->makeController()->actionExport();

        $lines = explode("\n", trim((string)$response->data));
        $this->assertSame('total,approved,rejected,timed_out,canceled,pending,approval_rate,avg_decision_seconds', $lines[0]);
        $this->assertStringStartsWith('2,1,0,0,1,0,100,', $lines[1]);
    }

    /**
     * The Approvals tab shows the canceled requests the user may see in a
     * card of their own, and the Avg Decision Time card says that canceled
     * requests are not counted: here 60 s and 120 s average to 90 s,
     * whatever the canceled requests took.
     *
     * Regression: the tab had no Canceled card, although the report counted
     * them apart (decision 10).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function approvalsTabProvider(): array
    {
        return [
            'a member sees their team\'s requests' => ['member', '1'],
            'an admin sees every request' => ['admin', '2'],
        ];
    }

    /**
     * @dataProvider approvalsTabProvider
     */
    public function testTheApprovalsTabShowsTheCanceledRequestsApart(string $viewer, string $canceled): void
    {
        $s = $this->teamScope();
        $ruleId = (int)$this->createApprovalRule((int)$s['admin']->id)->id;
        $requests = [
            ['own', ApprovalRequest::STATUS_APPROVED, 60],
            ['own', ApprovalRequest::STATUS_REJECTED, 120],
            ['own', ApprovalRequest::STATUS_CANCELED, 5000],
            ['foreign', ApprovalRequest::STATUS_CANCELED, 9000],
        ];
        $now = time();
        foreach ($requests as [$template, $status, $seconds]) {
            $job = $this->createJob((int)$s[$template]->id, (int)$s['admin']->id, Job::STATUS_CANCELED);
            $request = new ApprovalRequest();
            $request->job_id = $job->id;
            $request->approval_rule_id = $ruleId;
            $request->status = $status;
            $request->requested_at = $now - $seconds;
            $request->resolved_at = $now;
            $request->save(false);
        }
        $this->loginAs($s[$viewer]);

        $html = $this->renderedIndex();

        $this->assertStringContainsString(
            '<div class="text-muted small">Canceled</div>' . "\n"
            . '                        <div class="fs-4 fw-bold text-secondary" id="analytics-approvals-canceled">' . $canceled . '</div>',
            $html
        );
        $this->assertStringContainsString('<div class="fs-4 fw-bold" id="analytics-approvals-avg-decision">90s</div>', $html);
        $this->assertStringContainsString(
            '<div class="text-muted small" id="analytics-approvals-avg-decision-note">'
            . Html::encode(ApprovalAnalytics::AVG_DECISION_NOTE) . '</div>',
            $html
        );
        $this->assertSame('Canceled requests are not counted.', ApprovalAnalytics::AVG_DECISION_NOTE);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * The analytics page as its view renders it for the signed-in user.
     */
    private function renderedIndex(): string
    {
        $ctrl = $this->makeController();
        $ctrl->actionIndex();
        $previous = \Yii::$app->controller;
        // The view's links are routes relative to the active controller.
        \Yii::$app->controller = $ctrl;
        try {
            return \Yii::$app->view->renderFile('@app/views/analytics/index.php', $ctrl->capturedParams);
        } finally {
            \Yii::$app->controller = $previous;
        }
    }

    /**
     * teamScope() with one succeeded job per template: the member may see 3.
     *
     * @return array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate}
     */
    private function scopeWithJobs(): array
    {
        $s = $this->teamScope();
        foreach (['own', 'viewed', 'foreign', 'open'] as $key) {
            $job = $this->createJob((int)$s[$key]->id, (int)$s['admin']->id, Job::STATUS_SUCCEEDED);
            $job->started_at = time() - 60;
            $job->finished_at = time();
            $job->save(false);
        }
        return $s;
    }

    private function makeController(): AnalyticsController
    {
        return new class ('analytics', \Yii::$app) extends AnalyticsController {
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

            public function redirect($url, $statusCode = 302): Response
            {
                $r = new Response();
                $r->content = 'redirected';
                return $r;
            }
        };
    }
}
