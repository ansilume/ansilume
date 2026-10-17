<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\ApprovalRequest $model */
/** @var bool $canDecide the user's vote would be recorded now */
/** @var string|null $decisionNote why the user, who holds approval.decide, cannot decide; null: nothing to say */
/** @var app\services\runstate\RequestContext|null $context what the request is for (ApprovalRequestContexts) */
/** @var app\services\runstate\Viewer $viewer who looks at the page */

use app\components\JobInventory;
use app\controllers\ApprovalController;
use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\services\runstate\Texts;
use yii\helpers\Html;

$this->title = 'Approval Request #' . $model->id;
$rule = $model->approvalRule;
$launcher = $model->job?->launcher;
$decisionNote = $decisionNote ?? null;
$context = $context ?? null;
// The context names the inventory as the job's runner payload records it; without one, what required the
// request still comes from the request itself, and no inventory is named.
$requiredBy = $context !== null ? $context->requiredBy : $model->requiredBy();
$jobInventory = $context !== null ? $context->inventory : JobInventory::none();
?>

<h2><?= Html::encode($this->title) ?></h2>

<div class="row">
    <div class="col-lg-8">
        <table class="table table-bordered">
            <tr>
                <th style="width:180px">Status</th>
                <td><span class="badge text-bg-<?= Html::encode(ApprovalRequest::statusCssClass($model->status)) ?>"><?= Html::encode(ApprovalRequest::statusLabel($model->status)) ?></span></td>
            </tr>
            <tr>
                <th><?= Html::encode(ApprovalController::FOR_HEADER) ?></th>
                <td id="approval-for"><?= $this->render('_for', ['context' => $context, 'viewer' => $viewer]) ?></td>
            </tr>
            <tr>
                <th>Job</th>
                <td><?= Html::a('#' . Html::encode((string)$model->job_id), ['/job/view', 'id' => $model->job_id]) ?></td>
            </tr>
            <tr>
                <th><?= Html::encode(Texts::INVENTORY_LABEL) ?></th>
                <td id="approval-inventory"><?= $this->render('@app/views/job/_inventory', ['inventory' => $jobInventory]) ?></td>
            </tr>
            <tr>
                <th>Launched by</th>
                <td id="approval-launched-by"><?= Html::encode($launcher !== null ? (string)$launcher->username : '—') ?></td>
            </tr>
            <tr>
                <th>Rule</th>
                <td id="approval-rule"><?= $rule !== null ? Html::a(Html::encode((string)$rule->name), ['/approval-rule/view', 'id' => $rule->id]) : Html::encode('deleted rule') ?></td>
            </tr>
            <tr>
                <th><?= Html::encode(Texts::REQUIRED_BY_LABEL) ?></th>
                <td id="approval-required-by"><?= Html::encode(ApprovalRequest::requiredByLabel($requiredBy)) ?></td>
            </tr>
            <tr>
                <th>Approvals</th>
                <td><?= Html::encode((string)$model->approvalCount()) ?> / <?= Html::encode((string)($rule?->required_approvals ?? '?')) ?></td>
            </tr>
            <tr>
                <th>Rejections</th>
                <td><?= Html::encode((string)$model->rejectionCount()) ?></td>
            </tr>
            <tr>
                <th>Requested</th>
                <td><?= $model->requested_at ? Html::encode(date('Y-m-d H:i:s', (int)$model->requested_at)) : '—' ?></td>
            </tr>
            <tr>
                <th>Expires</th>
                <td><?= $model->expires_at ? Html::encode(date('Y-m-d H:i:s', (int)$model->expires_at)) : 'Never' ?></td>
            </tr>
            <tr>
                <th>Resolved</th>
                <td><?= $model->resolved_at ? Html::encode(date('Y-m-d H:i:s', (int)$model->resolved_at)) : '—' ?></td>
            </tr>
        </table>

        <?php if ($canDecide) : ?>
        <div class="d-flex flex-wrap gap-2 mb-4">
            <?= Html::beginForm(['approve', 'id' => $model->id], 'post', ['id' => 'approval-approve-form']) ?>
                <input type="text" name="comment" id="approval-approve-comment" maxlength="1000" class="form-control form-control-sm d-inline-block" style="width:300px" placeholder="Comment (optional)">
                <?= Html::submitButton('Approve', ['class' => 'btn btn-success btn-sm ms-2', 'data-confirm' => 'Approve this request?']) ?>
            <?= Html::endForm() ?>
            <?= Html::beginForm(['reject', 'id' => $model->id], 'post', ['id' => 'approval-reject-form']) ?>
                <input type="text" name="comment" id="approval-reject-comment" maxlength="1000" class="form-control form-control-sm d-inline-block" style="width:300px" placeholder="Reason (optional)">
                <?= Html::submitButton('Reject', ['class' => 'btn btn-danger btn-sm ms-2', 'data-confirm' => 'Reject this request?']) ?>
            <?= Html::endForm() ?>
        </div>
        <?php elseif ($decisionNote !== null) : ?>
        <div class="alert alert-info" id="approval-decision-note"><?= Html::encode($decisionNote) ?></div>
        <?php endif; ?>

        <?php
        $decisions = $model->decisions;
        if (!empty($decisions)) : ?>
        <h5>Decisions</h5>
        <table class="table table-bordered">
            <thead class="table-light">
                <tr>
                    <th>User</th>
                    <th>Decision</th>
                    <th>Comment</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($decisions as $d) : ?>
                <tr>
                    <td><?= Html::encode($d->user?->username ?? '—') ?></td>
                    <td>
                        <span class="badge text-bg-<?= $d->decision === ApprovalDecision::DECISION_APPROVED ? 'success' : 'danger' ?>"><?= Html::encode($d->decision) ?></span>
                        <?php if ($model->isLauncherDecision($d)) : ?>
                            <span class="badge text-bg-secondary approval-launcher-decision" title="<?= Html::encode('Cast by the user who launched the job, before self-approval was refused: it counts neither as an approval nor as a rejection.') ?>"><?= Html::encode('launcher, not counted') ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= Html::encode((string)$d->comment) ?></td>
                    <td><?= Html::encode(date('Y-m-d H:i:s', (int)$d->created_at)) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
