<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\WorkflowTemplate $model */
/** @var app\models\WorkflowStep $step the step as stored */
/** @var int $position its position in the run order */
/** @var app\services\WorkflowStepFormView $form the Edit Step form */
/** @var app\models\WorkflowStep $formModel the step the form shows: as the user filled it in when the save was refused, else as stored */
/** @var array<int, string> $jobTemplateOptions job templates the user may operate; without an approval rule unless $mayAssignRules */
/** @var array<int, string> $approvalRuleOptions offered only to users who may assign approval rules */
/** @var bool $mayAssignRules the user may add approval steps and gated job steps (approval-rule.update) */
/** @var string|null $error why the save was refused ("Step not saved: …"); null otherwise */
/** @var list<array{label: string, note: string|null}> $routedBy the other steps whose branch leads to this one, by position ("2. Build"), with a note when no run takes that branch */
/** @var list<int> $activeRunIds the workflow's active runs, which lock its steps */
// The parameters are WorkflowTemplatePage::editParams().

use app\services\WorkflowStepFormView;
use app\services\WorkflowStepsTable;
use app\services\WorkflowTemplatePage;
use yii\helpers\Html;

// 'Edit step 2 "Test"'
$this->title = $form->heading();
$locked = $activeRunIds !== [];
?>
<div class="row">
<div class="col-lg-10">
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><?= Html::a(Html::encode(WorkflowTemplatePage::TEMPLATES_CRUMB), ['index']) ?></li>
        <li class="breadcrumb-item"><?= Html::a(Html::encode($model->name), ['view', 'id' => $model->id, '#' => 'step-' . $step->id]) ?></li>
        <li class="breadcrumb-item active"><?= Html::encode(WorkflowStepsTable::stepReference($position, (string)$step->name)) ?></li>
    </ol>
</nav>
<h2><?= Html::encode($this->title) ?></h2>

<?php if ($locked) : ?>
    <?= $this->render('_runs-active', [
        'id' => 'edit-step-locked',
        'runIds' => $activeRunIds,
        'runCount' => count($activeRunIds),
    ]) ?>
<?php endif; ?>

<?php
// While a run is active the steps cannot change: a disabled fieldset
// disables every field and button of the form inside it.
?>
<?= Html::beginTag('fieldset', ['id' => 'edit-step-fieldset', 'disabled' => $locked]) ?>
    <?= $this->render('_step-form', [
        'form' => $form,
        'model' => $formModel,
        'jobTemplateOptions' => $jobTemplateOptions,
        'approvalRuleOptions' => $approvalRuleOptions,
        'mayAssignRules' => $mayAssignRules,
        'action' => ['edit-step', 'id' => $model->id, 'step' => $step->id],
        'error' => $error,
    ]) ?>
<?= Html::endTag('fieldset') ?>

<div class="card mt-4" id="edit-step-routed-by">
    <div class="card-header"><?= Html::encode(WorkflowStepFormView::ROUTED_BY_TITLE) ?></div>
    <div class="card-body small">
        <?php if ($routedBy === []) : ?>
            <span class="text-muted"><?= Html::encode(WorkflowStepFormView::ROUTED_BY_NONE) ?></span>
        <?php else : ?>
            <ul class="mb-0">
                <?php foreach ($routedBy as $routing) : ?>
                    <li>
                        <?= Html::encode($routing['label']) ?>
                        <?php if ($routing['note'] !== null) : ?>
                            <span class="d-block text-muted" data-routed-by-note><?= Html::encode($routing['note']) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php if (!\app\components\WorkflowStepMapping::isNone($step->extra_vars_template)) : ?>
    <div class="mt-3 small" id="edit-step-extra-vars">
        <div class="mb-1"><?= Html::encode(WorkflowStepFormView::EXTRA_VARS_READONLY) ?></div>
        <pre class="border rounded p-2 mb-0"><?= Html::encode((string)$step->extra_vars_template) ?></pre>
    </div>
<?php endif; ?>
</div>
</div>
