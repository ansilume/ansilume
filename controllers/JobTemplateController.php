<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\Credential;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\Project;
use app\services\JobLaunchService;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class JobTemplateController extends BaseController
{
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'],   'allow' => true, 'roles' => ['job-template.view']],
            ['actions' => ['create'],           'allow' => true, 'roles' => ['job-template.create']],
            ['actions' => ['update'],           'allow' => true, 'roles' => ['job-template.update']],
            ['actions' => ['delete'],           'allow' => true, 'roles' => ['job-template.delete']],
            ['actions' => ['launch'],                  'allow' => true, 'roles' => ['job.launch']],
            ['actions' => ['generate-trigger-token',
                           'revoke-trigger-token'],    'allow' => true, 'roles' => ['job-template.update']],
        ];
    }

    protected function verbRules(): array
    {
        return [
            'delete'                 => ['POST'],
            'launch'                 => ['POST', 'GET'],
            'generate-trigger-token' => ['POST'],
            'revoke-trigger-token'   => ['POST'],
        ];
    }

    public function actionIndex(): string
    {
        $dataProvider = new ActiveDataProvider([
            'query' => JobTemplate::find()->with(['project', 'inventory', 'creator'])->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 20],
        ]);
        return $this->render('index', ['dataProvider' => $dataProvider]);
    }

    public function actionView(int $id): string
    {
        return $this->render('view', ['model' => $this->findModel($id)]);
    }

    public function actionCreate(?int $project_id = null): Response|string
    {
        $model = new JobTemplate();
        $model->verbosity     = 0;
        $model->forks         = 5;
        $model->become        = false;
        $model->become_method = 'sudo';
        $model->become_user   = 'root';
        if ($project_id !== null) {
            $model->project_id = $project_id;
        }
        if ($model->load(\Yii::$app->request->post())) {
            $model->created_by = \Yii::$app->user->id;
            if ($model->save()) {
                \Yii::$app->session->setFlash('success', "Template "{$model->name}" created.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }
        return $this->render('form', $this->formData($model));
    }

    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);
        if ($model->load(\Yii::$app->request->post()) && $model->save()) {
            \Yii::$app->session->setFlash('success', "Template "{$model->name}" updated.");
            return $this->redirect(['view', 'id' => $model->id]);
        }
        return $this->render('form', $this->formData($model));
    }

    public function actionDelete(int $id): Response
    {
        $name = $this->findModel($id)->name;
        $this->findModel($id)->delete();
        \Yii::$app->session->setFlash('success', "Template "{$name}" deleted.");
        return $this->redirect(['index']);
    }

    public function actionLaunch(int $id): Response|string
    {
        $template  = $this->findModel($id);
        $overrides = \Yii::$app->request->post('overrides', []);

        if (\Yii::$app->request->isPost) {
            try {
                /** @var JobLaunchService $svc */
                $svc = \Yii::$app->get('jobLaunchService');
                $job = $svc->launch($template, (int)\Yii::$app->user->id, $overrides);
                \Yii::$app->session->setFlash('success', "Job #{$job->id} queued.");
                return $this->redirect(['/job/view', 'id' => $job->id]);
            } catch (\RuntimeException $e) {
                \Yii::$app->session->setFlash('danger', 'Launch failed: ' . $e->getMessage());
            }
        }
        return $this->render('launch', ['template' => $template]);
    }

    public function actionGenerateTriggerToken(int $id): Response
    {
        $model = $this->findModel($id);
        $model->generateTriggerToken();
        \Yii::$app->get('auditService')->log(
            'job-template.trigger-token.generated', 'job_template', $id,
            \Yii::$app->user->id,
            ['name' => $model->name]
        );
        \Yii::$app->session->setFlash('success', 'Trigger token generated. Copy it now — it will not be shown again.');
        // Flash the raw token once so the view can display it
        \Yii::$app->session->setFlash('trigger_token_raw', $model->trigger_token);
        return $this->redirect(['view', 'id' => $id]);
    }

    public function actionRevokeTriggerToken(int $id): Response
    {
        $model = $this->findModel($id);
        $model->revokeTriggerToken();
        \Yii::$app->get('auditService')->log(
            'job-template.trigger-token.revoked', 'job_template', $id,
            \Yii::$app->user->id,
            ['name' => $model->name]
        );
        \Yii::$app->session->setFlash('success', 'Trigger token revoked. The /trigger endpoint is now disabled for this template.');
        return $this->redirect(['view', 'id' => $id]);
    }

    private function formData(JobTemplate $model): array
    {
        return [
            'model'       => $model,
            'projects'    => Project::find()->orderBy('name')->all(),
            'inventories' => Inventory::find()->orderBy('name')->all(),
            'credentials' => Credential::find()->orderBy('name')->all(),
        ];
    }

    private function findModel(int $id): JobTemplate
    {
        $model = JobTemplate::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Job template #{$id} not found.");
        }
        return $model;
    }
}
