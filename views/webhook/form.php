<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Webhook $model */

use app\models\Webhook;
use yii\helpers\Html;
use yii\widgets\ActiveForm;

$isNew = $model->isNewRecord;
$this->title = $isNew ? 'New Webhook' : 'Edit: ' . Html::encode($model->name);
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><?= Html::a('Webhooks', ['index']) ?></li>
        <?php if (!$isNew) : ?>
            <li class="breadcrumb-item"><?= Html::a(Html::encode($model->name), ['view', 'id' => $model->id]) ?></li>
        <?php endif; ?>
        <li class="breadcrumb-item active"><?= $isNew ? 'New' : 'Edit' ?></li>
    </ol>
</nav>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><?= $this->title ?></h2>
    <?= Html::a('Cancel', $isNew ? ['index'] : ['view', 'id' => $model->id], ['class' => 'btn btn-outline-secondary']) ?>
</div>

<?php $form = ActiveForm::begin(['id' => 'webhook-form']); ?>

<div class="row g-3">
    <div class="col-md-6">
        <?= $form->field($model, 'name')->textInput(['maxlength' => 128]) ?>
    </div>
    <div class="col-md-6">
        <?= $form->field($model, 'url')
            ->textInput(['maxlength' => 512, 'placeholder' => 'https://example.com/hooks/ansilume'])
            ->hint('HTTPS recommended. Must be reachable from the Ansilume worker.') ?>
    </div>
    <div class="col-12">
        <?= $form->field($model, 'eventsArray')
            ->checkboxList(
                Webhook::allEvents(),
                ['value' => $model->getEventList()]
            )
            ->label('Events')
            ->hint('Select the job events this webhook should fire for.') ?>
    </div>
    <div class="col-md-6">
        <?= $form->field($model, 'secret')
            ->passwordInput(['maxlength' => 128, 'autocomplete' => 'off'])
            ->hint('Optional. When set, deliveries include an <code>X-Ansilume-Signature: sha256=&lt;hmac&gt;</code> header for verification.') ?>
    </div>
    <div class="col-12">
        <?= $form->field($model, 'enabled')->checkbox() ?>
    </div>
</div>

<div class="mt-3">
    <?= Html::submitButton($isNew ? 'Create Webhook' : 'Save Changes', ['class' => 'btn btn-primary']) ?>
</div>

<?php ActiveForm::end(); ?>
