<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\AuditLog;
use app\models\Job;
use app\models\JobArtifact;
use app\models\JobLog;
use app\models\JobSearchForm;
use app\models\JobTask;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\RunnerGroup;
use app\models\User;
use app\services\ArtifactService;
use app\services\JobLaunchService;
use app\services\NotificationDispatcher;
use app\services\notification\JobPayloadBuilder;
use app\controllers\traits\TeamScopingTrait;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class JobController extends BaseController
{
    use TeamScopingTrait;

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view', 'log-poll', 'download-artifact', 'artifact-content', 'download-all-artifacts'], 'allow' => true, 'roles' => ['job.view']],
            ['actions' => ['cancel', 'relaunch'], 'allow' => true, 'roles' => ['job.cancel']],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    protected function verbRules(): array
    {
        return ['cancel' => ['POST'], 'relaunch' => ['POST']];
    }

    public function actionIndex(): string
    {
        $checker = $this->checker();
        $userId = $this->currentUserId();

        $searchForm = new JobSearchForm();
        $searchForm->teamFilter = $checker->buildJobFilter($userId);
        $dataProvider = $searchForm->search(\Yii::$app->request->queryParams);

        $templateQuery = JobTemplate::find()->orderBy('name');
        $templateFilter = $checker->buildChildResourceFilter($userId, 'job_template.project_id');
        if ($templateFilter !== null) {
            $templateQuery->andWhere($templateFilter);
        }

        return $this->render('index', [
            'searchForm' => $searchForm,
            'dataProvider' => $dataProvider,
            'templates' => $templateQuery->all(),
            'runnerGroups' => RunnerGroup::find()->orderBy('name')->all(),
            'users' => User::find()->orderBy('username')->all(),
            'statusOptions' => array_combine(
                Job::statuses(),
                array_map(fn ($s) => Job::statusLabel($s), Job::statuses())
            ),
        ]);
    }

    public function actionView(int $id): string
    {
        $job = $this->findModel($id);
        $this->requireJobView($job);
        $logs = $job->getLogs()->all();
        $tasks = JobTask::find()->where(['job_id' => $job->id])->orderBy('sequence')->all();
        $hostSummaries = $job->getHostSummaries()->all();
        $artifacts = $job->getArtifacts()->all();
        return $this->render('view', [
            'job' => $job,
            'logs' => $logs,
            'tasks' => $tasks,
            'hostSummaries' => $hostSummaries,
            'artifacts' => $artifacts,
        ]);
    }

    public function actionLogPoll(int $id, int $after = -1): Response
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;
        $job = $this->findModel($id);
        $this->requireJobView($job);
        /** @var JobLog[] $chunks */
        $chunks = $job->getLogs()->andWhere(['>', 'sequence', $after])->all();

        return $this->asJson([
            'status' => $job->status,
            'chunks' => array_map(fn ($l) => [
                'sequence' => $l->sequence,
                'stream' => $l->stream,
                'content' => $l->content,
            ], $chunks),
            'finished' => $job->isFinished(),
            'execution_command' => $job->execution_command,
        ]);
    }

    public function actionCancel(int $id): Response
    {
        $job = $this->findModel($id);
        $this->requireJobOperate($job);
        if (!$job->isCancelable()) {
            $this->session()->setFlash('warning', "Job #{$job->id} cannot be canceled in status \"{$job->status}\".");
            return $this->redirect(['view', 'id' => $id]);
        }
        $job->status = Job::STATUS_CANCELED;
        $job->finished_at = time();
        $job->save(false);

        \Yii::$app->get('auditService')->log(AuditLog::ACTION_JOB_CANCELED, 'job', $job->id);

        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');
        $dispatcher->dispatch(
            NotificationTemplate::EVENT_JOB_CANCELED,
            JobPayloadBuilder::build($job)
        );
        $this->session()->setFlash('success', "Job #{$job->id} canceled.");
        return $this->redirect(['view', 'id' => $id]);
    }

    /**
     * Re-launch: create a new job from the same template with the same overrides
     * as the original job.
     */
    public function actionRelaunch(int $id): Response
    {
        $original = $this->findModel($id);
        $this->requireJobOperate($original);
        $template = $original->jobTemplate;

        if ($template === null) {
            $this->session()->setFlash('danger', 'Original template no longer exists.');
            return $this->redirect(['view', 'id' => $id]);
        }

        $overrides = [];
        if ($original->extra_vars) {
            $overrides['extra_vars'] = $original->extra_vars;
        }
        if ($original->limit) {
            $overrides['limit'] = $original->limit;
        }
        if ($original->verbosity !== null) {
            $overrides['verbosity'] = $original->verbosity;
        }
        if ($original->check_mode) {
            $overrides['check_mode'] = 1;
        }

        try {
            /** @var JobLaunchService $svc */
            $svc = \Yii::$app->get('jobLaunchService');
            $job = $svc->launch($template, (int)\Yii::$app->user->id, $overrides);
            $this->session()->setFlash('success', "Re-launched as Job #{$job->id}.");
            return $this->redirect(['view', 'id' => $job->id]);
        } catch (\RuntimeException $e) {
            $this->session()->setFlash('danger', 'Re-launch failed: ' . $e->getMessage());
            return $this->redirect(['view', 'id' => $id]);
        }
    }

    public function actionDownloadArtifact(int $id, int $artifact_id): Response
    {
        $job = $this->findModel($id);
        $this->requireJobView($job);

        /** @var JobArtifact|null $artifact */
        $artifact = JobArtifact::findOne(['id' => $artifact_id, 'job_id' => $id]);
        if ($artifact === null) {
            throw new NotFoundHttpException("Artifact not found.");
        }

        if (!file_exists($artifact->storage_path)) {
            throw new NotFoundHttpException("Artifact file no longer exists on disk.");
        }

        /** @var ArtifactService $svc */
        $svc = \Yii::$app->get('artifactService');
        $request = \Yii::$app->request;
        assert($request instanceof \yii\web\Request);
        $inlineRequested = $request->getQueryParam('inline') === '1';
        $isImage = $svc->isImageType($artifact->mime_type);
        $isFrame = $svc->isInlineFrameType($artifact->mime_type);
        $inline = $inlineRequested && ($isImage || $isFrame);

        $webResponse = \Yii::$app->response;
        assert($webResponse instanceof \yii\web\Response);

        // PDF + inline: serve with a hardened CSP so embedded JavaScript in the
        // document cannot escape the sandboxed <iframe> into the parent page.
        // The empty "sandbox" directive disables scripts, forms, top-navigation
        // and pointer-lock. X-Frame-Options keeps the response framable from
        // our own origin (the job-view page) but blocks cross-origin embedding.
        if ($inline && $isFrame) {
            $webResponse->headers->set(
                'Content-Security-Policy',
                "default-src 'none'; object-src 'self'; plugin-types application/pdf; sandbox;"
            );
            $webResponse->headers->set('X-Content-Type-Options', 'nosniff');
            $webResponse->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        return $webResponse->sendFile(
            $artifact->storage_path,
            $artifact->display_name,
            ['mimeType' => $artifact->mime_type, 'inline' => $inline]
        );
    }

    public function actionArtifactContent(int $id, int $artifact_id): Response
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;
        $job = $this->findModel($id);
        $this->requireJobView($job);

        /** @var JobArtifact|null $artifact */
        $artifact = JobArtifact::findOne(['id' => $artifact_id, 'job_id' => $id]);
        if ($artifact === null) {
            throw new NotFoundHttpException("Artifact not found.");
        }

        /** @var ArtifactService $svc */
        $svc = \Yii::$app->get('artifactService');
        if (!$svc->isPreviewable($artifact->mime_type)) {
            \Yii::$app->response->statusCode = 415;
            return $this->asJson(['error' => 'Artifact type is not previewable.']);
        }

        $content = $svc->getArtifactContent($artifact);
        if ($content === null) {
            throw new NotFoundHttpException("Artifact file could not be read.");
        }

        return $this->asJson([
            'content' => $content,
            'mime_type' => $artifact->mime_type,
        ]);
    }

    public function actionDownloadAllArtifacts(int $id): Response
    {
        $job = $this->findModel($id);
        $this->requireJobView($job);

        /** @var ArtifactService $svc */
        $svc = \Yii::$app->get('artifactService');
        $zipPath = $svc->createZipArchive($job);
        if ($zipPath === null) {
            throw new NotFoundHttpException("No artifacts to download.");
        }

        register_shutdown_function(static function () use ($zipPath): void {
            if (file_exists($zipPath)) {
                unlink($zipPath);
            }
        });

        $webResponse = \Yii::$app->response;
        assert($webResponse instanceof \yii\web\Response);
        return $webResponse->sendFile(
            $zipPath,
            "job-{$id}-artifacts.zip",
            ['mimeType' => 'application/zip', 'inline' => false]
        );
    }

    private function requireJobView(Job $job): void
    {
        $projectId = $job->jobTemplate->project_id ?? null;
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canViewChildResource($userId, $projectId)) {
            throw new ForbiddenHttpException('You do not have access to this resource.');
        }
    }

    private function requireJobOperate(Job $job): void
    {
        $projectId = $job->jobTemplate->project_id ?? null;
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canOperateChildResource($userId, $projectId)) {
            throw new ForbiddenHttpException('You do not have permission to modify this resource.');
        }
    }

    private function findModel(int $id): Job
    {
        /** @var Job|null $model */
        $model = Job::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Job #{$id} not found.");
        }
        return $model;
    }
}
