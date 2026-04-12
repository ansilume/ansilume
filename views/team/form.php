<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Team $model */

use yii\helpers\Html;
use yii\widgets\ActiveForm;

$isNew = $model->isNewRecord;
$this->title = $isNew ? 'New Team' : 'Edit: ' . Html::encode($model->name);
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><?= Html::a('Teams', ['index']) ?></li>
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

<?php $form = ActiveForm::begin(); ?>
<div class="row g-3">
    <div class="col-md-6">
        <?= $form->field($model, 'name')->textInput(['maxlength' => 128]) ?>
    </div>
    <div class="col-12">
        <?= $form->field($model, 'description')->textarea(['rows' => 3]) ?>
    </div>
</div>
<div class="mt-3">
    <?= Html::submitButton($isNew ? 'Create Team' : 'Save', ['class' => 'btn btn-primary']) ?>
</div>
<?php ActiveForm::end(); ?>
