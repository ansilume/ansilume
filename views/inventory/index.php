<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */

use app\models\Inventory;
use yii\helpers\Html;
use yii\widgets\LinkPager;

$this->title = 'Inventories';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Inventories</h2>
    <?php if (\Yii::$app->user?->can('inventory.create')) : ?>
        <?= Html::a('New Inventory', ['create'], ['class' => 'btn btn-primary']) ?>
    <?php endif; ?>
</div>

<?php $models = $dataProvider->getModels(); ?>
<?php if (empty($models)) : ?>
    <p class="text-muted">No inventories yet.</p>
<?php else : ?>
    <div class="mb-2">
        <input type="text" class="form-control form-control-sm" placeholder="Filter inventories…"
               data-table-filter="inventory-table" style="max-width:300px">
    </div>
    <div class="table-responsive">
        <table class="table table-hover" id="inventory-table">
            <thead class="table-light">
                <tr>
                    <th>#</th><th>Name</th><th>Type</th><th>Project</th><th>Created by</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($dataProvider->getModels() as $model) : ?>
                <tr>
                    <td><?= $model->id ?></td>
                    <td><?= Html::a(Html::encode($model->name), ['view', 'id' => $model->id]) ?></td>
                    <td><span class="badge text-bg-secondary"><?= Html::encode(strtoupper($model->inventory_type)) ?></span></td>
                    <td><?= $model->project ? Html::a(Html::encode($model->project->name), ['/project/view', 'id' => $model->project_id]) : '—' ?></td>
                    <td><?= Html::encode($model->creator->username ?? '—') ?></td>
                    <td class="text-end text-nowrap">
                        <?= Html::a('View', ['view', 'id' => $model->id], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
                        <?php if (\Yii::$app->user?->can('inventory.update')) : ?>
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
