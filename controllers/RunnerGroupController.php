<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\AuditLog;
use app\models\RunnerGroup;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class RunnerGroupController extends BaseController
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'], 'allow' => true, 'roles' => ['runner-group.view']],
            ['actions' => ['create'], 'allow' => true, 'roles' => ['runner-group.create']],
            ['actions' => ['update'], 'allow' => true, 'roles' => ['runner-group.update']],
            ['actions' => ['delete'], 'allow' => true, 'roles' => ['runner-group.delete']],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    protected function verbRules(): array
    {
        return ['delete' => ['POST']];
    }

    public function actionIndex(): string
    {
        $groups = RunnerGroup::find()->orderBy('name')->all();

        // Attach counts without N+1
        $ids = array_column($groups, 'id');
        $total = [];
        $online = [];

        if (!empty($ids)) {
            $cutoff = time() - RunnerGroup::STALE_AFTER;

            $rows = \Yii::$app->db->createCommand(
                'SELECT runner_group_id, COUNT(*) AS cnt FROM {{%runner}} WHERE runner_group_id IN (' . implode(',', $ids) . ') GROUP BY runner_group_id'
            )->queryAll();
            foreach ($rows as $r) {
                $total[(int)$r['runner_group_id']] = (int)$r['cnt'];
            }

            $rows = \Yii::$app->db->createCommand(
                'SELECT runner_group_id, COUNT(*) AS cnt FROM {{%runner}} WHERE runner_group_id IN (' . implode(',', $ids) . ') AND last_seen_at >= :cutoff GROUP BY runner_group_id',
                [':cutoff' => $cutoff]
            )->queryAll();
            foreach ($rows as $r) {
                $online[(int)$r['runner_group_id']] = (int)$r['cnt'];
            }
        }

        // Template counts per runner group
        $templateCounts = [];
        if (!empty($ids)) {
            $rows = \Yii::$app->db->createCommand(
                'SELECT runner_group_id, COUNT(*) AS cnt FROM {{%job_template}} WHERE runner_group_id IN (' . implode(',', $ids) . ') GROUP BY runner_group_id'
            )->queryAll();
            foreach ($rows as $r) {
                $templateCounts[(int)$r['runner_group_id']] = (int)$r['cnt'];
            }
        }

        return $this->render('index', compact('groups', 'total', 'online', 'templateCounts'));
    }

    public function actionView(int $id): string
    {
        $group = $this->findModel($id);
        $runners = $group->getRunners()->orderBy('name')->all();
        return $this->render('view', compact('group', 'runners'));
    }

    public function actionCreate(): string|Response
    {
        $model = new RunnerGroup();
        $model->created_by = (int)\Yii::$app->user->id;

        if ($model->load(\Yii::$app->request->post()) && $model->save()) {
            \Yii::$app->get('auditService')->log(AuditLog::ACTION_RUNNER_GROUP_CREATED, 'runner_group', $model->id, null, ['name' => $model->name]);
            $this->session()->setFlash('success', "Runner group \"{$model->name}\" created.");
            return $this->redirect(['view', 'id' => $model->id]);
        }

        return $this->render('form', ['model' => $model]);
    }

    public function actionUpdate(int $id): string|Response
    {
        $model = $this->findModel($id);

        if ($model->load(\Yii::$app->request->post()) && $model->save()) {
            \Yii::$app->get('auditService')->log(AuditLog::ACTION_RUNNER_GROUP_UPDATED, 'runner_group', $model->id, null, ['name' => $model->name]);
            $this->session()->setFlash('success', 'Runner group updated.');
            return $this->redirect(['view', 'id' => $model->id]);
        }

        return $this->render('form', ['model' => $model]);
    }

    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        $name = $model->name;
        $model->delete();
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_RUNNER_GROUP_DELETED, 'runner_group', $id, null, ['name' => $name]);
        $this->session()->setFlash('success', 'Runner group deleted.');
        return $this->redirect(['index']);
    }

    private function findModel(int $id): RunnerGroup
    {
        $model = RunnerGroup::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Runner group #{$id} not found.");
        }
        return $model;
    }
}
