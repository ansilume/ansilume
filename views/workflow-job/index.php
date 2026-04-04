<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */

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
                    <td><?= $model->started_at ? Html::encode(date('Y-m-d H:i', (int)$model->started_at)) : '—' ?></td>
                    <td><?= $model->finished_at ? Html::encode(date('Y-m-d H:i', (int)$model->finished_at)) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?= \yii\widgets\LinkPager::widget(['pagination' => $dataProvider->pagination]) ?>
<?php endif; ?>
