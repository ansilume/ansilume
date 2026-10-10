<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\controllers\api\v1\traits\ApiTeamScopingTrait;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowAccessChecker;
use app\services\WorkflowAccessDeniedException;
use app\services\WorkflowExecutionService;
use yii\base\Model;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;

/**
 * API v1: Workflow Templates CRUD + launch.
 *
 * Team scoping: a workflow template belongs to the projects of its job
 * steps. Seeing one needs view access to every job step's project; updating,
 * deleting and launching it need operator access to every one.
 *
 * Steps (create and update): a "steps" list replaces all steps. Every step
 * needs a name (max 128) and a step_type of job (default), approval or
 * pause; a job step needs job_template_id, an approval step approval_rule_id
 * of an existing approval rule. Job templates that do not exist or that the
 * caller cannot see answer 422, visible ones the caller may not operate 403,
 * both naming them in error.job_template_ids. Nothing is written unless the
 * template and every step are valid; then the template and its steps are
 * written in one transaction.
 */
class WorkflowTemplatesController extends BaseApiController
{
    use ApiTeamScopingTrait;

    protected function apiAccessRules(): array
    {
        return [
            'index' => 'workflow-template.view',
            'view' => 'workflow-template.view',
            'create' => 'workflow-template.create',
            'update' => 'workflow-template.update',
            'delete' => 'workflow-template.delete',
            'launch' => 'workflow.launch',
        ];
    }

    /**
     * Only the workflow templates the caller may view.
     *
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    public function actionIndex(): array
    {
        $query = WorkflowTemplate::find()->orderBy(['workflow_template.id' => SORT_DESC]);
        $filter = $this->workflows()->buildWorkflowTemplateFilter($this->currentUserId(), 'workflow_template.id');
        if ($filter !== null) {
            $query->andWhere($filter);
        }
        $dp = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 25],
        ]);
        $page = $this->requestedPage();

        /** @var WorkflowTemplate[] $workflows */
        $workflows = $dp->getModels();
        $changeable = $this->changeableIds(array_map(static fn (WorkflowTemplate $m): int => (int)$m->id, $workflows));

        return $this->paginated(
            array_map(fn (WorkflowTemplate $m) => $this->serialize($m, isset($changeable[(int)$m->id])), $workflows),
            (int)$dp->totalCount,
            $page,
            25
        );
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionView(int $id): array
    {
        $model = $this->findModel($id);
        if (!$this->may($model, false)) {
            return $this->error('Forbidden.', 403);
        }
        return $this->success($this->serializeDetailed($model));
    }

    /**
     * @return array{data: mixed}|array{error: array<string, mixed>}
     */
    public function actionCreate(): array
    {
        $model = new WorkflowTemplate();
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);
        $model->created_by = (int)\Yii::$app->user->id;

        $rejected = $this->write($model, $body, AuditLog::ACTION_WORKFLOW_TEMPLATE_CREATED);
        if ($rejected !== null) {
            return $rejected;
        }

        return $this->success($this->serializeDetailed($model), 201);
    }

    /**
     * @return array{data: mixed}|array{error: array<string, mixed>}
     */
    public function actionUpdate(int $id): array
    {
        $model = $this->findModel($id);
        if (!$this->may($model, true)) {
            return $this->error('Forbidden.', 403);
        }
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);

        $rejected = $this->write($model, $body, AuditLog::ACTION_WORKFLOW_TEMPLATE_UPDATED);
        if ($rejected !== null) {
            return $rejected;
        }

        return $this->success($this->serializeDetailed($model));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionDelete(int $id): array
    {
        $model = $this->findModel($id);
        if (!$this->may($model, true)) {
            return $this->error('Forbidden.', 403);
        }
        $name = $model->name;
        $model->softDelete();

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_WORKFLOW_TEMPLATE_DELETED,
            'workflow_template',
            $id,
            null,
            ['name' => $name]
        );

        return $this->success(['deleted' => true]);
    }

    /**
     * The service checks that the caller may launch every job step and
     * audits a refusal.
     *
     * @return array{data: mixed}|array{error: array<string, mixed>}
     */
    public function actionLaunch(int $id): array
    {
        $model = $this->findModel($id);

        /** @var WorkflowExecutionService $service */
        $service = \Yii::$app->get('workflowExecutionService');
        try {
            $wfJob = $service->launch($model, (int)\Yii::$app->user->id, [], 'api');
        } catch (\RuntimeException $e) {
            // A refusal by team scoping is a RuntimeException too.
            if ($e instanceof WorkflowAccessDeniedException) {
                return $this->errorWithDetails(
                    $e->getMessage(),
                    403,
                    $e->jobTemplateIds === [] ? [] : ['job_template_ids' => $e->jobTemplateIds]
                );
            }
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(['workflow_job_id' => $wfJob->id], 201);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyBody(WorkflowTemplate $model, array $body): void
    {
        if (array_key_exists('name', $body)) {
            $model->name = self::text($body['name']);
        }
        if (array_key_exists('description', $body)) {
            $model->description = $body['description'] === null ? null : self::text($body['description']);
        }
    }

    /**
     * Validate the template and the steps of the body, then write both in
     * one transaction and audit $auditAction. Returns the error response, or
     * null once written.
     *
     * @param array<string, mixed> $body
     * @return array{error: array<string, mixed>}|null
     */
    private function write(WorkflowTemplate $model, array $body, string $auditAction): ?array
    {
        if (!$model->validate()) {
            return $this->error($this->firstError($model), 422);
        }
        $steps = null;
        if (($body['steps'] ?? null) !== null) {
            $steps = $this->readSteps($body['steps']);
            if (is_string($steps)) {
                return $this->error($steps, 422);
            }
            $rejected = $this->referenceError($steps);
            if ($rejected !== null) {
                return $rejected;
            }
        }
        $error = $this->persist($model, $steps);
        if ($error !== null) {
            return $this->error($error, 422);
        }
        $this->audit($auditAction, $model, $steps);

        return null;
    }

    /**
     * The steps of the request as unsaved models in request order, or why
     * they are rejected.
     *
     * @return list<WorkflowStep>|string
     */
    private function readSteps(mixed $raw): array|string
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return 'steps must be a list of steps.';
        }
        $steps = [];
        foreach ($raw as $index => $data) {
            $step = is_array($data) ? $this->readStep($data, $index) : 'A step must be an object.';
            if (is_string($step)) {
                return sprintf('steps[%d]: %s', $index, $step);
            }
            $steps[] = $step;
        }

        return $steps;
    }

    /**
     * One step of the request as an unsaved model, or why it is rejected.
     *
     * @param array<array-key, mixed> $data
     */
    private function readStep(array $data, int $index): WorkflowStep|string
    {
        $step = new WorkflowStep();
        $step->name = self::text($data['name'] ?? '');
        $step->step_type = array_key_exists('step_type', $data) ? self::text($data['step_type']) : WorkflowStep::TYPE_JOB;
        $step->step_order = $index;
        $step->extra_vars_template = self::extraVarsTemplate($data['extra_vars_template'] ?? null);
        if (!$step->validate(['name', 'step_type', 'step_order', 'extra_vars_template'])) {
            return $this->firstError($step);
        }

        return $this->readTarget($step, $data);
    }

    /**
     * Read the target of the step's type: the job template of a job step,
     * the approval rule of an approval step. Other IDs are ignored, so a
     * pause step never carries a job template that would restrict who may
     * see the workflow.
     *
     * @param array<array-key, mixed> $data
     */
    private function readTarget(WorkflowStep $step, array $data): WorkflowStep|string
    {
        if ($step->step_type === WorkflowStep::TYPE_JOB) {
            $step->job_template_id = self::toId($data['job_template_id'] ?? null);
            return $step->job_template_id !== null ? $step : 'A job step needs job_template_id, the ID of a job template.';
        }
        if ($step->step_type === WorkflowStep::TYPE_APPROVAL) {
            $step->approval_rule_id = self::toId($data['approval_rule_id'] ?? null);
            return $step->approval_rule_id !== null ? $step : 'An approval step needs approval_rule_id, the ID of an approval rule.';
        }

        return $step;
    }

    /**
     * Approval rules must exist. Job templates must exist for the caller:
     * unknown, deleted and invisible ones answer 422. Visible ones the caller
     * may not operate answer 403.
     *
     * @param list<WorkflowStep> $steps
     * @return array{error: array<string, mixed>}|null
     */
    private function referenceError(array $steps): ?array
    {
        $ruleIds = self::presentIds(array_map(static fn (WorkflowStep $s): ?int => $s->approval_rule_id, $steps));
        $knownRuleIds = array_map('intval', ApprovalRule::find()->select('id')->where(['id' => $ruleIds])->column());
        $unknownRuleIds = array_values(array_diff($ruleIds, $knownRuleIds));
        if ($unknownRuleIds !== []) {
            return $this->error('Approval rule(s) not found: ' . self::idList($unknownRuleIds) . '.', 422);
        }

        $templateIds = self::presentIds(array_map(static fn (WorkflowStep $s): ?int => $s->job_template_id, $steps));
        $found = $this->workflows()->classifyStepTemplateIds((int)$this->currentUserId(), $templateIds);
        if ($found['missing'] !== []) {
            return $this->errorWithDetails(
                'Job template(s) not found: ' . self::idList($found['missing']) . '.',
                422,
                ['job_template_ids' => $found['missing']]
            );
        }
        if ($found['forbidden'] !== []) {
            return $this->errorWithDetails(
                'You may not use job template(s) ' . self::idList($found['forbidden'])
                    . ' in a workflow: that needs operator access to their project.',
                403,
                ['job_template_ids' => $found['forbidden']]
            );
        }

        return null;
    }

    /**
     * Save the template and, when given, replace its steps, all or nothing.
     * Returns why the write failed, or null.
     *
     * @param list<WorkflowStep>|null $steps null keeps the current steps
     */
    private function persist(WorkflowTemplate $model, ?array $steps): ?string
    {
        $transaction = \Yii::$app->db->beginTransaction();
        try {
            $error = $this->saveAll($model, $steps);
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        if ($error !== null) {
            $transaction->rollBack();
            return $error;
        }
        $transaction->commit();

        return null;
    }

    /**
     * @param list<WorkflowStep>|null $steps
     */
    private function saveAll(WorkflowTemplate $model, ?array $steps): ?string
    {
        if (!$model->save()) {
            return $this->firstError($model);
        }
        if ($steps === null) {
            return null;
        }
        WorkflowStep::deleteAll(['workflow_template_id' => $model->id]);
        foreach ($steps as $index => $step) {
            $step->workflow_template_id = (int)$model->id;
            if (!$step->save()) {
                return sprintf('steps[%d]: %s', $index, $this->firstError($step));
            }
        }

        return null;
    }

    /**
     * @param list<WorkflowStep>|null $steps
     */
    private function audit(string $action, WorkflowTemplate $model, ?array $steps): void
    {
        $context = ['name' => $model->name, 'source' => 'api'];
        if ($steps !== null) {
            $context['job_template_ids'] = self::presentIds(
                array_map(static fn (WorkflowStep $s): ?int => $s->job_template_id, $steps)
            );
        }
        \Yii::$app->get('auditService')->log($action, 'workflow_template', $model->id, null, $context);
    }

    /**
     * Whether the caller may view the workflow template or, with $operate,
     * update, delete and launch it.
     */
    private function may(WorkflowTemplate $model, bool $operate): bool
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return false;
        }

        return $operate
            ? $this->workflows()->canOperateWorkflowTemplate($userId, (int)$model->id)
            : $this->workflows()->canViewWorkflowTemplate($userId, (int)$model->id);
    }

    /**
     * Whether the caller may change the workflow template.
     */
    private function mayChange(WorkflowTemplate $model): bool
    {
        return isset($this->changeableIds([(int)$model->id])[(int)$model->id]);
    }

    /**
     * The IDs of the given workflow templates the caller may change:
     * workflow-template.update and operator access to the project of every
     * job step, as an update or the workflow's trigger token needs. Decided
     * for all of them at once, so a list costs no query per workflow.
     *
     * @param list<int> $workflowIds
     * @return array<int, true>
     */
    private function changeableIds(array $workflowIds): array
    {
        if (!$this->userCan('workflow-template.update')) {
            return [];
        }
        $filter = $this->workflows()->buildWorkflowTemplateFilter($this->currentUserId(), 'workflow_template.id', true);
        if ($filter !== null) {
            $workflowIds = array_map('intval', WorkflowTemplate::find()
                ->select('workflow_template.id')
                ->andWhere(['workflow_template.id' => $workflowIds])
                ->andWhere($filter)
                ->column());
        }

        return array_fill_keys($workflowIds, true);
    }

    /**
     * $withTrigger adds has_trigger_token and trigger_user_id, the user an
     * inbound trigger launches as (who generated the token, or created_by
     * for older tokens; null without a token). Only callers who may change
     * the workflow get them, as only they see the trigger card on its page.
     * The token and its hash are never returned.
     *
     * @return array{id: int, name: string, description: string|null, created_by: int, created_at: int, updated_at: int, has_trigger_token?: bool, trigger_user_id?: int|null}
     */
    private function serialize(WorkflowTemplate $m, bool $withTrigger): array
    {
        $data = [
            'id' => $m->id,
            'name' => $m->name,
            'description' => $m->description,
            'created_by' => $m->created_by,
            'created_at' => $m->created_at,
            'updated_at' => $m->updated_at,
        ];
        if ($withTrigger) {
            $data['has_trigger_token'] = $m->hasTriggerToken();
            $data['trigger_user_id'] = $m->hasTriggerToken() ? $m->getTriggerUserId() : null;
        }

        return $data;
    }

    /**
     * A step returns only the target of its type: job_template_id for a job
     * step, approval_rule_id for an approval step. A job template that an
     * older version saved on another step type does not restrict who sees the
     * workflow, so it is never shown.
     *
     * @return array<string, mixed>
     */
    private function serializeDetailed(WorkflowTemplate $m): array
    {
        $data = $this->serialize($m, $this->mayChange($m));
        $data['steps'] = array_map(fn (WorkflowStep $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'step_order' => $s->step_order,
            'step_type' => $s->step_type,
            'job_template_id' => $s->step_type === WorkflowStep::TYPE_JOB ? $s->job_template_id : null,
            'approval_rule_id' => $s->step_type === WorkflowStep::TYPE_APPROVAL ? $s->approval_rule_id : null,
            'on_success_step_id' => $s->on_success_step_id,
            'on_failure_step_id' => $s->on_failure_step_id,
            'on_always_step_id' => $s->on_always_step_id,
            'extra_vars_template' => $s->getParsedExtraVarsTemplate(),
        ], $m->steps);
        return $data;
    }

    private function findModel(int $id): WorkflowTemplate
    {
        /** @var WorkflowTemplate|null $model */
        $model = WorkflowTemplate::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Workflow template #{$id} not found.");
        }
        return $model;
    }

    private function firstError(Model $model): string
    {
        foreach ($model->errors as $errors) {
            return $errors[0] ?? 'Validation failed.';
        }
        return 'Validation failed.';
    }

    private function workflows(): WorkflowAccessChecker
    {
        /** @var WorkflowAccessChecker $checker */
        $checker = \Yii::$app->get('workflowAccessChecker');
        return $checker;
    }

    /**
     * A request value as text; anything that is not a scalar becomes empty
     * and fails validation instead of raising a conversion error.
     */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * extra_vars_template as stored: JSON text. A JSON object in the request
     * is encoded.
     */
    private static function extraVarsTemplate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_array($value) ? (string)json_encode($value) : self::text($value);
    }

    /**
     * A positive ID from the request: integers and digit strings only, as
     * filter_var() alone also takes true and 1.0 for 1.
     */
    private static function toId(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }

    /**
     * @param array<int, int|null> $ids
     * @return list<int>
     */
    private static function presentIds(array $ids): array
    {
        $present = [];
        foreach ($ids as $id) {
            if ($id !== null) {
                $present[$id] = $id;
            }
        }

        return array_values($present);
    }

    /**
     * @param list<int> $ids
     */
    private static function idList(array $ids): string
    {
        return '#' . implode(', #', $ids);
    }
}
