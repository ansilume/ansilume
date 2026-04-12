<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\RunnerGroup $model */

use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = $model->isNewRecord ? 'New Runner Group' : 'Edit: ' . $model->name;
?>
<div class="row justify-content-center">
<div class="col-lg-6">
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><?= Html::a('Runner Groups', ['index']) ?></li>
        <?php if (!$model->isNewRecord) : ?>
            <li class="breadcrumb-item"><?= Html::a(Html::encode($model->name), ['view', 'id' => $model->id]) ?></li>
        <?php endif; ?>
        <li class="breadcrumb-item active"><?= $model->isNewRecord ? 'New' : 'Edit' ?></li>
    </ol>
</nav>
<h2><?= Html::encode($this->title) ?></h2>

<?php $form = ActiveForm::begin(); ?>
    <?= $form->field($model, 'name')->textInput(['maxlength' => 128, 'autofocus' => true, 'placeholder' => 'e.g. Standort 1']) ?>
    <?= $form->field($model, 'description')->textarea(['rows' => 3]) ?>
    <div class="mt-3">
        <?= Html::submitButton($model->isNewRecord ? 'Create' : 'Save', ['class' => 'btn btn-success']) ?>
        <?= Html::a('Cancel', $model->isNewRecord ? ['index'] : ['view', 'id' => $model->id], ['class' => 'btn btn-outline-secondary ms-2']) ?>
    </div>
<?php ActiveForm::end(); ?>
</div>
</div>
