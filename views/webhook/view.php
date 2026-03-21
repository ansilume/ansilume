<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Webhook $model */

use yii\helpers\Html;

$this->title = Html::encode($model->name);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><?= Html::encode($model->name) ?></h2>
    <div>
        <?php if (\Yii::$app->user->can('admin')): ?>
            <?= Html::a('Edit', ['update', 'id' => $model->id], ['class' => 'btn btn-outline-secondary']) ?>
            <?= Html::a('Delete', ['delete', 'id' => $model->id], [
                'class' => 'btn btn-outline-danger ms-1',
                'data-method' => 'post',
                'data-confirm' => 'Delete this webhook?',
            ]) ?>
        <?php endif; ?>
        <?= Html::a('Back', ['index'], ['class' => 'btn btn-outline-secondary ms-1']) ?>
    </div>
</div>

<div class="card">
    <div class="card-header">Webhook details</div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">Status</dt>
            <dd class="col-sm-9">
                <?= $model->enabled
                    ? '<span class="badge text-bg-success">Enabled</span>'
                    : '<span class="badge text-bg-secondary">Disabled</span>' ?>
            </dd>

            <dt class="col-sm-3">URL</dt>
            <dd class="col-sm-9"><code><?= Html::encode($model->url) ?></code></dd>

            <dt class="col-sm-3">Events</dt>
            <dd class="col-sm-9">
                <?php foreach ($model->getEventList() as $event): ?>
                    <span class="badge text-bg-secondary me-1"><?= Html::encode($event) ?></span>
                <?php endforeach; ?>
            </dd>

            <dt class="col-sm-3">Signed</dt>
            <dd class="col-sm-9">
                <?= !empty($model->secret)
                    ? '<span class="badge text-bg-success">Yes — HMAC-SHA256</span>'
                    : '<span class="text-muted">No secret configured</span>' ?>
            </dd>

            <dt class="col-sm-3">Created by</dt>
            <dd class="col-sm-9"><?= Html::encode($model->creator->username ?? '—') ?></dd>

            <dt class="col-sm-3">Created</dt>
            <dd class="col-sm-9"><?= date('Y-m-d H:i:s', $model->created_at) ?></dd>
        </dl>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">Payload format</div>
    <div class="card-body">
        <pre class="mb-0"><code>{
  "event":     "job.success",
  "timestamp": 1710000000,
  "job": {
    "id":              42,
    "status":          "succeeded",
    "job_template_id": 3,
    "launched_by":     1,
    "started_at":      1710000000,
    "finished_at":     1710000120,
    "exit_code":       0
  }
}</code></pre>
        <p class="text-muted mt-2 mb-0 small">
            When a secret is set, the <code>X-Ansilume-Signature</code> header contains <code>sha256=&lt;hmac&gt;</code>
            computed over the raw JSON body.
        </p>
    </div>
</div>
