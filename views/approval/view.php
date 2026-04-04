<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\ApprovalRequest $model */

use app\models\ApprovalRequest;
use yii\helpers\Html;

$this->title = 'Approval Request #' . $model->id;
?>

<h2><?= Html::encode($this->title) ?></h2>

<div class="row">
    <div class="col-lg-8">
        <table class="table table-bordered">
            <tr>
                <th style="width:180px">Status</th>
                <td><span class="badge text-bg-<?= ApprovalRequest::statusCssClass($model->status) ?>"><?= Html::encode(ApprovalRequest::statusLabel($model->status)) ?></span></td>
            </tr>
            <tr>
                <th>Job</th>
                <td><?= Html::a('#' . Html::encode((string)$model->job_id), ['/job/view', 'id' => $model->job_id]) ?></td>
            </tr>
            <tr>
                <th>Rule</th>
                <td><?= Html::a(Html::encode($model->approvalRule?->name ?? '—'), ['/approval-rule/view', 'id' => $model->approval_rule_id]) ?></td>
            </tr>
            <tr>
                <th>Approvals</th>
                <td><?= Html::encode((string)$model->approvalCount()) ?> / <?= Html::encode((string)($model->approvalRule?->required_approvals ?? '?')) ?></td>
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
        </table>

        <?php if (!$model->isResolved() && Yii::$app->user->can('approval.decide')) : ?>
        <div class="d-flex gap-2 mb-4">
            <?= Html::beginForm(['approve', 'id' => $model->id], 'post') ?>
                <input type="text" name="comment" class="form-control form-control-sm d-inline-block" style="width:300px" placeholder="Comment (optional)">
                <?= Html::submitButton('Approve', ['class' => 'btn btn-success btn-sm ms-2', 'data-confirm' => 'Approve this request?']) ?>
            <?= Html::endForm() ?>
            <?= Html::beginForm(['reject', 'id' => $model->id], 'post') ?>
                <?= Html::submitButton('Reject', ['class' => 'btn btn-danger btn-sm', 'data-confirm' => 'Reject this request?']) ?>
            <?= Html::endForm() ?>
        </div>
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
                    <td><span class="badge text-bg-<?= $d->decision === 'approved' ? 'success' : 'danger' ?>"><?= Html::encode($d->decision) ?></span></td>
                    <td><?= Html::encode((string)$d->comment) ?></td>
                    <td><?= Html::encode(date('Y-m-d H:i:s', (int)$d->created_at)) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
