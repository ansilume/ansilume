<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Credential $model */
/** @var array $secrets  Always empty — never pre-populate secret fields */

use app\models\Credential;
use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = $model->isNewRecord ? 'New Credential' : 'Edit: ' . $model->name;
$isEdit = !$model->isNewRecord;
?>
<div class="row justify-content-center">
<div class="col-lg-7">
<h2><?= Html::encode($this->title) ?></h2>

<?php $form = ActiveForm::begin(['id' => 'credential-form']); ?>

    <?= $form->field($model, 'name')->textInput(['maxlength' => 128, 'autofocus' => true]) ?>
    <?= $form->field($model, 'description')->textarea(['rows' => 2]) ?>

    <?= $form->field($model, 'credential_type')->dropDownList([
        Credential::TYPE_SSH_KEY           => 'SSH Key',
        Credential::TYPE_USERNAME_PASSWORD => 'Username / Password',
        Credential::TYPE_VAULT             => 'Vault Secret',
        Credential::TYPE_TOKEN             => 'Token',
    ], ['id' => 'credential-type']) ?>

    <?= $form->field($model, 'username')->textInput(['maxlength' => 128, 'autocomplete' => 'off']) ?>

    <!-- Secret fields — rendered per type, posted as secrets[field] -->

    <div id="secret-ssh" class="secret-block" style="display:none">
        <div class="mb-3">
            <label class="form-label">Private Key <?= $isEdit ? '<span class="text-muted small">(leave blank to keep existing)</span>' : '' ?></label>
            <textarea name="secrets[private_key]" class="form-control font-monospace" rows="10"
                      placeholder="-----BEGIN OPENSSH PRIVATE KEY-----" autocomplete="off"></textarea>
        </div>
    </div>

    <div id="secret-password" class="secret-block" style="display:none">
        <div class="mb-3">
            <label class="form-label">Password <?= $isEdit ? '<span class="text-muted small">(leave blank to keep existing)</span>' : '' ?></label>
            <input type="password" name="secrets[password]" class="form-control" autocomplete="new-password">
        </div>
    </div>

    <div id="secret-vault" class="secret-block" style="display:none">
        <div class="mb-3">
            <label class="form-label">Vault Password <?= $isEdit ? '<span class="text-muted small">(leave blank to keep existing)</span>' : '' ?></label>
            <input type="password" name="secrets[vault_password]" class="form-control" autocomplete="new-password">
        </div>
    </div>

    <div id="secret-token" class="secret-block" style="display:none">
        <div class="mb-3">
            <label class="form-label">Token <?= $isEdit ? '<span class="text-muted small">(leave blank to keep existing)</span>' : '' ?></label>
            <input type="password" name="secrets[token]" class="form-control" autocomplete="new-password">
        </div>
    </div>

    <div class="mt-3">
        <?= Html::submitButton($model->isNewRecord ? 'Create Credential' : 'Save Changes', ['class' => 'btn btn-primary']) ?>
        <?= Html::a('Cancel', $model->isNewRecord ? ['index'] : ['view', 'id' => $model->id], ['class' => 'btn btn-outline-secondary ms-2']) ?>
    </div>

<?php ActiveForm::end(); ?>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var map = {
        '<?= Credential::TYPE_SSH_KEY ?>':           'secret-ssh',
        '<?= Credential::TYPE_USERNAME_PASSWORD ?>': 'secret-password',
        '<?= Credential::TYPE_VAULT ?>':             'secret-vault',
        '<?= Credential::TYPE_TOKEN ?>':             'secret-token',
    };
    var typeSelect = document.getElementById('credential-type');
    if (!typeSelect) return;
    function update() {
        var active = map[typeSelect.value];
        document.querySelectorAll('.secret-block').forEach(function (el) {
            el.style.display = el.id === active ? '' : 'none';
        });
    }
    typeSelect.addEventListener('change', update);
    update();
});
</script>
