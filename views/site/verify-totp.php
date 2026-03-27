<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\TotpVerifyForm $model */

use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = 'Two-Factor Authentication';
?>
<div class="row justify-content-center">
    <div class="col-md-4 mt-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h4 class="card-title mb-2">Two-Factor Authentication</h4>
                <p class="text-muted small mb-4">
                    Enter the 6-digit code from your authenticator app, or use a recovery code.
                </p>

                <?php $form = ActiveForm::begin(['id' => 'totp-verify-form']); ?>

                    <?= $form->field($model, 'code')->textInput([
                        'autofocus' => true,
                        'autocomplete' => 'one-time-code',
                        'maxlength' => 12,
                        'class' => 'form-control font-monospace',
                        'placeholder' => '000000',
                        'style' => 'font-size:1.25rem; letter-spacing:0.15em; text-align:center;',
                    ])->label('Authentication Code') ?>

                    <div class="d-grid mt-3">
                        <?= Html::submitButton('Verify', ['class' => 'btn btn-primary']) ?>
                    </div>

                    <div class="text-center mt-3">
                        <details class="text-start">
                            <summary class="text-muted small" style="cursor:pointer;">Use a recovery code instead</summary>
                            <p class="text-muted small mt-2 mb-0">
                                Enter one of your recovery codes (format: XXXX-XXXX) in the field above.
                            </p>
                        </details>
                    </div>

                    <div class="text-center mt-3">
                        <?= Html::a('Back to login', ['site/login'], ['class' => 'text-muted small']) ?>
                    </div>

                <?php ActiveForm::end(); ?>
            </div>
        </div>
    </div>
</div>
