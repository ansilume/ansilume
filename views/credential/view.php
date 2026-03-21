<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Credential $model */

use app\models\Credential;
use yii\helpers\Html;

$this->title = $model->name;
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><?= Html::a('Credentials', ['index']) ?></li>
        <li class="breadcrumb-item active"><?= Html::encode($model->name) ?></li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-start mb-3">
    <h2><?= Html::encode($model->name) ?></h2>
    <div>
        <?php if (\Yii::$app->user->can('credential.update')): ?>
            <?= Html::a('Edit', ['update', 'id' => $model->id], ['class' => 'btn btn-outline-secondary']) ?>
        <?php endif; ?>
        <?php if (\Yii::$app->user->can('credential.delete')): ?>
            <?= Html::a('Delete', ['delete', 'id' => $model->id], [
                'class' => 'btn btn-outline-danger ms-1',
                'data'  => ['method' => 'post', 'confirm' => 'Delete this credential?'],
            ]) ?>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-md-5">
        <div class="card">
            <div class="card-header">Details</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5">Type</dt>
                    <dd class="col-7"><span class="badge text-bg-secondary"><?= Html::encode(Credential::typeLabel($model->credential_type)) ?></span></dd>
                    <dt class="col-5">Username</dt>
                    <dd class="col-7"><?= $model->username ? Html::encode($model->username) : '<span class="text-muted">—</span>' ?></dd>
                    <dt class="col-5">Secret</dt>
                    <dd class="col-7"><span class="text-muted">***REDACTED***</span></dd>
                    <dt class="col-5">Created by</dt>
                    <dd class="col-7"><?= Html::encode($model->creator->username ?? '—') ?></dd>
                    <dt class="col-5">Created</dt>
                    <dd class="col-7"><?= date('Y-m-d H:i', $model->created_at) ?></dd>
                    <dt class="col-5">Updated</dt>
                    <dd class="col-7"><?= date('Y-m-d H:i', $model->updated_at) ?></dd>
                </dl>
            </div>
        </div>
        <?php if ($model->description): ?>
        <div class="card mt-3">
            <div class="card-header">Description</div>
            <div class="card-body"><?= nl2br(Html::encode($model->description)) ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>
