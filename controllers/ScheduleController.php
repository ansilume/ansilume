<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\Schedule;
use app\controllers\traits\TeamScopingTrait;
use yii\data\ActiveDataProvider;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ScheduleController extends BaseController
{
    use TeamScopingTrait;

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'], 'allow' => true, 'roles' => ['job.launch']],
            ['actions' => ['create', 'update'], 'allow' => true, 'roles' => ['job.launch']],
            ['actions' => ['delete', 'toggle'], 'allow' => true, 'roles' => ['job.launch']],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    protected function verbRules(): array
    {
        return [
            'delete' => ['POST'],
            'toggle' => ['POST'],
        ];
    }

    public function actionIndex(): string
    {
        $query = Schedule::find()->with(['jobTemplate', 'creator'])->orderBy(['id' => SORT_DESC]);

        $filter = $this->buildScheduleFilter();
        if ($filter !== null) {
            $query->andWhere($filter);
        }

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 25],
        ]);
        return $this->render('index', ['dataProvider' => $dataProvider]);
    }

    public function actionView(int $id): string
    {
        $model = $this->findModel($id);
        $this->requireScheduleView($model);
        return $this->render('view', ['model' => $model]);
    }

    public function actionCreate(): Response|string
    {
        $model = new Schedule();
        $model->timezone = 'UTC';
        $model->enabled = true;

        if ($model->load((array)\Yii::$app->request->post())) {
            $this->requireScheduleOperate($model);
            $model->created_by = (int)(\Yii::$app->user->id ?? 0);
            $model->computeNextRunAt();
            if ($model->save()) {
                \Yii::$app->get('auditService')->log(
                    AuditLog::ACTION_SCHEDULE_CREATED,
                    'schedule',
                    $model->id,
                    null,
                    ['name' => $model->name, 'cron' => $model->cron_expression]
                );
                $this->session()->setFlash('success', "Schedule \"{$model->name}\" created.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }

        return $this->render('form', [
            'model' => $model,
            'templates' => $this->getTemplateList(),
        ]);
    }

    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);
        $this->requireScheduleOperate($model);

        if ($model->load((array)\Yii::$app->request->post())) {
            $model->computeNextRunAt();
            if ($model->save()) {
                \Yii::$app->get('auditService')->log(
                    AuditLog::ACTION_SCHEDULE_UPDATED,
                    'schedule',
                    $model->id,
                    null,
                    ['name' => $model->name]
                );
                $this->session()->setFlash('success', "Schedule \"{$model->name}\" updated.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }

        return $this->render('form', [
            'model' => $model,
            'templates' => $this->getTemplateList(),
        ]);
    }

    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireScheduleOperate($model);
        $name = $model->name;
        $model->delete();
        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_SCHEDULE_DELETED,
            'schedule',
            $id,
            null,
            ['name' => $name]
        );
        $this->session()->setFlash('success', "Schedule \"{$name}\" deleted.");
        return $this->redirect(['index']);
    }

    public function actionToggle(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireScheduleOperate($model);
        $model->enabled = !$model->enabled;
        if ($model->enabled) {
            $model->computeNextRunAt();
        } else {
            $model->next_run_at = null;
        }
        $model->save(false, ['enabled', 'next_run_at', 'updated_at']);
        $state = $model->enabled ? 'enabled' : 'disabled';
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_SCHEDULE_TOGGLED, 'schedule', $model->id, null, ['name' => $model->name, 'enabled' => $model->enabled]);
        $this->session()->setFlash('success', "Schedule \"{$model->name}\" {$state}.");
        return $this->redirect(['index']);
    }

    private function findModel(int $id): Schedule
    {
        /** @var Schedule|null $model */
        $model = Schedule::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Schedule #{$id} not found.");
        }
        return $model;
    }

    /**
     * @return array<int, string>
     */
    private function getTemplateList(): array
    {
        $query = JobTemplate::find()
            ->select(['id', 'name', 'project_id'])
            ->orderBy('name');

        $filter = $this->checker()->buildChildResourceFilter($this->currentUserId(), 'job_template.project_id');
        if ($filter !== null) {
            $query->andWhere($filter);
        }

        /** @var array<int, array{id: int, name: string}> $rows */
        $rows = $query->asArray()->all();

        $list = [];
        foreach ($rows as $row) {
            $list[$row['id']] = $row['name'] . ' (' . $row['id'] . ')';
        }
        return $list;
    }

    /**
     * Build a schedule filter via job_template subquery.
     *
     * @return array<int|string, mixed>|null
     */
    private function buildScheduleFilter(): ?array
    {
        $jobFilter = $this->checker()->buildJobFilter($this->currentUserId());
        if ($jobFilter === null) {
            return null;
        }
        // buildJobFilter returns ['in', 'job_template_id', $subquery]
        // Schedules also use job_template_id, so reuse directly
        return $jobFilter;
    }

    private function requireScheduleView(Schedule $model): void
    {
        $projectId = $model->jobTemplate->project_id ?? null;
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canViewChildResource($userId, $projectId)) {
            throw new ForbiddenHttpException('You do not have access to this resource.');
        }
    }

    private function requireScheduleOperate(Schedule $model): void
    {
        $projectId = $model->jobTemplate->project_id ?? null;
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canOperateChildResource($userId, $projectId)) {
            throw new ForbiddenHttpException('You do not have permission to modify this resource.');
        }
    }
}
