<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\JobTemplate $model */
/** @var app\models\Project[] $projects */
/** @var app\models\Inventory[] $inventories */
/** @var app\models\Credential[] $credentials */
/** @var app\models\RunnerGroup[] $runnerGroups */

use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = $model->isNewRecord ? 'New Job Template' : 'Edit: ' . $model->name;
?>
<div class="row justify-content-center">
<div class="col-lg-8">
<h2><?= Html::encode($this->title) ?></h2>

<?php $form = ActiveForm::begin(['id' => 'jt-form']); ?>

    <h5 class="mt-3 text-muted">General</h5>
    <?= $form->field($model, 'name')->textInput(['maxlength' => 128, 'autofocus' => true]) ?>
    <?= $form->field($model, 'description')->textarea(['rows' => 2]) ?>

    <h5 class="mt-3 text-muted">Execution</h5>
    <div class="row g-2">
        <div class="col-md-6">
            <?= $form->field($model, 'project_id')->dropDownList(
                ArrayHelper::map($projects, 'id', 'name'),
                ['prompt' => '— Select project —']
            ) ?>
        </div>
        <div class="col-md-6">
            <?= $form->field($model, 'playbook')->textInput(['placeholder' => 'site.yml', 'maxlength' => 512]) ?>
        </div>
    </div>
    <div class="row g-2">
        <div class="col-md-6">
            <?= $form->field($model, 'inventory_id')->dropDownList(
                ArrayHelper::map($inventories, 'id', 'name'),
                ['prompt' => '— Select inventory —']
            ) ?>
        </div>
        <div class="col-md-6">
            <?= $form->field($model, 'credential_id')->dropDownList(
                ArrayHelper::map($credentials, 'id', 'name'),
                ['prompt' => '— None —']
            ) ?>
        </div>
    </div>

    <?= $form->field($model, 'extra_vars')->textarea([
        'rows' => 4,
        'class' => 'form-control font-monospace',
        'placeholder' => '{"env": "production", "version": "1.2.3"}',
    ])->hint('JSON object. Overridable at launch time.') ?>

    <h5 class="mt-3 text-muted">Runner</h5>
    <div class="row g-2">
        <div class="col-md-6">
            <?= $form->field($model, 'runner_group_id')->dropDownList(
                ArrayHelper::map($runnerGroups, 'id', 'name'),
                ['prompt' => '— Select —']
            )->hint('Which group of runners should execute this job.') ?>
        </div>
        <div class="col-md-3">
            <?= $form->field($model, 'timeout_minutes')->textInput([
                'type' => 'number', 'min' => 1, 'max' => 1440,
            ])->hint('Minutes before the job is killed (max 1440 = 24 h).') ?>
        </div>
    </div>

    <h5 class="mt-3 text-muted">Options</h5>
    <div class="row g-2">
        <div class="col-md-3">
            <?= $form->field($model, 'verbosity')->dropDownList([
                0 => '0 (Normal)', 1 => '1 (-v)', 2 => '2 (-vv)',
                3 => '3 (-vvv)', 4 => '4 (-vvvv)', 5 => '5 (-vvvvv)',
            ]) ?>
        </div>
        <div class="col-md-3">
            <?= $form->field($model, 'forks')->textInput(['type' => 'number', 'min' => 1, 'max' => 200]) ?>
        </div>
        <div class="col-md-6">
            <?= $form->field($model, 'limit')->textInput(['placeholder' => 'webservers:!staging', 'maxlength' => 255]) ?>
        </div>
    </div>
    <div class="row g-2">
        <div class="col-md-4">
            <?= $form->field($model, 'tags')->textInput(['placeholder' => 'deploy,config', 'maxlength' => 512]) ?>
        </div>
        <div class="col-md-4">
            <?= $form->field($model, 'skip_tags')->textInput(['placeholder' => 'slow', 'maxlength' => 512]) ?>
        </div>
    </div>

    <h5 class="mt-3 text-muted">Privilege Escalation</h5>
    <div class="row g-2 align-items-end">
        <div class="col-md-2">
            <?= $form->field($model, 'become')->checkbox() ?>
        </div>
        <div class="col-md-3">
            <?= $form->field($model, 'become_method')->dropDownList([
                'sudo' => 'sudo', 'su' => 'su', 'pbrun' => 'pbrun',
                'pfexec' => 'pfexec', 'doas' => 'doas',
            ]) ?>
        </div>
        <div class="col-md-3">
            <?= $form->field($model, 'become_user')->textInput(['maxlength' => 64]) ?>
        </div>
    </div>

    <h5 class="mt-3 text-muted">Survey Fields <span class="text-muted fw-normal small">(optional — prompts the user at launch time)</span></h5>
    <?= $this->render('_survey_editor', ['model' => $model]) ?>

    <div class="mt-4">
        <?= Html::submitButton($model->isNewRecord ? 'Create Template' : 'Save Changes', ['class' => 'btn btn-primary']) ?>
        <?= Html::a('Cancel', $model->isNewRecord ? ['index'] : ['view', 'id' => $model->id], ['class' => 'btn btn-outline-secondary ms-2']) ?>
    </div>

<?php ActiveForm::end(); ?>
</div>
</div>
