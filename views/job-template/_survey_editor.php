<?php

declare(strict_types=1);

/**
 * Survey field editor partial.
 * Renders a dynamic list of survey field rows driven by Alpine.js-style vanilla JS.
 * The final value is serialized to JSON and written into the hidden survey_fields input.
 *
 * Password fields have no default: the page never carries a stored one, and
 * the editor saves none.
 *
 * @var yii\web\View $this
 * @var app\models\JobTemplate $model
 */

use app\components\SurveyField;
use yii\helpers\Html;
use yii\helpers\Json;

$existing = array_map(static function (SurveyField $field): array {
    $data = $field->toArray();
    if ($field->type === SurveyField::TYPE_PASSWORD) {
        $data['default'] = '';
    }
    return $data;
}, $model->getSurveyFields());
$types = SurveyField::types();
?>
<div id="survey-editor">
    <div id="survey-rows">
        <!-- Rows injected by JS -->
    </div>
    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="surveyAddRow()">
        + Add Field
    </button>
    <!-- Hidden field that carries the serialized JSON to the form; it starts as the
         fields the editor shows, so the page source never holds a password default. -->
    <?= Html::hiddenInput('JobTemplate[survey_fields]', Json::htmlEncode($existing), ['id' => 'survey-fields-json']) ?>
    <?php foreach ($model->getErrors('survey_fields') as $error) : ?>
        <div class="invalid-feedback d-block" data-testid="survey-fields-error"><?= Html::encode($error) ?></div>
    <?php endforeach; ?>
</div>

<template id="survey-row-tpl">
    <div class="survey-row border rounded p-2 mb-2" data-idx="">
        <div class="row g-2 align-items-start">
            <div class="col-md-2">
                <label class="form-label small">Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control form-control-sm sf-name" placeholder="var_name">
            </div>
            <div class="col-md-3">
                <label class="form-label small">Label</label>
                <input type="text" class="form-control form-control-sm sf-label" placeholder="Display Label">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Type</label>
                <select class="form-select form-select-sm sf-type">
                    <?php foreach ($types as $val => $label) : ?>
                        <option value="<?= Html::encode($val) ?>"><?= Html::encode($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Default</label>
                <input type="text" class="form-control form-control-sm sf-default" placeholder="">
                <div class="form-text sf-default-none" style="display:none">None: entered at launch</div>
            </div>
            <div class="col-md-1 text-center">
                <label class="form-label small">Required</label><br>
                <input type="checkbox" class="form-check-input sf-required mt-1">
            </div>
            <div class="col-md-1 d-flex align-items-end pb-1">
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="surveyRemoveRow(this)">✕</button>
            </div>
        </div>
        <div class="row g-2 mt-1 sf-options-row" style="display:none">
            <div class="col-12">
                <label class="form-label small">Options <span class="text-muted">(comma-separated, for Select type)</span></label>
                <input type="text" class="form-control form-control-sm sf-options" placeholder="option1, option2, option3">
            </div>
        </div>
        <div class="row g-2 mt-1">
            <div class="col-12">
                <label class="form-label small">Hint <span class="text-muted">(optional)</span></label>
                <input type="text" class="form-control form-control-sm sf-hint" placeholder="">
            </div>
        </div>
    </div>
</template>

<script>
(function () {
    // htmlEncode: no survey text may end this script element or open a comment in it.
    var existing = <?= Json::htmlEncode($existing) ?>;
    var container = document.getElementById('survey-rows');
    var tpl       = document.getElementById('survey-row-tpl');
    var hidden    = document.getElementById('survey-fields-json');

    function serialize() {
        var rows = container.querySelectorAll('.survey-row');
        var result = [];
        rows.forEach(function (row) {
            var name = row.querySelector('.sf-name').value.trim();
            if (!name) return;
            var type    = row.querySelector('.sf-type').value;
            var opts    = row.querySelector('.sf-options').value
                .split(',').map(s => s.trim()).filter(Boolean);
            result.push({
                name:     name,
                label:    row.querySelector('.sf-label').value.trim() || name,
                type:     type,
                required: row.querySelector('.sf-required').checked,
                // A password field has no default: its answer is entered at launch.
                default:  type === 'password' ? '' : row.querySelector('.sf-default').value,
                options:  opts,
                hint:     row.querySelector('.sf-hint').value.trim(),
            });
        });
        hidden.value = JSON.stringify(result);
    }

    function makeRow(data) {
        var clone = tpl.content.cloneNode(true);
        var row   = clone.querySelector('.survey-row');
        if (data) {
            row.querySelector('.sf-name').value    = data.name    || '';
            row.querySelector('.sf-label').value   = data.label   || '';
            row.querySelector('.sf-default').value = data.default || '';
            row.querySelector('.sf-hint').value    = data.hint    || '';
            row.querySelector('.sf-required').checked = !!data.required;
            var typeEl = row.querySelector('.sf-type');
            if (data.type) typeEl.value = data.type;
            // An unknown stored type shows as text, as the launch form renders it.
            if (!typeEl.value) typeEl.value = 'text';
            row.querySelector('.sf-options').value = (data.options || []).join(', ');
            toggleOptionsRow(row, typeEl.value);
            toggleDefault(row, typeEl.value);
        }
        // Bind type change
        row.querySelector('.sf-type').addEventListener('change', function () {
            toggleOptionsRow(row, this.value);
            toggleDefault(row, this.value);
            serialize();
        });
        row.querySelectorAll('input, select, textarea').forEach(function (el) {
            el.addEventListener('input', serialize);
            el.addEventListener('change', serialize);
        });
        return clone;
    }

    function toggleOptionsRow(row, type) {
        row.querySelector('.sf-options-row').style.display = type === 'select' ? '' : 'none';
    }

    // Password fields get no default input; switching to password clears it.
    function toggleDefault(row, type) {
        var input = row.querySelector('.sf-default');
        var isPassword = type === 'password';
        if (isPassword) input.value = '';
        input.style.display = isPassword ? 'none' : '';
        row.querySelector('.sf-default-none').style.display = isPassword ? '' : 'none';
    }

    window.surveyAddRow = function () {
        container.appendChild(makeRow(null));
        serialize();
    };

    window.surveyRemoveRow = function (btn) {
        btn.closest('.survey-row').remove();
        serialize();
    };

    // Load existing fields
    existing.forEach(function (f) { container.appendChild(makeRow(f)); });
    serialize();

    // Serialize before form submit
    document.getElementById('jt-form') && document.getElementById('jt-form')
        .addEventListener('submit', serialize);
})();
</script>
