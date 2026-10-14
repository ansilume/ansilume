<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\JobTemplateWarnings;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobArtifact;
use app\models\JobLog;
use app\models\JobSearchForm;
use app\models\JobTask;
use app\models\JobTemplate;
use app\models\RunnerGroup;
use app\models\User;
use app\services\ArtifactService;
use app\services\JobCancellationService;
use app\services\JobLaunchService;
use app\services\RelaunchRefusedException;
use app\services\RelaunchService;
use app\services\runstate\ApprovalRequestContexts;
use app\services\runstate\Viewer;
use app\services\WorkflowAccessChecker;
use app\controllers\traits\TeamScopingTrait;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class JobController extends BaseController
{
    use TeamScopingTrait;

    /**
     * The danger flash of a re-launch whose launch failed. sprintf():
     * JobLaunchService::failureReason(): what may be said about the error
     * (ErrorText: its message, but for a database error only its class,
     * SQLSTATE and driver error code), without the "Job launch failed: " of
     * JobLaunchService's own failures.
     */
    public const RELAUNCH_FAILED = 'Re-launch failed: %s';

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view', 'log-poll', 'download-artifact', 'artifact-content', 'download-all-artifacts'], 'allow' => true, 'roles' => ['job.view']],
            ['actions' => ['cancel'], 'allow' => true, 'roles' => ['job.cancel']],
            // A re-launch starts a job: it needs what launching needs, as the job page's button does.
            ['actions' => ['relaunch'], 'allow' => true, 'roles' => ['job.launch']],
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
        /** @var ApprovalRequestContexts $contexts */
        $contexts = \Yii::$app->get('approvalRequestContexts');
        return $this->render('view', [
            'job' => $job,
            'logs' => $logs,
            'tasks' => $tasks,
            'hostSummaries' => $hostSummaries,
            'artifacts' => $artifacts,
            'jobCredentials' => $this->credentialResolver()->describe($job->decodedRunnerPayload()),
            // The workflow run and step the job was created for, and the approval it waits or waited for.
            'context' => $contexts->forJob($job, Viewer::current()),
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

        // The cancel also closes the job's pending approval request. It
        // changes nothing when the job finished after it was loaded here.
        /** @var JobCancellationService $cancellation */
        $cancellation = \Yii::$app->get('jobCancellationService');
        if (!$cancellation->cancel($job, (int)\Yii::$app->user->id)) {
            $this->session()->setFlash('warning', JobCancellationService::notCanceledMessage($job));
            return $this->redirect(['view', 'id' => $id]);
        }

        $this->session()->setFlash('success', "Job #{$job->id} canceled.");
        return $this->redirect(['view', 'id' => $id]);
    }

    /**
     * Re-launch: a new job of the same template with the same launch
     * parameters as the original job, password answers included (see
     * RelaunchService). Only a finished job whose template still exists can
     * be re-launched, as the job page's button shows.
     */
    public function actionRelaunch(int $id): Response
    {
        $original = $this->findModel($id);
        $this->requireJobOperate($original);

        try {
            $job = (new RelaunchService())->relaunch($original, (int)\Yii::$app->user->id);
        } catch (RelaunchRefusedException $e) {
            $this->session()->setFlash('danger', $e->getMessage());
            return $this->redirect(['view', 'id' => $id]);
        } catch (\RuntimeException $e) {
            $this->session()->setFlash('danger', sprintf(self::RELAUNCH_FAILED, JobLaunchService::failureReason($e)));
            return $this->redirect(['view', 'id' => $id]);
        }

        $this->session()->setFlash('success', "Re-launched as Job #{$job->id}.");
        // A relaunch skips the launch page: its warnings show afterwards.
        $this->flashTemplateWarnings($original->jobTemplate);

        return $this->redirect(['view', 'id' => $job->id]);
    }

    private function flashTemplateWarnings(?JobTemplate $template): void
    {
        $warnings = $template === null ? [] : JobTemplateWarnings::forTemplate($template);
        if ($warnings !== []) {
            $this->session()->setFlash('warning', implode(' ', array_column($warnings, 'message')));
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

    /**
     * A job follows its template's project. A job without a template (the
     * placeholder of a workflow approval step, or the history of a purged
     * template) is not global: see WorkflowAccessChecker::canAccessJob().
     */
    private function requireJobView(Job $job): void
    {
        $userId = $this->currentUserId();
        if ($userId === null || !$this->workflowChecker()->canAccessJob($userId, $job, false)) {
            throw new ForbiddenHttpException('You do not have access to this resource.');
        }
    }

    private function requireJobOperate(Job $job): void
    {
        $userId = $this->currentUserId();
        if ($userId === null || !$this->workflowChecker()->canAccessJob($userId, $job, true)) {
            throw new ForbiddenHttpException('You do not have permission to modify this resource.');
        }
    }

    private function credentialResolver(): \app\services\JobCredentialResolver
    {
        /** @var \app\services\JobCredentialResolver $resolver */
        $resolver = \Yii::$app->get('jobCredentialResolver');

        return $resolver;
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
