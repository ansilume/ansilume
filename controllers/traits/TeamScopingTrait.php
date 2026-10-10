<?php

declare(strict_types=1);

namespace app\controllers\traits;

use app\services\ProjectAccessChecker;
use app\services\WorkflowAccessChecker;
use yii\base\Model;
use yii\validators\NumberValidator;
use yii\web\ForbiddenHttpException;

/**
 * Provides team-scoping helpers for web controllers.
 *
 * Centralizes access checks for child resources (job templates, inventories,
 * schedules, jobs) that derive access from their parent project, and for
 * workflows, which belong to the projects of their job steps. A submitted
 * reference ID is checked as the int it is stored as (normalizeSubmittedId()).
 */
trait TeamScopingTrait
{
    protected function checker(): ProjectAccessChecker
    {
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');
        return $checker;
    }

    protected function workflowChecker(): WorkflowAccessChecker
    {
        /** @var WorkflowAccessChecker $checker */
        $checker = \Yii::$app->get('workflowAccessChecker');
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
     * 403 unless the current user may view the workflow template: view
     * access to the project of every job step. Also guards the template's
     * runs, by their workflow_template_id.
     */
    protected function requireWorkflowView(int $workflowTemplateId): void
    {
        $userId = $this->currentUserId();
        if ($userId === null || !$this->workflowChecker()->canViewWorkflowTemplate($userId, $workflowTemplateId)) {
            throw new ForbiddenHttpException('You do not have access to this resource.');
        }
    }

    /**
     * 403 unless the current user may change, launch, resume or cancel the
     * workflow template: operator access to the project of every job step.
     */
    protected function requireWorkflowOperate(int $workflowTemplateId): void
    {
        $userId = $this->currentUserId();
        if ($userId === null || !$this->workflowChecker()->canOperateWorkflowTemplate($userId, $workflowTemplateId)) {
            throw new ForbiddenHttpException('You do not have permission to modify this resource.');
        }
    }

    /**
     * The submitted reference ID as the model stores it, assigned back to
     * the attribute, so that the access check and the save use one value.
     *
     * The model's integer rule accepts more than filter_var() does, such as
     * "0112709" and "+0112709", and the integer column stores both as
     * 112709: an access check on a stricter parse lets them through
     * unchecked. Returns null and leaves the attribute as it is when the
     * value is empty or the integer rule rejects it; the model's rules
     * report that.
     */
    protected function normalizeSubmittedId(Model $model, string $attribute): ?int
    {
        $value = $model->$attribute;
        $integerRule = new NumberValidator(['integerOnly' => true]);
        if (!(is_int($value) || is_float($value) || is_string($value)) || !$integerRule->validate($value)) {
            return null;
        }
        $id = (int)$value;
        $model->$attribute = $id;

        return $id;
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
