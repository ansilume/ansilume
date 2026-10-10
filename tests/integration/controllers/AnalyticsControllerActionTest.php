<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\AnalyticsController;
use app\models\AnalyticsQuery;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\tests\integration\TeamScopeFixtures;
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

    // ── Helpers ──────────────────────────────────────────────────────────────

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
