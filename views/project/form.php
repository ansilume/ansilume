<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Project $model */

use app\models\Project;
use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = $model->isNewRecord ? 'New Project' : 'Edit: ' . $model->name;
?>
<div class="row justify-content-center">
<div class="col-lg-7">

<h2><?= Html::encode($this->title) ?></h2>

<?php $form = ActiveForm::begin(['id' => 'project-form']); ?>

    <?= $form->field($model, 'name')->textInput(['maxlength' => 128, 'autofocus' => true]) ?>
    <?= $form->field($model, 'description')->textarea(['rows' => 3]) ?>

    <?= $form->field($model, 'scm_type')->dropDownList([
        Project::SCM_TYPE_GIT    => 'Git',
        Project::SCM_TYPE_MANUAL => 'Manual (no SCM)',
    ]) ?>

    <div id="git-fields" <?= $model->scm_type !== Project::SCM_TYPE_GIT ? 'style="display:none"' : '' ?>>
        <?= $form->field($model, 'scm_url')->textInput(['maxlength' => 512, 'placeholder' => 'https://github.com/org/repo.git']) ?>
        <?= $form->field($model, 'scm_branch')->textInput(['maxlength' => 128]) ?>
    </div>

    <div class="mt-3">
        <?= Html::submitButton($model->isNewRecord ? 'Create Project' : 'Save Changes', ['class' => 'btn btn-primary']) ?>
        <?= Html::a('Cancel', $model->isNewRecord ? ['index'] : ['view', 'id' => $model->id], ['class' => 'btn btn-outline-secondary ms-2']) ?>
    </div>

<?php ActiveForm::end(); ?>

</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var scmType  = document.getElementById('project-scm_type');
    var gitFields = document.getElementById('git-fields');
    if (scmType) {
        scmType.addEventListener('change', function () {
            gitFields.style.display = this.value === 'git' ? '' : 'none';
        });
    }
});
</script>
