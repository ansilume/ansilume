<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\NotificationTemplate $model */
/** @var string $config The config the form shows: as submitted, else as stored with the placeholder for every secret */
/** @var app\services\notification\NotificationTemplateFormView $choices What the form offers its editor: the workflow filter, the channels without a panel */

use app\components\NotificationConfigSecrets;
use app\components\SurveySecrets;
use app\models\NotificationTemplate;
use app\services\notification\NotificationTemplateFormPost;
use app\services\notification\NotificationTemplateFormView;
use app\services\notification\NotificationTemplatePage;
use app\services\notification\NotificationTemplateSave;
use app\services\notification\WebhookConfig;
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
$filterError = $model->getFirstError(NotificationTemplateSave::FILTER_ATTRIBUTE);

/** Every error of an attribute, for the config, which the fields of a panel all write into: Html::error()'s errorSource. */
$allErrors = static fn (NotificationTemplate $template, string $attribute): string => implode(' ', array_filter($template->getErrors($attribute), 'is_string'));

/** A radio of a radio list, styled as a form check: Html::activeRadioList()'s item callback. */
$radio = static function (string $prefix): \Closure {
    return static function (int $index, string $label, string $name, bool $checked, string|int $value) use ($prefix): string {
        $id = $prefix . '-' . $value;

        return Html::tag(
            'div',
            Html::radio($name, $checked, ['value' => (string)$value, 'id' => $id, 'class' => 'form-check-input'])
                . Html::label(Html::encode($label), $id, ['class' => 'form-check-label']),
            ['class' => 'form-check']
        );
    };
};
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

<?php if (!$model->isNewRecord) : ?>
    <?php $deliversAs = $model->deliversAsUser?->username ?? sprintf(NotificationTemplatePage::UNKNOWN_USER, (int)$model->delivers_as); ?>
    <?php if ($model->isDeliveringAsInactive()) : ?>
        <div class="alert alert-warning" data-testid="notification-template-inactive-identity">
            <?= Html::encode(sprintf(NotificationTemplatePage::DELIVERS_AS_INACTIVE, $deliversAs)) ?>
        </div>
    <?php else : ?>
        <div class="form-text mb-3" data-testid="notification-template-delivers-as-hint">
            <?= Html::encode(sprintf(NotificationTemplateFormView::DELIVERS_AS_HINT, $deliversAs)) ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($model->hasErrors(NotificationTemplateFormPost::FIELDS_ERROR)) : ?>
    <div class="alert alert-danger" data-testid="notification-template-refusal"><?= Html::encode($model->getFirstError(NotificationTemplateFormPost::FIELDS_ERROR)) ?></div>
<?php endif; ?>

<?php $form = ActiveForm::begin(['id' => 'notification-template-form']); ?>

    <?= $form->field($model, 'name')->textInput(['maxlength' => 128, 'autofocus' => true]) ?>
    <?= $form->field($model, 'description')->textarea(['rows' => 2]) ?>

    <?= $form->field($model, 'channel')->dropDownList(
        NotificationTemplate::channelLabels(),
        ['id' => 'nt-channel']
    ) ?>

    <?php if (!$model->isNewRecord) : ?>
        <div class="form-text mb-3" data-testid="notification-template-secret-hint">
            <?= sprintf(Html::encode(NotificationConfigSecrets::FORM_HINT), Html::tag('code', Html::encode(SurveySecrets::PLACEHOLDER))) ?>
        </div>
    <?php endif; ?>

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
            <label class="form-label" for="webhook-url">Webhook URL <span class="text-danger">*</span></label>
            <input type="text" id="webhook-url" class="form-control font-monospace channel-required"
                   data-channel="webhook"
                   placeholder="https://example.com/hook">
            <label class="form-label mt-2" for="webhook-headers">Custom Headers (JSON)</label>
            <input type="text" id="webhook-headers" class="form-control font-monospace"
                   placeholder='{"X-Custom": "value"}'>
            <div class="help-block" id="webhook-headers-error" style="display:none"></div>
            <label class="form-label mt-2" for="webhook-signing-secret">Signing Secret</label>
            <input type="text" id="webhook-signing-secret" class="form-control font-monospace"
                   autocomplete="off" spellcheck="false"
                   placeholder="the output of openssl rand -hex 32">
            <div class="form-text"><?= Html::encode(NotificationTemplateFormView::SIGNING_SECRET_HINT) ?></div>
            <label class="form-label mt-2" for="webhook-payload">Payload</label>
            <select id="webhook-payload" class="form-select">
                <?php foreach (NotificationTemplateFormView::PAYLOAD_LABELS as $payload => $label) : ?>
                    <option value="<?= Html::encode($payload) ?>"><?= Html::encode($label) ?></option>
                <?php endforeach; ?>
            </select>
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
            <div class="form-text" data-testid="nt-pagerduty-note"><?= Html::encode(NotificationTemplateFormView::pagerDutyNote()) ?></div>
        </div>
    </div>

    <div id="config-mattermost" class="channel-config" style="display:none">
        <div class="card card-body bg-transparent border-secondary mb-3">
            <label class="form-label" for="mattermost-server-url">Server URL <span class="text-danger">*</span></label>
            <input type="text" id="mattermost-server-url" class="form-control font-monospace channel-required"
                   data-channel="mattermost"
                   placeholder="https://chat.example.com">
            <label class="form-label mt-2" for="mattermost-bot-token">Bot Token <span class="text-danger">*</span></label>
            <input type="text" id="mattermost-bot-token" class="form-control font-monospace channel-required"
                   data-channel="mattermost" autocomplete="off" spellcheck="false">
            <div class="form-text"><?= Html::encode(NotificationTemplateFormView::MATTERMOST_HINT) ?></div>
            <label class="form-label mt-2" for="mattermost-channel-by">Channel <span class="text-danger">*</span></label>
            <select id="mattermost-channel-by" class="form-select">
                <option value="name">By the names of its team and its channel</option>
                <option value="id">By its channel ID</option>
            </select>
            <div id="mattermost-channel-names" class="row g-2 mt-1">
                <div class="col-md-6">
                    <label class="form-label" for="mattermost-team">Team</label>
                    <input type="text" id="mattermost-team" class="form-control font-monospace" placeholder="ops">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="mattermost-channel">Channel</label>
                    <input type="text" id="mattermost-channel" class="form-control font-monospace" placeholder="town-square">
                </div>
                <div class="form-text">The names in the address of the channel, such as https://chat.example.com/ops/channels/town-square.</div>
            </div>
            <div id="mattermost-channel-id-box" class="mt-1" style="display:none">
                <label class="form-label" for="mattermost-channel-id">Channel ID</label>
                <input type="text" id="mattermost-channel-id" class="form-control font-monospace"
                       placeholder="26 lower-case letters and digits">
                <div class="form-text">An ID survives a rename of the channel; its names do not.</div>
            </div>
            <label class="form-label mt-2" for="mattermost-mention">Mention</label>
            <input type="text" id="mattermost-mention" class="form-control font-monospace" placeholder="@ops-team">
            <div class="form-text"><?= Html::encode(NotificationTemplateFormView::MENTION_HINT) ?></div>
        </div>
    </div>

    <?php foreach ($choices->rawChannels as $channelName => $definition) : ?>
        <div id="config-<?= Html::encode($channelName) ?>" class="channel-config" style="display:none" data-raw-config="1">
            <div class="card card-body bg-transparent border-secondary mb-3">
                <label class="form-label" for="raw-config-<?= Html::encode($channelName) ?>"><?= Html::encode($definition->label) ?> Config (JSON) <span class="text-danger">*</span></label>
                <textarea id="raw-config-<?= Html::encode($channelName) ?>" class="form-control font-monospace channel-raw-config"
                          rows="6" spellcheck="false"></textarea>
                <div class="form-text"><?= Html::encode(NotificationTemplateFormView::rawConfigHint($definition)) ?></div>
            </div>
        </div>
    <?php endforeach; ?>

    <?= $form->field($model, 'config', ['errorOptions' => ['class' => 'help-block', 'errorSource' => $allErrors]])
        ->hiddenInput(['id' => 'nt-config', 'value' => $config])
        ->label(false) ?>

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

    <h5 class="mt-3 text-muted">Workflow runs</h5>
    <div class="card card-body bg-transparent border-secondary mb-3">
        <?= $form->field($model, 'workflow_scope')
            ->radioList(NotificationTemplate::WORKFLOW_SCOPE_LABELS, ['id' => 'nt-workflow-scope', 'item' => $radio('nt-workflow-scope')])
            ->label('Workflows')
            ->hint(Html::encode(NotificationTemplateFormView::SCOPE_HINT)) ?>
        <div id="nt-workflow-templates-box" class="mb-3">
            <label class="form-label" for="nt-workflow-templates">Workflow templates</label>
            <?= Html::listBox(
                NotificationTemplateFormPost::FILTER_FIELD,
                $choices->selectedWorkflowTemplateIds,
                $choices->workflowTemplates,
                [
                    'multiple' => true,
                    'unselect' => '',
                    'id' => 'nt-workflow-templates',
                    'class' => 'form-select',
                    'size' => min(10, max(4, count($choices->workflowTemplates))),
                ]
            ) ?>
            <?php if ($choices->workflowTemplates === []) : ?>
                <div class="form-text"><?= Html::encode(NotificationTemplateFormView::NO_WORKFLOWS) ?></div>
            <?php else : ?>
                <div class="form-text"><?= Html::encode(NotificationTemplateFormView::MULTI_SELECT_HINT) ?></div>
            <?php endif; ?>
            <?php if ($choices->hiddenWorkflowTemplates > 0) : ?>
                <div class="form-text" data-testid="nt-workflow-templates-hidden">
                    <?= Html::encode(sprintf(NotificationTemplateFormView::HIDDEN_WORKFLOWS, $choices->hiddenWorkflowTemplates)) ?>
                </div>
            <?php endif; ?>
            <?php if ($filterError !== null) : ?>
                <div class="help-block" id="nt-workflow-templates-error"><?= Html::encode($filterError) ?></div>
            <?php endif; ?>
        </div>
        <?= $form->field($model, 'level')
            ->radioList(NotificationTemplate::LEVEL_LABELS, ['id' => 'nt-level', 'item' => $radio('nt-level')])
            ->label('Level')
            ->hint(Html::encode(NotificationTemplateFormView::LEVEL_HINT)) ?>
    </div>

    <h5 class="mt-3 text-muted">Templates</h5>
    <?= $form->field($model, 'subject_template')->textInput([
        'maxlength' => 512,
        'class' => 'form-control font-monospace',
    ])->hint(Html::encode(NotificationTemplateFormView::VARIABLES_HINT)) ?>

    <?= $form->field($model, 'body_template')->textarea([
        'rows' => 6,
        'class' => 'form-control font-monospace',
        'data-template-editor' => '1',
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
    // The keys each channel's panel shows and writes (NotificationTemplateFormView::PANEL_KEYS):
    // every other key of the config stays as it is while the channel stays.
    var panelKeys = <?= Json::htmlEncode(NotificationTemplateFormView::PANEL_KEYS) ?>;
    // The channel the hidden config belongs to: as stored, or as a failed save posted it.
    var configChannel = <?= Json::htmlEncode((string)$model->channel) ?>;
    var headersForm = <?= Json::htmlEncode(NotificationTemplateFormView::HEADERS_FORM) ?>;
    var defaultPayload = <?= Json::htmlEncode(WebhookConfig::PAYLOAD_TEMPLATE) ?>;
    var eventPayload = <?= Json::htmlEncode(WebhookConfig::PAYLOAD_EVENT) ?>;
    var selectedScope = <?= Json::htmlEncode(NotificationTemplate::WORKFLOW_SCOPE_SELECTED) ?>;

    document.addEventListener('DOMContentLoaded', function () {
        var channelSelect = document.getElementById('nt-channel');
        var configInput = document.getElementById('nt-config');
        var eventsInput = document.getElementById('nt-events');
        var panels = document.querySelectorAll('.channel-config');

        function field(id) { return document.getElementById(id); }
        function value(id) { return field(id).value; }
        function trimmed(id) { return value(id).trim(); }
        function show(id, shown) { field(id).style.display = shown ? '' : 'none'; }
        function fillText(id, v) {
            if (v !== undefined && v !== null) field(id).value = typeof v === 'string' ? v : JSON.stringify(v);
        }
        function isObject(v) { return v !== null && typeof v === 'object' && !Array.isArray(v); }
        function panelOf(ch) { return field('config-' + ch); }
        function isRaw(panel) { return panel !== null && panel.hasAttribute('data-raw-config'); }

        function showPanel() {
            panels.forEach(function (p) { p.style.display = 'none'; });
            var target = panelOf(channelSelect.value);
            if (target) target.style.display = '';
        }

        function parseExistingConfig() {
            try {
                var parsed = JSON.parse(configInput.value || '{}');
                return isObject(parsed) ? parsed : {};
            } catch (e) { return {}; }
        }

        var existing = parseExistingConfig();

        // The panel of the config's channel shows its keys.
        var fill = {
            email: function (c) { if (c.emails) field('email-recipients').value = JSON.stringify(c.emails); },
            slack: function (c) { fillText('slack-webhook-url', c.webhook_url); },
            teams: function (c) { fillText('teams-webhook-url', c.webhook_url); },
            webhook: function (c) {
                fillText('webhook-url', c.url);
                if (c.headers) field('webhook-headers').value = JSON.stringify(c.headers);
                fillText('webhook-signing-secret', c.signing_secret);
                field('webhook-payload').value = c.payload === eventPayload ? eventPayload : defaultPayload;
            },
            telegram: function (c) {
                fillText('telegram-bot-token', c.bot_token);
                fillText('telegram-chat-id', c.chat_id);
            },
            pagerduty: function (c) { fillText('pagerduty-routing-key', c.routing_key); },
            mattermost: function (c) {
                fillText('mattermost-server-url', c.server_url);
                fillText('mattermost-bot-token', c.bot_token);
                fillText('mattermost-team', c.team);
                fillText('mattermost-channel', c.channel);
                fillText('mattermost-channel-id', c.channel_id);
                fillText('mattermost-mention', c.mention);
                field('mattermost-channel-by').value = c.channel_id ? 'id' : 'name';
            }
        };
        if (fill[configChannel]) {
            fill[configChannel](existing);
        } else if (isRaw(panelOf(configChannel))) {
            // A channel without a panel: its whole config, as JSON.
            var text = configInput.value;
            try { text = JSON.stringify(JSON.parse(text), null, 2); } catch (e) { /* shown as it was posted */ }
            panelOf(configChannel).querySelector('textarea').value = text;
        }

        function showMattermostChannel() {
            var byId = value('mattermost-channel-by') === 'id';
            show('mattermost-channel-names', !byId);
            show('mattermost-channel-id-box', byId);
        }
        field('mattermost-channel-by').addEventListener('change', showMattermostChannel);
        showMattermostChannel();

        var scopes = document.querySelectorAll('input[name="NotificationTemplate[workflow_scope]"]');
        function showWorkflowTemplates() {
            var checked = document.querySelector('input[name="NotificationTemplate[workflow_scope]"]:checked');
            show('nt-workflow-templates-box', (checked !== null && checked.value === selectedScope) || field('nt-workflow-templates-error') !== null);
        }
        scopes.forEach(function (radio) { radio.addEventListener('change', showWorkflowTemplates); });
        showWorkflowTemplates();

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

        // The keys of the shown config that the panel of ch does not show: kept while the channel stays.
        function keptKeys(ch) {
            var kept = {};
            if (ch !== configChannel) return kept;
            var shown = panelKeys[ch] || [];
            Object.keys(existing).forEach(function (key) {
                if (shown.indexOf(key) === -1) kept[key] = existing[key];
            });
            return kept;
        }

        // The headers of the webhook panel: an object of names, {} for none, null for anything else.
        function webhookHeaders() {
            var text = trimmed('webhook-headers');
            if (text === '') return {};
            try {
                var parsed = JSON.parse(text);
                if (Array.isArray(parsed) && parsed.length === 0) return {};
                return isObject(parsed) ? parsed : null;
            } catch (e) { return null; }
        }

        // Each panel writes its keys into cfg; false when the panel cannot be sent as it is.
        var build = {
            email: function (cfg) {
                try { cfg.emails = JSON.parse(value('email-recipients') || '[]'); } catch (e) { cfg.emails = []; }
                return true;
            },
            slack: function (cfg) { cfg.webhook_url = value('slack-webhook-url'); return true; },
            teams: function (cfg) { cfg.webhook_url = value('teams-webhook-url'); return true; },
            webhook: function (cfg) {
                var headers = webhookHeaders();
                var error = field('webhook-headers-error');
                error.textContent = headers === null ? headersForm : '';
                error.style.display = headers === null ? '' : 'none';
                if (headers === null) return false;
                cfg.url = value('webhook-url');
                if (Object.keys(headers).length > 0) cfg.headers = headers;
                var secret = value('webhook-signing-secret');
                if (secret !== '') cfg.signing_secret = secret;
                var payload = value('webhook-payload');
                if (payload !== defaultPayload || (configChannel === 'webhook' && existing.payload !== undefined)) cfg.payload = payload;
                return true;
            },
            telegram: function (cfg) {
                cfg.bot_token = value('telegram-bot-token');
                cfg.chat_id = value('telegram-chat-id');
                return true;
            },
            pagerduty: function (cfg) { cfg.routing_key = value('pagerduty-routing-key'); return true; },
            mattermost: function (cfg) {
                cfg.server_url = trimmed('mattermost-server-url');
                cfg.bot_token = trimmed('mattermost-bot-token');
                if (value('mattermost-channel-by') === 'id') {
                    cfg.channel_id = trimmed('mattermost-channel-id');
                } else {
                    cfg.team = trimmed('mattermost-team');
                    cfg.channel = trimmed('mattermost-channel');
                }
                var mention = trimmed('mattermost-mention');
                if (mention !== '') cfg.mention = mention;
                return true;
            }
        };

        // Registered before the ActiveForm's own handler, which validates and posts the form.
        document.getElementById('nt-submit').closest('form').addEventListener('submit', function (event) {
            var ch = channelSelect.value;
            var panel = panelOf(ch);
            if (isRaw(panel)) {
                configInput.value = panel.querySelector('textarea').value;
            } else if (build[ch]) {
                var cfg = keptKeys(ch);
                if (!build[ch](cfg)) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    return;
                }
                configInput.value = JSON.stringify(cfg);
            }

            var events = [];
            document.querySelectorAll('.event-checkbox:checked').forEach(function (cb) {
                events.push(cb.value);
            });
            eventsInput.value = events.join(',');
        });
    });
})();
</script>

<link rel="stylesheet" href="/css/vendor/codemirror/codemirror.css">
<link rel="stylesheet" href="/css/extra-vars-editor.css">
<script src="/js/vendor/codemirror/codemirror.js"></script>
<script src="/js/vendor/codemirror/addon/edit/matchbrackets.js"></script>
<script src="/js/vendor/codemirror/addon/selection/active-line.js"></script>
<script src="/js/template-editor.js"></script>
