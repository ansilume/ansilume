<?php

declare(strict_types=1);

namespace app\controllers\api\v1\traits;

use app\services\ProjectAccessChecker;

/**
 * Provides team-scoping helpers for API controllers.
 *
 * API controllers return error arrays instead of throwing exceptions,
 * so callers check the return value of requireChildAccess() inline.
 */
trait ApiTeamScopingTrait
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
}
