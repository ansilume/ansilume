<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\TotpSetupForm $model */
/** @var string $secret */
/** @var string $qrDataUri */

use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = 'Set Up Two-Factor Authentication';
?>
<div class="row justify-content-center">
<div class="col-lg-6">
    <h2>Set Up Two-Factor Authentication</h2>

    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title">Step 1: Scan the QR Code</h5>
            <p class="text-muted">
                Open your authenticator app (Google Authenticator, Authy, 1Password, etc.)
                and scan the QR code below.
            </p>

            <div class="text-center my-4">
                <div style="background:#fff; display:inline-block; padding:16px; border-radius:8px;">
                    <img src="<?= Html::encode($qrDataUri) ?>" alt="TOTP QR Code" style="width:220px; height:220px;" />
                </div>
            </div>

            <p class="text-muted small mb-2">Can't scan? Enter this key manually:</p>
            <div class="input-group mb-4">
                <input type="text" class="form-control font-monospace" value="<?= Html::encode($secret) ?>" readonly id="totp-secret" />
                <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('totp-secret').value)">Copy</button>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h5 class="card-title">Step 2: Verify</h5>
            <p class="text-muted">
                Enter the 6-digit code from your authenticator app to confirm setup.
            </p>

            <?php $form = ActiveForm::begin([
                'id' => 'totp-setup-form',
                'action' => ['enable-totp'],
            ]); ?>

                <?= $form->field($model, 'code')->textInput([
                    'autofocus' => true,
                    'autocomplete' => 'one-time-code',
                    'inputmode' => 'numeric',
                    'pattern' => '[0-9]{6}',
                    'maxlength' => 6,
                    'class' => 'form-control font-monospace',
                    'placeholder' => '000000',
                    'style' => 'max-width:200px; font-size:1.25rem; letter-spacing:0.2em;',
                ]) ?>

                <div class="mt-3">
                    <?= Html::submitButton('Verify & Enable', ['class' => 'btn btn-primary']) ?>
                    <?= Html::a('Cancel', ['security'], ['class' => 'btn btn-outline-secondary ms-2']) ?>
                </div>

            <?php ActiveForm::end(); ?>
        </div>
    </div>
</div>
</div>
