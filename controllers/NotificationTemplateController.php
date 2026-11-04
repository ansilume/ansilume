<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\ErrorText;
use app\components\NotificationTestVariables;
use app\components\ShownSecretField;
use app\models\AuditLog;
use app\models\NotificationTemplate;
use app\services\notification\NotificationIdentity;
use app\services\notification\NotificationTemplateFormPost;
use app\services\notification\NotificationTemplateFormView;
use app\services\notification\NotificationTemplatePage;
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

    /**
     * The template, as its viewer may see it (NotificationTemplatePage): whom
     * it delivers as, its filter and its deliveries.
     */
    public function actionView(int $id): string
    {
        $model = $this->findModel($id);

        return $this->render('view', ['model' => $model, 'page' => NotificationTemplatePage::of($model, $this->userId())]);
    }

    /**
     * Creates the template as the signed-in user, who is the user it
     * delivers as, with the fields and the workflow filter the form posts,
     * read as the API reads a body (NotificationTemplateFormPost): the
     * template and its filter are saved together, or neither.
     */
    public function actionCreate(): Response|string
    {
        $model = new NotificationTemplate();
        $model->channel = NotificationTemplate::CHANNEL_EMAIL;
        $model->level = NotificationTemplate::LEVEL_ALL;
        $model->workflow_scope = NotificationTemplate::WORKFLOW_SCOPE_ALL;
        $form = NotificationTemplateFormPost::of((array)\Yii::$app->request->post());

        if ($form->load($model)) {
            $model->created_by = $this->userId();
            $model->delivers_as = $model->created_by;
            if ($form->create($model, $this->userId())) {
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
        return $this->render('form', $this->formParams($model, $form));
    }

    /**
     * Saves the form, its fields read as the API reads a body, with the
     * workflow filter as the form posts it: the part of the filter the
     * editor may see is replaced, the rest stays
     * (NotificationTemplateFormPost). A change of what or where the template
     * delivers makes the editor the user it delivers as
     * (NotificationIdentity), recorded in the audit entry as delivers_as
     * {from, to}.
     */
    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);
        $identity = new NotificationIdentity($model);
        $form = NotificationTemplateFormPost::of((array)\Yii::$app->request->post());
        if ($form->load($model) && $form->update($model, $identity, $this->userId())) {
            \Yii::$app->get('auditService')->log(
                AuditLog::ACTION_NOTIFICATION_TEMPLATE_UPDATED,
                'notification_template',
                $model->id,
                null,
                ['name' => $model->name] + $identity->auditContext()
            );
            $this->session()->setFlash('success', "Notification template \"{$model->name}\" updated.");
            return $this->redirect(['view', 'id' => $model->id]);
        }
        return $this->render('form', $this->formParams($model, $form));
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
        $launchedBy = \Yii::$app->user->identity?->username ?? NotificationTestVariables::NO_USER;

        try {
            // The event asks the template's channel which events it sends.
            $event = NotificationTestVariables::eventOf($model);
            /** @var NotificationDispatcher $dispatcher */
            $dispatcher = \Yii::$app->get('notificationDispatcher');
            $error = $dispatcher->sendSingle($model, NotificationTestVariables::of($event, $launchedBy), $event);
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
     * What the form of $model renders: the template, its config as shown
     * (shownConfig()) and what the form offers its editor
     * (NotificationTemplateFormView), with the filter $form posted as the
     * selection after a failed save.
     *
     * @return array{model: NotificationTemplate, config: string, choices: NotificationTemplateFormView}
     */
    private function formParams(NotificationTemplate $model, NotificationTemplateFormPost $form): array
    {
        return [
            'model' => $model,
            'config' => $this->shownConfig($model),
            'choices' => NotificationTemplateFormView::of($model, $this->userId(), $form->workflowTemplateIds),
        ];
    }

    /**
     * The config the edit form shows: as this request submitted it, else as
     * stored with the placeholder for every secret (ShownSecretField).
     */
    private function shownConfig(NotificationTemplate $model): string
    {
        return ShownSecretField::value($model, 'config', $model->getRedactedConfigJson());
    }

    /** The signed-in user. */
    private function userId(): int
    {
        return (int)\Yii::$app->user->id;
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
