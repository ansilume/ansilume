<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\components\CsvText;
use app\models\AnalyticsQuery;
use app\services\AnalyticsService;
use yii\web\Response;

/**
 * API v1: Analytics
 *
 * GET /api/v1/analytics/summary
 * GET /api/v1/analytics/template-reliability
 * GET /api/v1/analytics/project-activity
 * GET /api/v1/analytics/user-activity
 * GET /api/v1/analytics/host-health
 * GET /api/v1/analytics/job-trend
 * GET /api/v1/analytics/workflow-summary
 * GET /api/v1/analytics/workflow-activity
 * GET /api/v1/analytics/approval-summary
 * GET /api/v1/analytics/runner-activity
 *
 * All endpoints accept: date_from, date_to, project_id, template_id,
 * user_id, runner_group_id, granularity, format (json|csv).
 *
 * Team scoping applies: every report counts only the jobs, workflows and
 * approval requests the caller may see.
 */
class AnalyticsController extends BaseApiController
{
    protected function apiAccessRules(): array
    {
        return [
            'summary' => 'analytics.view',
            'template-reliability' => 'analytics.view',
            'project-activity' => 'analytics.view',
            'user-activity' => 'analytics.view',
            'host-health' => 'analytics.view',
            'job-trend' => 'analytics.view',
            'workflow-summary' => 'analytics.view',
            'workflow-activity' => 'analytics.view',
            'approval-summary' => 'analytics.view',
            'runner-activity' => 'analytics.view',
        ];
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}|Response
     */
    public function actionSummary(): array|Response
    {
        return $this->runReport('summary');
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}|Response
     */
    public function actionTemplateReliability(): array|Response
    {
        return $this->runReport('templateReliability');
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}|Response
     */
    public function actionProjectActivity(): array|Response
    {
        return $this->runReport('projectActivity');
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}|Response
     */
    public function actionUserActivity(): array|Response
    {
        return $this->runReport('userActivity');
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}|Response
     */
    public function actionHostHealth(): array|Response
    {
        return $this->runReport('hostHealth');
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}|Response
     */
    public function actionJobTrend(): array|Response
    {
        return $this->runReport('jobTrend');
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}|Response
     */
    public function actionWorkflowSummary(): array|Response
    {
        return $this->runReport('workflowSummary');
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}|Response
     */
    public function actionWorkflowActivity(): array|Response
    {
        return $this->runReport('workflowActivity');
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}|Response
     */
    public function actionApprovalSummary(): array|Response
    {
        return $this->runReport('approvalSummary');
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}|Response
     */
    public function actionRunnerActivity(): array|Response
    {
        return $this->runReport('runnerActivity');
    }

    /**
     * The report $method of AnalyticsService for the request's filters, as
     * JSON, or with format=csv as CSV.
     *
     * @return array{data: mixed}|array{error: array{message: string}}|Response
     */
    private function runReport(string $method): array|Response
    {
        $query = new AnalyticsQuery();
        $query->load((array)\Yii::$app->request->get(), '');
        $query->applyDefaults();
        // Never from the request: load() skips it, as it has no rule.
        $query->scopeUserId = \Yii::$app->user->isGuest ? null : (int)\Yii::$app->user->id;

        if (!$query->validate()) {
            return $this->error($this->firstQueryError($query), 422);
        }

        /** @var AnalyticsService $service */
        $service = \Yii::$app->get('analyticsService');
        $data = $service->$method($query);

        $format = (string)\Yii::$app->request->get('format', 'json');
        if ($format === 'csv') {
            // Same split as the web UI: viewing needs analytics.view,
            // exporting needs analytics.export.
            if (!$this->userCan('analytics.export')) {
                return $this->error('Forbidden.', 403);
            }
            return $this->respondCsv($data, $method);
        }

        return $this->success([
            'filters' => $query->toArray(),
            'result' => $data,
        ]);
    }

    /**
     * The rows as a CSV answer: the response itself, which the application
     * sends as it is. Any other result of the action would become the
     * response's data, and a raw response refuses an array.
     *
     * @param array<int|string, mixed> $rows
     */
    private function respondCsv(array $rows, string $report): Response
    {
        /** @var Response $response */
        $response = \Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set(
            'Content-Disposition',
            'attachment; filename="analytics-' . $report . '-' . date('Y-m-d') . '.csv"'
        );
        // A summary is a single row of values: wrap it.
        if ($rows !== [] && !is_array(reset($rows))) {
            $rows = [$rows];
        }
        /** @var array<int|string, array<int|string, scalar|null>> $rows */
        $response->data = CsvText::of($rows);

        return $response;
    }

    private function firstQueryError(AnalyticsQuery $query): string
    {
        foreach ($query->getFirstErrors() as $error) {
            return $error;
        }
        return 'Validation failed.';
    }
}
