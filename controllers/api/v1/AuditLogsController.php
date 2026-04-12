<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\AuditLog;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;

/**
 * API v1: Audit Logs (read-only)
 *
 * GET /api/v1/audit-logs
 * GET /api/v1/audit-logs/{id}
 */
class AuditLogsController extends BaseApiController
{
    /**
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}|array{error: array{message: string}}
     */
    public function actionIndex(): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('user.view')) {
            return $this->error('Forbidden.', 403);
        }

        $query = AuditLog::find()->orderBy(['id' => SORT_DESC]);

        /** @var string|null $action */
        $action = \Yii::$app->request->get('action');
        if ($action !== null && $action !== '') {
            $query->andWhere(['action' => $action]);
        }

        /** @var string|null $userId */
        $userId = \Yii::$app->request->get('user_id');
        if ($userId !== null && $userId !== '') {
            $query->andWhere(['user_id' => (int)$userId]);
        }

        /** @var string|null $objectType */
        $objectType = \Yii::$app->request->get('object_type');
        if ($objectType !== null && $objectType !== '') {
            $query->andWhere(['object_type' => $objectType]);
        }

        $dp = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 25],
        ]);
        /** @var int $page */
        $page = \Yii::$app->request->get('page', 1);

        return $this->paginated(
            array_map(fn ($l) => $this->serialize($l), $dp->getModels()),
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
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('user.view')) {
            return $this->error('Forbidden.', 403);
        }
        return $this->success($this->serialize($this->findModel($id)));
    }

    /**
     * @return array{id: int, user_id: int|null, action: string, object_type: string|null, object_id: int|null, metadata: mixed, ip_address: string|null, created_at: int}
     */
    private function serialize(AuditLog $l): array
    {
        return [
            'id' => $l->id,
            'user_id' => $l->user_id,
            'action' => $l->action,
            'object_type' => $l->object_type,
            'object_id' => $l->object_id,
            'metadata' => $l->metadata !== null ? json_decode($l->metadata, true) : null,
            'ip_address' => $l->ip_address,
            'created_at' => $l->created_at,
        ];
    }

    private function findModel(int $id): AuditLog
    {
        /** @var AuditLog|null $l */
        $l = AuditLog::findOne($id);
        if ($l === null) {
            throw new NotFoundHttpException("Audit log #{$id} not found.");
        }
        return $l;
    }
}
