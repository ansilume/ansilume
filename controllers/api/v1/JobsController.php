<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\AuditLog;
use app\models\Job;
use app\models\JobArtifact;
use app\models\JobSearchForm;
use app\models\JobTemplate;
use app\services\ArtifactService;
use app\services\JobLaunchService;
use app\controllers\api\v1\traits\ApiTeamScopingTrait;
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
    use ApiTeamScopingTrait;

    public $enableCsrfValidation = false;

    /**
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    public function actionIndex(): array
    {
        $search = new JobSearchForm();
        $search->teamFilter = $this->checker()->buildJobFilter($this->currentUserId());
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
    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionView(int $id): array
    {
        $job = $this->findJob($id);
        $projectId = $job->jobTemplate->project_id ?? null;
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canViewChildResource($userId, $projectId)) {
            return $this->error('Forbidden.', 403);
        }
        return $this->success($this->serializeJob($job));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionCreate(): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        $body = (array)\Yii::$app->request->bodyParams;

        $templateId = (int)($body['template_id'] ?? 0);
        /** @var JobTemplate|null $template */
        $template = JobTemplate::findOne($templateId);

        if ($template === null) {
            return $this->error("Job template #{$templateId} not found.", 404);
        }

        if (!$user->can('job.launch')) {
            return $this->error('Forbidden.', 403);
        }

        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canOperateChildResource($userId, $template->project_id)) {
            return $this->error('Forbidden.', 403);
        }

        $overrides = $this->buildOverrides($body);

        try {
            /** @var JobLaunchService $svc */
            $svc = \Yii::$app->get('jobLaunchService');
            $job = $svc->launch($template, (int)($user->id ?? 0), $overrides);
            return $this->success($this->serializeJob($job), 201);
        } catch (\RuntimeException $e) {
            \Yii::error('Job launch failed: ' . $e->getMessage(), __CLASS__);
            return $this->error('Launch failed.', 500);
        }
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionCancel(int $id): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        $job = $this->findJob($id);

        if (!$job->isCancelable()) {
            return $this->error("Job #{$id} cannot be canceled in status '{$job->status}'.", 409);
        }

        if (!$user->can('job.cancel')) {
            return $this->error('Forbidden.', 403);
        }

        $projectId = $job->jobTemplate->project_id ?? null;
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canOperateChildResource($userId, $projectId)) {
            return $this->error('Forbidden.', 403);
        }

        $job->status = Job::STATUS_CANCELED;
        $job->finished_at = time();
        $job->save(false);
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_JOB_CANCELED, 'job', $job->id);

        return $this->success($this->serializeJob($job));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionArtifacts(int $id): array
    {
        $job = $this->findJob($id);
        $projectId = $job->jobTemplate->project_id ?? null;
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canViewChildResource($userId, $projectId)) {
            return $this->error('Forbidden.', 403);
        }

        /** @var ArtifactService $svc */
        $svc = \Yii::$app->get('artifactService');
        $artifacts = $svc->getArtifacts($job);

        return $this->success(array_map(
            fn (JobArtifact $a) => $this->serializeArtifact($a, $svc),
            $artifacts
        ));
    }

    /**
     * @return \yii\web\Response|array{error: array{message: string}}
     */
    public function actionDownloadArtifact(int $id, int $artifact_id): \yii\web\Response|array
    {
        $job = $this->findJob($id);
        $projectId = $job->jobTemplate->project_id ?? null;
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canViewChildResource($userId, $projectId)) {
            return $this->error('Forbidden.', 403);
        }

        /** @var JobArtifact|null $artifact */
        $artifact = JobArtifact::findOne(['id' => $artifact_id, 'job_id' => $id]);
        if ($artifact === null) {
            return $this->error('Artifact not found.', 404);
        }

        if (!file_exists($artifact->storage_path)) {
            return $this->error('Artifact file no longer exists on disk.', 404);
        }

        $response = \Yii::$app->response;
        assert($response instanceof \yii\web\Response);
        return $response->sendFile(
            $artifact->storage_path,
            $artifact->display_name,
            ['mimeType' => $artifact->mime_type, 'inline' => false]
        );
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionArtifactContent(int $id, int $artifact_id): array
    {
        $job = $this->findJob($id);
        $projectId = $job->jobTemplate->project_id ?? null;
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canViewChildResource($userId, $projectId)) {
            return $this->error('Forbidden.', 403);
        }

        /** @var JobArtifact|null $artifact */
        $artifact = JobArtifact::findOne(['id' => $artifact_id, 'job_id' => $id]);
        if ($artifact === null) {
            return $this->error('Artifact not found.', 404);
        }

        /** @var ArtifactService $svc */
        $svc = \Yii::$app->get('artifactService');
        if (!$svc->isPreviewable($artifact->mime_type)) {
            return $this->error('Artifact type is not previewable.', 415);
        }

        $content = $svc->getArtifactContent($artifact);
        if ($content === null) {
            return $this->error('Artifact file could not be read.', 404);
        }

        return $this->success([
            'id' => $artifact->id,
            'display_name' => $artifact->display_name,
            'mime_type' => $artifact->mime_type,
            'content' => $content,
        ]);
    }

    /**
     * @return \yii\web\Response|array{error: array{message: string}}
     */
    public function actionDownloadAllArtifacts(int $id): \yii\web\Response|array
    {
        $job = $this->findJob($id);
        $projectId = $job->jobTemplate->project_id ?? null;
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canViewChildResource($userId, $projectId)) {
            return $this->error('Forbidden.', 403);
        }

        /** @var ArtifactService $svc */
        $svc = \Yii::$app->get('artifactService');
        $zipPath = $svc->createZipArchive($job);
        if ($zipPath === null) {
            return $this->error('No artifacts to download.', 404);
        }

        register_shutdown_function(static function () use ($zipPath): void {
            if (file_exists($zipPath)) {
                unlink($zipPath);
            }
        });

        $response = \Yii::$app->response;
        assert($response instanceof \yii\web\Response);
        return $response->sendFile(
            $zipPath,
            "job-{$id}-artifacts.zip",
            ['mimeType' => 'application/zip', 'inline' => false]
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function buildOverrides(array $body): array
    {
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
        if (!empty($body['check_mode'])) {
            $overrides['check_mode'] = 1;
        }
        return $overrides;
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
     * @return array{id: int, status: string, job_template_id: int|null, template_name: string|null, launched_by: string|null, extra_vars: mixed, limit: string|null, verbosity: int|null, check_mode: bool, exit_code: int|null, execution_command: string|null, artifact_count: int, queued_at: int|null, started_at: int|null, finished_at: int|null, created_at: int}
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
            'check_mode' => (bool)$job->check_mode,
            'exit_code' => $job->exit_code,
            'execution_command' => $job->execution_command,
            'artifact_count' => (int)$job->getArtifacts()->count(),
            'queued_at' => $job->queued_at,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
            'created_at' => $job->created_at,
        ];
    }

    /**
     * @return array{id: int, display_name: string, mime_type: string, size_bytes: int, previewable: bool, created_at: int}
     */
    private function serializeArtifact(JobArtifact $artifact, ArtifactService $svc): array
    {
        return [
            'id' => $artifact->id,
            'display_name' => $artifact->display_name,
            'mime_type' => $artifact->mime_type,
            'size_bytes' => $artifact->size_bytes,
            'previewable' => $svc->isPreviewable($artifact->mime_type),
            'created_at' => $artifact->created_at,
        ];
    }
}
