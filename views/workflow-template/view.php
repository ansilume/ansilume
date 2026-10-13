<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\WorkflowTemplate $model */
/** @var bool $canOperate operator access to the project of every job step */
/** @var array<int, string> $jobTemplateOptions job templates the user may operate; without an approval rule unless $mayAssignRules */
/** @var array<int, string> $approvalRuleOptions offered only to users who may assign approval rules */
/** @var bool $mayAssignRules the user may add approval steps and gated job steps (approval-rule.update) */
/** @var string|null $stepsLock why the user may not change the steps (an approval step or a gated job step); null when they may */
/** @var list<int> $activeRunIds the workflow's active runs, which lock its steps */
/** @var app\models\User|null $triggerUser whom the inbound trigger runs as; null without a token */
// The parameters are WorkflowTemplatePage::viewParams(), the route labels and notes its constants.

use app\helpers\ConfirmHelper;
use app\models\WorkflowStep;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowTemplatePage;
use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = $model->name;
$steps = $model->steps;
// Changing the workflow needs the RBAC permission and operator access to
// every job step's project; the controller refuses everything else.
$canChange = $canOperate && Yii::$app->user->can('workflow-template.update');
// Its steps, moreover, only while no run is active, and, when it has an
// approval step or a gated job step, only for admins.
$canChangeSteps = $canChange && $stepsLock === null && $activeRunIds === [];

// Map of step_id => "#order name" so operators see a human-readable label
// when picking on_success / on_failure / on_always targets.
$stepOptions = [];
foreach ($steps as $s) {
    $stepOptions[$s->id] = '#' . $s->step_order . ' ' . $s->name;
}

// A route as the steps table shows it; $whenNull is what a route left
// empty means for the step.
$routeLabel = static function (?int $target, string $whenNull) use ($stepOptions): string {
    if ($target === WorkflowStep::END_WORKFLOW) {
        return '<span class="text-muted">' . Html::encode(WorkflowTemplatePage::ROUTE_END) . '</span>';
    }
    if ($target !== null) {
        return Html::encode($stepOptions[$target] ?? '#' . $target);
    }
    return '<span class="text-muted">' . Html::encode($whenNull) . '</span>';
};
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><?= Html::encode($this->title) ?></h2>
    <div>
        <?php if ($canOperate && Yii::$app->user->can('workflow.launch')) : ?>
            <form action="<?= \yii\helpers\Url::to(['launch', 'id' => $model->id]) ?>" method="post" style="display:inline"
                  onsubmit="return confirm('Launch this workflow?')">
                <input type="hidden" name="<?= Yii::$app->request->csrfParam ?>" value="<?= Yii::$app->request->csrfToken ?>">
                <button type="submit" class="btn btn-success btn-sm">Launch</button>
            </form>
        <?php endif; ?>
        <?php if ($canChange) : ?>
            <?= Html::a('Edit', ['update', 'id' => $model->id], ['class' => 'btn btn-outline-primary btn-sm']) ?>
        <?php endif; ?>
        <?php if ($canOperate && Yii::$app->user->can('workflow-template.delete')) : ?>
            <?php
            // Deleting the workflow cancels its active runs.
            $deleteQuestion = $activeRunIds === []
                ? 'Delete this workflow template?'
                : sprintf('Delete this workflow template? Its %d running run(s) will be canceled.', count($activeRunIds));
            ?>
            <form action="<?= \yii\helpers\Url::to(['delete', 'id' => $model->id]) ?>" method="post" style="display:inline"
                  id="wf-delete-form" onsubmit="<?= ConfirmHelper::attribute($deleteQuestion) ?>">
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

        <?php if (!$canOperate) : ?>
            <div class="alert alert-secondary small" id="wf-view-only-notice">
                You may view this workflow. Changing or launching it needs operator access to the project of every job step.
            </div>
        <?php endif; ?>
        <?php if ($canChange && $stepsLock !== null) : ?>
            <div class="alert alert-secondary small" id="wf-approval-steps-locked-notice">
                <?= Html::encode($stepsLock) ?>
                <?= Html::encode(WorkflowTemplatePage::STEPS_LOCKED_NOTE) ?>
            </div>
        <?php endif; ?>
        <?php if ($canChange && $activeRunIds !== []) : ?>
            <?php
            // The notice is RUNS_ACTIVE with each named run linked.
            [$runsBefore, $runsAfter] = explode('%s', WorkflowStepChangeGuard::RUNS_ACTIVE, 2);
            $runLinks = array_map(
                static fn (int $runId): string => Html::a(Html::encode('#' . $runId), ['/workflow-job/view', 'id' => $runId]),
                array_slice($activeRunIds, 0, WorkflowStepChangeGuard::RUN_LIST_LIMIT)
            );
            $moreRuns = count($activeRunIds) - count($runLinks);
            ?>
            <div class="alert alert-warning small" id="wf-active-runs-notice">
                <?= Html::encode($runsBefore) ?><?= implode(', ', $runLinks) // xss-ok: Html::a() with encoded text?><?= $moreRuns > 0 ? Html::encode(sprintf(' and %d more', $moreRuns)) : '' ?><?= Html::encode($runsAfter) ?>
            </div>
        <?php endif; ?>

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
                        <th>Always</th>
                        <?php if ($canChangeSteps) : ?>
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
                            <?php
                            // Only the target of the step's type: steps saved by older
                            // versions can carry another one, e.g. a job template on a
                            // pause step, possibly of a project the user may not see.
                            ?>
                            <?php if ($step->step_type === WorkflowStep::TYPE_JOB && $step->job_template_id) : ?>
                                <?= Html::a(Html::encode($step->jobTemplate?->name ?? '#' . $step->job_template_id), ['/job-template/view', 'id' => $step->job_template_id]) ?>
                            <?php elseif ($step->step_type === WorkflowStep::TYPE_APPROVAL && $step->approval_rule_id) : ?>
                                <?= Html::a(Html::encode($step->approvalRule?->name ?? '#' . $step->approval_rule_id), ['/approval-rule/view', 'id' => $step->approval_rule_id]) ?>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <?php
                        // A rejected, timed-out or canceled approval ends the run as
                        // failed unless On Failure names a step, and never takes the
                        // Always route: for an approval step, and for a job step whose
                        // job template has an approval rule (its job then never ran).
                        // A gated job step's empty On Failure says both cases: a job
                        // that ran and failed goes on with the next step.
                        // An approval step's Always route (saved by older versions) is
                        // taken only on approval; a gated job step's after its job ran.
                        $isApproval = $step->step_type === WorkflowStep::TYPE_APPROVAL;
                        $isGatedJob = $step->step_type === WorkflowStep::TYPE_JOB && $step->jobTemplate?->approval_rule_id !== null;
                        ?>
                        <td data-route="success"><?= $routeLabel($step->on_success_step_id, WorkflowTemplatePage::ROUTE_NEXT_STEP) // xss-ok: encoded by $routeLabel?></td>
                        <?php if ($isGatedJob && $step->on_failure_step_id === null) : ?>
                        <td data-route="failure"><span class="text-muted" title="<?= Html::encode(WorkflowTemplatePage::GATED_JOB_FAILURE_TITLE) ?>"><?= Html::encode(WorkflowTemplatePage::GATED_JOB_FAILURE_ROUTE) ?></span></td>
                        <?php else : ?>
                        <td data-route="failure"><?= $routeLabel($step->on_failure_step_id, $isApproval ? WorkflowTemplatePage::ROUTE_END_FAILED : WorkflowTemplatePage::ROUTE_NEXT_STEP) // xss-ok: encoded by $routeLabel?></td>
                        <?php endif; ?>
                        <td data-route="always">
                            <?= $routeLabel($step->on_always_step_id, '—') // xss-ok: encoded by $routeLabel?>
                            <?php if ($isApproval && $step->on_always_step_id !== null) : ?>
                                <span class="text-muted small"><?= Html::encode(WorkflowTemplatePage::APPROVAL_ALWAYS_NOTE) ?></span>
                            <?php elseif ($isGatedJob && $step->on_always_step_id !== null) : ?>
                                <span class="text-muted small"><?= Html::encode(WorkflowTemplatePage::GATED_JOB_ALWAYS_NOTE) ?></span>
                            <?php endif; ?>
                        </td>
                        <?php if ($canChangeSteps) : ?>
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
            <?php if ($canChangeSteps) : ?>
            <p class="form-text small mb-3" id="ws-step-order-hint">
                <?= Html::encode(WorkflowTemplatePage::STEP_ORDER_HINT) ?>
            </p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($canChangeSteps) : ?>
            <h5 class="mt-4">Add Step</h5>
            <?php
            $step = new WorkflowStep();
            // Approval steps are for admins: the controller refuses them from anyone else.
            $typeOptions = $mayAssignRules
                ? WorkflowStep::typeLabels()
                : array_diff_key(WorkflowStep::typeLabels(), [WorkflowStep::TYPE_APPROVAL => true]);
            ?>
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
                        $typeOptions,
                        ['id' => 'ws-step-type']
                    ) ?>
                </div>
                <div class="col-md-2">
                    <?php
                    // Empty by default: the step then goes after the last one.
                    // Steps are renumbered 10, 20, 30… after every add, so a
                    // pre-filled number of steps put a new step first.
                    ?>
                    <?= $form->field($step, 'step_order')->textInput([
                        'type' => 'number',
                        'min' => 0,
                        'placeholder' => WorkflowTemplatePage::STEP_ORDER_PLACEHOLDER,
                    ]) ?>
                </div>
            </div>
            <div class="row g-2" id="ws-target-job">
                <div class="col-md-6">
                    <?php
                    // Gated job steps are for admins too: job templates with an approval
                    // rule are offered to admins only, and the controller refuses them
                    // from anyone else.
                    ?>
                    <?= $form->field($step, 'job_template_id')->dropDownList(
                        $jobTemplateOptions,
                        ['prompt' => '— select a job template —']
                    )->label('Job Template')->hint(
                        $mayAssignRules ? false : Html::encode(WorkflowTemplatePage::GATED_TEMPLATES_HINT),
                        ['id' => 'ws-gated-templates-hint']
                    ) ?>
                </div>
            </div>
            <?php if ($mayAssignRules) : ?>
            <div class="row g-2" id="ws-target-approval" style="display:none">
                <div class="col-md-6">
                    <?= $form->field($step, 'approval_rule_id')->dropDownList(
                        $approvalRuleOptions,
                        ['prompt' => '— select an approval rule —']
                    )->label('Approval Rule') ?>
                </div>
            </div>
            <?php endif; ?>
            <?php
            // Build options with "next step" (NULL) as default and "end workflow" (WorkflowStep::END_WORKFLOW, 0) as explicit choice
            $branchOptions = [WorkflowStep::END_WORKFLOW => '— end workflow —'] + $stepOptions;
            ?>
            <div id="ws-routing-explainer" class="form-text small mb-1">
                <span id="ws-routing-help-job" class="d-none">
                    <strong>Job step:</strong> success = exit code 0. Failure = any non-zero exit, timeout, or unreachable host. <em>Always</em> overrides both.
                    If the job template has an approval rule and the approval is rejected, times out or is canceled, the job does not run:
                    then only <em>On Failure</em> is followed (left empty, the run ends as failed), and <em>Always</em> is not.
                </span>
                <span id="ws-routing-help-approval" class="d-none">
                    <strong>Approval step:</strong> success = approved; failure = rejected, timed out or canceled.
                    After a failure the run ends as failed unless <em>On Failure</em> names a step. An approval step has no <em>Always</em> route: it would continue past a rejection.
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
                <div class="col-md-4" id="ws-route-failure" data-prompt-default="— next step (default) —" data-prompt-approval="— end workflow as failed (default) —">
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
                // Hide a routing field, and disable its select so a value chosen
                // before the type changed is not posted.
                function showRoute(id, visible) {
                    var col = document.getElementById(id);
                    if (!col) { return; }
                    col.style.display = visible ? '' : 'none';
                    var select = col.querySelector('select');
                    if (select) { select.disabled = !visible; }
                }

                function syncTargets() {
                    var type = document.getElementById('ws-step-type').value;
                    var isPause = type === 'pause';
                    var isApproval = type === 'approval';
                    // The target of another step type is disabled as well as
                    // hidden: the browser neither validates nor posts it. A
                    // hidden but enabled select kept Add Step from submitting
                    // with its own required error, which nobody could see.
                    showRoute('ws-target-job', type === 'job');
                    // Only rendered for users who may add approval steps.
                    showRoute('ws-target-approval', isApproval);

                    // Pause steps only advance via on_success (resume counts as success).
                    // on_failure and on_always are dead code for pauses — hide them to
                    // keep the form honest. An approval step has no Always route: the
                    // server refuses one.
                    showRoute('ws-route-failure', !isPause);
                    showRoute('ws-route-always', !isPause && !isApproval);

                    // An approval step without an On Failure route ends the run as failed.
                    var failureCol = document.getElementById('ws-route-failure');
                    var failurePrompt = failureCol ? failureCol.querySelector('option[value=""]') : null;
                    if (failurePrompt) {
                        failurePrompt.textContent = failureCol.getAttribute(isApproval ? 'data-prompt-approval' : 'data-prompt-default');
                    }

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

        <?php
        // The raw token right after it was generated, shown once. Read and
        // deleted here, card or not: the layout prints every flash that is
        // left. Shown only while it is still the workflow's token.
        $flashedToken = Yii::$app->session?->getFlash('trigger_token_raw', null, true);
        $freshToken = is_string($flashedToken) && is_string($model->trigger_token)
            && hash_equals($model->trigger_token, hash('sha256', $flashedToken)) ? $flashedToken : null;
        ?>
        <?php if ($canChange) : ?>
        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Inbound Trigger</span>
                <?php if ($model->trigger_token) : ?>
                    <form method="post" action="<?= \yii\helpers\Url::to(['revoke-trigger-token', 'id' => $model->id]) ?>" style="display:inline" onsubmit="return confirm('Revoke the trigger token? Any existing integrations will stop working.')">
                        <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Revoke Token</button>
                    </form>
                <?php else : ?>
                    <form method="post" action="<?= \yii\helpers\Url::to(['generate-trigger-token', 'id' => $model->id]) ?>" style="display:inline">
                        <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                        <button type="submit" class="btn btn-sm btn-outline-primary">Generate Token</button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($model->trigger_token) : ?>
                    <?php
                    $runsAs = $triggerUser?->username ?? ('#' . $model->getTriggerUserId());
                    $runsAsReason = $model->trigger_token_created_by === null
                        ? 'the workflow\'s creator, because this token was generated before Ansilume recorded who generated a token'
                        : 'who generated this token';
                    ?>
                    <p class="small mb-3" id="wf-trigger-runs-as">
                        Launches run as <strong><?= Html::encode($runsAs) ?></strong>
                        <?php if ($triggerUser !== null && !$triggerUser->isActive()) : ?>
                            <span class="badge text-bg-warning">disabled</span>
                        <?php endif; ?>
                        (<?= Html::encode($runsAsReason) ?>).
                        A launch is refused unless this user is active and may launch the job template of every step.
                    </p>
                    <?= $this->render('/trigger/_usage', [
                        'rawToken' => $freshToken,
                        'endpoint' => '/trigger/fire-workflow',
                        'idPrefix' => 'wf-',
                        'bodyExample' => '{"extra_vars":{"env":"prod"}}',
                        // Deprecated: the token in the query only. The path /trigger/<token>
                        // reaches job templates, so a workflow token there is a 404.
                        'deprecatedUrlForms' => ['?token=…'],
                    ]) ?>
                    <p class="mt-2 mb-0 small text-muted" id="wf-trigger-extra-vars-note">
                        The body's <code>extra_vars</code> reach every job step of the run: they win over a job template's own
                        extra vars, and a step's variable mapping wins over them.
                    </p>
                <?php else : ?>
                    <p class="mb-0 text-muted">No trigger token configured. Generate one to allow external systems to launch this workflow via HTTP POST.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
