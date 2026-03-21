<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\Webhook;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class WebhookController extends BaseController
{
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'],   'allow' => true, 'roles' => ['admin']],
            ['actions' => ['create', 'update'], 'allow' => true, 'roles' => ['admin']],
            ['actions' => ['delete'],           'allow' => true, 'roles' => ['admin']],
        ];
    }

    protected function verbRules(): array
    {
        return ['delete' => ['POST']];
    }

    public function actionIndex(): string
    {
        $dataProvider = new ActiveDataProvider([
            'query' => Webhook::find()->with('creator')->orderBy(['id' => SORT_DESC]),
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
        $model = new Webhook();

        if ($model->load(\Yii::$app->request->post())) {
            $model->created_by = \Yii::$app->user->id;
            if ($model->save()) {
                \Yii::$app->get('auditService')->log(
                    'webhook.created', 'webhook', $model->id,
                    \Yii::$app->user->id,
                    ['name' => $model->name, 'url' => $model->url]
                );
                \Yii::$app->session->setFlash('success', "Webhook "{$model->name}" created.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }

        return $this->render('form', ['model' => $model]);
    }

    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);

        if ($model->load(\Yii::$app->request->post())) {
            if ($model->save()) {
                \Yii::$app->get('auditService')->log(
                    'webhook.updated', 'webhook', $model->id,
                    \Yii::$app->user->id,
                    ['name' => $model->name]
                );
                \Yii::$app->session->setFlash('success', "Webhook "{$model->name}" updated.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }

        return $this->render('form', ['model' => $model]);
    }

    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        $name  = $model->name;
        $model->delete();
        \Yii::$app->get('auditService')->log(
            'webhook.deleted', 'webhook', $id,
            \Yii::$app->user->id,
            ['name' => $name]
        );
        \Yii::$app->session->setFlash('success', "Webhook "{$name}" deleted.");
        return $this->redirect(['index']);
    }

    private function findModel(int $id): Webhook
    {
        $model = Webhook::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Webhook #{$id} not found.");
        }
        return $model;
    }
}
