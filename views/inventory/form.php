<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Inventory $model */
/** @var app\models\Project[] $projects */
/** @var bool $canAssignRule approval-rule.update: the rule select, else the rule as text */
/** @var array<int, string> $approvalRuleOptions the rules by ID, for the select */
/** @var bool $changeLocked a gated inventory, a user without approval-rule.update: only name and description are enabled */
/** @var string|null $repositoryHostsNote GatedInventoryLock::REPOSITORY_HOSTS_NOTE for rule assigners and on a gated inventory, else null */

use app\models\Inventory;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\GatedInventoryLock;
use app\services\InventoryRuleAssignment;
use app\services\JobTemplateRuleAssignment;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = $model->isNewRecord ? 'New Inventory' : 'Edit: ' . $model->name;
?>
<div class="row justify-content-center">
<div class="col-lg-8">
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><?= Html::a('Inventories', ['index']) ?></li>
        <?php if (!$model->isNewRecord) : ?>
            <li class="breadcrumb-item"><?= Html::a(Html::encode($model->name), ['view', 'id' => $model->id]) ?></li>
        <?php endif; ?>
        <li class="breadcrumb-item active"><?= $model->isNewRecord ? 'New' : 'Edit' ?></li>
    </ol>
</nav>
<h2><?= Html::encode($this->title) ?></h2>

<?php if ($changeLocked) : ?>
    <div class="alert alert-info" id="inventory-locked-notice"><?= Html::encode(GatedInventoryLock::NOTICE) ?></div>
<?php endif; ?>

<?php $form = ActiveForm::begin(['id' => 'inventory-form']); ?>

    <?= $form->field($model, 'name')->textInput(['maxlength' => 128, 'autofocus' => true]) ?>
    <?= $form->field($model, 'description')->textarea(['rows' => 2]) ?>

    <?php // Where the hosts come from. Where the lock applies, every field in here is disabled, so none is posted.?>
    <?= Html::beginTag('fieldset', ['id' => 'inventory-host-fields', 'disabled' => $changeLocked]) ?>

    <?= $form->field($model, 'inventory_type')->dropDownList([
        Inventory::TYPE_STATIC => 'Static (inline YAML)',
        Inventory::TYPE_FILE => 'File (from project)',
        Inventory::TYPE_DYNAMIC => 'Dynamic script',
    ], ['id' => 'inventory-type']) ?>

    <div id="field-content" <?= $model->inventory_type !== Inventory::TYPE_STATIC ? 'style="display:none"' : '' // xss-ok: hardcoded attribute?>>

        <?= $form->field($model, 'content')->textarea([
            'rows' => 12,
            'class' => 'form-control font-monospace',
            'placeholder' => "all:\n  hosts:\n    192.168.1.10:\n    192.168.1.11:\n      ansible_user: ubuntu",
            // A locked form keeps the plain field: the editor would take edits that are not posted.
            'data-yaml-editor' => $changeLocked ? null : '1',
        ])->label('Inventory Content (YAML)') ?>
    </div>

    <div id="field-project" <?= $model->inventory_type === Inventory::TYPE_STATIC ? 'style="display:none"' : '' // xss-ok: hardcoded attribute?>>

        <?= $form->field($model, 'project_id')->dropDownList(
            ArrayHelper::map($projects, 'id', 'name'),
            ['prompt' => '— Select project —']
        ) ?>
        <?= $form->field($model, 'source_path')->textInput([
            'placeholder' => 'inventories/production.yml',
            'maxlength' => 512,
        ])->hint('Path relative to the project root') ?>
        <?php if ($repositoryHostsNote !== null) : ?>
            <p class="form-text" id="inventory-repository-hosts-note"><?= Html::encode($repositoryHostsNote) ?></p>
        <?php endif; ?>
    </div>

    <?= Html::endTag('fieldset') ?>

    <?php // A schema cached from before m000088 has no rule column: then the inventory has no rule to show.?>
    <?php if ($model->hasAttribute(InventoryRuleAssignment::FIELD)) : ?>
        <h5 class="mt-3 text-muted">Approval</h5>
        <?php if ($canAssignRule) : ?>
            <?= $form->field($model, 'approval_rule_id')->dropDownList($approvalRuleOptions, ['prompt' => '— None —'])
                ->hint(Html::encode(InventoryRuleAssignment::FORM_HINT)) ?>
        <?php else : ?>
            <?php // The rule as text: nothing is posted, so the stored rule stays.?>
            <?php $currentRule = $model->getAttribute(InventoryRuleAssignment::FIELD) === null ? null : $model->approvalRule; ?>
            <div class="mb-3" id="inventory-approval-rule-readonly">
                <span class="form-label d-block">Approval rule</span>
                <span class="form-control-plaintext py-0"><?= Html::encode($currentRule === null ? 'None' : JobTemplateRuleAssignment::label($currentRule)) ?></span>
                <div class="form-text"><?= Html::encode(ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED) ?></div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

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

<link rel="stylesheet" href="/css/vendor/codemirror/codemirror.css">
<link rel="stylesheet" href="/css/extra-vars-editor.css">
<script src="/js/vendor/codemirror/codemirror.js"></script>
<script src="/js/vendor/codemirror/mode/yaml/yaml.js"></script>
<script src="/js/vendor/codemirror/addon/edit/matchbrackets.js"></script>
<script src="/js/vendor/codemirror/addon/edit/closebrackets.js"></script>
<script src="/js/vendor/codemirror/addon/selection/active-line.js"></script>
<script src="/js/vendor/js-yaml/js-yaml.min.js"></script>
<script src="/js/yaml-editor.js"></script>
