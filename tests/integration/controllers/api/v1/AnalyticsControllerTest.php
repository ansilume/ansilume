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

    // ── Helpers ──────────────────────────────────────────────────────────────

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
