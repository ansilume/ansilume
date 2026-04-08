<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\NotificationTemplate $model */

use app\models\NotificationTemplate;
use yii\helpers\Html;

$this->title = $model->name;
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><?= Html::encode($this->title) ?></h2>
    <div>
        <?php if (Yii::$app->user->can('notification-template.update')) : ?>
            <?= Html::a('Edit', ['update', 'id' => $model->id], ['class' => 'btn btn-outline-primary btn-sm']) ?>
        <?php endif; ?>
        <?php if (Yii::$app->user->can('notification-template.delete')) : ?>
            <form action="<?= \yii\helpers\Url::to(['delete', 'id' => $model->id]) ?>" method="post" style="display:inline"
                  onsubmit="return confirm('Delete this notification template?')">
                <input type="hidden" name="<?= Yii::$app->request->csrfParam ?>" value="<?= Yii::$app->request->csrfToken ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm ms-1">Delete</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <table class="table table-bordered">
            <tr>
                <th style="width:180px">Channel</th>
                <td><span class="badge text-bg-secondary"><?= Html::encode(NotificationTemplate::channelLabel($model->channel)) ?></span></td>
            </tr>
            <tr>
                <th>Events</th>
                <td>
                    <?php foreach ($model->getEventList() as $ev) : ?>
                        <span class="badge text-bg-info"><?= Html::encode($ev) ?></span>
                    <?php endforeach; ?>
                </td>
            </tr>
            <?php if ($model->description) : ?>
            <tr>
                <th>Description</th>
                <td><?= Html::encode($model->description) ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <th>Subject Template</th>
                <td><code><?= Html::encode((string)$model->subject_template) ?></code></td>
            </tr>
            <tr>
                <th>Body Template</th>
                <td><pre class="mb-0" style="white-space:pre-wrap"><?= Html::encode((string)$model->body_template) ?></pre></td>
            </tr>
            <tr>
                <th>Config</th>
                <td><pre class="mb-0 font-monospace" style="white-space:pre-wrap"><?= Html::encode((string)$model->config) ?></pre></td>
            </tr>
            <tr>
                <th>Created</th>
                <td><?= Html::encode(date('Y-m-d H:i', (int)$model->created_at)) ?> by <?= Html::encode($model->creator?->username ?? '—') ?></td>
            </tr>
        </table>
    </div>
</div>
