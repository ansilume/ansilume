<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var string|null $rawToken the token right after it was generated (shown once); null for a token that is no longer shown */
/** @var string $endpoint path of the trigger endpoint, e.g. /trigger/fire */
/** @var string $idPrefix prefix of the element ids: '' on job templates, 'wf-' on workflow templates */
/** @var string $bodyExample a JSON body with optional overrides */
/** @var list<string> $deprecatedUrlForms the forms with the token in the URL that the endpoint still takes, as shown to the user, e.g. ['?token=…'] */

// How to call an inbound trigger, for the trigger cards of job and workflow
// templates: the token in the X-Trigger-Token header of a POST to the
// endpoint. Tokens in URLs still work but are deprecated (they end up in
// access logs and proxies), so no example puts the token into a URL. The
// note at the end names the URL forms of this endpoint only: the path form
// /trigger/<token> reaches job templates, never workflows.
//
// The copy buttons read their text from a data attribute: text inside an
// onclick JavaScript literal breaks as soon as it contains a quote.

use app\components\TriggerTokenResolver;
use yii\helpers\Html;
use yii\helpers\Url;

$appBase = rtrim(\Yii::$app->params['appBaseUrl'] ?? Url::to('/', true), '/');
$url = $appBase . $endpoint;
$curl = static fn (string $token, string $body): string => 'curl -X POST -H "' . TriggerTokenResolver::HEADER . ': ' . $token
    . '" -H "Content-Type: application/json" -d ' . $body . ' ' . $url;
$copyButton = static fn (string $id, string $text, string $class): string => Html::button('Copy', [
    'id' => $id,
    'class' => $class,
    'data-copy-text' => $text,
    'onclick' => "var btn = this; copyToClipboard(btn.getAttribute('data-copy-text')).then(function () {"
        . " btn.textContent = 'Copied!'; btn.classList.replace('btn-outline-secondary', 'btn-success');"
        . " setTimeout(function () { btn.textContent = 'Copy'; btn.classList.replace('btn-success', 'btn-outline-secondary'); }, 2000);"
        . " }).catch(function () { alert('Copy failed — please copy manually.'); })",
]);
$urlFormsHtml = implode(' or ', array_map(static fn (string $form): string => Html::tag('code', Html::encode($form)), $deprecatedUrlForms));
?>
<?php if ($rawToken !== null) : ?>
    <?php $curlExample = $curl($rawToken, '"{}"'); ?>
    <div class="alert alert-warning mb-3">
        <strong>Copy this token now — it will not be shown again.</strong>
        <div class="mt-2">
            <code id="<?= Html::encode($idPrefix) ?>trigger-token-display"><?= Html::encode($rawToken) ?></code>
            <?= $copyButton('btn-copy-' . $idPrefix . 'trigger-token', $rawToken, 'btn btn-sm btn-outline-secondary ms-2') // xss-ok: Html::button() encodes?>
        </div>
        <div class="mt-2 text-muted small">
            Send it in the <code><?= Html::encode(TriggerTokenResolver::HEADER) ?></code> header of a POST to
            <code id="<?= Html::encode($idPrefix) ?>trigger-endpoint"><?= Html::encode($url) ?></code>.
        </div>
    </div>
    <p class="mb-1 small fw-semibold">cURL example</p>
    <div class="input-group">
        <code id="<?= Html::encode($idPrefix) ?>trigger-curl-example" class="form-control bg-body-secondary font-monospace small text-break" style="white-space:pre-wrap;"><?= Html::encode($curlExample) ?></code>
        <?= $copyButton('btn-copy-' . $idPrefix . 'curl', $curlExample, 'btn btn-outline-secondary btn-sm') // xss-ok: Html::button() encodes?>
    </div>
    <p class="mt-2 mb-0 small text-muted">
        Pass optional overrides in the JSON body:
        <code class="bg-body-secondary px-1 rounded"><?= Html::encode($curl($rawToken, "'" . $bodyExample . "'")) ?></code>
    </p>
<?php else : ?>
    <p class="mb-1 text-muted">
        A trigger token is active. Send it in the <code><?= Html::encode(TriggerTokenResolver::HEADER) ?></code> header of a POST to
        <code id="<?= Html::encode($idPrefix) ?>trigger-endpoint"><?= Html::encode($url) ?></code>:
    </p>
    <code id="<?= Html::encode($idPrefix) ?>trigger-curl-masked" class="d-block text-break"><?= Html::encode($curl(str_repeat('*', 16), '"{}"')) ?></code>
    <p class="mt-2 mb-0 small text-muted">
        Optional JSON body: <code><?= Html::encode($bodyExample) ?></code>
    </p>
<?php endif; ?>
<p class="mt-2 mb-0 small text-muted" id="<?= Html::encode($idPrefix) ?>trigger-transport-note">
    <code>Authorization: Bearer &lt;token&gt;</code> works as well. Callers that send the token in the URL
    (<?= $urlFormsHtml // xss-ok: Html::tag() around Html::encode()?>) keep working, but that form is deprecated:
    URLs end up in access logs. Move them to the header.
</p>
