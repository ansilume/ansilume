<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Inventory $model */
/** @var app\models\Project[] $projects */

use app\models\Inventory;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = $model->isNewRecord ? 'New Inventory' : 'Edit: ' . $model->name;
?>
<div class="row justify-content-center">
<div class="col-lg-8">
<h2><?= Html::encode($this->title) ?></h2>

<?php $form = ActiveForm::begin(['id' => 'inventory-form']); ?>

    <?= $form->field($model, 'name')->textInput(['maxlength' => 128, 'autofocus' => true]) ?>
    <?= $form->field($model, 'description')->textarea(['rows' => 2]) ?>

    <?= $form->field($model, 'inventory_type')->dropDownList([
        Inventory::TYPE_STATIC  => 'Static (inline INI/YAML)',
        Inventory::TYPE_FILE    => 'File (from project)',
        Inventory::TYPE_DYNAMIC => 'Dynamic script',
    ], ['id' => 'inventory-type']) ?>

    <div id="field-content" <?= $model->inventory_type !== Inventory::TYPE_STATIC ? 'style="display:none"' : '' ?>>
        <?= $form->field($model, 'content')->textarea([
            'rows'        => 12,
            'class'       => 'form-control font-monospace',
            'placeholder' => "[all]\n192.168.1.10\n192.168.1.11 ansible_user=ubuntu",
        ])->label('Inventory Content (INI or YAML)') ?>
    </div>

    <div id="field-project" <?= $model->inventory_type === Inventory::TYPE_STATIC ? 'style="display:none"' : '' ?>>
        <?= $form->field($model, 'project_id')->dropDownList(
            ArrayHelper::map($projects, 'id', 'name'),
            ['prompt' => '— Select project —']
        ) ?>
        <?= $form->field($model, 'source_path')->textInput([
            'placeholder' => 'inventories/production.ini',
            'maxlength'   => 512,
        ])->hint('Path relative to the project root') ?>
    </div>

    <div class="mt-3">
        <?= Html::submitButton($model->isNewRecord ? 'Create Inventory' : 'Save Changes', ['class' => 'btn btn-primary']) ?>
        <?= Html::a('Cancel', $model->isNewRecord ? ['index'] : ['view', 'id' => $model->id], ['class' => 'btn btn-outline-secondary ms-2']) ?>
    </div>

<?php ActiveForm::end(); ?>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var typeSelect    = document.getElementById('inventory-type');
    var fieldContent  = document.getElementById('field-content');
    var fieldProject  = document.getElementById('field-project');
    if (!typeSelect) return;
    function update() {
        var v = typeSelect.value;
        fieldContent.style.display = v === 'static'  ? '' : 'none';
        fieldProject.style.display = v !== 'static'  ? '' : 'none';
    }
    typeSelect.addEventListener('change', update);
    update();
});
</script>
