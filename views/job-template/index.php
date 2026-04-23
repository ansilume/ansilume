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
    <?php if (\Yii::$app->user?->can('job-template.create')) : ?>
        <?= Html::a('New Template', ['create'], ['class' => 'btn btn-primary']) ?>
    <?php endif; ?>
</div>

<?php $models = $dataProvider->getModels(); ?>
<?php if (empty($models)) : ?>
    <p class="text-muted">No job templates yet.</p>
<?php else : ?>
    <div class="mb-2">
        <input type="text" class="form-control form-control-sm" placeholder="Filter templates…"
               data-table-filter="template-table" style="max-width:300px">
    </div>
    <div class="table-responsive">
        <?php $sort = $dataProvider->getSort(); ?>
        <table class="table table-hover" id="template-table">
            <thead class="table-light">
                <tr>
                    <th><?= $sort ? $sort->link('id', ['label' => '#']) : '#' ?></th>
                    <th><?= $sort ? $sort->link('name', ['label' => 'Name']) : 'Name' ?></th>
                    <th><?= $sort ? $sort->link('project', ['label' => 'Project']) : 'Project' ?></th>
                    <th><?= $sort ? $sort->link('playbook', ['label' => 'Playbook']) : 'Playbook' ?></th>
                    <th><?= $sort ? $sort->link('inventory', ['label' => 'Inventory']) : 'Inventory' ?></th>
                    <th><?= $sort ? $sort->link('runner_group', ['label' => 'Runner']) : 'Runner' ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($models as $model) : ?>
                <tr>
                    <td><?= $model->id ?></td>
                    <td><?= Html::a(Html::encode($model->name), ['view', 'id' => $model->id]) ?></td>
                    <td><?= Html::encode($model->project->name ?? '—') ?></td>
                    <td><code><?= Html::encode($model->playbook) ?></code></td>
                    <td><?= Html::encode($model->inventory->name ?? '—') ?></td>
                    <td><?= Html::encode($model->runnerGroup->name ?? '—') ?></td>
                    <td class="text-end text-nowrap">
                        <?php if (\Yii::$app->user?->can('job.launch')) : ?>
                            <?= Html::a('Launch', ['launch', 'id' => $model->id], ['class' => 'btn btn-sm btn-success']) ?>
                        <?php endif; ?>
                        <?= Html::a('View', ['view', 'id' => $model->id], ['class' => 'btn btn-sm btn-outline-secondary ms-1']) ?>
                        <?php if (\Yii::$app->user?->can('job-template.update')) : ?>
                            <?= Html::a('Edit', ['update', 'id' => $model->id], ['class' => 'btn btn-sm btn-outline-secondary ms-1']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= LinkPager::widget(['pagination' => $dataProvider->pagination, 'firstPageLabel' => '«', 'lastPageLabel' => '»']) ?>
<?php endif; ?>
