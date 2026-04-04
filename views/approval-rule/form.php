<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\ApprovalRule $model */

use app\models\ApprovalRule;
use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = $model->isNewRecord ? 'New Approval Rule' : 'Edit: ' . $model->name;
?>
<div class="row justify-content-center">
<div class="col-lg-8">

<h2><?= Html::encode($this->title) ?></h2>

<?php $form = ActiveForm::begin(['id' => 'approval-rule-form']); ?>

    <?= $form->field($model, 'name')->textInput(['maxlength' => 128, 'autofocus' => true]) ?>
    <?= $form->field($model, 'description')->textarea(['rows' => 2]) ?>
    <?= $form->field($model, 'approver_type')->dropDownList(ApprovalRule::approverTypes()) ?>
    <?= $form->field($model, 'approver_config')->textarea([
        'rows' => 3,
        'class' => 'form-control font-monospace',
        'placeholder' => '{"role": "operator"} or {"team_id": 1} or {"user_ids": [1, 2]}',
    ])->hint('JSON config: {"role": "name"}, {"team_id": N}, or {"user_ids": [N, N]}') ?>
    <?= $form->field($model, 'required_approvals')->textInput(['type' => 'number', 'min' => 1, 'max' => 50]) ?>
    <?= $form->field($model, 'timeout_minutes')->textInput(['type' => 'number', 'min' => 1, 'max' => 10080])->hint('Leave empty for no timeout.') ?>
    <?= $form->field($model, 'timeout_action')->dropDownList(ApprovalRule::timeoutActions()) ?>

    <div class="mt-4">
        <?= Html::submitButton($model->isNewRecord ? 'Create' : 'Save Changes', ['class' => 'btn btn-primary']) ?>
        <?= Html::a('Cancel', $model->isNewRecord ? ['index'] : ['view', 'id' => $model->id], ['class' => 'btn btn-outline-secondary ms-2']) ?>
    </div>

<?php ActiveForm::end(); ?>

</div>
</div>
