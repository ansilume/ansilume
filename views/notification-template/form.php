<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\NotificationTemplate $model */

use app\models\NotificationTemplate;
use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = $model->isNewRecord ? 'New Notification Template' : 'Edit: ' . $model->name;
?>
<div class="row justify-content-center">
<div class="col-lg-8">

<h2><?= Html::encode($this->title) ?></h2>

<?php $form = ActiveForm::begin(['id' => 'notification-template-form']); ?>

    <?= $form->field($model, 'name')->textInput(['maxlength' => 128, 'autofocus' => true]) ?>
    <?= $form->field($model, 'description')->textarea(['rows' => 2]) ?>

    <?= $form->field($model, 'channel')->dropDownList(
        NotificationTemplate::channelLabels(),
        ['id' => 'nt-channel']
    ) ?>

    <div id="config-email" class="channel-config">
        <div class="card card-body bg-transparent border-secondary mb-3">
            <label class="form-label">Email Recipients</label>
            <input type="text" id="email-recipients" class="form-control font-monospace"
                   placeholder='["ops@example.com","alerts@example.com"]'>
            <div class="form-text">JSON array of email addresses. Stored in the config field.</div>
        </div>
    </div>

    <div id="config-slack" class="channel-config" style="display:none">
        <div class="card card-body bg-transparent border-secondary mb-3">
            <label class="form-label">Slack Webhook URL</label>
            <input type="text" id="slack-webhook-url" class="form-control font-monospace"
                   placeholder="https://hooks.slack.com/services/...">
        </div>
    </div>

    <div id="config-teams" class="channel-config" style="display:none">
        <div class="card card-body bg-transparent border-secondary mb-3">
            <label class="form-label">Teams Webhook URL</label>
            <input type="text" id="teams-webhook-url" class="form-control font-monospace"
                   placeholder="https://outlook.office.com/webhook/...">
        </div>
    </div>

    <div id="config-webhook" class="channel-config" style="display:none">
        <div class="card card-body bg-transparent border-secondary mb-3">
            <label class="form-label">Webhook URL</label>
            <input type="text" id="webhook-url" class="form-control font-monospace"
                   placeholder="https://example.com/hook">
            <label class="form-label mt-2">Custom Headers (JSON)</label>
            <input type="text" id="webhook-headers" class="form-control font-monospace"
                   placeholder='{"X-Custom": "value"}'>
        </div>
    </div>

    <?= $form->field($model, 'config')->hiddenInput(['id' => 'nt-config'])->label(false) ?>

    <h5 class="mt-3 text-muted">Events</h5>
    <div class="row g-2 mb-3">
        <?php foreach (NotificationTemplate::eventLabels() as $value => $label) : ?>
            <div class="col-md-3">
                <div class="form-check">
                    <input class="form-check-input event-checkbox" type="checkbox"
                           value="<?= Html::encode($value) ?>"
                           id="event-<?= Html::encode($value) ?>"
                           <?= in_array($value, $model->getEventList(), true) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="event-<?= Html::encode($value) ?>">
                        <?= Html::encode($label) ?>
                    </label>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?= $form->field($model, 'events')->hiddenInput(['id' => 'nt-events'])->label(false) ?>

    <h5 class="mt-3 text-muted">Templates</h5>
    <?= $form->field($model, 'subject_template')->textInput([
        'maxlength' => 512,
        'placeholder' => '[Ansilume] Job #{{ job.id }} {{ job.status }} — {{ template.name }}',
        'class' => 'form-control font-monospace',
    ])->hint('Available variables: {{ job.id }}, {{ job.status }}, {{ job.exit_code }}, {{ job.duration }}, {{ job.url }}, {{ template.name }}, {{ project.name }}, {{ launched_by }}, {{ timestamp }}') ?>

    <?= $form->field($model, 'body_template')->textarea([
        'rows' => 6,
        'class' => 'form-control font-monospace',
        'placeholder' => "Job #{{ job.id }} finished with status {{ job.status }}.\n\nTemplate: {{ template.name }}\nProject: {{ project.name }}\nLaunched by: {{ launched_by }}",
    ]) ?>

    <div class="mt-4">
        <?= Html::submitButton($model->isNewRecord ? 'Create' : 'Save Changes', ['class' => 'btn btn-primary', 'id' => 'nt-submit']) ?>
        <?= Html::a('Cancel', $model->isNewRecord ? ['index'] : ['view', 'id' => $model->id], ['class' => 'btn btn-outline-secondary ms-2']) ?>
    </div>

<?php ActiveForm::end(); ?>

</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var channelSelect = document.getElementById('nt-channel');
    var configInput = document.getElementById('nt-config');
    var eventsInput = document.getElementById('nt-events');
    var panels = document.querySelectorAll('.channel-config');

    function showPanel() {
        var ch = channelSelect.value;
        panels.forEach(function (p) { p.style.display = 'none'; });
        var target = document.getElementById('config-' + ch);
        if (target) target.style.display = '';
    }

    function parseExistingConfig() {
        try { return JSON.parse(configInput.value || '{}'); } catch (e) { return {}; }
    }

    // Populate helper inputs from existing config
    var existing = parseExistingConfig();
    if (existing.emails) document.getElementById('email-recipients').value = JSON.stringify(existing.emails);
    if (existing.webhook_url) {
        var ch = channelSelect.value;
        if (ch === 'slack') document.getElementById('slack-webhook-url').value = existing.webhook_url;
        else if (ch === 'teams') document.getElementById('teams-webhook-url').value = existing.webhook_url;
        else if (ch === 'webhook') document.getElementById('webhook-url').value = existing.webhook_url;
    }
    if (existing.url) document.getElementById('webhook-url').value = existing.url;
    if (existing.headers) document.getElementById('webhook-headers').value = JSON.stringify(existing.headers);

    channelSelect.addEventListener('change', showPanel);
    showPanel();

    // Before submit: build config JSON and events CSV
    document.getElementById('nt-submit').closest('form').addEventListener('submit', function () {
        var ch = channelSelect.value;
        var cfg = {};
        if (ch === 'email') {
            try { cfg.emails = JSON.parse(document.getElementById('email-recipients').value || '[]'); } catch (e) { cfg.emails = []; }
        } else if (ch === 'slack') {
            cfg.webhook_url = document.getElementById('slack-webhook-url').value;
        } else if (ch === 'teams') {
            cfg.webhook_url = document.getElementById('teams-webhook-url').value;
        } else if (ch === 'webhook') {
            cfg.url = document.getElementById('webhook-url').value;
            try { cfg.headers = JSON.parse(document.getElementById('webhook-headers').value || '{}'); } catch (e) { cfg.headers = {}; }
        }
        configInput.value = JSON.stringify(cfg);

        var events = [];
        document.querySelectorAll('.event-checkbox:checked').forEach(function (cb) {
            events.push(cb.value);
        });
        eventsInput.value = events.join(',');
    });
});
</script>
