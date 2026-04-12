<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var string[] $recoveryCodes */

use yii\helpers\Html;

$this->title = 'Recovery Codes';
?>
<div class="row justify-content-center">
<div class="col-lg-6">
    <h2>Two-Factor Authentication Enabled</h2>

    <div class="alert alert-warning" role="alert">
        <strong>Save your recovery codes now.</strong>
        These codes can be used to access your account if you lose your authenticator device.
        Each code can only be used once. Store them in a safe place.
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title mb-3">Recovery Codes</h5>
            <div class="row">
                <?php foreach ($recoveryCodes as $i => $code) : ?>
                    <?php if ($i > 0 && $i % 5 === 0) : ?>
                        </div><div class="row">
                    <?php endif; ?>
                    <div class="col-6 mb-2">
                        <code class="fs-6"><?= Html::encode($code) ?></code>
                    </div>
                <?php endforeach; ?>
            </div>

            <hr class="my-3">

            <div class="d-flex gap-2">
                <button class="btn btn-outline-secondary btn-sm" type="button" id="copy-codes-btn"
                    onclick="copyToClipboard(<?= Html::encode(json_encode(implode("\n", $recoveryCodes))) ?>).then(function(){ document.getElementById('copy-codes-btn').textContent='Copied!'; }).catch(function(){ alert('Copy failed — please copy manually.'); })">
                    Copy all codes
                </button>
                <button class="btn btn-outline-secondary btn-sm" type="button"
                    onclick="var w=window.open('','','width=400,height=500');w.document.write('<pre style=\'font-size:14px;padding:20px\'><?= Html::encode(implode("\\n", $recoveryCodes)) ?></pre>');w.print();">
                    Print
                </button>
            </div>
        </div>
    </div>

    <div class="alert alert-danger" role="alert">
        <strong>You will not be able to see these codes again.</strong>
        If you lose them and your authenticator device, you will be locked out of your account.
    </div>

    <?= Html::a('I have saved my recovery codes', ['security'], ['class' => 'btn btn-primary']) ?>
</div>
</div>
