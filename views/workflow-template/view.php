<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\WorkflowTemplate $model */
/** @var bool $canOperate operator access to the project of every job step */
/** @var bool $canChange the user may change the workflow: workflow-template.update and $canOperate */
/** @var bool $canChangeSteps $canChange, and neither a gated step nor an active run locks the steps */
/** @var array<int, string> $jobTemplateOptions job templates the user may operate; without an approval rule unless $mayAssignRules */
/** @var array<int, string> $approvalRuleOptions offered only to users who may assign approval rules */
/** @var bool $mayAssignRules the user may add approval steps and gated job steps (approval-rule.update) */
/** @var string|null $stepsLock why the user may not change the steps (an approval step or a gated job step); null when they may */
/** @var list<int> $activeRunIds the oldest active runs, which lock the steps (at most WorkflowStepChangeGuard::RUN_LIST_LIMIT) */
/** @var list<app\models\WorkflowJob> $activeRuns these runs, for a user who may see runs (workflow.view); none otherwise */
/** @var array<int, app\services\runstate\RunState> $runStates what each active run is doing, by run ID, for a user who may see runs */
/** @var int $activeRunCount how many runs are active */
/** @var app\models\User|null $triggerUser whom the inbound trigger runs as; null without a token */
/** @var app\services\WorkflowStepsTable $stepsTable the steps table */
/** @var app\services\WorkflowStepFormView|null $stepForm the Add Step form; null when the user may not change the steps */
// The parameters are WorkflowTemplatePage::viewParams().

use app\helpers\ConfirmHelper;
use app\models\WorkflowStep;
use app\services\WorkflowTemplatePage;
use yii\helpers\Html;

$this->title = $model->name;
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
            $deleteQuestion = $activeRunCount === 0
                ? 'Delete this workflow template?'
                : sprintf('Delete this workflow template? Its %d running run(s) will be canceled.', $activeRunCount);
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
        <?php if ($canChange && $activeRunCount > 0) : ?>
            <?= $this->render('_runs-active', [
                'id' => 'wf-active-runs-notice',
                'runIds' => $activeRunIds,
                'runCount' => $activeRunCount,
            ]) ?>
        <?php endif; ?>

        <?= $this->render('_active-runs', [
            'model' => $model,
            'activeRuns' => $activeRuns,
            'runStates' => $runStates,
            'activeRunCount' => $activeRunCount,
        ]) ?>

        <?= $this->render('_steps', [
            'model' => $model,
            'stepsTable' => $stepsTable,
            'canChangeSteps' => $canChangeSteps,
        ]) ?>

        <?php if ($stepForm !== null) : ?>
            <?= $this->render('_step-form', [
                'form' => $stepForm,
                'model' => new WorkflowStep(),
                'jobTemplateOptions' => $jobTemplateOptions,
                'approvalRuleOptions' => $approvalRuleOptions,
                'mayAssignRules' => $mayAssignRules,
                'action' => ['add-step', 'id' => $model->id],
                'error' => null,
            ]) ?>
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
