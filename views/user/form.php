<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\UserForm $form */
/** @var app\models\User|null $user */

use app\models\User;
use app\models\UserForm;
use yii\helpers\Html;
use yii\widgets\ActiveForm;

$isNew = ($user === null);
$this->title = $isNew ? 'New User' : 'Edit: ' . $user->username;
?>
<div class="row justify-content-center">
<div class="col-lg-6">
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><?= Html::a('Users', ['index']) ?></li>
        <?php if (!$isNew) : ?>
            <li class="breadcrumb-item"><?= Html::a(Html::encode($user->username), ['view', 'id' => $user->id]) ?></li>
        <?php endif; ?>
        <li class="breadcrumb-item active"><?= $isNew ? 'New' : 'Edit' ?></li>
    </ol>
</nav>
<h2><?= Html::encode($this->title) ?></h2>

<?php $f = ActiveForm::begin(['id' => 'user-form']); ?>

    <?= $f->field($form, 'username')->textInput(['maxlength' => 64, 'autofocus' => true, 'autocomplete' => 'off']) ?>
    <?= $f->field($form, 'email')->input('email') ?>

    <?= $f->field($form, 'password')->passwordInput(['autocomplete' => 'new-password'])
        ->hint($isNew ? '' : 'Leave blank to keep the current password.') ?>

    <?= $f->field($form, 'role')->dropDownList(UserForm::roleOptions()) ?>

    <?= $f->field($form, 'status')->dropDownList([
        User::STATUS_ACTIVE => 'Active',
        User::STATUS_INACTIVE => 'Inactive',
    ]) ?>

    <?php if (\Yii::$app->user?->identity?->is_superadmin) : ?>
        <?= $f->field($form, 'is_superadmin')->checkbox()->hint('Superadmins bypass all RBAC checks.') ?>
    <?php endif; ?>

    <div class="mt-3">
        <?= Html::submitButton($isNew ? 'Create User' : 'Save Changes', ['class' => 'btn btn-primary']) ?>
        <?= Html::a('Cancel', $isNew ? ['index'] : ['view', 'id' => $user->id], ['class' => 'btn btn-outline-secondary ms-2']) ?>
    </div>

<?php ActiveForm::end(); ?>
</div>
</div>
