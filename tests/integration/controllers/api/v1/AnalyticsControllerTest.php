<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\AnalyticsController;
use app\models\ApiToken;
use app\models\ApprovalRequest;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\web\Response;

/**
 * Analytics API under team scoping.
 *
 * Regression: every report covered all teams' jobs, workflows and approval
 * requests.
 */
class AnalyticsControllerTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    public function testSummaryCountsOnlyWhatTheCallerMaySee(): void
    {
        $s = $this->scopeWithData();
        $this->authenticate($s['member']);

        $result = $this->controller()->actionSummary();

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(3, $result['data']['result']['total_jobs']);
        $this->assertArrayNotHasKey('scopeUserId', $result['data']['filters']);
    }

    public function testTemplateReliabilityLeavesOutOtherTeams(): void
    {
        $s = $this->scopeWithData();
        $this->authenticate($s['member']);

        $result = $this->controller()->actionTemplateReliability();

        $ids = array_column($result['data']['result'], 'template_id');
        $this->assertEqualsCanonicalizing([(int)$s['own']->id, (int)$s['viewed']->id, (int)$s['open']->id], $ids);
    }

    public function testWorkflowAndApprovalReportsAreScoped(): void
    {
        $s = $this->scopeWithData();
        $this->authenticate($s['member']);

        $workflows = $this->controller()->actionWorkflowSummary();
        $this->assertSame(1, $workflows['data']['result']['total'], 'the own workflow, not the foreign one');

        $approvals = $this->controller()->actionApprovalSummary();
        $this->assertSame(1, $approvals['data']['result']['total'], 'the own job\'s request, not the foreign one');
    }

    public function testTheRequestCannotChooseTheScopeUser(): void
    {
        $s = $this->scopeWithData();
        $this->authenticate($s['member']);
        $this->setQueryParams(['scopeUserId' => (string)$s['admin']->id, 'scope_user_id' => (string)$s['admin']->id]);

        $result = $this->controller()->actionSummary();

        $this->assertSame(3, $result['data']['result']['total_jobs']);
    }

    public function testCsvExportIsScoped(): void
    {
        $s = $this->scopeWithData();
        $this->authenticate($s['member']);
        $this->setQueryParams(['format' => 'csv']);

        $this->controller()->actionTemplateReliability();

        $csv = (string)\Yii::$app->response->data;
        $this->assertStringContainsString((string)$s['own']->name, $csv);
        $this->assertStringNotContainsString((string)$s['foreign']->name, $csv);
    }

    public function testAnAdminGetsTheUnfilteredReports(): void
    {
        $s = $this->scopeWithData();
        $this->authenticate($s['admin']);

        $this->assertSame(4, $this->controller()->actionSummary()['data']['result']['total_jobs']);
        $this->assertSame(2, $this->controller()->actionWorkflowSummary()['data']['result']['total']);
        $this->assertSame(2, $this->controller()->actionApprovalSummary()['data']['result']['total']);
    }

    /**
     * Regression: every CSV export of the API answered 500, with or without
     * rows. The action returned ['data' => null] after it had put the CSV
     * into the response, the application made that array the response's
     * data, and a raw response refuses an array ("Response content must not
     * be an array."). The other tests read the response's data before it
     * was sent, so none of them saw it.
     */
    public function testRegressionTheCsvExportOfAReportWithoutRowsAnswered500(): void
    {
        $this->authenticate($this->superadmin());
        $this->setQueryParams(['format' => 'csv', 'date_from' => '2001-01-01', 'date_to' => '2001-01-02']);

        $body = $this->sentBody($this->controller()->runAction('job-trend'));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame('text/csv; charset=UTF-8', \Yii::$app->response->headers->get('Content-Type'));
        $this->assertSame('', $body);
    }

    /**
     * A summary is one row: the export is its header row and that row.
     */
    public function testTheCsvExportOfASummaryIsItsHeaderRowAndOneRow(): void
    {
        $this->authenticate($this->superadmin());
        $this->setQueryParams(['format' => 'csv', 'date_from' => '2001-01-01', 'date_to' => '2001-01-02']);

        $body = $this->sentBody($this->controller()->runAction('approval-summary'));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(
            "total,approved,rejected,timed_out,canceled,pending,approval_rate,avg_decision_seconds\n0,0,0,0,0,0,0,0\n",
            $body
        );
    }

    /**
     * The CSV export quotes fields as RFC 4180 says (CsvText), each line
     * ending with a line feed: a project name with a backslash in front of
     * a quote reads back as it is, and a name without a backslash keeps the
     * bytes of 2.13.1.
     *
     * Regression: on PHP 8.4 and later every CSV export with a row answered
     * 500 ("fputcsv(): the $escape parameter must be provided as its default
     * value will change").
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function csvProjectNameProvider(): array
    {
        return [
            'a backslash and a backslash in front of a quote' => [
                'Deploy "prod" C:\x\"y',
                '"Deploy ""prod"" C:\x\""y"',
            ],
            'quotes without a backslash, as 2.13.1 wrote them' => ['Deploy "prod"', '"Deploy ""prod"""'],
        ];
    }

    /**
     * @dataProvider csvProjectNameProvider
     */
    public function testRegressionTheCsvExportAnswered500AndReadsBackEveryProjectName(
        string $name,
        string $written
    ): void {
        $owner = $this->superadmin();
        $project = $this->createProject((int)$owner->id);
        $project->name = $name;
        $project->save(false);
        $template = $this->createJobTemplate(
            (int)$project->id,
            (int)$this->createInventory((int)$owner->id)->id,
            (int)$this->createRunnerGroup((int)$owner->id)->id,
            (int)$owner->id
        );
        $this->createJob((int)$template->id, (int)$owner->id, Job::STATUS_SUCCEEDED);
        $this->authenticate($owner);
        $this->setQueryParams(['format' => 'csv', 'project_id' => (string)$project->id]);

        $body = $this->sentBody($this->controller()->runAction('project-activity'));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(
            'attachment; filename="analytics-projectActivity-' . date('Y-m-d') . '.csv"',
            \Yii::$app->response->headers->get('Content-Disposition')
        );
        $this->assertSame(
            "project_id,project_name,total,succeeded,failed\n" . $project->id . ',' . $written . ",1,1,0\n",
            $body
        );
        $row = explode("\n", $body)[1];
        $this->assertSame([(string)$project->id, $name, '1', '1', '0'], str_getcsv($row, ',', '"', ''));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * The body a request gets for the action's result $result. The test
     * application is a console application, so this does what
     * yii\web\Application::handleRequest() does with the result (a Response
     * is sent as it is, any other result but null becomes the data of the
     * response), then prepares the response as Response::send() does.
     */
    private function sentBody(mixed $result): string
    {
        $response = \Yii::$app->response;
        if ($result instanceof Response) {
            $response = $result;
        } elseif ($result !== null) {
            $response->data = $result;
        }
        (new \ReflectionMethod($response, 'prepare'))->invoke($response);

        return (string)$response->content;
    }

    private function superadmin(): User
    {
        $user = $this->createUser('analytics-api-csv');
        $user->is_superadmin = true;
        $user->save(false);

        return $user;
    }

    /**
     * teamScope() with one succeeded job per template, a run of a workflow
     * of "own" and of "foreign", and an approval request on the jobs of both.
     *
     * @return array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate}
     */
    private function scopeWithData(): array
    {
        $s = $this->teamScope();
        $adminId = (int)$s['admin']->id;
        $ruleId = (int)$this->createApprovalRule($adminId)->id;
        foreach (['own', 'viewed', 'foreign', 'open'] as $key) {
            $job = $this->createJob((int)$s[$key]->id, $adminId, Job::STATUS_SUCCEEDED);
            $job->started_at = time() - 60;
            $job->finished_at = time();
            $job->save(false);
            if ($key === 'own' || $key === 'foreign') {
                $request = new ApprovalRequest();
                $request->job_id = $job->id;
                $request->approval_rule_id = $ruleId;
                $request->status = ApprovalRequest::STATUS_APPROVED;
                $request->requested_at = time() - 30;
                $request->resolved_at = time();
                $request->save(false);
                $workflow = $this->createWorkflowWithJobSteps($adminId, (int)$s[$key]->id);
                $this->createWorkflowJob((int)$workflow->id, $adminId, WorkflowJob::STATUS_SUCCEEDED);
            }
        }
        return $s;
    }

    private function controller(): AnalyticsController
    {
        return new AnalyticsController('api/v1/analytics', \Yii::$app);
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'analytics-api');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $component */
        $component = \Yii::$app->user;
        $component->loginByAccessToken($raw);
    }
}
