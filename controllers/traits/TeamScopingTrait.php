<?php

declare(strict_types=1);

namespace app\controllers\traits;

use app\services\ProjectAccessChecker;
use yii\web\ForbiddenHttpException;

/**
 * Provides team-scoping helpers for web controllers.
 *
 * Centralizes access checks for child resources (job templates, inventories,
 * schedules, jobs) that derive access from their parent project.
 */
trait TeamScopingTrait
{
    protected function checker(): ProjectAccessChecker
    {
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');
        return $checker;
    }

    protected function currentUserId(): ?int
    {
        return \Yii::$app->user->isGuest ? null : (int)\Yii::$app->user->id;
    }

    /**
     * @param int|string|null $projectId
     */
    protected function requireChildView($projectId): void
    {
        $pid = $this->normalizeProjectId($projectId);
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canViewChildResource($userId, $pid)) {
            throw new ForbiddenHttpException('You do not have access to this resource.');
        }
    }

    /**
     * @param int|string|null $projectId
     */
    protected function requireChildOperate($projectId): void
    {
        $pid = $this->normalizeProjectId($projectId);
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canOperateChildResource($userId, $pid)) {
            throw new ForbiddenHttpException('You do not have permission to modify this resource.');
        }
    }

    /**
     * @param int|string|null $value
     */
    private function normalizeProjectId($value): ?int
    {
        if ($value === null || $value === '' || $value === '0') {
            return null;
        }
        return (int)$value;
    }
}
