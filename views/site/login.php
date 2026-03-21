<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\LoginForm $model */

use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = 'Login';
?>
<div class="row justify-content-center">
    <div class="col-md-4 mt-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h4 class="card-title mb-4">Sign in to Ansilume</h4>

                <?php $form = ActiveForm::begin(['id' => 'login-form']); ?>

                    <?= $form->field($model, 'username')->textInput([
                        'autofocus' => true,
                        'autocomplete' => 'username',
                        'class' => 'form-control',
                    ]) ?>

                    <?= $form->field($model, 'password')->passwordInput([
                        'autocomplete' => 'current-password',
                        'class' => 'form-control',
                    ]) ?>

                    <?= $form->field($model, 'rememberMe')->checkbox() ?>

                    <div class="d-grid mt-3">
                        <?= Html::submitButton('Sign in', ['class' => 'btn btn-primary', 'name' => 'login-button']) ?>
                    </div>

                <?php ActiveForm::end(); ?>
            </div>
        </div>
    </div>
</div>
