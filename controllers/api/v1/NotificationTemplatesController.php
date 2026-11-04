<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\components\ErrorText;
use app\components\NotificationTestVariables;
use app\models\AuditLog;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\services\notification\NotificationIdentity;
use app\services\notification\NotificationTemplateBody;
use app\services\notification\NotificationTemplateResource;
use app\services\notification\NotificationTemplateSave;
use app\services\notification\VisibleDeliveries;
use app\services\NotificationDispatcher;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;

/**
 * API v1: Notification Templates
 *
 * GET    /api/v1/notification-templates
 * GET    /api/v1/notification-templates/{id}
 * POST   /api/v1/notification-templates
 * PUT    /api/v1/notification-templates/{id}
 * DELETE /api/v1/notification-templates/{id}
 * POST   /api/v1/notification-templates/{id}/test
 * GET    /api/v1/notification-templates/{id}/deliveries
 *
 * A body is read by NotificationTemplateBody, and a template saved with its
 * workflow filter by NotificationTemplateSave; every answer shows its
 * reader only the workflow templates of the filter and the deliveries the
 * reader may see (NotificationTemplateResource, VisibleDeliveries).
 */
class NotificationTemplatesController extends BaseApiController
{
    /**
     * The error of a test notification that was not sent: 502 when the
     * channel did not accept it, 500 for an error outside the delivery.
     * sprintf(): the channel's error as NotificationDispatcher::sendSingle()
     * returns it, or what may be said about the other error (ErrorText:
     * never the message of a database error).
     */
    public const TEST_FAILED = 'Test failed: %s';

    /** The templates, and the deliveries of a template, on one page of a list. */
    private const PAGE_SIZE = 25;

    protected function apiAccessRules(): array
    {
        return [
            'index' => 'notification-template.view',
            'view' => 'notification-template.view',
            'deliveries' => 'notification-template.view',
            'create' => 'notification-template.create',
            'update' => 'notification-template.update',
            'test' => 'notification-template.update',
            'delete' => 'notification-template.delete',
        ];
    }

    /**
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    public function actionIndex(): array
    {
        $dp = new ActiveDataProvider([
            'query' => NotificationTemplate::find()->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => self::PAGE_SIZE],
        ]);
        $page = $this->requestedPage();
        /** @var list<NotificationTemplate> $templates */
        $templates = $this->modelsOfPage($dp, $page);
        $extras = NotificationTemplateResource::extrasOf($templates, $this->readerId());

        return $this->paginated(
            array_map(fn (NotificationTemplate $m): array => $this->serialize($m, $extras), $templates),
            (int)$dp->totalCount,
            $page,
            self::PAGE_SIZE
        );
    }

    /**
     * @return array{data: mixed}
     */
    public function actionView(int $id): array
    {
        return $this->success($this->serializeOne($this->findModel($id)));
    }

    /**
     * Creates the template as the caller, who is the user it delivers as,
     * with the workflow filter of the body as the caller may set it
     * (NotificationTemplateSave): an ID the caller may not see answers 422,
     * and nothing is written.
     *
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionCreate(): array
    {
        $model = new NotificationTemplate();
        $input = NotificationTemplateBody::apply($model, (array)\Yii::$app->request->bodyParams);
        if ($input->refusal !== null) {
            return $this->error($input->refusal, 422);
        }
        $model->created_by = $this->readerId();
        $model->delivers_as = $model->created_by;
        if (!NotificationTemplateSave::create($model, $input->workflowTemplateIds, $this->readerId())) {
            return $this->error($this->firstError($model), 422);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_NOTIFICATION_TEMPLATE_CREATED,
            'notification_template',
            $model->id,
            null,
            ['name' => $model->name]
        );

        return $this->success($this->serializeOne($model), 201);
    }

    /**
     * Saves the change with the workflow filter of the body as the caller
     * may set it (NotificationTemplateSave): it replaces the workflow
     * templates of the filter the caller may see, the others stay. A change
     * of what or where the template delivers makes the caller the user it
     * delivers as (NotificationIdentity), recorded in the audit entry as
     * delivers_as {from, to}.
     *
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionUpdate(int $id): array
    {
        $model = $this->findModel($id);
        $identity = new NotificationIdentity($model);
        $input = NotificationTemplateBody::apply($model, (array)\Yii::$app->request->bodyParams);
        if ($input->refusal !== null) {
            return $this->error($input->refusal, 422);
        }
        if (!NotificationTemplateSave::update($model, $identity, $input->workflowTemplateIds, $this->readerId())) {
            return $this->error($this->firstError($model), 422);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_NOTIFICATION_TEMPLATE_UPDATED,
            'notification_template',
            $model->id,
            null,
            ['name' => $model->name] + $identity->auditContext()
        );

        return $this->success($this->serializeOne($model));
    }

    /**
     * @return array{data: mixed}
     */
    public function actionDelete(int $id): array
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

        return $this->success(['deleted' => true]);
    }

    /**
     * Send a test notification with the sample variables and the event of
     * the web's Send Test (NotificationTestVariables): {sent: true} when
     * the channel accepted it; 502 with the channel's error (TEST_FAILED)
     * when it did not, which its notification.failed audit entry carries
     * too; 500 for an error outside the delivery.
     *
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionTest(int $id): array
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
            return $this->error(sprintf(self::TEST_FAILED, ErrorText::of($e)), 500);
        }
        if ($error !== null) {
            return $this->error(sprintf(self::TEST_FAILED, $error), 502);
        }

        return $this->success(['sent' => true]);
    }

    /**
     * The deliveries of the template, newest first: those whose event and
     * objects the caller may see (VisibleDeliveries); meta.hidden counts
     * the others. A row names its event, kind, status, attempts, last error,
     * times and objects, never its variables or the post it made.
     *
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int, hidden: int}}
     */
    public function actionDeliveries(int $id): array
    {
        $template = $this->findModel($id);
        $all = NotificationDelivery::find()->where(['notification_template_id' => (int)$template->id]);
        $dp = new ActiveDataProvider([
            'query' => (clone $all)->andWhere(VisibleDeliveries::condition($this->readerId()))->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => self::PAGE_SIZE],
            // Newest first, whatever ?sort= says.
            'sort' => false,
        ]);
        $page = $this->requestedPage();
        /** @var list<NotificationDelivery> $deliveries */
        $deliveries = $this->modelsOfPage($dp, $page);
        $visible = (int)$dp->totalCount;
        $answer = $this->paginated(array_map(self::serializeDelivery(...), $deliveries), $visible, $page, self::PAGE_SIZE);

        return ['data' => $answer['data'], 'meta' => $answer['meta'] + ['hidden' => (int)$all->count() - $visible]];
    }

    /**
     * $m as the API answers it, for its reader: serialize() with the fields
     * that depend on the reader (NotificationTemplateResource).
     *
     * @return array<string, mixed>
     */
    private function serializeOne(NotificationTemplate $m): array
    {
        return $this->serialize($m, NotificationTemplateResource::extrasOf([$m], $this->readerId()));
    }

    /**
     * The template as the API answers it: its config with
     * SurveySecrets::PLACEHOLDER for every channel secret, for every caller
     * (viewers read the list and the detail); a PUT that sends the
     * placeholder back keeps the stored value (NotificationConfigSecrets).
     * Of its workflow filter the reader's visible part, and how many more
     * it holds ($extras, NotificationTemplateResource::extrasOf()).
     *
     * @param array<int, array{workflow_template_ids: list<int>, workflow_templates_hidden: int, delivers_as_inactive: bool}> $extras
     * @return array{id: int, name: string, description: string|null, channel: string, config: mixed, events: string[], level: string, workflow_scope: string, workflow_template_ids: list<int>, workflow_templates_hidden: int, subject_template: string|null, body_template: string|null, created_by: int, delivers_as: int, delivers_as_inactive: bool, created_at: int, updated_at: int}
     */
    private function serialize(NotificationTemplate $m, array $extras): array
    {
        $extra = $extras[(int)$m->id] ?? ['workflow_template_ids' => [], 'workflow_templates_hidden' => 0, 'delivers_as_inactive' => true];

        return [
            'id' => $m->id,
            'name' => $m->name,
            'description' => $m->description,
            'channel' => $m->channel,
            'config' => $m->getRedactedConfig(),
            'events' => $m->getEventList(),
            'level' => (string)($m->level ?? NotificationTemplate::LEVEL_ALL),
            'workflow_scope' => (string)($m->workflow_scope ?? NotificationTemplate::WORKFLOW_SCOPE_ALL),
            'workflow_template_ids' => $extra['workflow_template_ids'],
            'workflow_templates_hidden' => $extra['workflow_templates_hidden'],
            'subject_template' => $m->subject_template,
            'body_template' => $m->body_template,
            'created_by' => $m->created_by,
            'delivers_as' => (int)$m->delivers_as,
            'delivers_as_inactive' => $extra['delivers_as_inactive'],
            'created_at' => $m->created_at,
            'updated_at' => $m->updated_at,
        ];
    }

    /**
     * A delivery as the list answers it: what happened to it and what it is
     * about, without its variables, which hold what the event carried, and
     * without the post it made.
     *
     * @return array{id: int, event: string, kind: string, status: string, attempts: int, last_error: string|null, workflow_job_id: int|null, job_id: int|null, approval_request_id: int|null, project_id: int|null, job_template_id: int|null, next_attempt_at: int, created_at: int, updated_at: int, finished_at: int|null}
     */
    private static function serializeDelivery(NotificationDelivery $d): array
    {
        $refs = $d->getRefs();

        return [
            'id' => (int)$d->id,
            'event' => (string)$d->event,
            'kind' => (string)$d->kind,
            'status' => (string)$d->status,
            'attempts' => (int)$d->attempts,
            'last_error' => $d->last_error,
            'workflow_job_id' => $refs['workflow_job_id'] ?? null,
            'job_id' => $refs['job_id'] ?? null,
            'approval_request_id' => $refs['approval_request_id'] ?? null,
            'project_id' => $refs['project_id'] ?? null,
            'job_template_id' => $refs['job_template_id'] ?? null,
            'next_attempt_at' => (int)$d->next_attempt_at,
            'created_at' => (int)$d->created_at,
            'updated_at' => (int)$d->updated_at,
            'finished_at' => $d->finished_at === null ? null : (int)$d->finished_at,
        ];
    }

    /**
     * The caller, signed in by its API token.
     */
    private function readerId(): int
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

    private function firstError(NotificationTemplate $model): string
    {
        foreach ($model->errors as $errors) {
            return $errors[0] ?? 'Validation failed.';
        }
        return 'Validation failed.';
    }
}
