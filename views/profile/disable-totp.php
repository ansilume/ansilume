<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\TotpDisableForm $model */

use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = 'Disable Two-Factor Authentication';
?>
<div class="row justify-content-center">
<div class="col-lg-5">
    <h2>Disable Two-Factor Authentication</h2>

    <div class="alert alert-warning" role="alert">
        Disabling 2FA will make your account less secure.
        You will only need your password to log in.
    </div>

    <div class="card">
        <div class="card-body">
            <p class="text-muted">
                Enter a code from your authenticator app or one of your recovery codes to confirm.
            </p>

            <?php $form = ActiveForm::begin(['id' => 'totp-disable-form']); ?>

                <?= $form->field($model, 'code')->textInput([
                    'autofocus' => true,
                    'autocomplete' => 'one-time-code',
                    'maxlength' => 12,
                    'class' => 'form-control font-monospace',
                    'placeholder' => '000000 or XXXX-XXXX',
                    'style' => 'max-width:250px; font-size:1.1rem;',
                ]) ?>

                <div class="mt-3">
                    <?= Html::submitButton('Disable 2FA', ['class' => 'btn btn-danger']) ?>
                    <?= Html::a('Cancel', ['security'], ['class' => 'btn btn-outline-secondary ms-2']) ?>
                </div>

            <?php ActiveForm::end(); ?>
        </div>
    </div>
</div>
</div>
