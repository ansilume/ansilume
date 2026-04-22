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
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><?= Html::a('Job Templates', ['index']) ?></li>
        <?php if (!$model->isNewRecord) : ?>
            <li class="breadcrumb-item"><?= Html::a(Html::encode($model->name), ['view', 'id' => $model->id]) ?></li>
        <?php endif; ?>
        <li class="breadcrumb-item active"><?= $model->isNewRecord ? 'New' : 'Edit' ?></li>
    </ol>
</nav>
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
            )->hint('Primary credential (e.g. SSH key). Used for <code>--user</code> / <code>--private-key</code> when connecting to target hosts.') ?>
        </div>
    </div>

    <?php
    $selectedExtraIds = array_map(
        static fn ($c) => (int)$c->id,
        array_filter($model->credentials, static fn ($c) => (int)$c->id !== (int)$model->credential_id)
    );
    $extraCredentials = array_filter($credentials, static fn ($c) => (int)$c->id !== (int)$model->credential_id);
    ?>
    <div class="mb-3">
        <label class="form-label">Additional credentials</label>
        <?php if (empty($extraCredentials)) : ?>
            <div class="form-text">Create more credentials to attach tokens, API keys, vault passwords, or extra secrets to this template.</div>
        <?php else : ?>
            <div class="border rounded p-2" style="max-height:200px;overflow-y:auto">
                <?php foreach ($extraCredentials as $c) : ?>
                    <?php $checked = in_array((int)$c->id, $selectedExtraIds, true); ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox"
                               name="credential_ids[]" value="<?= (int)$c->id ?>"
                               id="cred-extra-<?= (int)$c->id ?>"
                               <?= $checked ? 'checked' : '' ?>>
                        <label class="form-check-label" for="cred-extra-<?= (int)$c->id ?>">
                            <?= Html::encode($c->name) ?>
                            <span class="text-muted small">— <?= Html::encode(\app\models\Credential::typeLabel($c->credential_type)) ?></span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="form-text">
                Tokens are injected as environment variables (see the credential's <em>Env var name</em>).
                Vault and SSH credentials can only claim one Ansible slot (<code>--user</code>, <code>--vault-password-file</code>); the primary credential above wins if multiple are selected.
            </div>
        <?php endif; ?>
    </div>

    <?= $form->field($model, 'extra_vars')->textarea([
        'rows' => 6,
        'class' => 'form-control font-monospace',
        'placeholder' => '{"env": "production", "version": "1.2.3"}',
        'data-extra-vars-editor' => '1',
    ])->hint('Stored as JSON. Toggle to YAML for easier editing — the editor converts in-place. Overridable at launch time.') ?>

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
            ])->hint('Higher levels produce more detailed output for debugging.') ?>
        </div>
        <div class="col-md-3">
            <?= $form->field($model, 'forks')->textInput(['type' => 'number', 'min' => 1, 'max' => 200])
                ->hint('Number of parallel host connections (default 5).') ?>
        </div>
        <div class="col-md-6">
            <?= $form->field($model, 'limit')->textInput(['placeholder' => 'webservers:!staging', 'maxlength' => 255])
                ->hint('Ansible host pattern to restrict execution to a subset of hosts.') ?>
        </div>
    </div>
    <div class="row g-2">
        <div class="col-md-4">
            <?= $form->field($model, 'tags')->textInput(['placeholder' => 'deploy,config', 'maxlength' => 512])
                ->hint('Comma-separated. Only run tasks with these tags.') ?>
        </div>
        <div class="col-md-4">
            <?= $form->field($model, 'skip_tags')->textInput(['placeholder' => 'slow', 'maxlength' => 512])
                ->hint('Comma-separated. Skip tasks with these tags.') ?>
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

<link rel="stylesheet" href="/css/vendor/codemirror/codemirror.css">
<script src="/js/vendor/codemirror/codemirror.js"></script>
<script src="/js/vendor/codemirror/mode/javascript/javascript.js"></script>
<script src="/js/vendor/codemirror/mode/yaml/yaml.js"></script>
<script src="/js/vendor/codemirror/addon/edit/matchbrackets.js"></script>
<script src="/js/vendor/codemirror/addon/edit/closebrackets.js"></script>
<script src="/js/vendor/codemirror/addon/selection/active-line.js"></script>
<script src="/js/vendor/js-yaml/js-yaml.min.js"></script>
<script src="/js/extra-vars-editor.js"></script>
