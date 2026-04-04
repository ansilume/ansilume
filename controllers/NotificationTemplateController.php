<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\AuditLog;
use app\models\NotificationTemplate;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class NotificationTemplateController extends BaseController
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'], 'allow' => true, 'roles' => ['notification-template.view']],
            ['actions' => ['create'], 'allow' => true, 'roles' => ['notification-template.create']],
            ['actions' => ['update'], 'allow' => true, 'roles' => ['notification-template.update']],
            ['actions' => ['delete'], 'allow' => true, 'roles' => ['notification-template.delete']],
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
            'query' => NotificationTemplate::find()->with('creator')->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 20],
        ]);
        return $this->render('index', ['dataProvider' => $dataProvider]);
    }

    public function actionView(int $id): string
    {
        return $this->render('view', ['model' => $this->findModel($id)]);
    }

    public function actionCreate(): Response|string
    {
        $model = new NotificationTemplate();
        $model->channel = NotificationTemplate::CHANNEL_EMAIL;

        if ($model->load((array)\Yii::$app->request->post())) {
            $model->created_by = (int)\Yii::$app->user->id;
            if ($model->save()) {
                \Yii::$app->get('auditService')->log(
                    AuditLog::ACTION_NOTIFICATION_TEMPLATE_CREATED,
                    'notification_template',
                    $model->id,
                    null,
                    ['name' => $model->name]
                );
                $this->session()->setFlash('success', "Notification template \"{$model->name}\" created.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }
        return $this->render('form', ['model' => $model]);
    }

    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);
        if ($model->load((array)\Yii::$app->request->post()) && $model->save()) {
            \Yii::$app->get('auditService')->log(
                AuditLog::ACTION_NOTIFICATION_TEMPLATE_UPDATED,
                'notification_template',
                $model->id,
                null,
                ['name' => $model->name]
            );
            $this->session()->setFlash('success', "Notification template \"{$model->name}\" updated.");
            return $this->redirect(['view', 'id' => $model->id]);
        }
        return $this->render('form', ['model' => $model]);
    }

    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        $name = $model->name;
        $model->delete();
        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_NOTIFICATION_TEMPLATE_DELETED,
            'notification_template',
            $id,
            null,
            ['name' => $name]
        );
        $this->session()->setFlash('success', "Notification template \"{$name}\" deleted.");
        return $this->redirect(['index']);
    }

    private function findModel(int $id): NotificationTemplate
    {
        /** @var NotificationTemplate|null $model */
        $model = NotificationTemplate::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Notification template #{$id} not found.");
        }
        return $model;
    }
}
