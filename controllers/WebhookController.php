<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\AuditLog;
use app\models\Webhook;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class WebhookController extends BaseController
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'], 'allow' => true, 'roles' => ['admin']],
            ['actions' => ['create', 'update'], 'allow' => true, 'roles' => ['admin']],
            ['actions' => ['delete'], 'allow' => true, 'roles' => ['admin']],
        ];
    }

    /**
     * @return array<string, string[]>
     */
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
            $model->created_by = (int)\Yii::$app->user->id;
            if ($model->save()) {
                \Yii::$app->get('auditService')->log(
                    AuditLog::ACTION_WEBHOOK_CREATED,
                    'webhook',
                    $model->id,
                    null,
                    ['name' => $model->name, 'url' => $model->url]
                );
                $this->session()->setFlash('success', "Webhook \"{$model->name}\" created.");
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
                    AuditLog::ACTION_WEBHOOK_UPDATED,
                    'webhook',
                    $model->id,
                    null,
                    ['name' => $model->name]
                );
                $this->session()->setFlash('success', "Webhook \"{$model->name}\" updated.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }

        return $this->render('form', ['model' => $model]);
    }

    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        $name = $model->name;
        $model->delete();
        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_WEBHOOK_DELETED,
            'webhook',
            $id,
            null,
            ['name' => $name]
        );
        $this->session()->setFlash('success', "Webhook \"{$name}\" deleted.");
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
