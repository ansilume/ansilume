<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\PasswordResetRequestForm $model */

use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = 'Forgot Password';
?>
<div class="row justify-content-center">
    <div class="col-md-4 mt-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h4 class="card-title mb-2">Reset Password</h4>
                <p class="text-muted small mb-4">Enter your email address and we'll send you a link to reset your password.</p>

                <?php $form = ActiveForm::begin(['id' => 'forgot-password-form']); ?>

                    <?= $form->field($model, 'email')->textInput([
                        'autofocus' => true,
                        'autocomplete' => 'email',
                        'type' => 'email',
                        'class' => 'form-control',
                        'placeholder' => 'you@example.com',
                    ]) ?>

                    <div class="d-grid mt-3">
                        <?= Html::submitButton('Send Reset Link', ['class' => 'btn btn-primary']) ?>
                    </div>

                    <div class="text-center mt-3">
                        <?= Html::a('Back to login', ['site/login'], ['class' => 'text-muted small']) ?>
                    </div>

                <?php ActiveForm::end(); ?>
            </div>
        </div>
    </div>
</div>
