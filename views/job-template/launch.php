<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\JobTemplate $template */

use app\components\SurveyField;
use yii\helpers\Html;

$this->title = 'Launch: ' . $template->name;
$surveyFields = $template->getSurveyFields();
$hasSurvey = !empty($surveyFields);
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><?= Html::a('Templates', ['index']) ?></li>
        <li class="breadcrumb-item"><?= Html::a(Html::encode($template->name), ['view', 'id' => $template->id]) ?></li>
        <li class="breadcrumb-item active">Launch</li>
    </ol>
</nav>

<div class="row justify-content-center">
<div class="col-lg-7">

<?php if ($template->inventory !== null) : ?>
    <?= $this->render('/inventory/_localhost-warning', ['inventory' => $template->inventory]) ?>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header">Template Summary</div>
    <div class="card-body">
        <dl class="row mb-0 small">
            <dt class="col-4">Project</dt>    <dd class="col-8"><?= Html::encode($template->project->name ?? '—') ?></dd>
            <dt class="col-4">Playbook</dt>   <dd class="col-8"><code><?= Html::encode($template->playbook) ?></code></dd>
            <dt class="col-4">Inventory</dt>  <dd class="col-8"><?= Html::encode($template->inventory->name ?? '—') ?></dd>
            <dt class="col-4">Credential</dt> <dd class="col-8"><?= Html::encode($template->credential->name ?? 'None') ?></dd>
        </dl>
    </div>
</div>

<?php $form = \yii\widgets\ActiveForm::begin([
    'id' => 'launch-form',
    'action' => ['launch', 'id' => $template->id],
    'method' => 'post',
]); ?>

<?php if ($hasSurvey) : ?>
<div class="card mb-3">
    <div class="card-header">Survey</div>
    <div class="card-body">
        <?php foreach ($surveyFields as $field) : ?>
        <div class="mb-3">
            <label class="form-label">
                <?= Html::encode($field->label) ?>
                <?php if ($field->required) :
                    ?><span class="text-danger">*</span><?php
                endif; ?>
            </label>
            <?php $inputName = 'survey[' . $field->name . ']'; ?>
            <?php $inputId = 'survey-' . preg_replace('/[^a-z0-9_-]/i', '-', $field->name); ?>

            <?php if ($field->type === SurveyField::TYPE_TEXTAREA) : ?>
                <textarea name="<?= Html::encode($inputName) ?>" id="<?= Html::encode($inputId) ?>"
                          class="form-control font-monospace" rows="4"
                          <?= $field->required ? 'required' : '' // xss-ok: hardcoded attribute?>><?= Html::encode($field->default) ?></textarea>

            <?php elseif ($field->type === SurveyField::TYPE_BOOLEAN) : ?>
                <div class="form-check">
                    <input type="hidden" name="<?= Html::encode($inputName) ?>" value="false">
                    <input type="checkbox" name="<?= Html::encode($inputName) ?>" id="<?= Html::encode($inputId) ?>"
                           class="form-check-input" value="true"
                           <?= $field->default === 'true' ? 'checked' : '' // xss-ok: hardcoded attribute?>>
                    <label class="form-check-label" for="<?= Html::encode($inputId) ?>">Yes</label>
                </div>

            <?php elseif ($field->type === SurveyField::TYPE_SELECT) : ?>
                <select name="<?= Html::encode($inputName) ?>" id="<?= Html::encode($inputId) ?>"
                        class="form-select" <?= $field->required ? 'required' : '' // xss-ok: hardcoded attribute?>>
                    <?php if (!$field->required) : ?>
                        <option value="">— Select —</option>
                    <?php endif; ?>
                    <?php foreach ($field->options as $opt) : ?>
                        <option value="<?= Html::encode($opt) ?>"
                                <?= $field->default === $opt ? 'selected' : '' // xss-ok: hardcoded attribute?>>
                            <?= Html::encode($opt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

            <?php elseif ($field->type === SurveyField::TYPE_PASSWORD) : ?>
                <input type="password" name="<?= Html::encode($inputName) ?>" id="<?= Html::encode($inputId) ?>"
                       class="form-control" autocomplete="off"
                       <?= $field->required ? 'required' : '' // xss-ok: hardcoded attribute?>>

            <?php else : /* text | integer */ ?>
                <input type="<?= $field->type === SurveyField::TYPE_INTEGER ? 'number' : 'text' // xss-ok: hardcoded strings?>"
                       name="<?= Html::encode($inputName) ?>" id="<?= Html::encode($inputId) ?>"
                       class="form-control" value="<?= Html::encode($field->default) ?>"
                       <?= $field->required ? 'required' : '' // xss-ok: hardcoded attribute?>>
            <?php endif; ?>

            <?php if ($field->hint) : ?>
                <div class="form-text"><?= Html::encode($field->hint) ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        Advanced Overrides
        <button type="button" class="btn btn-sm btn-link float-end" data-bs-toggle="collapse" data-bs-target="#advanced-overrides">
            Toggle
        </button>
    </div>
    <div id="advanced-overrides" class="collapse<?= $hasSurvey ? '' : ' show' // xss-ok: hardcoded CSS class?>">
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">Extra Vars <span class="text-muted small">(JSON, merged with template defaults<?= $hasSurvey ? ' and survey answers' : '' // xss-ok: hardcoded strings?>)</span></label>
                <textarea name="overrides[extra_vars]" class="form-control font-monospace" rows="4"><?= Html::encode(!$hasSurvey ? ($template->extra_vars ?? '') : '') ?></textarea>
                <div class="form-text">Example: <code>{"env": "staging"}</code></div>
            </div>
            <div class="mb-3">
                <label class="form-label">Limit</label>
                <input type="text" name="overrides[limit]" class="form-control"
                       value="<?= Html::encode($template->limit ?? '') ?>"
                       placeholder="webservers:!dbservers">
            </div>
            <div class="mb-3">
                <label class="form-label">Verbosity</label>
                <select name="overrides[verbosity]" class="form-select" style="max-width:200px">
                    <?php foreach ([0 => 'Default', 1 => '-v', 2 => '-vv', 3 => '-vvv', 4 => '-vvvv', 5 => '-vvvvv'] as $v => $label) : ?>
                        <option value="<?= $v ?>" <?= $v == $template->verbosity ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-0">
                <div class="form-check">
                    <input type="hidden" name="overrides[check_mode]" value="0">
                    <input type="checkbox" name="overrides[check_mode]" id="override-check-mode"
                           class="form-check-input" value="1">
                    <label class="form-check-label" for="override-check-mode">
                        Dry run (check mode)
                    </label>
                </div>
                <div class="form-text">Run with <code>--check --diff</code> — simulate changes without applying them.</div>
            </div>
        </div>
    </div>
</div>

<div class="mt-3 d-flex gap-2">
    <?= Html::submitButton('Launch Job', ['class' => 'btn btn-success btn-lg']) ?>
    <?= Html::a('Cancel', ['view', 'id' => $template->id], ['class' => 'btn btn-outline-secondary btn-lg']) ?>
</div>

<?php \yii\widgets\ActiveForm::end(); ?>

</div>
</div>
