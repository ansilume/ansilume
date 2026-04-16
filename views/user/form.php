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

$ldapEnabled = !empty(\Yii::$app->params['ldap']['enabled']);
$isLdap = !$isNew && $user->isLdap();
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

    <?php if ($isNew && $ldapEnabled) : ?>
        <?= $f->field($form, 'auth_source')->dropDownList(UserForm::authSourceOptions())
            ->hint('Choose where this account authenticates. This cannot be changed later.') ?>
    <?php elseif (!$isNew) : ?>
        <div class="mb-3">
            <label class="form-label">Authentication source</label>
            <div>
                <?php if ($isLdap) : ?>
                    <span class="badge text-bg-info">LDAP / Active Directory</span>
                <?php else : ?>
                    <span class="badge text-bg-secondary">Local</span>
                <?php endif; ?>
            </div>
            <div class="form-text">Authentication source is fixed once the account exists.</div>
        </div>
    <?php endif; ?>

    <?php if (!$isLdap) : ?>
        <?= $f->field($form, 'password')->passwordInput(['autocomplete' => 'new-password'])
            ->hint($isNew ? '' : 'Leave blank to keep the current password.') ?>
    <?php else : ?>
        <div class="alert alert-info" role="alert">
            This account is managed by the directory. Password, display name, and email
            are sourced from LDAP and cannot be edited here.
        </div>
        <?php if ($form->ldap_dn !== '' || $form->ldap_uid !== '') : ?>
            <dl class="row">
                <?php if ($form->ldap_dn !== '') : ?>
                    <dt class="col-4">DN</dt>
                    <dd class="col-8"><code><?= Html::encode($form->ldap_dn) ?></code></dd>
                <?php endif; ?>
                <?php if ($form->ldap_uid !== '') : ?>
                    <dt class="col-4">Directory UID</dt>
                    <dd class="col-8"><code><?= Html::encode($form->ldap_uid) ?></code></dd>
                <?php endif; ?>
            </dl>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($isNew && $ldapEnabled) : ?>
        <?= $f->field($form, 'ldap_dn')->textInput(['maxlength' => 512, 'autocomplete' => 'off'])
            ->hint('Optional. Pre-fill the DN if you know it; otherwise the directory bind sets it on first login.') ?>
        <?= $f->field($form, 'ldap_uid')->textInput(['maxlength' => 255, 'autocomplete' => 'off'])
            ->hint('Optional. Stable directory identifier (objectGUID/entryUUID). Filled automatically by the directory sync.') ?>
    <?php endif; ?>

    <?= $f->field($form, 'role')->dropDownList(UserForm::roleOptions()) ?>

    <?= $f->field($form, 'status')->dropDownList([
        User::STATUS_ACTIVE => 'Active',
        User::STATUS_INACTIVE => 'Inactive',
    ]) ?>

    <?php $identityCheck = \Yii::$app->user?->identity; ?>
    <?php if ($identityCheck instanceof \app\models\User && $identityCheck->is_superadmin) : ?>
        <?= $f->field($form, 'is_superadmin')->checkbox()->hint('Superadmins bypass all RBAC checks.') ?>
    <?php endif; ?>

    <div class="mt-3">
        <?= Html::submitButton($isNew ? 'Create User' : 'Save Changes', ['class' => 'btn btn-primary']) ?>
        <?= Html::a('Cancel', $isNew ? ['index'] : ['view', 'id' => $user->id], ['class' => 'btn btn-outline-secondary ms-2']) ?>
    </div>

<?php ActiveForm::end(); ?>
</div>
</div>
