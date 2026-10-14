<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\services\WorkflowStepFormView $form Add Step or Edit Step */
/** @var app\models\WorkflowStep $model the step the form shows: new (Add Step), as stored, or as the user filled it in when the save was refused */
/** @var array<int, string> $jobTemplateOptions job templates the user may operate; without an approval rule unless $mayAssignRules */
/** @var array<int, string> $approvalRuleOptions offered only to users who may assign approval rules */
/** @var bool $mayAssignRules the user may add approval steps and gated job steps (approval-rule.update) */
/** @var array<int|string, mixed>|string $action where the form posts to */
/** @var string|null $error why the change was refused, shown above the form; null otherwise */
// The step form (WorkflowStepFormView): Add Step on the template page, with
// its heading (#add-step); Edit Step on its own page, which has the title.
// web/js/workflow-step-form.js keeps it so when the type or the Position
// changes; the server renders it for the type and the selected Position.

use app\components\VersionedAssetUrl;
use app\models\WorkflowStep;
use app\services\WorkflowStepFormView;
use app\services\WorkflowTemplatePage;
use yii\helpers\Html;
use yii\widgets\ActiveForm;

$type = $form->type($model);
$position = $form->position($model);
$routes = $form->routes($model);
$notes = $form->notes($model);
$shows = $form->shows($model);
$isPause = $type === WorkflowStep::TYPE_PAUSE;
// A part the type does not show is hidden, and its select disabled.
$part = static fn (string $id, string $class, array $options = []): string => Html::beginTag('div', array_merge(
    ['class' => $class, 'id' => $id, 'style' => $shows[$id] ? null : 'display:none'],
    $options
));
$help = static fn (string $id, string $title, string $text): string => Html::tag(
    'span',
    Html::tag('strong', Html::encode($title)) . ' ' . Html::encode($text),
    ['id' => $id, 'class' => $shows[$id] ? null : 'd-none']
);
?>
<?php if (!$form->isEdit()) : ?>
    <h5 class="mt-4" id="add-step"><?= Html::encode($form->heading()) ?></h5>
<?php endif; ?>
<?php if ($error !== null) : ?>
    <div class="alert alert-danger" id="step-form-error" role="alert"><?= Html::encode($error) ?></div>
<?php endif; ?>
<?php $activeForm = ActiveForm::begin([
    'action' => $action,
    'id' => $form->formId(),
    'options' => ['data-step-form' => $form->isEdit() ? 'edit' : 'add'],
]); ?>
<div class="row g-2">
    <div class="col-md-5">
        <?= $activeForm->field($model, 'name')->textInput([
            'maxlength' => 128,
            'placeholder' => WorkflowStepFormView::NAME_PLACEHOLDER,
            'autofocus' => $form->hasAutofocus(),
        ])->label(Html::encode(WorkflowStepFormView::NAME_LABEL)) ?>
    </div>
    <div class="col-md-3">
        <?= $activeForm->field($model, 'step_type')->dropDownList($form->typeItems($mayAssignRules, $model), [
            'id' => 'ws-step-type',
            'value' => $type,
            // Edit Step: the type cannot change, and is not posted.
            'disabled' => $form->isEdit(),
        ])->label(Html::encode(WorkflowStepFormView::TYPE_LABEL))->hint(
            $form->typeHint() === null ? false : Html::encode($form->typeHint()),
            ['id' => 'ws-type-hint']
        ) ?>
    </div>
</div>
<?php if ($position === null) : ?>
    <p class="form-text small" id="ws-position-first-step"><?= Html::encode(WorkflowStepFormView::FIRST_STEP_NOTE) ?></p>
<?php else : ?>
    <div class="row g-2">
        <?= Html::beginTag('div', ['class' => 'col-md-8 mb-3', 'id' => WorkflowStepFormView::POSITION_FIELD_ID]) ?>
            <label class="control-label" for="ws-position"><?= Html::encode(WorkflowStepFormView::POSITION_LABEL) ?></label>
            <?= Html::dropDownList('position', $position['selected'], $position['items'], [
                'id' => 'ws-position',
                'class' => 'form-control',
                'options' => $position['options'],
            ]) ?>
            <div class="form-text" id="ws-position-preview" aria-live="polite"><?= Html::encode($position['preview']) ?></div>
            <?= Html::tag('div', Html::encode($position['warning']), [
                'class' => 'form-text text-warning' . ($position['warning'] === '' ? ' d-none' : ''),
                'id' => 'ws-position-warning',
                'aria-live' => 'polite',
            ]) ?>
        </div>
    </div>
<?php endif; ?>
<?= $part('ws-target-job', 'row g-2') // xss-ok: Html::beginTag()?>
    <div class="col-md-6">
        <?php
        // Gated job steps are for admins: job templates with an approval rule
        // are offered to admins only, and the write path refuses them from
        // anyone else.
        ?>
        <?= $activeForm->field($model, 'job_template_id')->dropDownList($jobTemplateOptions, [
            'prompt' => WorkflowStepFormView::JOB_TEMPLATE_PROMPT,
            'disabled' => !$shows['ws-target-job'],
        ])->label(Html::encode(WorkflowStepFormView::JOB_TEMPLATE_LABEL))->hint(
            $mayAssignRules ? false : Html::encode(WorkflowTemplatePage::GATED_TEMPLATES_HINT),
            ['id' => 'ws-gated-templates-hint']
        ) ?>
        <?php if ($notes['templateGone']) : ?>
            <div class="form-text text-warning mb-3" id="ws-template-gone-hint"><?= Html::encode(WorkflowStepFormView::TEMPLATE_GONE_HINT) ?></div>
        <?php endif; ?>
    </div>
</div>
<?php if ($mayAssignRules) : ?>
    <?= $part('ws-target-approval', 'row g-2') // xss-ok: Html::beginTag()?>
        <div class="col-md-6">
            <?= $activeForm->field($model, 'approval_rule_id')->dropDownList($approvalRuleOptions, [
                'prompt' => WorkflowStepFormView::APPROVAL_RULE_PROMPT,
                'disabled' => !$shows['ws-target-approval'],
            ])->label(Html::encode(WorkflowStepFormView::APPROVAL_RULE_LABEL)) ?>
        </div>
    </div>
<?php endif; ?>
<div id="ws-routing-explainer" class="form-text small mb-1">
    <?= $help('ws-routing-help-job', WorkflowStepFormView::HELP_JOB_TITLE, WorkflowStepFormView::HELP_JOB) // xss-ok: Html::tag() with encoded texts?>
    <?= $help('ws-routing-help-approval', WorkflowStepFormView::HELP_APPROVAL_TITLE, WorkflowStepFormView::HELP_APPROVAL) // xss-ok: Html::tag() with encoded texts?>
    <?= $help('ws-routing-help-pause', WorkflowStepFormView::HELP_PAUSE_TITLE, WorkflowStepFormView::HELP_PAUSE) // xss-ok: Html::tag() with encoded texts?>
</div>
<div class="row g-2">
    <?= Html::beginTag('div', [
        'class' => $isPause ? 'col-md-12' : 'col-md-4',
        'id' => 'ws-route-success',
        'data-label-default' => WorkflowStepFormView::SUCCESS_LABEL,
        'data-label-pause' => WorkflowStepFormView::SUCCESS_LABEL_PAUSE,
    ]) ?>
        <?= $activeForm->field($model, 'on_success_step_id')->dropDownList($routes['success']['items'], [
            'prompt' => $routes['success']['prompt'],
            'value' => $routes['success']['value'],
        ])->label(Html::encode($isPause ? WorkflowStepFormView::SUCCESS_LABEL_PAUSE : WorkflowStepFormView::SUCCESS_LABEL)) ?>
        <?php if ($notes['legacyAlways']) : ?>
            <div class="form-text text-warning mb-3" id="ws-legacy-always-note"><?= Html::encode(WorkflowStepFormView::LEGACY_ALWAYS_NOTE) ?></div>
        <?php endif; ?>
    </div>
    <?php
    // The empty On Failure choice of an approval step, and of a job step
    // without a Position (the page script swaps them when the type changes).
    $failurePrompts = [
        'data-prompt-approval' => WorkflowStepFormView::END_FAILS_OPTION,
        'data-prompt-job' => WorkflowStepFormView::NEXT_OPTION_NONE_FAILS,
    ];
    ?>
    <?= $part('ws-route-failure', 'col-md-4', $failurePrompts) // xss-ok: Html::beginTag()?>
        <?= $activeForm->field($model, 'on_failure_step_id')->dropDownList($routes['failure']['items'], [
            'prompt' => $routes['failure']['prompt'],
            'value' => $routes['failure']['value'],
            'disabled' => !$shows['ws-route-failure'],
        ])->label(Html::encode(WorkflowStepFormView::FAILURE_LABEL)) ?>
        <?php if (!$form->isEdit()) : ?>
            <?= Html::tag('div', Html::encode(WorkflowStepFormView::FAILURE_DEFAULT_NOTE), [
                'class' => 'form-text mb-3' . ($notes['failureDefault'] ? '' : ' d-none'),
                'id' => 'ws-failure-default-note',
            ]) ?>
        <?php endif; ?>
    </div>
    <?= $part('ws-route-always', 'col-md-4') // xss-ok: Html::beginTag()?>
        <?= $activeForm->field($model, 'on_always_step_id')->dropDownList($routes['always']['items'], [
            'prompt' => $routes['always']['prompt'],
            'value' => $routes['always']['value'],
            'disabled' => !$shows['ws-route-always'],
        ])->label(Html::encode(WorkflowStepFormView::ALWAYS_LABEL)) ?>
    </div>
</div>
<div class="mb-3">
    <?= Html::submitButton(Html::encode($form->submitLabel()), ['class' => 'btn btn-primary']) ?>
    <?php if ($form->cancelRoute() !== null) : ?>
        <?= Html::a(Html::encode(WorkflowStepFormView::CANCEL), $form->cancelRoute(), ['class' => 'btn btn-outline-secondary ms-2', 'id' => 'edit-step-cancel']) ?>
    <?php endif; ?>
</div>
<?php ActiveForm::end(); ?>
<?= Html::jsFile(VersionedAssetUrl::of('/js/workflow-step-form.js')) ?>
