<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\NotificationTemplate $model */

use app\models\NotificationTemplate;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\widgets\ActiveForm;

$this->title = $model->isNewRecord ? 'New Notification Template' : 'Edit: ' . $model->name;

// Smart defaults: on a brand-new template, pre-check only the failure events
// so operators subscribe to noise-free notifications by default. An existing
// template shows whatever it had saved.
$selectedEvents = $model->isNewRecord
    ? NotificationTemplate::defaultFailureEvents()
    : $model->getEventList();

// Pre-fill subject and body with sensible defaults so users can edit from there.
$defaultSubject = '[Ansilume] {{ event }} — {{ template.name }}';
$defaultBody = "Event: {{ event }} ({{ severity }})\nTemplate: {{ template.name }}\nProject: {{ project.name }}\nURL: {{ job.url }}";

if ($model->isNewRecord && empty($model->subject_template)) {
    $model->subject_template = $defaultSubject;
}
if ($model->isNewRecord && empty($model->body_template)) {
    $model->body_template = $defaultBody;
}

$failurePreset = NotificationTemplate::allFailureEvents();
$allEvents = array_keys(NotificationTemplate::eventLabels());
?>
<div class="row justify-content-center">
<div class="col-lg-9">
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><?= Html::a('Notification Templates', ['index']) ?></li>
        <?php if (!$model->isNewRecord) : ?>
            <li class="breadcrumb-item"><?= Html::a(Html::encode($model->name), ['view', 'id' => $model->id]) ?></li>
        <?php endif; ?>
        <li class="breadcrumb-item active"><?= $model->isNewRecord ? 'New' : 'Edit' ?></li>
    </ol>
</nav>
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
            <label class="form-label">Email Recipients <span class="text-danger">*</span></label>
            <input type="text" id="email-recipients" class="form-control font-monospace channel-required"
                   data-channel="email"
                   placeholder='["ops@example.com","alerts@example.com"]'>
            <div class="form-text">JSON array of email addresses.</div>
        </div>
    </div>

    <div id="config-slack" class="channel-config" style="display:none">
        <div class="card card-body bg-transparent border-secondary mb-3">
            <label class="form-label">Slack Webhook URL <span class="text-danger">*</span></label>
            <input type="text" id="slack-webhook-url" class="form-control font-monospace channel-required"
                   data-channel="slack"
                   placeholder="https://hooks.slack.com/services/...">
        </div>
    </div>

    <div id="config-teams" class="channel-config" style="display:none">
        <div class="card card-body bg-transparent border-secondary mb-3">
            <label class="form-label">Teams Webhook URL <span class="text-danger">*</span></label>
            <input type="text" id="teams-webhook-url" class="form-control font-monospace channel-required"
                   data-channel="teams"
                   placeholder="https://outlook.office.com/webhook/...">
        </div>
    </div>

    <div id="config-webhook" class="channel-config" style="display:none">
        <div class="card card-body bg-transparent border-secondary mb-3">
            <label class="form-label">Webhook URL <span class="text-danger">*</span></label>
            <input type="text" id="webhook-url" class="form-control font-monospace channel-required"
                   data-channel="webhook"
                   placeholder="https://example.com/hook">
            <label class="form-label mt-2">Custom Headers (JSON)</label>
            <input type="text" id="webhook-headers" class="form-control font-monospace"
                   placeholder='{"X-Custom": "value"}'>
        </div>
    </div>

    <div id="config-telegram" class="channel-config" style="display:none">
        <div class="card card-body bg-transparent border-secondary mb-3">
            <label class="form-label">Bot Token <span class="text-danger">*</span></label>
            <input type="text" id="telegram-bot-token" class="form-control font-monospace channel-required"
                   data-channel="telegram"
                   placeholder="123456789:ABCdefGHIjklMNOpqrSTUvwxYZ">
            <label class="form-label mt-2">Chat ID <span class="text-danger">*</span></label>
            <input type="text" id="telegram-chat-id" class="form-control font-monospace channel-required"
                   data-channel="telegram"
                   placeholder="-1001234567890">
            <div class="form-text">Create a bot via @BotFather and invite it to the target chat.</div>
        </div>
    </div>

    <div id="config-pagerduty" class="channel-config" style="display:none">
        <div class="card card-body bg-transparent border-secondary mb-3">
            <label class="form-label">Routing Key (Integration Key) <span class="text-danger">*</span></label>
            <input type="text" id="pagerduty-routing-key" class="form-control font-monospace channel-required"
                   data-channel="pagerduty"
                   placeholder="R0UT1NGKEY1234567890">
            <div class="form-text">From a PagerDuty Events API v2 integration on the target service. Severity follows the event type; success events automatically resolve the matching incident.</div>
        </div>
    </div>

    <?= $form->field($model, 'config')->hiddenInput(['id' => 'nt-config'])->label(false) ?>

    <h5 class="mt-3 text-muted">Events</h5>
    <div class="mb-2">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="nt-preset-failures">Subscribe to all failures</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="nt-preset-all">Subscribe to everything</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="nt-preset-none">Clear all</button>
    </div>
    <?php foreach (NotificationTemplate::eventGroups() as $groupKey => $group) : ?>
        <div class="card card-body bg-transparent border-secondary mb-2">
            <div class="fw-bold text-muted mb-2"><?= Html::encode($group['label']) ?></div>
            <div class="row g-2">
                <?php foreach ($group['events'] as $value => $label) : ?>
                    <div class="col-md-4">
                        <div class="form-check">
                            <input class="form-check-input event-checkbox" type="checkbox"
                                   value="<?= Html::encode($value) ?>"
                                   id="event-<?= Html::encode($value) ?>"
                                   <?= in_array($value, $selectedEvents, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="event-<?= Html::encode($value) ?>">
                                <?= Html::encode($label) ?>
                            </label>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?= $form->field($model, 'events')->hiddenInput(['id' => 'nt-events'])->label(false) ?>

    <h5 class="mt-3 text-muted">Templates</h5>
    <?= $form->field($model, 'subject_template')->textInput([
        'maxlength' => 512,
        'class' => 'form-control font-monospace',
    ])->hint('Variables: {{ event }}, {{ severity }}, {{ job.id }}, {{ job.status }}, {{ job.url }}, {{ template.name }}, {{ project.name }}, {{ launched_by }}, {{ runner.name }}, {{ workflow.id }}, {{ approval.rule_name }}, {{ schedule.name }}, {{ timestamp }}') ?>

    <?= $form->field($model, 'body_template')->textarea([
        'rows' => 6,
        'class' => 'form-control font-monospace',
    ]) ?>

    <div class="mt-4">
        <?= Html::submitButton($model->isNewRecord ? 'Create' : 'Save Changes', ['class' => 'btn btn-primary', 'id' => 'nt-submit']) ?>
        <?= Html::a('Cancel', $model->isNewRecord ? ['index'] : ['view', 'id' => $model->id], ['class' => 'btn btn-outline-secondary ms-2']) ?>
    </div>

<?php ActiveForm::end(); ?>

</div>
</div>

<script>
(function () {
    var failurePreset = <?= Json::htmlEncode($failurePreset) ?>;
    var allEvents = <?= Json::htmlEncode($allEvents) ?>;

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

        var existing = parseExistingConfig();
        if (existing.emails) document.getElementById('email-recipients').value = JSON.stringify(existing.emails);
        if (existing.webhook_url) {
            var ch = channelSelect.value;
            if (ch === 'slack') document.getElementById('slack-webhook-url').value = existing.webhook_url;
            else if (ch === 'teams') document.getElementById('teams-webhook-url').value = existing.webhook_url;
        }
        if (existing.url) document.getElementById('webhook-url').value = existing.url;
        if (existing.headers) document.getElementById('webhook-headers').value = JSON.stringify(existing.headers);
        if (existing.bot_token) document.getElementById('telegram-bot-token').value = existing.bot_token;
        if (existing.chat_id) document.getElementById('telegram-chat-id').value = existing.chat_id;
        if (existing.routing_key) document.getElementById('pagerduty-routing-key').value = existing.routing_key;

        channelSelect.addEventListener('change', showPanel);
        showPanel();

        function applyPreset(list) {
            document.querySelectorAll('.event-checkbox').forEach(function (cb) {
                cb.checked = list.indexOf(cb.value) !== -1;
            });
        }
        document.getElementById('nt-preset-failures').addEventListener('click', function () { applyPreset(failurePreset); });
        document.getElementById('nt-preset-all').addEventListener('click', function () { applyPreset(allEvents); });
        document.getElementById('nt-preset-none').addEventListener('click', function () { applyPreset([]); });

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
            } else if (ch === 'telegram') {
                cfg.bot_token = document.getElementById('telegram-bot-token').value;
                cfg.chat_id = document.getElementById('telegram-chat-id').value;
            } else if (ch === 'pagerduty') {
                cfg.routing_key = document.getElementById('pagerduty-routing-key').value;
            }
            configInput.value = JSON.stringify(cfg);

            var events = [];
            document.querySelectorAll('.event-checkbox:checked').forEach(function (cb) {
                events.push(cb.value);
            });
            eventsInput.value = events.join(',');
        });
    });
})();
</script>
