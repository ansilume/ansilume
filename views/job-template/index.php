<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */

use yii\helpers\Html;
use yii\widgets\LinkPager;

$this->title = 'Job Templates';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Job Templates</h2>
    <?php if (\Yii::$app->user->can('job-template.create')): ?>
        <?= Html::a('New Template', ['create'], ['class' => 'btn btn-primary']) ?>
    <?php endif; ?>
</div>

<?php $models = $dataProvider->getModels(); ?>
<?php if (empty($models)): ?>
    <p class="text-muted">No job templates yet.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead class="table-light">
                <tr><th>#</th><th>Name</th><th>Project</th><th>Playbook</th><th>Inventory</th><th>Created by</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($models as $model): ?>
                <tr>
                    <td><?= $model->id ?></td>
                    <td><?= Html::a(Html::encode($model->name), ['view', 'id' => $model->id]) ?></td>
                    <td><?= Html::encode($model->project->name ?? '—') ?></td>
                    <td><code><?= Html::encode($model->playbook) ?></code></td>
                    <td><?= Html::encode($model->inventory->name ?? '—') ?></td>
                    <td><?= Html::encode($model->creator->username ?? '—') ?></td>
                    <td class="text-end text-nowrap">
                        <?php if (\Yii::$app->user->can('job.launch')): ?>
                            <?= Html::a('Launch', ['launch', 'id' => $model->id], ['class' => 'btn btn-sm btn-success']) ?>
                        <?php endif; ?>
                        <?= Html::a('View', ['view', 'id' => $model->id], ['class' => 'btn btn-sm btn-outline-secondary ms-1']) ?>
                        <?php if (\Yii::$app->user->can('job-template.update')): ?>
                            <?= Html::a('Edit', ['update', 'id' => $model->id], ['class' => 'btn btn-sm btn-outline-secondary ms-1']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= LinkPager::widget(['pagination' => $dataProvider->pagination]) ?>
<?php endif; ?>
