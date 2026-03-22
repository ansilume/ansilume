<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Project $model */
/** @var app\models\Credential[] $sshCredentials */

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
        <?= $form->field($model, 'scm_url')->textInput(['maxlength' => 512, 'placeholder' => 'git@github.com:org/repo.git']) ?>
        <?= $form->field($model, 'scm_branch')->textInput(['maxlength' => 128]) ?>
        <?= $form->field($model, 'scm_credential_id')->dropDownList(
            array_merge(['' => '— None (public repo) —'], array_column(
                array_map(fn($c) => ['id' => $c->id, 'name' => $c->name], $sshCredentials), 'name', 'id'
            )),
            ['prompt' => false]
        )->hint('Select an SSH Key credential to authenticate with a private repository. The public key must be added as a Deploy Key on GitHub/GitLab.') ?>
    </div>

    <div id="manual-fields" <?= $model->scm_type !== Project::SCM_TYPE_MANUAL ? 'style="display:none"' : '' ?>>
        <?= $form->field($model, 'local_path')->textInput([
            'maxlength'   => 512,
            'placeholder' => '/opt/playbooks/myproject',
        ])->hint('Absolute path on the host where playbooks and roles are located. The worker must have read access to this directory.') ?>
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
    var scmType     = document.getElementById('project-scm_type');
    var gitFields    = document.getElementById('git-fields');
    var manualFields = document.getElementById('manual-fields');
    if (scmType) {
        scmType.addEventListener('change', function () {
            gitFields.style.display    = this.value === 'git'    ? '' : 'none';
            manualFields.style.display = this.value === 'manual' ? '' : 'none';
        });
    }
});
</script>
