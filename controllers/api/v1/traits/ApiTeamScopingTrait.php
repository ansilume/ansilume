<?php

declare(strict_types=1);

namespace app\controllers\api\v1\traits;

use app\services\ProjectAccessChecker;
use yii\db\BaseActiveRecord;

/**
 * Provides team-scoping helpers for API controllers.
 *
 * API controllers return error arrays instead of throwing exceptions,
 * so callers check the return value of requireChildAccess() inline.
 */
trait ApiTeamScopingTrait
{
    /**
     * Whether the project that the request's body set on $model is refused
     * before the access checks read it: a value that is no project ID, such
     * as a fraction, a boolean, text or a JSON number beyond the int range,
     * which the controller set as sent (it sets a project ID as its int,
     * SubmittedInteger, and none as null). The model's rules then report it
     * on $model, for the 422; nothing was checked, and nothing is saved. No
     * check could clear such a value, and taken for none it would pass as a
     * project every user may reach.
     */
    protected function refusesSubmittedProject(BaseActiveRecord $model): bool
    {
        $projectId = $model->getAttribute('project_id');
        if ($projectId === null || is_int($projectId)) {
            return false;
        }
        $model->validate(['project_id']);

        return true;
    }

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
