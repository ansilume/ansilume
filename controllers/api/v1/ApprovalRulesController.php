<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\controllers\api\v1\traits\ApiTeamScopingTrait;
use app\models\ApprovalRule;
use app\services\ApprovalRuleUsageService;
use app\services\ApprovalRuleWriteService;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;

/**
 * API v1: Approval Rules
 *
 * GET    /api/v1/approval-rules          approval-rule.view
 * GET    /api/v1/approval-rules/{id}     approval-rule.view, with used_by
 * POST   /api/v1/approval-rules          approval-rule.create (admins by default)
 * PUT    /api/v1/approval-rules/{id}     approval-rule.update (admins by default)
 * DELETE /api/v1/approval-rules/{id}     approval-rule.delete; 409 with used_by while in use
 *
 * Writes go through ApprovalRuleWriteService, the same rules as the web UI.
 * job_template_id never gated a template: null is ignored, any other value
 * is refused (job_template.approval_rule_id gates a template).
 * approver_config is a JSON object both ways: a list or a single value is
 * refused (422), and every response returns an object, {} for a rule that
 * names no approvers.
 */
class ApprovalRulesController extends BaseApiController
{
    use ApiTeamScopingTrait;

    public const JOB_TEMPLATE_ID_REFUSED = 'job_template_id is not used by approval rules: '
        . 'set approval_rule_id on the job template (admins only).';

    protected function apiAccessRules(): array
    {
        return [
            'index' => 'approval-rule.view',
            'view' => 'approval-rule.view',
            'create' => 'approval-rule.create',
            'update' => 'approval-rule.update',
            'delete' => 'approval-rule.delete',
        ];
    }

    /**
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    public function actionIndex(): array
    {
        $dp = new ActiveDataProvider([
            'query' => ApprovalRule::find()->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 25],
        ]);
        $page = $this->requestedPage();
        /** @var list<ApprovalRule> $rules */
        $rules = $this->modelsOfPage($dp, $page);

        return $this->paginated(
            array_map(fn ($m) => $this->serialize($m), $rules),
            (int)$dp->totalCount,
            $page,
            25
        );
    }

    /**
     * @return array{data: mixed}
     */
    public function actionView(int $id): array
    {
        $model = $this->findModel($id);

        return $this->success($this->serialize($model) + [
            'used_by' => $this->usageService()->forRule($model, $this->currentUserId())->toArray(),
        ]);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionCreate(): array
    {
        $body = (array)\Yii::$app->request->bodyParams;
        if (self::setsJobTemplateId($body)) {
            return $this->error(self::JOB_TEMPLATE_ID_REFUSED, 422);
        }
        $model = new ApprovalRule();
        $this->applyBody($model, $body);

        if (!$this->writeService()->create($model, (int)\Yii::$app->user->id, ApprovalRuleWriteService::SOURCE_API)) {
            return $this->error($this->firstError($model), 422);
        }
        // As stored: database defaults and typed values, not the request's.
        $model->refresh();

        return $this->success($this->serialize($model), 201);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionUpdate(int $id): array
    {
        $model = $this->findModel($id);
        $body = (array)\Yii::$app->request->bodyParams;
        if (self::setsJobTemplateId($body)) {
            return $this->error(self::JOB_TEMPLATE_ID_REFUSED, 422);
        }
        $this->applyBody($model, $body);

        if (!$this->writeService()->update($model, ApprovalRuleWriteService::SOURCE_API)) {
            return $this->error($this->firstError($model), 422);
        }
        $model->refresh();

        return $this->success($this->serialize($model));
    }

    /**
     * A rule in use is not deleted: 409 with what uses it.
     *
     * @return array{data: mixed}|array{error: array<string, mixed>}
     */
    public function actionDelete(int $id): array
    {
        $model = $this->findModel($id);
        $result = $this->writeService()->delete($model, $this->currentUserId(), ApprovalRuleWriteService::SOURCE_API);
        if (!$result['deleted']) {
            return $this->errorWithDetails(
                ApprovalRuleWriteService::refusal($result['usage']),
                409,
                ['used_by' => $result['usage']->toArray()]
            );
        }

        return $this->success(['deleted' => true]);
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function setsJobTemplateId(array $body): bool
    {
        return array_key_exists('job_template_id', $body) && $body['job_template_id'] !== null;
    }

    /**
     * Text fields take any scalar as text; other values reach the model as
     * they are, so its validation refuses a list or an object instead of
     * PHP turning it into "Array" or 1.
     *
     * @param array<array-key, mixed> $body
     */
    private function applyBody(ApprovalRule $model, array $body): void
    {
        foreach (['name', 'description', 'timeout_action', 'approver_type'] as $field) {
            if (array_key_exists($field, $body)) {
                $value = $body[$field];
                $model->setAttribute($field, is_scalar($value) ? (string)$value : $value);
            }
        }
        foreach (['required_approvals', 'timeout_minutes'] as $field) {
            if (array_key_exists($field, $body)) {
                $model->setAttribute($field, $body[$field]);
            }
        }
        if (array_key_exists('approver_config', $body)) {
            $model->setAttribute('approver_config', self::configText($body['approver_config']));
        }
    }

    /**
     * The approver config as the model stores it: an object or a list as
     * JSON text, anything else as it came, for validation to judge. The
     * JSON body parser turns {} into an empty array, which is stored as {}:
     * an empty list cannot be told apart from it, and names no approvers
     * either.
     */
    private static function configText(mixed $value): mixed
    {
        if ($value === []) {
            return '{}';
        }

        return is_array($value) ? (string)json_encode($value) : $value;
    }

    /**
     * job_template_id is deprecated: it never gated a template.
     * approver_config is always a JSON object: {} when the rule names no
     * approvers (an empty config, or a list stored by an older version).
     *
     * @return array{id: int, name: string, description: string|null, required_approvals: int, timeout_minutes: int|null, timeout_action: string, approver_type: string, approver_config: array<string, mixed>|\stdClass, job_template_id: int|null, created_by: int, created_at: int, updated_at: int}
     */
    private function serialize(ApprovalRule $m): array
    {
        $config = $m->getParsedConfig();

        return [
            'id' => $m->id,
            'name' => $m->name,
            'description' => $m->description,
            'required_approvals' => $m->required_approvals,
            'timeout_minutes' => $m->timeout_minutes,
            'timeout_action' => $m->timeout_action,
            'approver_type' => $m->approver_type,
            // An empty array would be encoded as the JSON list [].
            'approver_config' => $config === [] ? new \stdClass() : $config,
            'job_template_id' => $m->job_template_id,
            'created_by' => $m->created_by,
            'created_at' => $m->created_at,
            'updated_at' => $m->updated_at,
        ];
    }

    private function findModel(int $id): ApprovalRule
    {
        /** @var ApprovalRule|null $model */
        $model = ApprovalRule::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Approval rule #{$id} not found.");
        }
        return $model;
    }

    private function firstError(ApprovalRule $model): string
    {
        foreach ($model->errors as $errors) {
            return $errors[0] ?? 'Validation failed.';
        }
        return 'Validation failed.';
    }

    private function usageService(): ApprovalRuleUsageService
    {
        /** @var ApprovalRuleUsageService $service */
        $service = \Yii::$app->get('approvalRuleUsageService');

        return $service;
    }

    private function writeService(): ApprovalRuleWriteService
    {
        /** @var ApprovalRuleWriteService $service */
        $service = \Yii::$app->get('approvalRuleWriteService');

        return $service;
    }
}
