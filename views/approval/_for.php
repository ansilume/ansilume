<?php

declare(strict_types=1);

/**
 * What an approval request is for, as RequestContext::text() words it: the
 * workflow, step and run of its job (Texts::FOR_WORKFLOW_STEP), which
 * ApprovalRequestContexts names only to a user who may view that workflow,
 * else its job template (Texts::FOR_JOB_TEMPLATE,
 * Texts::FOR_DELETED_JOB_TEMPLATE), else RequestContext::UNKNOWN. The
 * workflow's name links to the workflow for a user with
 * workflow-template.view, unless it was deleted (marked
 * Texts::WORKFLOW_DELETED instead, ../workflow-job/_workflow-name.php), the
 * run to the run page for a user with workflow.view. Used by the approval
 * list, the request page and the dashboard.
 *
 * @var yii\web\View $this
 * @var app\services\runstate\RequestContext|null $context what the request is for; null: nothing names it
 * @var app\services\runstate\Viewer $viewer who looks at the page: the links follow their permissions
 */

use app\controllers\ApprovalController;
use app\services\runstate\RequestContext;
use app\services\runstate\Texts;
use yii\helpers\Html;

$origin = $context?->origin;
if ($origin === null) {
    echo Html::encode($context?->text() ?? RequestContext::UNKNOWN);
} else {
    // A deleted workflow is named without a link, marked deleted.
    $workflow = $this->render('@app/views/workflow-job/_workflow-name', [
        'name' => $origin->workflowName,
        'id' => $origin->workflowTemplateId,
        'deleted' => $origin->workflowDeleted,
        'link' => $viewer->may('workflow-template.view'),
        'linkOptions' => ['data-for-workflow' => $origin->workflowTemplateId],
    ]);
    $runLabel = Html::encode(sprintf(ApprovalController::RUN_LABEL, $origin->workflowJobId));
    $run = $runLabel;
    if ($viewer->may('workflow.view')) {
        $run = Html::a($runLabel, ['/workflow-job/view', 'id' => $origin->workflowJobId], ['data-for-run' => $origin->workflowJobId]);
    }
    // The names go in through placeholders after the text is encoded, so a
    // name that contains the run label (or a placeholder) stays as it is:
    // strtr() does not search what it has put in.
    echo strtr(
        Html::encode(sprintf(Texts::FOR_WORKFLOW_STEP, "\x01workflow", "\x01step", $origin->workflowJobId)),
        ["\x01workflow" => $workflow, "\x01step" => Html::encode($origin->stepName), $runLabel => $run]
    );
}
