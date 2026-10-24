<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\AuditLog;
use app\models\Schedule;
use app\controllers\api\v1\traits\ApiTeamScopingTrait;
use app\services\ScheduleEnablement;
use app\services\ScheduleRunAs;
use app\services\ScheduleSecrets;
use app\services\ScheduleService;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\web\NotFoundHttpException;

/**
 * API v1: Schedules
 *
 * GET    /api/v1/schedules
 * GET    /api/v1/schedules/{id}
 * POST   /api/v1/schedules
 * PUT    /api/v1/schedules/{id}
 * DELETE /api/v1/schedules/{id}
 * POST   /api/v1/schedules/{id}/toggle
 */
class SchedulesController extends BaseApiController
{
    use ApiTeamScopingTrait;

    protected function apiAccessRules(): array
    {
        return [
            'index' => 'job.launch',
            'view' => 'job.launch',
            'create' => 'job.launch',
            'update' => 'job.launch',
            'delete' => 'job.launch',
            'toggle' => 'job.launch',
        ];
    }

    /**
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    public function actionIndex(): array
    {
        // The job templates come with the page: their password fields mask the extra vars.
        $query = Schedule::find()->with('jobTemplate')->orderBy(['id' => SORT_DESC]);
        $filter = $this->checker()->buildJobFilter($this->currentUserId());
        if ($filter !== null) {
            $query->andWhere($filter);
        }

        $dp = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 25],
        ]);
        $page = $this->requestedPage();

        return $this->paginated(
            array_map(fn ($s) => $this->serialize($s), $dp->getModels()),
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
        $userId = $this->currentUserId();
        if ($userId === null || !$this->schedules()->canView($userId, $model)) {
            return $this->error('Forbidden.', 403);
        }
        return $this->success($this->serialize($model));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionCreate(): array
    {
        if (!$this->userCan('job.launch')) {
            return $this->error('Forbidden.', 403);
        }

        $userId = (int)\Yii::$app->user->id;
        $model = new Schedule();
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);
        // Set in code only: the schedule launches its jobs as this user.
        $model->created_by = $userId;

        return $this->saveSchedule($model, $userId, AuditLog::ACTION_SCHEDULE_CREATED, 201, array_key_exists('extra_vars', $body));
    }

    /**
     * A change to what or when the schedule runs (job_template_id,
     * cron_expression, timezone, extra_vars, a password answer sent anew or
     * dropped) or enabled: true for a disabled schedule makes the caller the
     * user the schedule runs as (created_by; see ScheduleRunAs).
     *
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionUpdate(int $id): array
    {
        if (!$this->userCan('job.launch')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $userId = $this->currentUserId();
        if ($userId === null || !$this->schedules()->canOperate($userId, $model)) {
            return $this->error('Forbidden.', 403);
        }
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);

        return $this->saveSchedule($model, $userId, AuditLog::ACTION_SCHEDULE_UPDATED, 200, array_key_exists('extra_vars', $body));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionDelete(int $id): array
    {
        if (!$this->userCan('job.launch')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $userId = $this->currentUserId();
        if ($userId === null || !$this->schedules()->canOperate($userId, $model)) {
            return $this->error('Forbidden.', 403);
        }
        $name = $model->name;
        $model->delete();

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_SCHEDULE_DELETED,
            'schedule',
            $id,
            null,
            ['name' => $name, 'source' => 'api']
        );

        return $this->success(['deleted' => true]);
    }

    /**
     * Enable a disabled schedule or disable an enabled one. Enabling a
     * schedule whose job template was deleted answers 422
     * (ScheduleEnablement::ENABLE_REFUSED) and changes nothing. Enabling
     * makes the caller the user the schedule runs as (created_by).
     *
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionToggle(int $id): array
    {
        $schedule = $this->findModel($id);
        $userId = $this->currentUserId();
        if ($userId === null || !$this->schedules()->canOperate($userId, $schedule)) {
            return $this->error('Forbidden.', 403);
        }
        $enablement = new ScheduleEnablement();
        $refusal = $enablement->toggle($schedule, $userId);
        if ($refusal !== null) {
            return $this->error($refusal, 422);
        }
        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_SCHEDULE_TOGGLED,
            'schedule',
            $schedule->id,
            null,
            ['name' => $schedule->name, 'enabled' => $schedule->enabled] + $enablement->runAsContext()
        );
        return $this->success($this->serialize($schedule));
    }

    /**
     * Validate and save a schedule after the request body was applied. The
     * job template it now points at must be one the caller may operate: one
     * they cannot see is reported like an unknown one (422), one they see
     * but may not operate is forbidden (403).
     *
     * Password answers in the extra vars are sealed last (ScheduleSecrets),
     * once the template is known to be one the caller may use: their errors
     * (422) name the template's password fields. A body without extra_vars
     * keeps the stored extra vars and answers, unless the template changes.
     *
     * An update that arms the schedule makes the caller the user it runs as
     * in the same save (ScheduleRunAs); a new schedule runs as the caller
     * already.
     *
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    private function saveSchedule(Schedule $model, int $userId, string $auditAction, int $status, bool $extraVarsSubmitted): array
    {
        $refused = $this->checkTemplate($model, $userId);
        if ($refused !== null) {
            return $refused;
        }

        $model->computeNextRunAt();
        if (!$model->validate()) {
            return $this->error($this->firstError($model), 422);
        }
        // Taken before prepare(), which drops the stored extra vars from the old attributes.
        $runAs = new ScheduleRunAs($model);
        $secrets = new ScheduleSecrets();
        $secretsError = $secrets->prepare($model, $extraVarsSubmitted);
        if ($secretsError !== null) {
            return $this->error($secretsError, 422);
        }
        $runAs->claim($userId, $secrets->answersChanged());
        if (!$model->save(false)) {
            return $this->error('Failed to save schedule.', 422);
        }

        \Yii::$app->get('auditService')->log(
            $auditAction,
            'schedule',
            $model->id,
            null,
            ['name' => $model->name, 'source' => 'api'] + $secrets->auditContext() + $runAs->auditContext()
        );

        return $this->success($this->serialize($model), $status);
    }

    /**
     * The error for a job template the caller may not schedule, or null. A
     * body without job_template_id is left to the model's rules.
     *
     * @return array{error: array{message: string}}|null
     */
    private function checkTemplate(Schedule $model, int $userId): ?array
    {
        if ($model->job_template_id === null) {
            return null;
        }

        return match ($this->schedules()->templateAccess($userId, (int)$model->job_template_id)) {
            ScheduleService::TEMPLATE_MISSING => $this->error(ScheduleService::TEMPLATE_MISSING_MESSAGE, 422),
            ScheduleService::TEMPLATE_FORBIDDEN => $this->error('Forbidden.', 403),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyBody(Schedule $model, #[\SensitiveParameter] array $body): void
    {
        foreach (['name', 'cron_expression', 'timezone', 'extra_vars'] as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = $body[$field];
            if ($value === null && $field === 'extra_vars') {
                $model->$field = null;
            } else {
                $model->$field = (string)$value;
            }
        }
        if (array_key_exists('job_template_id', $body)) {
            $model->job_template_id = (int)$body['job_template_id'];
        }
        if (array_key_exists('enabled', $body)) {
            $model->enabled = (bool)$body['enabled'];
        }
    }

    /**
     * created_by is the user the schedule launches its jobs as: its creator,
     * or the user who last changed what or when it runs, or enabled it.
     *
     * cron_expression_problem is why a save refuses the stored cron
     * expression, which an older version saved, or null
     * (Schedule::cronExpressionProblem()).
     *
     * @return array{id: int, name: string, job_template_id: int, cron_expression: string, cron_expression_problem: string|null, timezone: string, extra_vars: string|null, enabled: bool, last_run_at: int|null, next_run_at: int|null, created_by: int, created_at: int, updated_at: int}
     */
    private function serialize(Schedule $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'job_template_id' => $s->job_template_id,
            'cron_expression' => $s->cron_expression,
            'cron_expression_problem' => $s->cronExpressionProblem(),
            'timezone' => $s->timezone,
            'extra_vars' => self::extraVarsOf($s),
            'enabled' => (bool)$s->enabled,
            'last_run_at' => $s->last_run_at,
            'next_run_at' => $s->next_run_at,
            'created_by' => (int)$s->created_by,
            'created_at' => $s->created_at,
            'updated_at' => $s->updated_at,
        ];
    }

    /**
     * The extra vars as the schedule's edit form shows them, with
     * `$encrypted$` for every password answer, also for one the stored
     * column still holds in plain text: never the column as such. Null when
     * there are none.
     */
    private static function extraVarsOf(Schedule $s): ?string
    {
        $masked = ScheduleSecrets::maskedExtraVars($s);

        return $masked === null || $masked === '' ? null : $masked;
    }

    private function findModel(int $id): Schedule
    {
        /** @var Schedule|null $schedule */
        $schedule = Schedule::findOne($id);
        if ($schedule === null) {
            throw new NotFoundHttpException("Schedule #{$id} not found.");
        }
        return $schedule;
    }

    private function schedules(): ScheduleService
    {
        /** @var ScheduleService $service */
        $service = \Yii::$app->get('scheduleService');
        return $service;
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
