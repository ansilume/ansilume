<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */

use yii\helpers\Html;
use yii\widgets\LinkPager;

$this->title = 'Teams';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Teams</h2>
    <?= Html::a('New Team', ['create'], ['class' => 'btn btn-primary']) ?>
</div>

<p class="text-muted">
    Teams group users and grant them access to specific projects.
    Projects with no team assignments are accessible to all authenticated users.
</p>

<?php $models = $dataProvider->getModels(); ?>
<?php if (empty($models)): ?>
    <p class="text-muted">No teams yet.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead class="table-light">
                <tr>
                    <th>#</th><th>Name</th><th>Description</th><th>Created by</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($models as $team): ?>
                <tr>
                    <td><?= $team->id ?></td>
                    <td><?= Html::a(Html::encode($team->name), ['view', 'id' => $team->id]) ?></td>
                    <td><?= Html::encode($team->description ?? '—') ?></td>
                    <td><?= Html::encode($team->creator->username ?? '—') ?></td>
                    <td class="text-end text-nowrap">
                        <?= Html::a('Manage', ['view', 'id' => $team->id], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
                        <?= Html::a('Edit', ['update', 'id' => $team->id], ['class' => 'btn btn-sm btn-outline-secondary ms-1']) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= LinkPager::widget(['pagination' => $dataProvider->pagination]) ?>
<?php endif; ?>
