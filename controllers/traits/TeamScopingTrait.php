<?php

declare(strict_types=1);

namespace app\controllers\traits;

use app\components\SubmittedId;
use app\controllers\BaseController;
use app\services\ProjectAccessChecker;
use app\services\WorkflowAccessChecker;
use yii\base\Model;
use yii\db\BaseActiveRecord;
use yii\web\ForbiddenHttpException;

/**
 * Provides team-scoping helpers for web controllers.
 *
 * Centralizes access checks for child resources (job templates, inventories,
 * schedules, jobs) that derive access from their parent project, and for
 * workflows, which belong to the projects of their job steps. A submitted
 * reference ID is checked as the int it is stored as (normalizeSubmittedId()).
 *
 * A project that a request submitted is checked as the save stores it
 * (normalizeProjectId()). A value that is neither none nor a project ID,
 * such as a fraction, a text or a JSON number beyond the int range, names no
 * project that a check could clear: the checks refuse it, never taking it for
 * no project, which every user may reach; a form asks
 * refusesSubmittedProject() first, so that it shows the model's error for it.
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
     * 403 unless the current user may view a child resource of the project
     * $projectId names, stored or submitted (normalizeProjectId()).
     */
    protected function requireChildView(mixed $projectId): void
    {
        $pid = $this->normalizeProjectId($projectId);
        $userId = $this->currentUserId();
        if ($userId === null || $pid === false || !$this->checker()->canViewChildResource($userId, $pid)) {
            throw new ForbiddenHttpException('You do not have access to this resource.');
        }
    }

    /**
     * 403 unless the current user may operate a child resource of the
     * project $projectId names, stored or submitted (normalizeProjectId()).
     */
    protected function requireChildOperate(mixed $projectId): void
    {
        $pid = $this->normalizeProjectId($projectId);
        $userId = $this->currentUserId();
        if ($userId === null || $pid === false || !$this->checker()->canOperateChildResource($userId, $pid)) {
            throw new ForbiddenHttpException(BaseController::MODIFY_FORBIDDEN);
        }
    }

    /**
     * Whether the project that a request submitted into $model is refused
     * before the access check reads it: a value that names no project the
     * check could clear (normalizeProjectId()). Its rules then report it on
     * $model, for the form to show; nothing was checked, and the caller
     * saves nothing.
     */
    protected function refusesSubmittedProject(BaseActiveRecord $model): bool
    {
        if ($this->normalizeProjectId($model->getAttribute('project_id')) !== false) {
            return false;
        }
        $model->validate(['project_id']);

        return true;
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
     * The template page answers the same text (BaseController::MODIFY_FORBIDDEN)
     * when the step write path finds the same with the workflow locked.
     */
    protected function requireWorkflowOperate(int $workflowTemplateId): void
    {
        $userId = $this->currentUserId();
        if ($userId === null || !$this->workflowChecker()->canOperateWorkflowTemplate($userId, $workflowTemplateId)) {
            throw new ForbiddenHttpException(BaseController::MODIFY_FORBIDDEN);
        }
    }

    /**
     * The submitted reference ID as the model stores it, assigned back to
     * the attribute, so that the access check and the save use one value
     * (SubmittedId::normalize(), which the step write path uses too).
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
        return SubmittedId::normalize($model, $attribute);
    }

    /**
     * The project $value names, read as the model's integer rule reads an
     * ID, so that the check and the save use one value (SubmittedId: "05",
     * "+5" and 5.0 are 5); null for no project: null, '' and '0', and [],
     * which the rules take for none too. False for any other value, such as
     * a fraction, a text or a JSON number beyond the int range (the (int)
     * cast it had warns on PHP 8.5): it names no project a check could
     * clear, and taken for none it would pass as one every user may reach.
     */
    private function normalizeProjectId(mixed $value): int|false|null
    {
        if ($value === null || $value === '' || $value === '0' || $value === []) {
            return null;
        }

        return SubmittedId::of($value) ?? false;
    }
}
