<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */

use app\helpers\TimeHelper;
use app\models\NotificationTemplate;
use yii\helpers\Html;

$this->title = 'Notification Templates';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><?= Html::encode($this->title) ?></h2>
    <?php if (Yii::$app->user->can('notification-template.create')) : ?>
        <?= Html::a('New Notification Template', ['create'], ['class' => 'btn btn-primary btn-sm']) ?>
    <?php endif; ?>
</div>

<?php if ($dataProvider->totalCount === 0) : ?>
    <div class="text-muted">No notification templates yet.</div>
<?php else : ?>
    <table class="table table-hover">
        <thead class="table-light">
            <tr>
                <th>Name</th>
                <th>Channel</th>
                <th>Events</th>
                <th>Created</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($dataProvider->getModels() as $model) : ?>
            <?php /** @var NotificationTemplate $model */ ?>
            <tr>
                <td><?= Html::a(Html::encode($model->name), ['view', 'id' => $model->id]) ?></td>
                <td>
                    <span class="badge text-bg-secondary">
                        <?= Html::encode(NotificationTemplate::channelLabel($model->channel)) ?>
                    </span>
                </td>
                <td>
                    <?php foreach ($model->getEventList() as $ev) : ?>
                        <span class="badge text-bg-info"><?= Html::encode($ev) ?></span>
                    <?php endforeach; ?>
                </td>
                <td class="text-muted small">
                    <?= TimeHelper::relative((int)$model->created_at) ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php
    $pagination = $dataProvider->getPagination();
    if ($pagination !== false && $pagination->pageCount > 1) {
        echo \yii\widgets\LinkPager::widget(['pagination' => $pagination]);
    }
    ?>
<?php endif; ?>
