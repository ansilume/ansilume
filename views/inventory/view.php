<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Inventory $model */

use yii\helpers\Html;

$this->title = $model->name;
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><?= Html::a('Inventories', ['index']) ?></li>
        <li class="breadcrumb-item active"><?= Html::encode($model->name) ?></li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-start mb-3">
    <h2><?= Html::encode($model->name) ?></h2>
    <div>
        <?php if (\Yii::$app->user->can('inventory.update')): ?>
            <?= Html::a('Edit', ['update', 'id' => $model->id], ['class' => 'btn btn-outline-secondary']) ?>
        <?php endif; ?>
        <?php if (\Yii::$app->user->can('inventory.delete')): ?>
            <?= Html::a('Delete', ['delete', 'id' => $model->id], [
                'class' => 'btn btn-outline-danger ms-1',
                'data'  => ['method' => 'post', 'confirm' => 'Delete this inventory?'],
            ]) ?>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">Details</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5">Type</dt>
                    <dd class="col-7"><span class="badge text-bg-secondary"><?= Html::encode(strtoupper($model->inventory_type)) ?></span></dd>
                    <?php if ($model->project): ?>
                        <dt class="col-5">Project</dt>
                        <dd class="col-7"><?= Html::a(Html::encode($model->project->name), ['/project/view', 'id' => $model->project_id]) ?></dd>
                    <?php endif; ?>
                    <?php if ($model->source_path): ?>
                        <dt class="col-5">Path</dt>
                        <dd class="col-7"><code><?= Html::encode($model->source_path) ?></code></dd>
                    <?php endif; ?>
                    <dt class="col-5">Created by</dt>
                    <dd class="col-7"><?= Html::encode($model->creator->username ?? '—') ?></dd>
                    <dt class="col-5">Created</dt>
                    <dd class="col-7"><?= date('Y-m-d H:i', $model->created_at) ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <?php if ($model->content): ?>
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">Content</div>
            <div class="card-body p-0">
                <pre class="job-log m-0" style="max-height: 400px; overflow-y:auto;"><?= Html::encode($model->content) ?></pre>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
