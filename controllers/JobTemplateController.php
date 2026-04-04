<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\AuditLog;
use app\models\Credential;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\JobTemplateNotification;
use app\models\NotificationTemplate;
use app\models\Project;
use app\models\RunnerGroup;
use app\services\JobLaunchService;
use app\services\LintService;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class JobTemplateController extends BaseController
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'], 'allow' => true, 'roles' => ['job-template.view']],
            ['actions' => ['create'], 'allow' => true, 'roles' => ['job-template.create']],
            ['actions' => ['update'], 'allow' => true, 'roles' => ['job-template.update']],
            ['actions' => ['delete'], 'allow' => true, 'roles' => ['job-template.delete']],
            ['actions' => ['launch'], 'allow' => true, 'roles' => ['job.launch']],
            ['actions' => ['generate-trigger-token',
                           'revoke-trigger-token'], 'allow' => true, 'roles' => ['job-template.update']],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    protected function verbRules(): array
    {
        return [
            'delete' => ['POST'],
            'launch' => ['POST', 'GET'],
            'generate-trigger-token' => ['POST'],
            'revoke-trigger-token' => ['POST'],
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

    public function actionCreate(?int $project_id = null, ?string $playbook = null): Response|string
    {
        $model = new JobTemplate();
        $model->verbosity = 0;
        $model->forks = 5;
        $model->timeout_minutes = 120;
        $model->become = false;
        $model->become_method = 'sudo';
        $model->become_user = 'root';
        if ($project_id !== null) {
            $model->project_id = $project_id;
        }
        if ($playbook !== null) {
            $model->playbook = $playbook;
        }
        if ($model->load((array)\Yii::$app->request->post())) {
            $model->created_by = (int)(\Yii::$app->user->id ?? 0);
            if ($model->save()) {
                $this->syncNotificationTemplates($model);
                \Yii::$app->get('auditService')->log(AuditLog::ACTION_TEMPLATE_CREATED, 'job_template', $model->id, null, ['name' => $model->name]);
                /** @var \app\services\LintService $lintService */
                $lintService = \Yii::$app->get('lintService');
                $lintService->runForTemplate($model);
                $this->session()->setFlash('success', "Template \"{$model->name}\" created.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }
        return $this->render('form', $this->formData($model));
    }

    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);
        if ($model->load((array)\Yii::$app->request->post()) && $model->save()) {
            $this->syncNotificationTemplates($model);
            \Yii::$app->get('auditService')->log(AuditLog::ACTION_TEMPLATE_UPDATED, 'job_template', $model->id, null, ['name' => $model->name]);
            /** @var \app\services\LintService $lintService */
            $lintService = \Yii::$app->get('lintService');
            $lintService->runForTemplate($model);
            $this->session()->setFlash('success', "Template \"{$model->name}\" updated.");
            return $this->redirect(['view', 'id' => $model->id]);
        }
        return $this->render('form', $this->formData($model));
    }

    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        $name = $model->name;
        $model->softDelete();
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_TEMPLATE_DELETED, 'job_template', $id, null, ['name' => $name]);
        $this->session()->setFlash('success', "Template \"{$name}\" deleted.");
        return $this->redirect(['index']);
    }

    public function actionLaunch(int $id = 0): Response|string
    {
        // Allow id to arrive as a POST body param (quick-launch from dashboard).
        if ($id === 0) {
            /** @var int|string $postedId */
            $postedId = \Yii::$app->request->post('id', 0);
            $id = (int)$postedId;
        }
        $template = $this->findModel($id);
        /** @var array<string, mixed> $overrides */
        $overrides = (array)\Yii::$app->request->post('overrides', []);
        /** @var array<string, mixed> $survey */
        $survey = (array)\Yii::$app->request->post('survey', []);
        if (!empty($survey)) {
            $overrides['survey'] = $survey;
        }

        if (\Yii::$app->request->isPost) {
            try {
                /** @var JobLaunchService $svc */
                $svc = \Yii::$app->get('jobLaunchService');
                $job = $svc->launch($template, (int)(\Yii::$app->user->id ?? 0), $overrides);
                $this->session()->setFlash('success', "Job #{$job->id} queued.");
                return $this->redirect(['/job/view', 'id' => $job->id]);
            } catch (\RuntimeException $e) {
                $this->session()->setFlash('danger', 'Launch failed: ' . $e->getMessage());
            }
        }
        return $this->render('launch', ['template' => $template]);
    }

    public function actionGenerateTriggerToken(int $id): Response
    {
        $model = $this->findModel($id);
        $model->generateTriggerToken();
        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_GENERATED,
            'job_template',
            $id,
            \Yii::$app->user->id,
            ['name' => $model->name]
        );
        $this->session()->setFlash('success', 'Trigger token generated. Copy it now — it will not be shown again.');
        // Flash the raw token once so the view can display it
        $this->session()->setFlash('trigger_token_raw', $model->trigger_token);
        return $this->redirect(['view', 'id' => $id]);
    }

    public function actionRevokeTriggerToken(int $id): Response
    {
        $model = $this->findModel($id);
        $model->revokeTriggerToken();
        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_REVOKED,
            'job_template',
            $id,
            \Yii::$app->user->id,
            ['name' => $model->name]
        );
        $this->session()->setFlash('success', 'Trigger token revoked. The /trigger endpoint is now disabled for this template.');
        return $this->redirect(['view', 'id' => $id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(JobTemplate $model): array
    {
        $selectedIds = [];
        if (!$model->isNewRecord) {
            $selectedIds = JobTemplateNotification::find()
                ->select('notification_template_id')
                ->where(['job_template_id' => $model->id])
                ->column();
        }

        return [
            'model' => $model,
            'projects' => Project::find()->orderBy('name')->all(),
            'inventories' => Inventory::find()->orderBy('name')->all(),
            'credentials' => Credential::find()->orderBy('name')->all(),
            'runnerGroups' => RunnerGroup::find()->orderBy('name')->all(),
            'notificationTemplates' => NotificationTemplate::find()->orderBy('name')->all(),
            'selectedNotificationIds' => $selectedIds,
        ];
    }

    private function syncNotificationTemplates(JobTemplate $model): void
    {
        /** @var int[] $ids */
        $ids = (array)\Yii::$app->request->post('notification_template_ids', []);
        $ids = array_map('intval', array_filter($ids));

        JobTemplateNotification::deleteAll(['job_template_id' => $model->id]);

        foreach ($ids as $ntId) {
            $link = new JobTemplateNotification();
            $link->job_template_id = $model->id;
            $link->notification_template_id = $ntId;
            $link->save(false);
        }
    }

    private function findModel(int $id): JobTemplate
    {
        /** @var JobTemplate|null $model */
        $model = JobTemplate::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Job template #{$id} not found.");
        }
        return $model;
    }
}
