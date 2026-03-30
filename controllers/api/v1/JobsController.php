<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\AuditLog;
use app\models\Job;
use app\models\JobSearchForm;
use app\models\JobTemplate;
use app\services\JobLaunchService;
use yii\web\NotFoundHttpException;

/**
 * API v1: Jobs
 *
 * GET  /api/v1/jobs            — list (filterable)
 * GET  /api/v1/jobs/{id}       — detail
 * POST /api/v1/jobs            — launch (body: {"template_id": N, "extra_vars": {...}, "limit": "..."})
 * POST /api/v1/jobs/{id}/cancel
 */
class JobsController extends BaseApiController
{
    public $enableCsrfValidation = false;

    /**
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    public function actionIndex(): array
    {
        $search = new JobSearchForm();
        $dp = $search->search(\Yii::$app->request->queryParams);

        $jobs = $dp->getModels();
        $total = $dp->totalCount;
        /** @var int $page */
        $page = \Yii::$app->request->get('page', 1);
        $per = 25;

        return $this->paginated(
            array_map(fn ($j) => $this->serializeJob($j), $jobs),
            (int)$total,
            $page,
            $per
        );
    }

    /**
     * @return array{data: mixed}
     */
    public function actionView(int $id): array
    {
        return $this->success($this->serializeJob($this->findJob($id)));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionCreate(): array
    {
        $body = (array)\Yii::$app->request->bodyParams;

        $templateId = (int)($body['template_id'] ?? 0);
        /** @var JobTemplate|null $template */
        $template = JobTemplate::findOne($templateId);

        if ($template === null) {
            return $this->error("Job template #{$templateId} not found.", 404);
        }

        if (!\Yii::$app->user->can('job.launch')) {
            return $this->error('Forbidden.', 403);
        }

        $overrides = [];
        if (!empty($body['extra_vars'])) {
            $overrides['extra_vars'] = is_array($body['extra_vars'])
                ? json_encode($body['extra_vars'])
                : $body['extra_vars'];
        }
        if (!empty($body['limit'])) {
            $overrides['limit'] = $body['limit'];
        }
        if (isset($body['verbosity'])) {
            $overrides['verbosity'] = (int)$body['verbosity'];
        }

        try {
            /** @var JobLaunchService $svc */
            $svc = \Yii::$app->get('jobLaunchService');
            $job = $svc->launch($template, (int)(\Yii::$app->user->id ?? 0), $overrides);
            return $this->success($this->serializeJob($job), 201);
        } catch (\RuntimeException $e) {
            return $this->error('Launch failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionCancel(int $id): array
    {
        $job = $this->findJob($id);

        if (!$job->isCancelable()) {
            return $this->error("Job #{$id} cannot be canceled in status '{$job->status}'.", 409);
        }

        if (!\Yii::$app->user->can('job.cancel')) {
            return $this->error('Forbidden.', 403);
        }

        $job->status = Job::STATUS_CANCELED;
        $job->finished_at = time();
        $job->save(false);
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_JOB_CANCELED, 'job', $job->id);

        return $this->success($this->serializeJob($job));
    }

    private function findJob(int $id): Job
    {
        /** @var Job|null $job */
        $job = Job::findOne($id);
        if ($job === null) {
            throw new NotFoundHttpException("Job #{$id} not found.");
        }
        return $job;
    }

    /**
     * @return array{id: int, status: string, job_template_id: int, template_name: string|null, launched_by: string|null, extra_vars: mixed, limit: string|null, verbosity: int|null, exit_code: int|null, queued_at: int|null, started_at: int|null, finished_at: int|null, created_at: int}
     */
    private function serializeJob(Job $job): array
    {
        return [
            'id' => $job->id,
            'status' => $job->status,
            'job_template_id' => $job->job_template_id,
            'template_name' => $job->jobTemplate->name ?? null,
            'launched_by' => $job->launcher->username ?? null,
            'extra_vars' => $job->extra_vars ? json_decode($job->extra_vars, true) : null,
            'limit' => $job->limit,
            'verbosity' => $job->verbosity,
            'exit_code' => $job->exit_code,
            'queued_at' => $job->queued_at,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
            'created_at' => $job->created_at,
        ];
    }
}
