<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\AnalyticsQuery;
use app\services\AnalyticsService;

/**
 * API v1: Analytics
 *
 * GET /api/v1/analytics/summary
 * GET /api/v1/analytics/template-reliability
 * GET /api/v1/analytics/project-activity
 * GET /api/v1/analytics/user-activity
 * GET /api/v1/analytics/host-health
 * GET /api/v1/analytics/job-trend
 *
 * All endpoints accept: date_from, date_to, project_id, template_id,
 * user_id, runner_group_id, granularity, format (json|csv).
 */
class AnalyticsController extends BaseApiController
{
    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionSummary(): array
    {
        return $this->runReport('summary');
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionTemplateReliability(): array
    {
        return $this->runReport('templateReliability');
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionProjectActivity(): array
    {
        return $this->runReport('projectActivity');
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionUserActivity(): array
    {
        return $this->runReport('userActivity');
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionHostHealth(): array
    {
        return $this->runReport('hostHealth');
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionJobTrend(): array
    {
        return $this->runReport('jobTrend');
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    private function runReport(string $method): array
    {
        $query = new AnalyticsQuery();
        $query->load((array)\Yii::$app->request->get(), '');
        $query->applyDefaults();

        if (!$query->validate()) {
            return $this->error($this->firstQueryError($query), 422);
        }

        /** @var AnalyticsService $service */
        $service = \Yii::$app->get('analyticsService');
        $data = $service->$method($query);

        $format = (string)\Yii::$app->request->get('format', 'json');
        if ($format === 'csv') {
            return $this->respondCsv($data, $method);
        }

        return $this->success([
            'filters' => $query->toArray(),
            'result' => $data,
        ]);
    }

    /**
     * @param array<int|string, mixed> $rows
     * @return array{data: mixed}
     */
    private function respondCsv(array $rows, string $report): array
    {
        $response = \Yii::$app->response;
        $response->format = \yii\web\Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set(
            'Content-Disposition',
            'attachment; filename="analytics-' . $report . '-' . date('Y-m-d') . '.csv"'
        );

        $output = fopen('php://temp', 'r+');
        if ($output === false) {
            $response->data = '';
            return ['data' => null];
        }

        // summary returns a single assoc array, wrap it
        if (!empty($rows) && !is_array(reset($rows))) {
            $rows = [$rows];
        }

        if (!empty($rows)) {
            $first = reset($rows);
            if (is_array($first)) {
                fputcsv($output, array_keys($first));
                foreach ($rows as $row) {
                    /** @var array<int|string, scalar|null> $row */
                    fputcsv($output, $row);
                }
            }
        }

        rewind($output);
        $response->data = (string)stream_get_contents($output);
        fclose($output);

        return ['data' => null];
    }

    private function firstQueryError(AnalyticsQuery $query): string
    {
        foreach ($query->getFirstErrors() as $error) {
            return $error;
        }
        return 'Validation failed.';
    }
}
