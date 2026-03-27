<?php

declare(strict_types=1);

/**
 * Survey field editor partial.
 * Renders a dynamic list of survey field rows driven by Alpine.js-style vanilla JS.
 * The final value is serialized to JSON and written into the hidden survey_fields input.
 *
 * @var yii\web\View $this
 * @var app\models\JobTemplate $model
 */

use app\components\SurveyField;
use yii\helpers\Html;
use yii\helpers\Json;

$existing = $model->getSurveyFields();
$types = SurveyField::types();
?>
<div id="survey-editor">
    <div id="survey-rows">
        <!-- Rows injected by JS -->
    </div>
    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="surveyAddRow()">
        + Add Field
    </button>
    <!-- Hidden field that carries the serialized JSON to the form -->
    <?= Html::hiddenInput('JobTemplate[survey_fields]', $model->survey_fields ?? '', ['id' => 'survey-fields-json']) ?>
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
    var existing = <?= Json::encode(array_map(fn($f) => $f->toArray(), $existing)) ?>;
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
                default:  row.querySelector('.sf-default').value,
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
            row.querySelector('.sf-options').value = (data.options || []).join(', ');
            toggleOptionsRow(row, typeEl.value);
        }
        // Bind type change
        row.querySelector('.sf-type').addEventListener('change', function () {
            toggleOptionsRow(row, this.value);
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
