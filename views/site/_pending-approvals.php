<?php

declare(strict_types=1);

/**
 * The dashboard's Pending Approvals: the newest pending requests the user may
 * see, each with what it is for (views/approval/_for.php), which names a
 * workflow only to a user who may view it. The Rule cell names the inventory
 * of a request that the inventory of its job required
 * (RequestContext::ruleText()), as the approval list does.
 *
 * @var yii\web\View $this
 * @var list<app\models\ApprovalRequest> $pendingApprovals the newest pending requests the user may see
 * @var array<int, app\services\runstate\RequestContext> $approvalContexts what each request is for, by request ID
 * @var int $pendingCount every pending request the user may see
 * @var app\services\runstate\Viewer $viewer who looks at the page
 */

use app\controllers\ApprovalController;
use app\models\ApprovalRequest;
use app\services\runstate\RequestContext;
use yii\helpers\Html;
use yii\helpers\Url;

?>
<div class="card mb-3 border-warning" id="dashboard-pending-approvals">
    <div class="card-header text-bg-warning d-flex justify-content-between align-items-center">
        <strong>Pending Approvals (<?= Html::encode((string)$pendingCount) ?>)</strong>
        <?= Html::a(Html::encode(ApprovalController::ALL_PENDING), Url::to(['/approval/index', 'status' => ApprovalRequest::STATUS_PENDING]), ['class' => 'btn btn-sm btn-outline-dark']) ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr><th>Rule</th><th><?= Html::encode(ApprovalController::FOR_HEADER) ?></th><th>Job</th><th>Requested</th><th>Expires</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($pendingApprovals as $ar) : ?>
                <?php
                $context = $approvalContexts[(int)$ar->id] ?? null;
                $ruleName = $ar->approvalRule?->name;
                ?>
                <tr data-request-id="<?= (int)$ar->id ?>">
                    <td data-approval-rule><?= Html::encode($context !== null ? $context->ruleText($ruleName) : ($ruleName ?? RequestContext::UNKNOWN)) ?></td>
                    <td data-approval-for><?= $this->render('@app/views/approval/_for', ['context' => $context, 'viewer' => $viewer]) ?></td>
                    <td><?= Html::a('#' . (int)$ar->job_id, Url::to(['/job/view', 'id' => $ar->job_id])) ?></td>
                    <td class="text-nowrap"><?= $ar->requested_at !== null ? Html::encode(date('Y-m-d H:i', (int)$ar->requested_at)) : '—' ?></td>
                    <td class="text-nowrap"><?= $ar->expires_at !== null ? Html::encode(date('Y-m-d H:i', (int)$ar->expires_at)) : '—' ?></td>
                    <td><?= Html::a(Html::encode(ApprovalController::REVIEW), Url::to(['/approval/view', 'id' => $ar->id]), ['class' => 'btn btn-sm btn-outline-warning']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
