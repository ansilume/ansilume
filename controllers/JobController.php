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
use app\models\RunnerGroup;
use app\models\User;
use app\services\JobLaunchService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class JobController extends BaseController
{
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view', 'log-poll', 'download-artifact'], 'allow' => true, 'roles' => ['job.view']],
            ['actions' => ['cancel', 'relaunch'],        'allow' => true, 'roles' => ['job.cancel']],
        ];
    }

    protected function verbRules(): array
    {
        return ['cancel' => ['POST'], 'relaunch' => ['POST']];
    }

    public function actionIndex(): string
    {
        $searchForm   = new JobSearchForm();
        $dataProvider = $searchForm->search(\Yii::$app->request->queryParams);

        return $this->render('index', [
            'searchForm'    => $searchForm,
            'dataProvider'  => $dataProvider,
            'templates'     => JobTemplate::find()->orderBy('name')->all(),
            'runnerGroups'  => RunnerGroup::find()->orderBy('name')->all(),
            'users'         => User::find()->orderBy('username')->all(),
            'statusOptions' => array_combine(
                Job::statuses(),
                array_map(fn($s) => Job::statusLabel($s), Job::statuses())
            ),
        ]);
    }

    public function actionView(int $id): string
    {
        $job           = $this->findModel($id);
        $logs          = $job->getLogs()->all();
        $tasks         = JobTask::find()->where(['job_id' => $job->id])->orderBy('sequence')->all();
        $hostSummaries = $job->getHostSummaries()->all();
        $artifacts     = $job->getArtifacts()->all();
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
        $job    = $this->findModel($id);
        /** @var JobLog[] $chunks */
        $chunks = $job->getLogs()->andWhere(['>', 'sequence', $after])->all();

        return $this->asJson([
            'status'   => $job->status,
            'chunks'   => array_map(fn($l) => [
                'sequence' => $l->sequence,
                'stream'   => $l->stream,
                'content'  => $l->content,
            ], $chunks),
            'finished' => $job->isFinished(),
        ]);
    }

    public function actionCancel(int $id): Response
    {
        $job = $this->findModel($id);
        if (!$job->isCancelable()) {
            $this->session()->setFlash('warning', "Job #{$job->id} cannot be canceled in status \"{$job->status}\".");
            return $this->redirect(['view', 'id' => $id]);
        }
        $job->status      = Job::STATUS_CANCELED;
        $job->finished_at = time();
        $job->save(false);

        \Yii::$app->get('auditService')->log(AuditLog::ACTION_JOB_CANCELED, 'job', $job->id);
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
        $this->findModel($id);

        $artifact = JobArtifact::findOne(['id' => $artifact_id, 'job_id' => $id]);
        if ($artifact === null) {
            throw new NotFoundHttpException("Artifact not found.");
        }

        if (!file_exists($artifact->storage_path)) {
            throw new NotFoundHttpException("Artifact file no longer exists on disk.");
        }

        return \Yii::$app->response->sendFile(
            $artifact->storage_path,
            $artifact->display_name,
            ['mimeType' => $artifact->mime_type, 'inline' => false]
        );
    }

    private function findModel(int $id): Job
    {
        $model = Job::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Job #{$id} not found.");
        }
        return $model;
    }
}
