<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */

use app\models\Webhook;
use yii\helpers\Html;
use yii\widgets\LinkPager;

$this->title = 'Webhooks';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Webhooks</h2>
    <?php if (\Yii::$app->user?->can('admin')) : ?>
        <?= Html::a('New Webhook', ['create'], ['class' => 'btn btn-primary']) ?>
    <?php endif; ?>
</div>

<p class="text-muted">Outbound webhooks are fired when job events occur. Payloads are signed with HMAC-SHA256 when a secret is set.</p>

<?php $models = $dataProvider->getModels(); ?>
<?php if (empty($models)) : ?>
    <p class="text-muted">No webhooks configured yet.</p>
<?php else : ?>
    <div class="mb-2">
        <input type="text" class="form-control form-control-sm" placeholder="Filter webhooks…"
               data-table-filter="webhook-table" style="max-width:300px">
    </div>
    <div class="table-responsive">
        <table class="table table-hover" id="webhook-table">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>URL</th>
                    <th>Events</th>
                    <th>Signed</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($models as $model) : ?>
                <tr>
                    <td><?= $model->id ?></td>
                    <td><?= Html::a(Html::encode($model->name), ['view', 'id' => $model->id]) ?></td>
                    <td class="text-truncate" style="max-width:240px">
                        <code title="<?= Html::encode($model->url) ?>"><?= Html::encode($model->url) ?></code>
                    </td>
                    <td>
                        <?php foreach ($model->getEventList() as $event) : ?>
                            <span class="badge text-bg-secondary me-1"><?= Html::encode($event) ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td><?= !empty($model->secret) ? '<span class="badge text-bg-success">Yes</span>' : '<span class="badge text-bg-light text-muted">No</span>' ?></td>
                    <td>
                        <?= $model->enabled
                            ? '<span class="badge text-bg-success">Enabled</span>'
                            : '<span class="badge text-bg-secondary">Disabled</span>' ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <?= Html::a('View', ['view', 'id' => $model->id], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
                        <?php if (\Yii::$app->user?->can('admin')) : ?>
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
