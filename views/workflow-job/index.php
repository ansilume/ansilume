<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */

use app\helpers\TimeHelper;
use app\models\WorkflowJob;
use yii\helpers\Html;

$this->title = 'Workflow Jobs';
?>

<h2><?= Html::encode($this->title) ?></h2>

<?php if ($dataProvider->totalCount === 0) : ?>
    <div class="text-muted">No workflow executions yet.</div>
<?php else : ?>
    <table class="table table-hover">
        <thead class="table-light">
            <tr>
                <th>#</th>
                <th>Workflow</th>
                <th>Status</th>
                <th>Launched By</th>
                <th>Started</th>
                <th>Finished</th>
            </tr>
        </thead>
        <tbody>
            <?php /** @var WorkflowJob $model */ ?>
            <?php foreach ($dataProvider->getModels() as $model) : ?>
                <tr>
                    <td><?= Html::a(Html::encode((string)$model->id), ['view', 'id' => $model->id]) ?></td>
                    <td><?= Html::encode($model->workflowTemplate?->name ?? '—') ?></td>
                    <td><span class="badge text-bg-<?= WorkflowJob::statusCssClass($model->status) ?>"><?= Html::encode(WorkflowJob::statusLabel($model->status)) ?></span></td>
                    <td><?= Html::encode($model->launcher?->username ?? '—') ?></td>
                    <td><?= TimeHelper::relative($model->started_at !== null ? (int)$model->started_at : null) ?></td>
                    <td><?= TimeHelper::relative($model->finished_at !== null ? (int)$model->finished_at : null) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?= \yii\widgets\LinkPager::widget(['pagination' => $dataProvider->pagination, 'firstPageLabel' => '«', 'lastPageLabel' => '»']) ?>
<?php endif; ?>
