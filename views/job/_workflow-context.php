<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\services\runstate\JobContext $context the workflow run and step the job was created for, and its approval request, as the viewer may see them */
// Rows of the job page's Details list: the job's approval request, and the
// workflow run and step it belongs to.

use app\models\ApprovalRequest;
use app\services\runstate\Banner;
use app\services\runstate\PollPayload;
use app\services\runstate\Texts;
use app\services\runstate\Viewer;
use yii\helpers\Html;

$approval = $context->approval;
$origin = $context->origin;
?>
<?php if ($approval !== null) :
    $requestLabel = Html::encode(sprintf(PollPayload::REQUEST_LABEL, $approval->requestId));
    ?>
    <dt class="col-5">Approval Request</dt>
    <dd class="col-7" id="detail-approval">
        <?= $approval->linkable ? Html::a($requestLabel, Banner::requestUrl($approval->requestId)) : $requestLabel // xss-ok: encoded label in Html::a()?><?= Html::encode(PollPayload::LINK_SEPARATOR . ApprovalRequest::statusLabel($approval->status)) ?>
    </dd>
<?php endif; ?>
<?php if ($origin !== null) :
    $originText = Html::encode(sprintf(Texts::JOB_WORKFLOW_ORIGIN, $origin->workflowJobId, $origin->stepName));
    ?>
    <dt class="col-5">Workflow Job</dt>
    <dd class="col-7" id="detail-workflow-job">
        <?= Viewer::current()->may('workflow.view') ? Html::a($originText, ['/workflow-job/view', 'id' => $origin->workflowJobId]) : $originText // xss-ok: encoded text in Html::a()?>
    </dd>
<?php endif; ?>
