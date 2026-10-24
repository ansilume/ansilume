<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Schedule $model */
/** @var array $templates  id => name map of job templates */

use app\components\SurveySecrets;
use app\components\VersionedAssetUrl;
use app\models\Schedule;
use app\services\ScheduleRunAs;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\widgets\ActiveForm;

$isNew = $model->isNewRecord;
$this->title = $isNew ? 'New Schedule' : 'Edit: ' . Html::encode($model->name);
// Whom the schedule runs as; saving a change to what or when it runs makes it run as the user who saves it.
$runsAs = ScheduleRunAs::FORM_RUNS_AS_YOU;
if (!$isNew && (int)$model->created_by !== (int)\Yii::$app->user->id) {
    $runsAs = sprintf(ScheduleRunAs::FORM_RUNS_AS_OTHER, $model->creator->username ?? 'user #' . (int)$model->created_by)
        . ScheduleRunAs::FORM_NOTE;
}
// Why a save refuses the stored cron expression, before a save: a rejected
// submission shows the reason as the error of the cron expression instead.
$cronProblem = $isNew || $model->hasErrors() ? null : $model->cronExpressionProblem();
// The expression whose description gets no check mark: the stored one a save
// refuses, or the one a rejected submission was refused for.
$refusedCron = $cronProblem !== null || $model->hasErrors('cron_expression') ? (string)$model->cron_expression : null;
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><?= Html::a('Schedules', ['index']) ?></li>
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

<?php if ($cronProblem !== null) : ?>
    <div class="alert alert-warning" id="schedule-form-cron-problem" data-testid="schedule-form-cron-problem">
        <div><?= Html::encode(Schedule::CRON_REFUSED_NOTE) ?></div>
        <div class="mt-1"><?= Html::encode($cronProblem) ?></div>
    </div>
<?php endif; ?>

<?php $form = ActiveForm::begin(['id' => 'schedule-form']); ?>

<div class="row g-3">
    <div class="col-md-6">
        <?= $form->field($model, 'name')->textInput(['maxlength' => 128, 'placeholder' => 'e.g. Nightly playbook run']) ?>
    </div>
    <div class="col-md-6">
        <?= $form->field($model, 'job_template_id')->dropDownList(
            $templates,
            ['prompt' => '— Select template —']
        )->hint(Html::encode('Lists the job templates you may launch. ' . $runsAs)) ?>
    </div>
    <div class="col-md-6">
        <?= $form->field($model, 'cron_expression')
            ->textInput(['maxlength' => 64, 'placeholder' => '0 2 * * *', 'id' => 'cron-input', 'data-refused' => $refusedCron])
            ->hint('Standard 5-field cron: min hour dom mon dow. Example: <code>0 2 * * *</code> = daily at 02:00.') ?>
        <div id="cron-preview" class="form-text mt-1" style="min-height:1.25em"></div>
    </div>
    <div class="col-md-6">
        <?= $form->field($model, 'timezone')
            ->textInput(['maxlength' => 64, 'placeholder' => 'UTC'])
            ->hint('PHP timezone name, e.g. <code>UTC</code>, <code>Europe/Berlin</code>, <code>America/New_York</code>.') ?>
    </div>
    <div class="col-12">
        <?= $form->field($model, 'extra_vars')
            ->textarea([
                'rows' => 5,
                'class' => 'form-control font-monospace',
                'placeholder' => "{\n  \"env\": \"production\"\n}",
                'data-extra-vars-editor' => '1',
            ])
            ->hint(
                'Optional JSON extra vars that override the template defaults for scheduled runs. '
                . 'Answers to the template\'s password survey fields are stored encrypted and show as <code>'
                . Html::encode(SurveySecrets::PLACEHOLDER) . '</code>: leave it to keep the stored answer, or replace it to change it.'
            ) ?>
    </div>
    <div class="col-12">
        <?= $form->field($model, 'enabled')->checkbox() ?>
    </div>
</div>

<div class="mt-3">
    <?= Html::submitButton($isNew ? 'Create Schedule' : 'Save Changes', ['class' => 'btn btn-primary']) ?>
</div>

<?php ActiveForm::end(); ?>

<script src="/js/cronstrue.min.js"></script>
<script>
(function () {
    var input   = document.getElementById('cron-input');
    var preview = document.getElementById('cron-preview');
    if (!input || !preview) return;
    var refused = input.getAttribute('data-refused');
    var asWritten = <?= Json::htmlEncode(Schedule::CRON_AS_WRITTEN) ?>;

    function show(className, text) {
        var span = document.createElement('span');
        span.className = className;
        span.textContent = text;
        preview.replaceChildren(span);
    }

    function update() {
        var val = input.value.trim();
        if (!val) { preview.replaceChildren(); return; }
        var text;
        try {
            text = cronstrue.toString(val, { use24HourTimeFormat: true });
        } catch (e) {
            show('text-danger', 'Invalid expression');
            return;
        }
        // A save refuses this expression: the scheduler does not run it as the description reads.
        if (refused !== null && val === refused.trim()) {
            show('text-warning-emphasis', '\u26A0 ' + asWritten + ' ' + text);
        } else {
            show('text-success', '\u2713 ' + text);
        }
    }

    input.addEventListener('input', update);
    update();
})();
</script>

<link rel="stylesheet" href="/css/vendor/codemirror/codemirror.css">
<?= Html::cssFile(VersionedAssetUrl::of('/css/extra-vars-editor.css')) ?>
<script src="/js/vendor/codemirror/codemirror.js"></script>
<script src="/js/vendor/codemirror/mode/javascript/javascript.js"></script>
<script src="/js/vendor/codemirror/mode/yaml/yaml.js"></script>
<script src="/js/vendor/codemirror/addon/edit/matchbrackets.js"></script>
<script src="/js/vendor/codemirror/addon/edit/closebrackets.js"></script>
<script src="/js/vendor/codemirror/addon/selection/active-line.js"></script>
<script src="/js/vendor/js-yaml/js-yaml.min.js"></script>
<?= Html::jsFile(VersionedAssetUrl::of('/js/extra-vars-editor.js')) ?>
