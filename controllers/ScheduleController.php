<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\JobTemplate;
use app\models\Schedule;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ScheduleController extends BaseController
{
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'],   'allow' => true, 'roles' => ['job.launch']],
            ['actions' => ['create', 'update'], 'allow' => true, 'roles' => ['job.launch']],
            ['actions' => ['delete', 'toggle'], 'allow' => true, 'roles' => ['job.launch']],
        ];
    }

    protected function verbRules(): array
    {
        return [
            'delete' => ['POST'],
            'toggle' => ['POST'],
        ];
    }

    public function actionIndex(): string
    {
        $dataProvider = new ActiveDataProvider([
            'query' => Schedule::find()->with(['jobTemplate', 'creator'])->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 25],
        ]);
        return $this->render('index', ['dataProvider' => $dataProvider]);
    }

    public function actionView(int $id): string
    {
        return $this->render('view', ['model' => $this->findModel($id)]);
    }

    public function actionCreate(): Response|string
    {
        $model = new Schedule();
        $model->timezone = 'UTC';
        $model->enabled  = true;

        if ($model->load(\Yii::$app->request->post())) {
            $model->created_by = \Yii::$app->user->id;
            $model->computeNextRunAt();
            if ($model->save()) {
                \Yii::$app->get('auditService')->log(
                    'schedule.created', 'schedule', $model->id,
                    \Yii::$app->user->id,
                    ['name' => $model->name, 'cron' => $model->cron_expression]
                );
                \Yii::$app->session->setFlash('success', "Schedule \"{$model->name}\" created.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }

        return $this->render('form', [
            'model'     => $model,
            'templates' => $this->getTemplateList(),
        ]);
    }

    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);

        if ($model->load(\Yii::$app->request->post())) {
            $model->computeNextRunAt();
            if ($model->save()) {
                \Yii::$app->get('auditService')->log(
                    'schedule.updated', 'schedule', $model->id,
                    \Yii::$app->user->id,
                    ['name' => $model->name]
                );
                \Yii::$app->session->setFlash('success', "Schedule \"{$model->name}\" updated.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }

        return $this->render('form', [
            'model'     => $model,
            'templates' => $this->getTemplateList(),
        ]);
    }

    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        $name  = $model->name;
        $model->delete();
        \Yii::$app->get('auditService')->log(
            'schedule.deleted', 'schedule', $id,
            \Yii::$app->user->id,
            ['name' => $name]
        );
        \Yii::$app->session->setFlash('success', "Schedule \"{$name}\" deleted.");
        return $this->redirect(['index']);
    }

    public function actionToggle(int $id): Response
    {
        $model = $this->findModel($id);
        $model->enabled = !$model->enabled;
        if ($model->enabled) {
            $model->computeNextRunAt();
        }
        $model->save(false, ['enabled', 'next_run_at', 'updated_at']);
        $state = $model->enabled ? 'enabled' : 'disabled';
        \Yii::$app->session->setFlash('success', "Schedule \"{$model->name}\" {$state}.");
        return $this->redirect(['index']);
    }

    private function findModel(int $id): Schedule
    {
        $model = Schedule::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Schedule #{$id} not found.");
        }
        return $model;
    }

    private function getTemplateList(): array
    {
        $rows = JobTemplate::find()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->asArray()
            ->all();

        $list = [];
        foreach ($rows as $row) {
            $list[$row['id']] = $row['name'] . ' (' . $row['id'] . ')';
        }
        return $list;
    }
}
