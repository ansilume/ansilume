<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\AuditLog;
use app\models\Job;
use app\models\Runner;
use app\models\RunnerGroup;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class RunnerController extends BaseController
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['create', 'delete', 'regenerate-token', 'move'], 'allow' => true, 'roles' => ['runner-group.update']],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    protected function verbRules(): array
    {
        return [
            'create' => ['POST'],
            'delete' => ['POST'],
            'regenerate-token' => ['POST'],
            'move' => ['POST'],
        ];
    }

    public function actionCreate(): Response
    {
        /** @var int|string $rawGroupId */
        $rawGroupId = \Yii::$app->request->post('group_id');
        $groupId = (int)$rawGroupId;
        /** @var RunnerGroup|null $group */
        $group = RunnerGroup::findOne($groupId);

        if ($group === null) {
            $this->session()->setFlash('danger', 'Runner group not found.');
            return $this->redirect(['/runner-group/index']);
        }

        $model = new Runner();
        $model->runner_group_id = $group->id;
        /** @var string $runnerName */
        $runnerName = \Yii::$app->request->post('name', '');
        $model->name = $runnerName;
        /** @var string $runnerDesc */
        $runnerDesc = \Yii::$app->request->post('description', '');
        $model->description = $runnerDesc ?: null;
        $model->created_by = (int)(\Yii::$app->user->id ?? 0);

        $token = Runner::generateToken();
        $model->token_hash = $token['hash'];

        if (!$model->validate() || !$model->save()) {
            $this->session()->setFlash('danger', 'Failed to create runner: ' . json_encode($model->errors));
            return $this->redirect(['/runner-group/view', 'id' => $group->id]);
        }

        \Yii::$app->get('auditService')->log(AuditLog::ACTION_RUNNER_CREATED, 'runner', $model->id, null, ['name' => $model->name, 'group_id' => $group->id]);
        $this->session()->setFlash('runner_token', [
            'runner_id' => $model->id,
            'runner_name' => $model->name,
            'raw_token' => $token['raw'],
        ]);

        return $this->redirect(['/runner-group/view', 'id' => $group->id]);
    }

    public function actionDelete(int $id): Response
    {
        $runner = $this->findModel($id);
        $groupId = $runner->runner_group_id;
        $name = $runner->name;
        $runner->delete();
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_RUNNER_DELETED, 'runner', $id, null, ['name' => $name, 'group_id' => $groupId]);

        $this->session()->setFlash('success', "Runner \"{$name}\" deleted.");
        return $this->redirect(['/runner-group/view', 'id' => $groupId]);
    }

    public function actionMove(int $id): Response
    {
        $runner = $this->findModel($id);
        $sourceGroupId = $runner->runner_group_id;

        /** @var int|string $rawTargetGroupId */
        $rawTargetGroupId = \Yii::$app->request->post('target_group_id');
        $targetGroupId = (int)$rawTargetGroupId;

        if ($targetGroupId === $sourceGroupId) {
            $this->session()->setFlash('warning', 'Runner is already in that group.');
            return $this->redirect(['/runner-group/view', 'id' => $sourceGroupId]);
        }

        /** @var RunnerGroup|null $targetGroup */
        $targetGroup = RunnerGroup::findOne($targetGroupId);
        if ($targetGroup === null) {
            $this->session()->setFlash('danger', 'Target runner group not found.');
            return $this->redirect(['/runner-group/view', 'id' => $sourceGroupId]);
        }

        $hasActiveJobs = Job::find()
            ->where(['runner_id' => $runner->id])
            ->andWhere(['in', 'status', [Job::STATUS_RUNNING, Job::STATUS_PENDING]])
            ->exists();

        if ($hasActiveJobs) {
            $this->session()->setFlash('danger', 'Cannot move runner — it has active or pending jobs. Wait for them to complete.');
            return $this->redirect(['/runner-group/view', 'id' => $sourceGroupId]);
        }

        $runner->runner_group_id = $targetGroup->id;
        $runner->save(false);

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_RUNNER_UPDATED,
            'runner',
            $runner->id,
            null,
            ['name' => $runner->name, 'from_group_id' => $sourceGroupId, 'to_group_id' => $targetGroup->id]
        );

        $this->session()->setFlash('success', "Runner \"{$runner->name}\" moved to group \"{$targetGroup->name}\".");
        return $this->redirect(['/runner-group/view', 'id' => $targetGroup->id]);
    }

    public function actionRegenerateToken(int $id): Response
    {
        $runner = $this->findModel($id);
        $token = Runner::generateToken();

        $runner->token_hash = $token['hash'];
        $runner->save(false);
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_RUNNER_TOKEN_REGENERATED, 'runner', $runner->id, null, ['name' => $runner->name]);

        $this->session()->setFlash('runner_token', [
            'runner_id' => $runner->id,
            'runner_name' => $runner->name,
            'raw_token' => $token['raw'],
        ]);

        return $this->redirect(['/runner-group/view', 'id' => $runner->runner_group_id]);
    }

    private function findModel(int $id): Runner
    {
        /** @var Runner|null $model */
        $model = Runner::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Runner #{$id} not found.");
        }
        return $model;
    }
}
