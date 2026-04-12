<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */

use app\helpers\TimeHelper;
use app\models\ApprovalRule;
use yii\helpers\Html;

$this->title = 'Approval Rules';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><?= Html::encode($this->title) ?></h2>
    <?php if (Yii::$app->user->can('approval-rule.create')) : ?>
        <?= Html::a('New Approval Rule', ['create'], ['class' => 'btn btn-primary btn-sm']) ?>
    <?php endif; ?>
</div>

<?php if ($dataProvider->totalCount === 0) : ?>
    <div class="text-muted">No approval rules defined yet.</div>
<?php else : ?>
    <table class="table table-hover">
        <thead class="table-light">
            <tr>
                <th>Name</th>
                <th>Approver Type</th>
                <th>Required</th>
                <th>Timeout</th>
                <th>Created</th>
            </tr>
        </thead>
        <tbody>
            <?php /** @var ApprovalRule $model */ ?>
            <?php foreach ($dataProvider->getModels() as $model) : ?>
                <tr>
                    <td><?= Html::a(Html::encode($model->name), ['view', 'id' => $model->id]) ?></td>
                    <td><?= Html::encode(ApprovalRule::approverTypes()[$model->approver_type] ?? $model->approver_type) ?></td>
                    <td><?= Html::encode((string)$model->required_approvals) ?></td>
                    <td><?= $model->timeout_minutes !== null ? Html::encode($model->timeout_minutes . ' min') : '—' ?></td>
                    <td><?= TimeHelper::relative((int)$model->created_at) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?= \yii\widgets\LinkPager::widget(['pagination' => $dataProvider->pagination]) ?>
<?php endif; ?>
