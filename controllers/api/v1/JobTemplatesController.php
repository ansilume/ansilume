<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\components\JobTemplateWarnings;
use app\models\AuditLog;
use app\models\Credential;
use app\models\JobTemplate;
use app\controllers\api\v1\traits\ApiTeamScopingTrait;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\web\NotFoundHttpException;

/**
 * API v1: Job Templates
 *
 * GET    /api/v1/job-templates
 * GET    /api/v1/job-templates/{id}
 * POST   /api/v1/job-templates
 * PUT    /api/v1/job-templates/{id}
 * DELETE /api/v1/job-templates/{id}
 */
class JobTemplatesController extends BaseApiController
{
    use ApiTeamScopingTrait;

    protected function apiAccessRules(): array
    {
        return [
            'index' => 'job-template.view',
            'view' => 'job-template.view',
            'create' => 'job-template.create',
            'update' => 'job-template.update',
            'delete' => 'job-template.delete',
        ];
    }

    /**
     * Optional ?warning=<code> lists only templates with that warning
     * (see JobTemplateWarnings).
     *
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}|array{error: array{message: string}}
     */
    public function actionIndex(): array
    {
        $query = JobTemplate::find()
            ->with(['project', 'inventory', 'credential', 'jobTemplateCredentials.credential', 'vaultCheck.credential'])
            ->orderBy(['job_template.id' => SORT_DESC]);
        $filter = $this->checker()->buildChildResourceFilter($this->currentUserId(), 'job_template.project_id');
        if ($filter !== null) {
            $query->andWhere($filter);
        }
        $warning = \Yii::$app->request->get('warning', '');
        if ($warning !== '' && (!is_string($warning) || !JobTemplateWarnings::filter($query, $warning))) {
            return $this->error('Unknown warning. Use one of: ' . implode(', ', JobTemplateWarnings::CODES) . '.', 422);
        }

        $dp = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 25],
        ]);
        $page = $this->requestedPage();

        /** @var JobTemplate[] $templates */
        $templates = $dp->getModels();
        $changeable = $this->changeableIds(array_map(static fn (JobTemplate $t): int => (int)$t->id, $templates));

        return $this->paginated(
            array_map(fn (JobTemplate $t) => $this->serialize($t, isset($changeable[(int)$t->id])), $templates),
            (int)$dp->totalCount,
            $page,
            25
        );
    }

    /**
     * @return array{data: mixed}
     */
    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionView(int $id): array
    {
        $model = $this->findModel($id);
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canViewChildResource($userId, $model->project_id)) {
            return $this->error('Forbidden.', 403);
        }
        return $this->success($this->serialize($model, $this->mayChange($model)));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionCreate(): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('job-template.create')) {
            return $this->error('Forbidden.', 403);
        }

        $model = new JobTemplate();
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);
        $model->created_by = (int)$user->id;

        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canOperateChildResource($userId, $model->project_id)) {
            return $this->error('Forbidden.', 403);
        }
        $this->restrictInventories($model, $userId);

        $credentialIds = $this->readCredentialIds($body);
        if ($credentialIds === false) {
            return $this->error('credential_ids must be an array of credential IDs.', 422);
        }
        if (!$this->credentialService()->saveWithCredentials($model, $credentialIds, ['source' => 'api'])) {
            return $this->error($this->firstError($model), 422);
        }

        return $this->success($this->serialize($model, $this->mayChange($model)), 201);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionUpdate(int $id): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('job-template.update')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canOperateChildResource($userId, $model->project_id)) {
            return $this->error('Forbidden.', 403);
        }
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);
        // The project it moves to as well, not only the one it comes from.
        if (!$this->checker()->canOperateChildResource($userId, $model->project_id)) {
            return $this->error('Forbidden.', 403);
        }
        $this->restrictInventories($model, $userId);

        $credentialIds = $this->readCredentialIds($body);
        if ($credentialIds === false) {
            return $this->error('credential_ids must be an array of credential IDs.', 422);
        }
        if (!$this->credentialService()->saveWithCredentials($model, $credentialIds, ['source' => 'api'])) {
            return $this->error($this->firstError($model), 422);
        }

        return $this->success($this->serialize($model, $this->mayChange($model)));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionDelete(int $id): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('job-template.delete')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canOperateChildResource($userId, $model->project_id)) {
            return $this->error('Forbidden.', 403);
        }
        $name = $model->name;
        // Soft delete, like the web UI: jobs keep their template link.
        $model->softDelete();

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_TEMPLATE_DELETED,
            'job_template',
            $id,
            null,
            ['name' => $name, 'source' => 'api']
        );

        return $this->success(['deleted' => true]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyBody(JobTemplate $model, array $body): void
    {
        $this->applyStringFields($model, $body);
        $this->applyIntFields($model, $body);
        if (array_key_exists('become', $body)) {
            $model->become = (bool)$body['become'];
        }
        if (array_key_exists('survey_fields', $body)) {
            $model->survey_fields = $body['survey_fields'] !== null
                ? (is_string($body['survey_fields']) ? $body['survey_fields'] : (string)json_encode($body['survey_fields']))
                : null;
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyStringFields(JobTemplate $model, array $body): void
    {
        $fields = ['name', 'description', 'playbook', 'become_method', 'become_user', 'limit', 'tags', 'skip_tags', 'extra_vars'];
        $nullable = ['description', 'limit', 'tags', 'skip_tags', 'extra_vars'];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            if ($body[$field] === null && in_array($field, $nullable, true)) {
                $model->$field = null;
            } else {
                $model->$field = (string)$body[$field];
            }
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyIntFields(JobTemplate $model, array $body): void
    {
        $fields = ['project_id', 'inventory_id', 'credential_id', 'verbosity', 'forks', 'timeout_minutes', 'runner_group_id', 'approval_rule_id'];
        $nullable = ['credential_id', 'runner_group_id', 'approval_rule_id'];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            if ($body[$field] === null && in_array($field, $nullable, true)) {
                $model->$field = null;
            } else {
                $model->$field = (int)$body[$field];
            }
        }
    }

    /**
     * Only inventories the user may see, so a request cannot point the
     * template at another team's hosts.
     */
    private function restrictInventories(JobTemplate $model, int $userId): void
    {
        $model->restrictInventories($this->checker()->buildChildResourceFilter($userId, 'inventory.project_id'));
    }

    /**
     * $withTrigger adds has_trigger_token and trigger_user_id, the user an
     * inbound trigger launches as (who generated the token, or created_by
     * for older tokens; null without a token). Only callers who may change
     * the template get them, as only they see the trigger card on its page.
     * The token and its hash are never returned.
     *
     * @return array{id: int, name: string, description: string|null, project_id: int, project_name: string|null, inventory_id: int, inventory_name: string|null, runner_group_id: int|null, credential_id: int|null, credential_ids: list<int>, credentials: list<array{id: int, name: string, credential_type: string, role: string}>, playbook: string, verbosity: int, forks: int, become: bool, become_method: string, become_user: string, limit: string|null, tags: string|null, skip_tags: string|null, has_survey: bool, warnings: list<array{code: string, message: string, credential_ids: list<int>}>, created_by: int, created_at: int, updated_at: int, has_trigger_token?: bool, trigger_user_id?: int|null}
     */
    private function serialize(JobTemplate $t, bool $withTrigger): array
    {
        $credentials = $this->credentialService()->describe($t);
        $additional = array_filter($credentials, static fn (array $c): bool => $c['role'] === Credential::ROLE_ADDITIONAL);

        $data = [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'project_id' => $t->project_id,
            'project_name' => $t->project->name ?? null,
            'inventory_id' => $t->inventory_id,
            'inventory_name' => $t->inventory->name ?? null,
            'runner_group_id' => $t->runner_group_id,
            'credential_id' => $t->credential_id,
            'credential_ids' => array_values(array_column($additional, 'id')),
            'credentials' => $credentials,
            'playbook' => $t->playbook,
            'verbosity' => $t->verbosity,
            'forks' => $t->forks,
            'become' => (bool)$t->become,
            'become_method' => $t->become_method,
            'become_user' => $t->become_user,
            'limit' => $t->limit,
            'tags' => $t->tags,
            'skip_tags' => $t->skip_tags,
            'has_survey' => $t->hasSurvey(),
            'warnings' => JobTemplateWarnings::forTemplate($t),
            'created_by' => (int)$t->created_by,
            'created_at' => $t->created_at,
            'updated_at' => $t->updated_at,
        ];
        if ($withTrigger) {
            $data['has_trigger_token'] = $t->hasTriggerToken();
            $data['trigger_user_id'] = $t->hasTriggerToken() ? $t->getTriggerUserId() : null;
        }

        return $data;
    }

    /**
     * Whether the caller may change the template.
     */
    private function mayChange(JobTemplate $t): bool
    {
        return isset($this->changeableIds([(int)$t->id])[(int)$t->id]);
    }

    /**
     * The IDs of the given job templates the caller may change:
     * job-template.update and operator access to the template's project, as
     * an update or the template's trigger token needs. Decided for all of
     * them at once, so a list costs no query per template.
     *
     * @param list<int> $templateIds
     * @return array<int, true>
     */
    private function changeableIds(array $templateIds): array
    {
        if (!$this->userCan('job-template.update')) {
            return [];
        }
        $filter = $this->checker()->buildChildOperateFilter($this->currentUserId(), 'job_template.project_id');
        if ($filter !== null) {
            $templateIds = array_map('intval', JobTemplate::find()
                ->select('job_template.id')
                ->andWhere(['job_template.id' => $templateIds])
                ->andWhere($filter)
                ->column());
        }

        return array_fill_keys($templateIds, true);
    }

    /**
     * The additional credentials from the request body: null when the key is
     * absent (keep the current ones), false when it is not a list.
     *
     * @param array<string, mixed> $body
     * @return list<mixed>|null|false
     */
    private function readCredentialIds(array $body): array|null|false
    {
        if (!array_key_exists('credential_ids', $body)) {
            return null;
        }
        $ids = $body['credential_ids'] ?? [];

        return is_array($ids) && array_is_list($ids) ? $ids : false;
    }

    private function credentialService(): \app\services\JobTemplateCredentialService
    {
        /** @var \app\services\JobTemplateCredentialService $service */
        $service = \Yii::$app->get('jobTemplateCredentialService');

        return $service;
    }

    private function findModel(int $id): JobTemplate
    {
        /** @var JobTemplate|null $t */
        $t = JobTemplate::findOne($id);
        if ($t === null) {
            throw new NotFoundHttpException("Template #{$id} not found.");
        }
        return $t;
    }

    /**
     * @param ActiveRecord $model
     */
    private function firstError($model): string
    {
        foreach ($model->errors as $errors) {
            return $errors[0] ?? 'Validation failed.';
        }
        return 'Validation failed.';
    }
}
