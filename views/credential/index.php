<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */

use app\models\Credential;
use yii\helpers\Html;
use yii\widgets\LinkPager;

$this->title = 'Credentials';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Credentials</h2>
    <?php if (\Yii::$app->user->can('credential.create')): ?>
        <?= Html::a('New Credential', ['create'], ['class' => 'btn btn-primary']) ?>
    <?php endif; ?>
</div>

<?php $models = $dataProvider->getModels(); ?>
<?php if (empty($models)): ?>
    <p class="text-muted">No credentials yet.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead class="table-light">
                <tr><th>#</th><th>Name</th><th>Type</th><th>Username</th><th>Created by</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($models as $model): ?>
                <tr>
                    <td><?= $model->id ?></td>
                    <td><?= Html::a(Html::encode($model->name), ['view', 'id' => $model->id]) ?></td>
                    <td><span class="badge text-bg-secondary"><?= Html::encode(Credential::typeLabel($model->credential_type)) ?></span></td>
                    <td><?= $model->username ? Html::encode($model->username) : '<span class="text-muted">—</span>' ?></td>
                    <td><?= Html::encode($model->creator->username ?? '—') ?></td>
                    <td class="text-end text-nowrap">
                        <?= Html::a('View', ['view', 'id' => $model->id], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
                        <?php if (\Yii::$app->user->can('credential.update')): ?>
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
