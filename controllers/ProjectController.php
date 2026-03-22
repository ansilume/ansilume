<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\Credential;
use app\models\Project;
use app\services\AuditService;
use app\services\ProjectAccessChecker;
use app\services\ProjectService;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ProjectController extends BaseController
{
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'],   'allow' => true, 'roles' => ['project.view']],
            ['actions' => ['create'],           'allow' => true, 'roles' => ['project.create']],
            ['actions' => ['update', 'sync'],   'allow' => true, 'roles' => ['project.update']],
            ['actions' => ['delete'],           'allow' => true, 'roles' => ['project.delete']],
        ];
    }

    protected function verbRules(): array
    {
        return ['delete' => ['POST'], 'sync' => ['POST']];
    }

    public function actionIndex(): string
    {
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');
        $query   = Project::find()->with('creator')->orderBy(['id' => SORT_DESC]);

        $filter = $checker->buildProjectFilter(\Yii::$app->user->id);
        if ($filter !== null) {
            $query->andWhere($filter);
        }

        $dataProvider = new ActiveDataProvider([
            'query'      => $query,
            'pagination' => ['pageSize' => 20],
        ]);
        return $this->render('index', ['dataProvider' => $dataProvider]);
    }

    public function actionView(int $id): string
    {
        $model = $this->findModel($id);
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');
        if (!$checker->canView(\Yii::$app->user->id, $model->id)) {
            throw new \yii\web\ForbiddenHttpException('You do not have access to this project.');
        }
        return $this->render('view', ['model' => $model]);
    }

    public function actionCreate(): Response|string
    {
        $model = new Project();
        $model->scm_type   = Project::SCM_TYPE_GIT;
        $model->scm_branch = 'main';
        $model->status     = Project::STATUS_NEW;

        if ($model->load(\Yii::$app->request->post())) {
            $model->created_by = \Yii::$app->user->id;
            if ($model->save()) {
                \Yii::$app->get('auditService')->log('project.created', 'project', $model->id, null, ['name' => $model->name]);
                \Yii::$app->session->setFlash('success', "Project \"{$model->name}\" created.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }
        return $this->render('form', ['model' => $model, 'sshCredentials' => $this->sshCredentials()]);
    }

    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);
        $this->requireAccess($model);
        if ($model->load(\Yii::$app->request->post()) && $model->save()) {
            \Yii::$app->get('auditService')->log('project.updated', 'project', $model->id, null, ['name' => $model->name]);
            \Yii::$app->session->setFlash('success', "Project \"{$model->name}\" updated.");
            return $this->redirect(['view', 'id' => $model->id]);
        }
        return $this->render('form', ['model' => $model, 'sshCredentials' => $this->sshCredentials()]);
    }

    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireAccess($model);

        $templateCount = $model->getJobTemplates()->count();
        if ($templateCount > 0) {
            \Yii::$app->session->setFlash('danger', "Cannot delete \"{$model->name}\": {$templateCount} job template(s) still reference this project. Remove or reassign them first.");
            return $this->redirect(['view', 'id' => $id]);
        }

        $name = $model->name;
        $model->delete();
        \Yii::$app->get('auditService')->log('project.deleted', 'project', $id, null, ['name' => $name]);
        \Yii::$app->session->setFlash('success', "Project \"{$name}\" deleted.");
        return $this->redirect(['index']);
    }

    public function actionSync(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireAccess($model);
        if ($model->scm_type !== Project::SCM_TYPE_GIT) {
            \Yii::$app->session->setFlash('warning', 'This project has no SCM configured.');
            return $this->redirect(['view', 'id' => $id]);
        }
        /** @var ProjectService $svc */
        $svc = \Yii::$app->get('projectService');
        $svc->queueSync($model);
        \Yii::$app->session->setFlash('success', "Sync queued for \"{$model->name}\".");
        return $this->redirect(['view', 'id' => $id]);
    }

    private function sshCredentials(): array
    {
        return Credential::find()
            ->where(['credential_type' => Credential::TYPE_SSH_KEY])
            ->orderBy('name')
            ->all();
    }

    private function findModel(int $id): Project
    {
        $model = Project::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Project #{$id} not found.");
        }
        return $model;
    }

    private function requireAccess(Project $model): void
    {
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');
        if (!$checker->canView(\Yii::$app->user->id, $model->id)) {
            throw new \yii\web\ForbiddenHttpException('You do not have access to this project.');
        }
    }
}
