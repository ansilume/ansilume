<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\components\ErrorText;
use app\components\WorkflowStepsInput;
use app\controllers\api\v1\traits\ApiTeamScopingTrait;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\WorkflowAccessChecker;
use app\services\WorkflowAccessDeniedException;
use app\services\WorkflowExecutionService;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepChangeRefusedException;
use app\services\WorkflowTemplateDeletionService;
use yii\data\ActiveDataProvider;
use yii\db\Exception as DbException;
use yii\web\NotFoundHttpException;

/**
 * API v1: Workflow Templates CRUD + launch.
 *
 * Team scoping: a workflow template belongs to the projects of its job
 * steps. Seeing one needs view access to every job step's project; updating,
 * deleting and launching it need operator access to every one.
 *
 * Steps (create and update): a "steps" list replaces all steps; null or no
 * "steps" keeps them. Every step needs a name (max 128) and a step_type of
 * job (default), approval or pause; a job step needs job_template_id, an
 * approval step approval_rule_id of an existing approval rule
 * (WorkflowStepsInput). extra_vars_template, JSON text or a JSON object, must
 * be JSON that can be stored: a number too large for JSON answers 422 in
 * either form. The answers, in this order (update: 403 "Forbidden." first,
 * without access to the workflow):
 * - 422 for a template that is not valid, such as an empty name;
 * - 422 for steps that cannot be read;
 * - 403 when the caller may not change these steps: gated steps (approval
 *   steps, and job steps whose job template has an approval rule) are for
 *   admins (approval-rule.update), so a "steps" list that includes one, or
 *   any "steps" list for a workflow that has one, needs that permission
 *   (ApprovalRuleAssignmentPolicy); a job template the caller cannot see
 *   counts as none here and answers 422 below;
 * - 422 for unknown approval rules; then 422 for job templates that do not
 *   exist or that the caller cannot see, 403 for visible ones the caller may
 *   not operate, both naming them in error.job_template_ids;
 * - (update) 404 when the workflow was deleted meanwhile, then 409 while a
 *   run of the workflow is active, naming the runs in error.workflow_job_ids
 *   (WorkflowStepChangeGuard), checked with the workflow's row locked, as a
 *   launch locks it;
 * - 422 for a template or step that cannot be saved.
 * Nothing is written unless the template and every step are valid; then the
 * template and its steps are written in one transaction. Runs keep the
 * history of replaced steps.
 *
 * Delete cancels the workflow's active runs (WorkflowTemplateDeletionService)
 * and answers how many it canceled and how many it could not (canceled_runs,
 * failed_runs). Launch takes optional extra_vars, which every job
 * step of the run receives; the engine refuses unusable ones with 422.
 */
class WorkflowTemplatesController extends BaseApiController
{
    use ApiTeamScopingTrait;

    /**
     * Logged at error level, category
     * app\controllers\api\v1\WorkflowTemplatesController, when a launch fails
     * with a database error. sprintf(): the workflow template's ID, the error
     * as ErrorText words it (class, SQLSTATE, driver error code).
     */
    public const LAUNCH_FAILED_LOG = 'Launch of workflow template #%d failed: %s';

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
     * Soft-deletes the template and cancels its active runs; canceled_runs
     * counts them, failed_runs the ones whose cancel failed (the maintenance
     * run retries those).
     *
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionDelete(int $id): array
    {
        $model = $this->findModel($id);
        if (!$this->may($model, true)) {
            return $this->error('Forbidden.', 403);
        }

        /** @var WorkflowTemplateDeletionService $deletion */
        $deletion = \Yii::$app->get('workflowTemplateDeletionService');
        $result = $deletion->delete($model, $this->currentUserId(), 'api');
        if (!$result['deleted']) {
            // Another request deleted it after it was loaded.
            throw new NotFoundHttpException("Workflow template #{$id} not found.");
        }

        return $this->success($result);
    }

    /**
     * The service checks that the caller may launch every job step and
     * audits a refusal (403). The body's extra_vars, when it has the key,
     * are the run's launch extra vars whatever their type: the engine
     * refuses what it cannot use (not an object, not storable as JSON,
     * $encrypted$ as a password answer) with a WorkflowLaunchRejectedException,
     * a 422 here like every other refused launch. A database error (a
     * yii\db\Exception, or a PDOException that escaped Yii, such as a failed
     * BEGIN or COMMIT) is no refusal: 500, its message and the log line
     * (LAUNCH_FAILED_LOG) name it by its class, SQLSTATE and driver error
     * code only (ErrorText).
     *
     * @return array{data: mixed}|array{error: array<string, mixed>}
     */
    public function actionLaunch(int $id): array
    {
        $model = $this->findModel($id);
        $body = (array)\Yii::$app->request->bodyParams;
        $overrides = array_key_exists('extra_vars', $body) ? ['extra_vars' => $body['extra_vars']] : [];

        /** @var WorkflowExecutionService $service */
        $service = \Yii::$app->get('workflowExecutionService');
        try {
            $wfJob = $service->launch($model, (int)\Yii::$app->user->id, $overrides, 'api');
        } catch (WorkflowAccessDeniedException $e) {
            return $this->errorWithDetails(
                $e->getMessage(),
                403,
                $e->jobTemplateIds === [] ? [] : ['job_template_ids' => $e->jobTemplateIds]
            );
        } catch (DbException | \PDOException $e) {
            $summary = ErrorText::of($e);
            \Yii::error(sprintf(self::LAUNCH_FAILED_LOG, $id, $summary), __CLASS__);
            return $this->error($summary, 500);
        } catch (\RuntimeException $e) {
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
            $model->name = WorkflowStepsInput::text($body['name']);
        }
        if (array_key_exists('description', $body)) {
            $model->description = $body['description'] === null ? null : WorkflowStepsInput::text($body['description']);
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
            return $this->error(WorkflowStepsInput::firstError($model), 422);
        }
        $steps = null;
        if (($body['steps'] ?? null) !== null) {
            $steps = WorkflowStepsInput::read($body['steps']);
            if (is_string($steps)) {
                return $this->error($steps, 422);
            }
            $rejected = $this->stepsPolicyError($model, $steps) ?? $this->referenceError($steps);
            if ($rejected !== null) {
                return $rejected;
            }
        }
        $rejected = $this->persist($model, $steps);
        if ($rejected !== null) {
            return $rejected;
        }
        $this->audit($auditAction, $model, $steps);

        return null;
    }

    /**
     * 403 when the caller may not change the steps to these: approval steps
     * and gated job steps are for admins. Checked here before the
     * references; the workflow's own steps are checked again while it is
     * locked (WorkflowStepChangeGuard).
     *
     * @param list<WorkflowStep> $steps
     * @return array{error: array<string, mixed>}|null
     */
    private function stepsPolicyError(WorkflowTemplate $model, array $steps): ?array
    {
        /** @var ApprovalRuleAssignmentPolicy $policy */
        $policy = \Yii::$app->get('approvalRuleAssignmentPolicy');
        $refusal = $policy->stepsAddRefusal((int)$this->currentUserId(), $model, $steps);

        return $refusal === null ? null : $this->error($refusal, 403);
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
     * Steps are replaced through WorkflowStepChangeGuard: with the workflow
     * locked, 403 for a caller who may not change them, 409 while a run is
     * active, and 404 when the workflow was deleted meanwhile. Whether a new
     * job step is gated was asked before (stepsPolicyError()): a rule that a
     * job template gets in between is one it got right after, and locks the
     * workflow from then on. Returns the error response, or null.
     *
     * @param list<WorkflowStep>|null $steps null keeps the current steps
     * @return array{error: array<string, mixed>}|null
     */
    private function persist(WorkflowTemplate $model, ?array $steps): ?array
    {
        if ($steps === null) {
            return $model->save() ? null : $this->error(WorkflowStepsInput::firstError($model), 422);
        }
        /** @var WorkflowStepChangeGuard $guard */
        $guard = \Yii::$app->get('workflowStepChangeGuard');
        try {
            $guard->change(
                $model,
                (int)$this->currentUserId(),
                ApprovalRuleAssignmentPolicy::includesApprovalStep($steps),
                fn () => $this->saveAll($model, $steps)
            );
        } catch (WorkflowStepChangeRefusedException $e) {
            if ($e->status === WorkflowStepChangeRefusedException::NOT_FOUND) {
                throw new NotFoundHttpException($e->getMessage(), 0, $e);
            }
            return $this->errorWithDetails($e->getMessage(), $e->status, $e->details);
        }

        return null;
    }

    /**
     * Save the template, then replace its steps. A refusal (422) rolls back
     * the guard's transaction.
     *
     * @param list<WorkflowStep> $steps
     * @throws WorkflowStepChangeRefusedException when the template or a step cannot be saved
     */
    private function saveAll(WorkflowTemplate $model, array $steps): void
    {
        if (!$model->save()) {
            throw new WorkflowStepChangeRefusedException(
                WorkflowStepsInput::firstError($model),
                WorkflowStepChangeRefusedException::INVALID
            );
        }
        WorkflowStep::deleteAll(['workflow_template_id' => $model->id]);
        foreach ($steps as $index => $step) {
            $step->workflow_template_id = (int)$model->id;
            if (!$step->save()) {
                throw new WorkflowStepChangeRefusedException(
                    sprintf('steps[%d]: %s', $index, WorkflowStepsInput::firstError($step)),
                    WorkflowStepChangeRefusedException::INVALID
                );
            }
        }
    }

    /**
     * The audit entry names the job templates and the approval rules of the
     * new steps, when steps were given.
     *
     * @param list<WorkflowStep>|null $steps
     */
    private function audit(string $action, WorkflowTemplate $model, ?array $steps): void
    {
        $context = ['name' => $model->name, 'source' => 'api'];
        if ($steps !== null) {
            $context['job_template_ids'] = self::presentIds(
                array_map(static fn (WorkflowStep $s): ?int => $s->job_template_id, $steps)
            );
            $context['approval_rule_ids'] = self::presentIds(
                array_map(static fn (WorkflowStep $s): ?int => $s->approval_rule_id, $steps)
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

    private function workflows(): WorkflowAccessChecker
    {
        /** @var WorkflowAccessChecker $checker */
        $checker = \Yii::$app->get('workflowAccessChecker');
        return $checker;
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
