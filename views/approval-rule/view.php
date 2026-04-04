<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\ApprovalRule $model */

use app\models\ApprovalRule;
use yii\helpers\Html;

$this->title = $model->name;
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><?= Html::encode($this->title) ?></h2>
    <div>
        <?php if (Yii::$app->user->can('approval-rule.update')) : ?>
            <?= Html::a('Edit', ['update', 'id' => $model->id], ['class' => 'btn btn-outline-primary btn-sm']) ?>
        <?php endif; ?>
        <?php if (Yii::$app->user->can('approval-rule.delete')) : ?>
            <?= Html::a('Delete', ['delete', 'id' => $model->id], [
                'class' => 'btn btn-outline-danger btn-sm ms-1',
                'data' => ['confirm' => 'Delete this approval rule?', 'method' => 'post'],
            ]) ?>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <table class="table table-bordered">
            <tr>
                <th style="width:180px">Approver Type</th>
                <td><?= Html::encode(ApprovalRule::approverTypes()[$model->approver_type] ?? $model->approver_type) ?></td>
            </tr>
            <tr>
                <th>Required Approvals</th>
                <td><?= Html::encode((string)$model->required_approvals) ?></td>
            </tr>
            <tr>
                <th>Timeout</th>
                <td><?= $model->timeout_minutes !== null ? Html::encode($model->timeout_minutes . ' minutes') : 'None' ?></td>
            </tr>
            <tr>
                <th>Timeout Action</th>
                <td><?= Html::encode(ApprovalRule::timeoutActions()[$model->timeout_action] ?? $model->timeout_action) ?></td>
            </tr>
            <?php if ($model->description) : ?>
            <tr>
                <th>Description</th>
                <td><?= Html::encode($model->description) ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <th>Config</th>
                <td><pre class="mb-0 font-monospace" style="white-space:pre-wrap"><?= Html::encode((string)$model->approver_config) ?></pre></td>
            </tr>
            <tr>
                <th>Created</th>
                <td><?= Html::encode(date('Y-m-d H:i', (int)$model->created_at)) ?> by <?= Html::encode($model->creator?->username ?? '—') ?></td>
            </tr>
        </table>
    </div>
</div>
