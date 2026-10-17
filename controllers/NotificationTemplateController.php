<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\ErrorText;
use app\components\NotificationTestVariables;
use app\components\ShownSecretField;
use app\models\AuditLog;
use app\models\NotificationTemplate;
use app\services\NotificationDispatcher;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class NotificationTemplateController extends BaseController
{
    /** The success flash of a test notification that the channel accepted. */
    public const TEST_SENT = 'Test notification sent successfully.';

    /**
     * The danger flash of a test notification that was not sent.
     * sprintf(): the channel's error as NotificationDispatcher::sendSingle()
     * returns it, or what may be said about an error outside the delivery
     * (ErrorText: never the message of a database error).
     */
    public const TEST_FAILED = 'Test notification failed: %s';

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'], 'allow' => true, 'roles' => ['notification-template.view']],
            ['actions' => ['create'], 'allow' => true, 'roles' => ['notification-template.create']],
            ['actions' => ['update', 'test'], 'allow' => true, 'roles' => ['notification-template.update']],
            ['actions' => ['delete'], 'allow' => true, 'roles' => ['notification-template.delete']],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    protected function verbRules(): array
    {
        return ['delete' => ['POST'], 'test' => ['POST']];
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
        return $this->render('form', ['model' => $model, 'config' => $this->shownConfig($model)]);
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
        return $this->render('form', ['model' => $model, 'config' => $this->shownConfig($model)]);
    }

    /**
     * Send the template once with sample values (NotificationTestVariables,
     * as POST /api/v1/notification-templates/{id}/test sends them): a
     * success flash when its channel accepted the message, else a danger
     * flash with the channel's error (TEST_FAILED), which its
     * notification.failed audit entry carries too.
     */
    public function actionTest(int $id): Response
    {
        $model = $this->findModel($id);
        $event = NotificationTestVariables::eventOf($model);
        $launchedBy = \Yii::$app->user->identity?->username ?? NotificationTestVariables::NO_USER;
        $variables = NotificationTestVariables::of($event, $launchedBy);

        try {
            /** @var NotificationDispatcher $dispatcher */
            $dispatcher = \Yii::$app->get('notificationDispatcher');
            $error = $dispatcher->sendSingle($model, $variables, $event);
        } catch (\Throwable $e) {
            $error = ErrorText::of($e);
        }
        if ($error === null) {
            $this->session()->setFlash('success', self::TEST_SENT);
        } else {
            $this->session()->setFlash('danger', sprintf(self::TEST_FAILED, $error));
        }

        return $this->redirect(['view', 'id' => $model->id]);
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

    /**
     * The config the edit form shows: as this request submitted it, else as
     * stored with the placeholder for every secret (ShownSecretField).
     */
    private function shownConfig(NotificationTemplate $model): string
    {
        return ShownSecretField::value($model, 'config', $model->getRedactedConfigJson());
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
