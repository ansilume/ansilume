<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\AuditLog;
use app\models\Schedule;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;

/**
 * API v1: Schedules (read-only + toggle)
 *
 * GET  /api/v1/schedules
 * GET  /api/v1/schedules/{id}
 * POST /api/v1/schedules/{id}/toggle
 */
class SchedulesController extends BaseApiController
{
    /**
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    public function actionIndex(): array
    {
        $dp = new ActiveDataProvider([
            'query' => Schedule::find()->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 25],
        ]);
        /** @var int $page */
        $page = \Yii::$app->request->get('page', 1);

        return $this->paginated(
            array_map(fn ($s) => $this->serialize($s), $dp->getModels()),
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
        return $this->success($this->serialize($this->findModel($id)));
    }

    /**
     * @return array{data: mixed}
     */
    public function actionToggle(int $id): array
    {
        $schedule = $this->findModel($id);
        $schedule->enabled = !$schedule->enabled;
        if ($schedule->enabled) {
            $schedule->computeNextRunAt();
        }
        $schedule->save(false, ['enabled', 'next_run_at', 'updated_at']);
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_SCHEDULE_TOGGLED, 'schedule', $schedule->id, null, ['name' => $schedule->name, 'enabled' => $schedule->enabled]);
        return $this->success($this->serialize($schedule));
    }

    /**
     * @return array{id: int, name: string, job_template_id: int, cron_expression: string, timezone: string, enabled: bool, last_run_at: int|null, next_run_at: int|null, created_at: int, updated_at: int}
     */
    private function serialize(Schedule $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'job_template_id' => $s->job_template_id,
            'cron_expression' => $s->cron_expression,
            'timezone' => $s->timezone,
            'enabled' => (bool)$s->enabled,
            'last_run_at' => $s->last_run_at,
            'next_run_at' => $s->next_run_at,
            'created_at' => $s->created_at,
            'updated_at' => $s->updated_at,
        ];
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
}
