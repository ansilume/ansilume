<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\WorkflowTemplate $model */

use app\models\WorkflowStep;
use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = $model->name;
$steps = $model->steps;
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><?= Html::encode($this->title) ?></h2>
    <div>
        <?php if (Yii::$app->user->can('workflow.launch')) : ?>
            <?= Html::a('Launch', ['launch', 'id' => $model->id], [
                'class' => 'btn btn-success btn-sm',
                'data' => ['confirm' => 'Launch this workflow?', 'method' => 'post'],
            ]) ?>
        <?php endif; ?>
        <?php if (Yii::$app->user->can('workflow-template.update')) : ?>
            <?= Html::a('Edit', ['update', 'id' => $model->id], ['class' => 'btn btn-outline-primary btn-sm']) ?>
        <?php endif; ?>
        <?php if (Yii::$app->user->can('workflow-template.delete')) : ?>
            <?= Html::a('Delete', ['delete', 'id' => $model->id], [
                'class' => 'btn btn-outline-danger btn-sm ms-1',
                'data' => ['confirm' => 'Delete this workflow template?', 'method' => 'post'],
            ]) ?>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-lg-10">
        <table class="table table-bordered mb-4">
            <?php if ($model->description) : ?>
            <tr>
                <th style="width:180px">Description</th>
                <td><?= Html::encode($model->description) ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <th>Created</th>
                <td><?= Html::encode(date('Y-m-d H:i', (int)$model->created_at)) ?> by <?= Html::encode($model->creator?->username ?? '—') ?></td>
            </tr>
        </table>

        <h4>Steps</h4>
        <?php if (empty($steps)) : ?>
            <div class="text-muted mb-3">No steps defined yet.</div>
        <?php else : ?>
            <table class="table table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Target</th>
                        <th>On Success</th>
                        <th>On Failure</th>
                        <?php if (Yii::$app->user->can('workflow-template.update')) : ?>
                        <th></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($steps as $step) : ?>
                    <tr>
                        <td><?= Html::encode((string)$step->step_order) ?></td>
                        <td><?= Html::encode($step->name) ?></td>
                        <td><span class="badge text-bg-secondary"><?= Html::encode(WorkflowStep::typeLabels()[$step->step_type] ?? $step->step_type) ?></span></td>
                        <td>
                            <?php if ($step->job_template_id) : ?>
                                <?= Html::a(Html::encode($step->jobTemplate?->name ?? '#' . $step->job_template_id), ['/job-template/view', 'id' => $step->job_template_id]) ?>
                            <?php elseif ($step->approval_rule_id) : ?>
                                <?= Html::a(Html::encode($step->approvalRule?->name ?? '#' . $step->approval_rule_id), ['/approval-rule/view', 'id' => $step->approval_rule_id]) ?>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?= $step->on_success_step_id ? Html::encode('Step #' . $step->on_success_step_id) : '—' ?></td>
                        <td><?= $step->on_failure_step_id ? Html::encode('Step #' . $step->on_failure_step_id) : '—' ?></td>
                        <?php if (Yii::$app->user->can('workflow-template.update')) : ?>
                        <td>
                            <?= Html::beginForm(['remove-step', 'id' => $model->id], 'post') ?>
                                <?= Html::hiddenInput('step_id', (string)$step->id) ?>
                                <?= Html::submitButton('Remove', ['class' => 'btn btn-outline-danger btn-sm', 'data-confirm' => 'Remove this step?']) ?>
                            <?= Html::endForm() ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if (Yii::$app->user->can('workflow-template.update')) : ?>
            <h5 class="mt-4">Add Step</h5>
            <?php $step = new WorkflowStep(); ?>
            <?php $form = ActiveForm::begin([
                'action' => ['add-step', 'id' => $model->id],
                'id' => 'add-step-form',
            ]); ?>
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <?= $form->field($step, 'name')->textInput([
                        'maxlength' => 128,
                        'placeholder' => 'Step name',
                    ]) ?>
                </div>
                <div class="col-md-2">
                    <?= $form->field($step, 'step_type')->dropDownList(
                        WorkflowStep::typeLabels()
                    ) ?>
                </div>
                <div class="col-md-2">
                    <?= $form->field($step, 'job_template_id')->textInput([
                        'type' => 'number',
                        'placeholder' => 'Template ID',
                    ]) ?>
                </div>
                <div class="col-md-2">
                    <?= $form->field($step, 'step_order')->textInput([
                        'type' => 'number',
                        'value' => count($steps),
                    ]) ?>
                </div>
                <div class="col-md-2">
                    <?= Html::submitButton('Add', [
                        'class' => 'btn btn-primary mb-3',
                    ]) ?>
                </div>
            </div>
            <?php ActiveForm::end(); ?>
        <?php endif; ?>
    </div>
</div>
