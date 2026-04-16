<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\AuditLog;
use app\models\Credential;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\Project;
use app\models\RunnerGroup;
use app\services\JobLaunchService;
use app\services\LintService;
use app\controllers\traits\TeamScopingTrait;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class JobTemplateController extends BaseController
{
    use TeamScopingTrait;

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
        $query = JobTemplate::find()->with(['project', 'inventory', 'creator'])->orderBy(['id' => SORT_DESC]);

        $filter = $this->checker()->buildChildResourceFilter($this->currentUserId(), 'job_template.project_id');
        if ($filter !== null) {
            $query->andWhere($filter);
        }

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 20],
        ]);
        return $this->render('index', ['dataProvider' => $dataProvider]);
    }

    public function actionView(int $id): string
    {
        $model = $this->findModel($id);
        $this->requireChildView($model->project_id);
        return $this->render('view', ['model' => $model]);
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
            $this->requireChildOperate($model->project_id);
            $model->created_by = (int)(\Yii::$app->user->id ?? 0);
            if ($model->save()) {
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
        $this->requireChildOperate($model->project_id);
        if ($model->load((array)\Yii::$app->request->post()) && $model->save()) {
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
        $this->requireChildOperate($model->project_id);
        $name = $model->name;
        $model->softDelete();
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_TEMPLATE_DELETED, 'job_template', $id, null, ['name' => $name]);
        $this->session()->setFlash('success', "Template \"{$name}\" deleted.");
        return $this->redirect(['index']);
    }

    public function actionLaunch(): Response|string
    {
        $id = (int)(\Yii::$app->request->get('id') ?? \Yii::$app->request->post('id', 0));
        $template = $this->findModel($id);
        $this->requireChildOperate($template->project_id);
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
        $this->requireChildOperate($model->project_id);
        $rawToken = $model->generateTriggerToken();
        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_GENERATED,
            'job_template',
            $id,
            \Yii::$app->user->id,
            ['name' => $model->name]
        );
        $this->session()->setFlash('success', 'Trigger token generated. Copy it now — it will not be shown again.');
        // Flash the raw token once so the view can display it. The DB stores only the hash.
        $this->session()->setFlash('trigger_token_raw', $rawToken);
        return $this->redirect(['view', 'id' => $id]);
    }

    public function actionRevokeTriggerToken(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireChildOperate($model->project_id);
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
        $userId = $this->currentUserId();
        $checker = $this->checker();

        $projectQuery = Project::find()->orderBy('name');
        $projectFilter = $checker->buildProjectFilter($userId);
        if ($projectFilter !== null) {
            $projectQuery->andWhere($projectFilter);
        }

        $inventoryQuery = Inventory::find()->orderBy('name');
        $inventoryFilter = $checker->buildChildResourceFilter($userId, 'inventory.project_id');
        if ($inventoryFilter !== null) {
            $inventoryQuery->andWhere($inventoryFilter);
        }

        return [
            'model' => $model,
            'projects' => $projectQuery->all(),
            'inventories' => $inventoryQuery->all(),
            'credentials' => Credential::find()->orderBy('name')->all(),
            'runnerGroups' => RunnerGroup::find()->orderBy('name')->all(),
        ];
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
