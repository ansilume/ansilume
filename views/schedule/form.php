<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Schedule $model */
/** @var array $templates  id => name map of job templates */

use yii\helpers\Html;
use yii\widgets\ActiveForm;

$isNew       = $model->isNewRecord;
$this->title = $isNew ? 'New Schedule' : 'Edit: ' . Html::encode($model->name);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><?= $this->title ?></h2>
    <?= Html::a('Cancel', $isNew ? ['index'] : ['view', 'id' => $model->id], ['class' => 'btn btn-outline-secondary']) ?>
</div>

<?php $form = ActiveForm::begin(['id' => 'schedule-form']); ?>

<div class="row g-3">
    <div class="col-md-6">
        <?= $form->field($model, 'name')->textInput(['maxlength' => 128, 'placeholder' => 'e.g. Nightly playbook run']) ?>
    </div>
    <div class="col-md-6">
        <?= $form->field($model, 'job_template_id')->dropDownList(
            $templates,
            ['prompt' => '— Select template —']
        ) ?>
    </div>
    <div class="col-md-6">
        <?= $form->field($model, 'cron_expression')
            ->textInput(['maxlength' => 64, 'placeholder' => '0 2 * * *'])
            ->hint('Standard 5-field cron: min hour dom mon dow. Example: <code>0 2 * * *</code> = daily at 02:00.') ?>
    </div>
    <div class="col-md-6">
        <?= $form->field($model, 'timezone')
            ->textInput(['maxlength' => 64, 'placeholder' => 'UTC'])
            ->hint('PHP timezone name, e.g. <code>UTC</code>, <code>Europe/Berlin</code>, <code>America/New_York</code>.') ?>
    </div>
    <div class="col-12">
        <?= $form->field($model, 'extra_vars')
            ->textarea(['rows' => 5, 'class' => 'form-control font-monospace', 'placeholder' => "{\n  \"env\": \"production\"\n}"])
            ->hint('Optional JSON extra vars that override the template defaults for scheduled runs.') ?>
    </div>
    <div class="col-12">
        <?= $form->field($model, 'enabled')->checkbox() ?>
    </div>
</div>

<div class="mt-3">
    <?= Html::submitButton($isNew ? 'Create Schedule' : 'Save Changes', ['class' => 'btn btn-primary']) ?>
</div>

<?php ActiveForm::end(); ?>
