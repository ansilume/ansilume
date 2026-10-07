<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Project $model */
/** @var app\models\Credential[] $scmCredentials */

use app\models\Credential;
use app\models\Project;
use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = $model->isNewRecord ? 'New Project' : 'Edit: ' . $model->name;

$credOptions = ['' => '— None (public repo) —'];
foreach ($scmCredentials as $c) {
    $credOptions[$c->id] = $c->name . ' (' . Credential::typeLabel($c->credential_type) . ')';
}
?>
<div class="row justify-content-center">
<div class="col-lg-7">
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><?= Html::a('Projects', ['index']) ?></li>
        <?php if (!$model->isNewRecord) : ?>
            <li class="breadcrumb-item"><?= Html::a(Html::encode($model->name), ['view', 'id' => $model->id]) ?></li>
        <?php endif; ?>
        <li class="breadcrumb-item active"><?= $model->isNewRecord ? 'New' : 'Edit' ?></li>
    </ol>
</nav>
<h2><?= Html::encode($this->title) ?></h2>

<?php $form = ActiveForm::begin(['id' => 'project-form']); ?>

    <?= $form->field($model, 'name')->textInput(['maxlength' => 128, 'autofocus' => true]) ?>
    <?= $form->field($model, 'description')->textarea(['rows' => 3]) ?>

    <?= $form->field($model, 'scm_type')->dropDownList([
        Project::SCM_TYPE_GIT => 'Git',
        Project::SCM_TYPE_MANUAL => 'Manual (no SCM)',
    ]) ?>

    <div id="git-fields" <?= $model->scm_type !== Project::SCM_TYPE_GIT ? 'style="display:none"' : '' // xss-ok: hardcoded attribute?>>

        <?= $form->field($model, 'scm_url')->textInput(['maxlength' => 512, 'placeholder' => 'https://github.com/org/repo.git', 'id' => 'project-scm_url']) ?>
        <?= $form->field($model, 'scm_branch')->textInput(['maxlength' => 128]) ?>
        <?= $form->field($model, 'scm_credential_id')->dropDownList(
            $credOptions,
            ['id' => 'project-scm_credential_id']
        )->hint('SSH URLs require an SSH Key credential. HTTPS URLs require a Token or Username/Password credential.') ?>
    </div>

    <div id="manual-fields" <?= $model->scm_type !== Project::SCM_TYPE_MANUAL ? 'style="display:none"' : '' // xss-ok: hardcoded attribute?>>

        <?= $form->field($model, 'local_path')->textInput([
            'maxlength' => 512,
            'placeholder' => '/opt/playbooks/myproject',
        ])->hint('Absolute path on the host where playbooks and roles are located. The worker must have read access to this directory.') ?>
    </div>

    <?= $form->field($model, 'vault_password_source')->dropDownList([
        Project::VAULT_SOURCE_ANSILUME => Project::vaultSourceLabel(Project::VAULT_SOURCE_ANSILUME),
        Project::VAULT_SOURCE_REPOSITORY => Project::vaultSourceLabel(Project::VAULT_SOURCE_REPOSITORY),
    ])->label('Vault passwords on runners')->hint(
        '"Ansilume only": runners use only the vault password attached to the job template and ignore the vault settings '
        . 'of the repository\'s ansible.cfg (vault_password_file, vault_identity_list, ask_vault_pass, vault_id_match). '
        . '"Ansilume and repository": runners also apply those settings, so a password file or script from the repository '
        . 'is used as well. Runners older than 2.8 always behave like "Ansilume and repository".'
    ) ?>

    <div class="mt-3">
        <?= Html::submitButton($model->isNewRecord ? 'Create Project' : 'Save Changes', ['class' => 'btn btn-primary']) ?>
        <?= Html::a('Cancel', $model->isNewRecord ? ['index'] : ['view', 'id' => $model->id], ['class' => 'btn btn-outline-secondary ms-2']) ?>
    </div>

<?php ActiveForm::end(); ?>

</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var scmType      = document.getElementById('project-scm_type');
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
