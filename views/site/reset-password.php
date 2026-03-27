<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\PasswordResetForm $model */

use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = 'Set New Password';
?>
<div class="row justify-content-center">
    <div class="col-md-4 mt-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h4 class="card-title mb-2">Set New Password</h4>
                <p class="text-muted small mb-4">Choose a new password for your account.</p>

                <?php $form = ActiveForm::begin(['id' => 'reset-password-form']); ?>

                    <?= $form->field($model, 'password')->passwordInput([
                        'autofocus' => true,
                        'autocomplete' => 'new-password',
                        'class' => 'form-control',
                    ]) ?>

                    <?= $form->field($model, 'password_confirm')->passwordInput([
                        'autocomplete' => 'new-password',
                        'class' => 'form-control',
                    ]) ?>

                    <div class="d-grid mt-3">
                        <?= Html::submitButton('Reset Password', ['class' => 'btn btn-primary']) ?>
                    </div>

                <?php ActiveForm::end(); ?>
            </div>
        </div>
    </div>
</div>
