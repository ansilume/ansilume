<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\RoleForm $form */

use app\helpers\PermissionCatalog;
use yii\helpers\Html;
use yii\widgets\ActiveForm;

$selected = array_flip($form->permissions);
?>

<?php $af = ActiveForm::begin(['id' => 'role-form']); ?>

    <?php if ($form->isSystemRole) : ?>
        <div class="alert alert-info">
            This is a built-in system role. Its name cannot be changed, but you can adjust
            its permissions.
        </div>
    <?php endif; ?>

    <?= $af->field($form, 'name')->textInput([
        'maxlength' => 40,
        'readonly' => $form->isSystemRole,
        'autofocus' => !$form->isSystemRole,
    ])->hint('Lowercase letters, digits, hyphens, underscores. 3–40 characters.') ?>

    <?= $af->field($form, 'description')->textInput(['maxlength' => 255]) ?>

    <label class="form-label mt-3">Permissions</label>
    <div class="row">
        <?php foreach (PermissionCatalog::groups() as $group) : ?>
            <div class="col-lg-4 col-md-6 mb-3">
                <div class="card h-100">
                    <div class="card-header py-2 d-flex justify-content-between align-items-center">
                        <strong><?= Html::encode($group['label']) ?></strong>
                        <a href="#" class="small text-decoration-none role-group-toggle"
                            data-domain="<?= Html::encode($group['domain']) ?>">toggle</a>
                    </div>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($group['permissions'] as $perm) : ?>
                            <li class="list-group-item py-1">
                                <label class="form-check-label w-100">
                                    <input
                                        type="checkbox"
                                        class="form-check-input me-1 role-permission"
                                        name="RoleForm[permissions][]"
                                        value="<?= Html::encode($perm['name']) ?>"
                                        data-domain="<?= Html::encode($group['domain']) ?>"
                                        <?= isset($selected[$perm['name']]) ? 'checked' : '' ?>
                                    >
                                    <?= Html::encode($perm['label']) ?>
                                    <code class="text-muted small"><?= Html::encode($perm['name']) ?></code>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($form->hasErrors('permissions')) : ?>
        <div class="text-danger small mb-3">
            <?= Html::encode((string)$form->getFirstError('permissions')) ?>
        </div>
    <?php endif; ?>

    <div class="mt-3">
        <?= Html::submitButton($form->originalName === null ? 'Create Role' : 'Save Changes', ['class' => 'btn btn-primary']) ?>
        <?= Html::a('Cancel', $form->originalName === null ? ['index'] : ['view', 'name' => $form->originalName], ['class' => 'btn btn-outline-secondary ms-2']) ?>
    </div>

<?php ActiveForm::end(); ?>

<?php
$js = <<<'JS'
document.querySelectorAll('.role-group-toggle').forEach(function (link) {
    link.addEventListener('click', function (e) {
        e.preventDefault();
        var domain = this.getAttribute('data-domain');
        var boxes = document.querySelectorAll('.role-permission[data-domain="' + domain + '"]');
        var allChecked = Array.prototype.every.call(boxes, function (b) { return b.checked; });
        boxes.forEach(function (b) { b.checked = !allChecked; });
    });
});
JS;
$this->registerJs($js);
?>
