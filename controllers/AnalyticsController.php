<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\AnalyticsQuery;
use app\models\JobTemplate;
use app\models\Project;
use app\models\User;
use app\services\AnalyticsService;
use yii\web\Response;

class AnalyticsController extends BaseController
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index'], 'allow' => true, 'roles' => ['analytics.view']],
            ['actions' => ['export'], 'allow' => true, 'roles' => ['analytics.export']],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    protected function verbRules(): array
    {
        return [];
    }

    public function actionIndex(): string
    {
        $query = new AnalyticsQuery();
        $query->load((array)\Yii::$app->request->get(), '');
        $query->applyDefaults();

        /** @var AnalyticsService $service */
        $service = \Yii::$app->get('analyticsService');

        $data = [];
        if ($query->validate()) {
            $data = [
                'summary' => $service->summary($query),
                'templateReliability' => $service->templateReliability($query),
                'projectActivity' => $service->projectActivity($query),
                'userActivity' => $service->userActivity($query),
                'hostHealth' => $service->hostHealth($query),
                'jobTrend' => $service->jobTrend($query),
                'workflowSummary' => $service->workflowSummary($query),
                'workflowActivity' => $service->workflowActivity($query),
                'approvalSummary' => $service->approvalSummary($query),
                'runnerActivity' => $service->runnerActivity($query),
            ];
        }

        return $this->render('index', [
            'query' => $query,
            'data' => $data,
            'projects' => $this->projectList(),
            'templates' => $this->templateList(),
            'users' => $this->userList(),
        ]);
    }

    public function actionExport(): Response
    {
        $query = new AnalyticsQuery();
        $query->load((array)\Yii::$app->request->get(), '');
        $query->applyDefaults();

        if (!$query->validate()) {
            \Yii::$app->session->setFlash('error', 'Invalid filter parameters.');
            return $this->redirect(['index']);
        }

        /** @var AnalyticsService $service */
        $service = \Yii::$app->get('analyticsService');
        $report = (string)\Yii::$app->request->get('report', 'summary');
        $format = (string)\Yii::$app->request->get('format', 'json');

        $rows = $this->getReportData($service, $query, $report);

        if ($format === 'csv') {
            return $this->exportCsv($rows, $report);
        }

        \Yii::$app->response->format = Response::FORMAT_JSON;
        return $this->asJson([
            'report' => $report,
            'filters' => $query->toArray(),
            'data' => $rows,
        ]);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function getReportData(AnalyticsService $service, AnalyticsQuery $query, string $report): array
    {
        return match ($report) {
            'template-reliability' => $service->templateReliability($query),
            'project-activity' => $service->projectActivity($query),
            'user-activity' => $service->userActivity($query),
            'host-health' => $service->hostHealth($query),
            'job-trend' => $service->jobTrend($query),
            'workflow-activity' => $service->workflowActivity($query),
            'workflow-summary' => [$service->workflowSummary($query)],
            'approval-summary' => [$service->approvalSummary($query)],
            'runner-activity' => $service->runnerActivity($query),
            default => [$service->summary($query)],
        };
    }

    /**
     * @param array<int|string, mixed> $rows
     */
    private function exportCsv(array $rows, string $report): Response
    {
        /** @var Response $response */
        $response = \Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set(
            'Content-Disposition',
            'attachment; filename="analytics-' . $report . '-' . date('Y-m-d') . '.csv"'
        );

        $output = fopen('php://temp', 'r+');
        if ($output === false) {
            $response->data = '';
            return $response;
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

        return $response;
    }

    /**
     * @return array<int, string>
     */
    private function projectList(): array
    {
        /** @var array<int, string> $list */
        $list = Project::find()
            ->select(['name', 'id'])
            ->orderBy(['name' => SORT_ASC])
            ->indexBy('id')
            ->column();
        return $list;
    }

    /**
     * @return array<int, string>
     */
    private function templateList(): array
    {
        /** @var array<int, string> $list */
        $list = JobTemplate::find()
            ->select(['name', 'id'])
            ->where(['deleted_at' => null])
            ->orderBy(['name' => SORT_ASC])
            ->indexBy('id')
            ->column();
        return $list;
    }

    /**
     * @return array<int, string>
     */
    private function userList(): array
    {
        /** @var array<int, string> $list */
        $list = User::find()
            ->select(['username', 'id'])
            ->orderBy(['username' => SORT_ASC])
            ->indexBy('id')
            ->column();
        return $list;
    }
}
