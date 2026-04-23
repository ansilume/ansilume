<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */

use app\helpers\TimeHelper;
use app\models\ApprovalRequest;
use yii\helpers\Html;

$this->title = 'Approvals';
?>

<h2><?= Html::encode($this->title) ?></h2>

<?php if ($dataProvider->totalCount === 0) : ?>
    <div class="text-muted">No approval requests.</div>
<?php else : ?>
    <table class="table table-hover">
        <thead class="table-light">
            <tr>
                <th>#</th>
                <th>Job</th>
                <th>Rule</th>
                <th>Status</th>
                <th>Requested</th>
                <th>Resolved</th>
            </tr>
        </thead>
        <tbody>
            <?php /** @var ApprovalRequest $model */ ?>
            <?php foreach ($dataProvider->getModels() as $model) : ?>
                <tr>
                    <td><?= Html::a(Html::encode((string)$model->id), ['view', 'id' => $model->id]) ?></td>
                    <td><?= Html::a('#' . Html::encode((string)$model->job_id), ['/job/view', 'id' => $model->job_id]) ?></td>
                    <td><?= Html::encode($model->approvalRule?->name ?? '—') ?></td>
                    <td><span class="badge text-bg-<?= ApprovalRequest::statusCssClass($model->status) ?>"><?= Html::encode(ApprovalRequest::statusLabel($model->status)) ?></span></td>
                    <td><?= TimeHelper::relative($model->requested_at !== null ? (int)$model->requested_at : null) ?></td>
                    <td><?= TimeHelper::relative($model->resolved_at !== null ? (int)$model->resolved_at : null) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?= \yii\widgets\LinkPager::widget(['pagination' => $dataProvider->pagination, 'firstPageLabel' => '«', 'lastPageLabel' => '»']) ?>
<?php endif; ?>
