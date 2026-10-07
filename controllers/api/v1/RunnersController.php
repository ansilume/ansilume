<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\AuditLog;
use app\models\Job;
use app\models\Runner;
use app\models\RunnerGroup;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;

/**
 * API v1: Runners
 *
 * GET    /api/v1/runners
 * GET    /api/v1/runners/{id}
 * POST   /api/v1/runners/{id}/move
 * DELETE /api/v1/runners/{id}
 * POST   /api/v1/runners/{id}/regenerate-token
 */
class RunnersController extends BaseApiController
{
    protected function apiAccessRules(): array
    {
        return [
            'index' => 'runner-group.view',
            'view' => 'runner-group.view',
            'move' => 'runner-group.update',
            'delete' => 'runner-group.update',
            'regenerate-token' => 'runner-group.update',
        ];
    }

    /**
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    public function actionIndex(): array
    {
        $query = Runner::find()->orderBy(['id' => SORT_DESC]);

        /** @var int|string $groupId */
        $groupId = \Yii::$app->request->get('group_id', '');
        if ($groupId !== '') {
            $query->andWhere(['runner_group_id' => (int)$groupId]);
        }

        $dp = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 25],
        ]);

        $page = $this->requestedPage();

        /** @var Runner[] $runners */
        $runners = $dp->getModels();
        $reregistrations = $this->reregistrations(array_map(static fn (Runner $r): int => (int)$r->id, $runners));

        return $this->paginated(
            array_map(fn (Runner $r) => $this->serialize($r, $reregistrations[$r->id] ?? null), $runners),
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
        return $this->success($this->serializeOne($this->findModel($id)));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionMove(int $id): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('runner-group.update')) {
            return $this->error('Forbidden.', 403);
        }

        $runner = $this->findModel($id);
        $sourceGroupId = $runner->runner_group_id;

        $body = (array)\Yii::$app->request->bodyParams;
        if (!isset($body['target_group_id'])) {
            return $this->error('target_group_id is required.', 422);
        }
        $targetGroupId = (int)$body['target_group_id'];

        if ($targetGroupId === $sourceGroupId) {
            return $this->error('Runner is already in that group.', 422);
        }

        /** @var RunnerGroup|null $targetGroup */
        $targetGroup = RunnerGroup::findOne($targetGroupId);
        if ($targetGroup === null) {
            return $this->error('Target runner group not found.', 404);
        }

        $hasActiveJobs = Job::find()
            ->where(['runner_id' => $runner->id])
            ->andWhere(['in', 'status', [Job::STATUS_RUNNING, Job::STATUS_PENDING]])
            ->exists();

        if ($hasActiveJobs) {
            return $this->error('Cannot move runner — it has active or pending jobs.', 422);
        }

        $runner->runner_group_id = $targetGroup->id;
        $runner->save(false);

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_RUNNER_UPDATED,
            'runner',
            $runner->id,
            null,
            [
                'name' => $runner->name,
                'from_group_id' => $sourceGroupId,
                'to_group_id' => $targetGroup->id,
                'source' => 'api',
            ]
        );

        return $this->success($this->serializeOne($runner));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionDelete(int $id): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('runner-group.update')) {
            return $this->error('Forbidden.', 403);
        }

        $runner = $this->findModel($id);
        $name = $runner->name;
        $groupId = $runner->runner_group_id;
        $runner->delete();

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_RUNNER_DELETED,
            'runner',
            $id,
            null,
            ['name' => $name, 'group_id' => $groupId, 'source' => 'api']
        );

        return $this->success(['deleted' => true]);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionRegenerateToken(int $id): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('runner-group.update')) {
            return $this->error('Forbidden.', 403);
        }

        $runner = $this->findModel($id);
        $token = Runner::generateToken();
        $runner->token_hash = $token['hash'];
        $runner->save(false);

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_RUNNER_TOKEN_REGENERATED,
            'runner',
            $runner->id,
            null,
            ['name' => $runner->name, 'source' => 'api']
        );

        return $this->success([
            'runner' => $this->serializeOne($runner),
            'token' => $token['raw'],
        ]);
    }

    /**
     * @param array{last_at: int, recent: int}|null $reregistration
     * @return array{id: int, name: string, description: string|null, runner_group_id: int, runner_group_name: string|null, is_online: bool, last_seen_at: int|null, software_version: string|null, transport: string|null, transport_insecure: bool, remote_addr: string|null, plaintext_seen_at: int|null, last_reregistered_at: int|null, reregistrations_24h: int, created_at: int}
     */
    private function serialize(Runner $r, ?array $reregistration): array
    {
        /** @var RunnerGroup|null $group */
        $group = $r->group;
        return [
            'id' => $r->id,
            'name' => $r->name,
            'description' => $r->description,
            'runner_group_id' => $r->runner_group_id,
            'runner_group_name' => $group?->name,
            'is_online' => $r->isOnline(),
            'last_seen_at' => $r->last_seen_at,
            'software_version' => $r->software_version,
            'transport' => $r->transport,
            'transport_insecure' => $r->hasInsecureTransport(),
            'remote_addr' => $r->remote_addr,
            'plaintext_seen_at' => $r->plaintext_seen_at,
            'last_reregistered_at' => $reregistration['last_at'] ?? null,
            'reregistrations_24h' => $reregistration['recent'] ?? 0,
            'created_at' => $r->created_at,
        ];
    }

    /**
     * @return array{id: int, name: string, description: string|null, runner_group_id: int, runner_group_name: string|null, is_online: bool, last_seen_at: int|null, software_version: string|null, transport: string|null, transport_insecure: bool, remote_addr: string|null, plaintext_seen_at: int|null, last_reregistered_at: int|null, reregistrations_24h: int, created_at: int}
     */
    private function serializeOne(Runner $r): array
    {
        return $this->serialize($r, $this->reregistrations([(int)$r->id])[(int)$r->id] ?? null);
    }

    /**
     * Self-registrations that re-issued a runner's token: latest and count
     * within Runner::REREGISTRATION_WINDOW, one query for all given runners.
     *
     * @param list<int> $runnerIds
     * @return array<int, array{last_at: int, recent: int}>
     */
    private function reregistrations(array $runnerIds): array
    {
        return AuditLog::summarizeByObject(
            AuditLog::ACTION_RUNNER_REREGISTERED,
            'runner',
            $runnerIds,
            time() - Runner::REREGISTRATION_WINDOW
        );
    }

    private function findModel(int $id): Runner
    {
        /** @var Runner|null $r */
        $r = Runner::findOne($id);
        if ($r === null) {
            throw new NotFoundHttpException("Runner #{$id} not found.");
        }
        return $r;
    }
}
