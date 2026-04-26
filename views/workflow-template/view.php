<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\WorkflowTemplate $model */

use app\models\ApprovalRule;
use app\models\JobTemplate;
use app\models\WorkflowStep;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = $model->name;
$steps = $model->steps;

/** @var array<int, string> $jobTemplateOptions */
$jobTemplateOptions = ArrayHelper::map(
    JobTemplate::find()->orderBy('name')->all(),
    'id',
    'name'
);

/** @var array<int, string> $approvalRuleOptions */
$approvalRuleOptions = ArrayHelper::map(
    ApprovalRule::find()->orderBy('name')->all(),
    'id',
    'name'
);

// Map of step_id => "#order name" so operators see a human-readable label
// when picking on_success / on_failure / on_always targets.
$stepOptions = [];
foreach ($steps as $s) {
    $stepOptions[$s->id] = '#' . $s->step_order . ' ' . $s->name;
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><?= Html::encode($this->title) ?></h2>
    <div>
        <?php if (Yii::$app->user->can('workflow.launch')) : ?>
            <form action="<?= \yii\helpers\Url::to(['launch', 'id' => $model->id]) ?>" method="post" style="display:inline"
                  onsubmit="return confirm('Launch this workflow?')">
                <input type="hidden" name="<?= Yii::$app->request->csrfParam ?>" value="<?= Yii::$app->request->csrfToken ?>">
                <button type="submit" class="btn btn-success btn-sm">Launch</button>
            </form>
        <?php endif; ?>
        <?php if (Yii::$app->user->can('workflow-template.update')) : ?>
            <?= Html::a('Edit', ['update', 'id' => $model->id], ['class' => 'btn btn-outline-primary btn-sm']) ?>
        <?php endif; ?>
        <?php if (Yii::$app->user->can('workflow-template.delete')) : ?>
            <form action="<?= \yii\helpers\Url::to(['delete', 'id' => $model->id]) ?>" method="post" style="display:inline"
                  onsubmit="return confirm('Delete this workflow template?')">
                <input type="hidden" name="<?= Yii::$app->request->csrfParam ?>" value="<?= Yii::$app->request->csrfToken ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm ms-1">Delete</button>
            </form>
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
            <table class="table table-bordered" id="workflow-steps-table">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Target</th>
                        <th>On Success</th>
                        <th>On Failure</th>
                        <?php if (Yii::$app->user->can('workflow-template.update')) : ?>
                        <th class="text-nowrap">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $stepCount = count($steps);
                    foreach ($steps as $idx => $step) :
                        $isFirst = $idx === 0;
                        $isLast = $idx === $stepCount - 1;
                        ?>
                    <tr data-step-id="<?= (int)$step->id // xss-ok: int?>">
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
                        <td><?php
                        if ($step->on_success_step_id === WorkflowStep::END_WORKFLOW) {
                            echo '<span class="text-muted">end workflow</span>';
                        } elseif ($step->on_success_step_id !== null) {
                            echo Html::encode($stepOptions[$step->on_success_step_id] ?? '#' . $step->on_success_step_id);
                        } else {
                            echo '<span class="text-muted">→ next step</span>';
                        }
                        ?></td>
                        <td><?php
                        if ($step->on_failure_step_id === WorkflowStep::END_WORKFLOW) {
                            echo '<span class="text-muted">end workflow</span>';
                        } elseif ($step->on_failure_step_id !== null) {
                            echo Html::encode($stepOptions[$step->on_failure_step_id] ?? '#' . $step->on_failure_step_id);
                        } else {
                            echo '<span class="text-muted">→ next step</span>';
                        }
                        ?></td>
                        <?php if (Yii::$app->user->can('workflow-template.update')) : ?>
                        <td class="text-nowrap">
                            <?= Html::beginForm(['move-step', 'id' => $model->id], 'post', ['class' => 'd-inline']) ?>
                                <?= Html::hiddenInput('step_id', (string)$step->id) ?>
                                <?= Html::hiddenInput('direction', 'up') ?>
                                <?= Html::submitButton('▲', [
                                    'class' => 'btn btn-outline-secondary btn-sm',
                                    'title' => 'Move up',
                                    'aria-label' => 'Move step up',
                                    'data-action' => 'move-up',
                                    'disabled' => $isFirst,
                                ]) ?>
                            <?= Html::endForm() ?>
                            <?= Html::beginForm(['move-step', 'id' => $model->id], 'post', ['class' => 'd-inline']) ?>
                                <?= Html::hiddenInput('step_id', (string)$step->id) ?>
                                <?= Html::hiddenInput('direction', 'down') ?>
                                <?= Html::submitButton('▼', [
                                    'class' => 'btn btn-outline-secondary btn-sm',
                                    'title' => 'Move down',
                                    'aria-label' => 'Move step down',
                                    'data-action' => 'move-down',
                                    'disabled' => $isLast,
                                ]) ?>
                            <?= Html::endForm() ?>
                            <?= Html::beginForm(['remove-step', 'id' => $model->id], 'post', ['class' => 'd-inline']) ?>
                                <?= Html::hiddenInput('step_id', (string)$step->id) ?>
                                <?= Html::submitButton('Remove', ['class' => 'btn btn-outline-danger btn-sm ms-1', 'data-confirm' => 'Remove this step?']) ?>
                            <?= Html::endForm() ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="form-text small mb-3">
                Use ▲ / ▼ to reorder. Step numbers are auto-spaced (10, 20, 30…) so you can manually wedge a new step between two existing ones by typing any value in between.
            </p>
        <?php endif; ?>

        <?php if (Yii::$app->user->can('workflow-template.update')) : ?>
            <h5 class="mt-4">Add Step</h5>
            <?php $step = new WorkflowStep(); ?>
            <?php $form = ActiveForm::begin([
                'action' => ['add-step', 'id' => $model->id],
                'id' => 'add-step-form',
            ]); ?>
            <div class="row g-2">
                <div class="col-md-4">
                    <?= $form->field($step, 'name')->textInput([
                        'maxlength' => 128,
                        'placeholder' => 'Step name',
                    ]) ?>
                </div>
                <div class="col-md-3">
                    <?= $form->field($step, 'step_type')->dropDownList(
                        WorkflowStep::typeLabels(),
                        ['id' => 'ws-step-type']
                    ) ?>
                </div>
                <div class="col-md-2">
                    <?= $form->field($step, 'step_order')->textInput([
                        'type' => 'number',
                        'value' => count($steps),
                    ]) ?>
                </div>
            </div>
            <div class="row g-2" id="ws-target-job">
                <div class="col-md-6">
                    <?= $form->field($step, 'job_template_id')->dropDownList(
                        $jobTemplateOptions,
                        ['prompt' => '— select a job template —']
                    )->label('Job Template') ?>
                </div>
            </div>
            <div class="row g-2" id="ws-target-approval" style="display:none">
                <div class="col-md-6">
                    <?= $form->field($step, 'approval_rule_id')->dropDownList(
                        $approvalRuleOptions,
                        ['prompt' => '— select an approval rule —']
                    )->label('Approval Rule') ?>
                </div>
            </div>
            <?php
            // Build options with "next step" (NULL) as default and "end workflow" (-1) as explicit choice
            $branchOptions = [WorkflowStep::END_WORKFLOW => '— end workflow —'] + $stepOptions;
            ?>
            <div id="ws-routing-explainer" class="form-text small mb-1">
                <span id="ws-routing-help-job" class="d-none">
                    <strong>Job step:</strong> success = exit code 0. Failure = any non-zero exit, timeout, or unreachable host. <em>Always</em> overrides both.
                </span>
                <span id="ws-routing-help-approval" class="d-none">
                    <strong>Approval step:</strong> success = approved, failure = rejected. <em>Always</em> overrides both (rarely useful here — skips the decision).
                </span>
                <span id="ws-routing-help-pause" class="d-none">
                    <strong>Pause step:</strong> pauses only resume via the operator clicking <em>Resume</em>, which counts as success — so only the "Next step" (on-success) field applies. <em>On Failure</em> and <em>Always</em> are ignored for pause.
                </span>
            </div>
            <div class="row g-2">
                <div class="col-md-4" id="ws-route-success" data-label-default="On Success → go to" data-label-pause="Next step →">
                    <?= $form->field($step, 'on_success_step_id')->dropDownList(
                        $branchOptions,
                        ['prompt' => '— next step (default) —']
                    )->label('On Success → go to') ?>
                </div>
                <div class="col-md-4" id="ws-route-failure">
                    <?= $form->field($step, 'on_failure_step_id')->dropDownList(
                        $branchOptions,
                        ['prompt' => '— next step (default) —']
                    )->label('On Failure → go to') ?>
                </div>
                <div class="col-md-4" id="ws-route-always">
                    <?= $form->field($step, 'on_always_step_id')->dropDownList(
                        $stepOptions,
                        ['prompt' => '— use success/failure above —']
                    )->label('Always → go to (overrides success/failure)') ?>
                </div>
            </div>
            <div class="mb-3">
                <?= Html::submitButton('Add Step', ['class' => 'btn btn-primary']) ?>
            </div>
            <?php ActiveForm::end(); ?>
            <script>
            (function () {
                function syncTargets() {
                    var type = document.getElementById('ws-step-type').value;
                    var isPause = type === 'pause';
                    document.getElementById('ws-target-job').style.display = type === 'job' ? '' : 'none';
                    document.getElementById('ws-target-approval').style.display = type === 'approval' ? '' : 'none';

                    // Pause steps only advance via on_success (resume counts as success).
                    // on_failure and on_always are dead code for pauses — hide them to
                    // keep the form honest.
                    document.getElementById('ws-route-failure').style.display = isPause ? 'none' : '';
                    document.getElementById('ws-route-always').style.display = isPause ? 'none' : '';

                    // When pause is active, expand the remaining field to full width
                    // and swap the label from "On Success → go to" to "Next step →".
                    var successCol = document.getElementById('ws-route-success');
                    if (successCol) {
                        successCol.classList.toggle('col-md-4', !isPause);
                        successCol.classList.toggle('col-md-12', isPause);
                        var successLabel = successCol.querySelector('label');
                        if (successLabel) {
                            successLabel.textContent = isPause
                                ? successCol.getAttribute('data-label-pause')
                                : successCol.getAttribute('data-label-default');
                        }
                    }

                    // Show only the help blurb that matches the current step type.
                    ['job', 'approval', 'pause'].forEach(function (t) {
                        var help = document.getElementById('ws-routing-help-' + t);
                        if (help) { help.classList.toggle('d-none', t !== type); }
                    });
                }
                document.addEventListener('DOMContentLoaded', function () {
                    var sel = document.getElementById('ws-step-type');
                    if (!sel) { return; }
                    sel.addEventListener('change', syncTargets);
                    syncTargets();
                });
            })();
            </script>
        <?php endif; ?>
    </div>
</div>
