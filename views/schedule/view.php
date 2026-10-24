<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Schedule $model */
/** @var string|null $approvalNote the approval each run waits for (JobTemplateGate::scheduleNote()); null when none, or the template is gone */

use app\components\SurveySecrets;
use app\helpers\ConfirmHelper;
use app\models\Schedule;
use app\services\ScheduleRunAs;
use app\services\ScheduleSecrets;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;

$this->title = Html::encode($model->name);
// Why a save refuses the stored cron expression, which an older version saved.
$cronProblem = $model->cronExpressionProblem();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><?= Html::encode($model->name) ?></h2>
    <div>
        <?php if (\Yii::$app->user?->can('job.launch')) : ?>
            <?= Html::a('Edit', ['update', 'id' => $model->id], ['class' => 'btn btn-outline-secondary']) ?>
            <form method="post" action="<?= Url::to(['toggle', 'id' => $model->id]) ?>" style="display:inline"
                  onsubmit="<?= ConfirmHelper::attribute($model->enabled ? 'Disable this schedule?' : 'Enable this schedule?') ?>">
                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                <button type="submit" class="btn btn-outline-<?= $model->enabled ? 'warning' : 'success' ?> ms-1">
                    <?= $model->enabled ? 'Disable' : 'Enable' ?>
                </button>
            </form>
            <form method="post" action="<?= Url::to(['delete', 'id' => $model->id]) ?>" style="display:inline"
                  onsubmit="return confirm('Delete this schedule permanently?')">
                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                <button type="submit" class="btn btn-outline-danger ms-1">Delete</button>
            </form>
        <?php endif; ?>
        <?= Html::a('Back', ['index'], ['class' => 'btn btn-outline-secondary ms-1']) ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Schedule details</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Status</dt>
                    <dd class="col-sm-8">
                        <?= $model->enabled
                            ? '<span class="badge text-bg-success">Enabled</span>'
                            : '<span class="badge text-bg-secondary">Disabled</span>' ?>
                    </dd>

                    <dt class="col-sm-4">Template</dt>
                    <dd class="col-sm-8">
                        <?= $model->jobTemplate // xss-ok: Html::a encodes name; fallback is hardcoded HTML
                            ? Html::a(Html::encode($model->jobTemplate->name), ['/job-template/view', 'id' => $model->job_template_id])
                            : '<span class="text-danger">Missing</span>' ?>
                    </dd>

                    <dt class="col-sm-4">Cron expression</dt>
                    <dd class="col-sm-8">
                        <code id="cron-expr"><?= Html::encode($model->cron_expression) ?></code>
                        <?= Html::tag('span', '', ['id' => 'cron-human', 'class' => 'text-muted ms-2 small', 'data-refused' => $cronProblem === null ? null : '1']) ?>
                        <?php if ($cronProblem !== null) : ?>
                            <div class="alert alert-warning small mt-2 mb-0" id="schedule-cron-problem" data-testid="schedule-cron-problem">
                                <div><?= Html::encode(Schedule::CRON_REFUSED_NOTE) ?></div>
                                <div class="mt-1" data-testid="schedule-cron-problem-reason"><?= Html::encode($cronProblem) ?></div>
                            </div>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-4">Timezone</dt>
                    <dd class="col-sm-8"><?= Html::encode($model->timezone) ?></dd>

                    <dt class="col-sm-4">Next run</dt>
                    <dd class="col-sm-8">
                        <?= $model->next_run_at ? date('Y-m-d H:i:s', $model->next_run_at) . ' UTC' : '—' ?>
                    </dd>

                    <dt class="col-sm-4">Last run</dt>
                    <dd class="col-sm-8">
                        <?= $model->last_run_at ? date('Y-m-d H:i:s', $model->last_run_at) . ' UTC' : 'Never' ?>
                    </dd>

                    <dt class="col-sm-4">Runs as</dt>
                    <dd class="col-sm-8">
                        <span id="schedule-runs-as"><?= Html::encode($model->creator->username ?? '—') ?></span>
                        <div class="small text-muted" data-testid="schedule-runs-as"><?= Html::encode(ScheduleRunAs::VIEW_NOTE) ?></div>
                    </dd>

                    <?php // isset(): a page rendered without the note (not by actionView) has none.?>
                    <?php if (isset($approvalNote)) : ?>
                        <dt class="col-sm-4">Approval</dt>
                        <dd class="col-sm-8" id="schedule-approval"><?= Html::encode($approvalNote) ?></dd>
                    <?php endif; ?>

                    <dt class="col-sm-4">Created</dt>
                    <dd class="col-sm-8"><?= date('Y-m-d H:i:s', $model->created_at) ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <script src="/js/cronstrue.min.js"></script>
    <script>
    (function () {
        var expr = document.getElementById('cron-expr');
        var human = document.getElementById('cron-human');
        if (!expr || !human || typeof cronstrue === 'undefined') return;
        // A save refuses the expression: the scheduler does not run it as the description reads.
        var prefix = human.hasAttribute('data-refused') ? <?= Json::htmlEncode(Schedule::CRON_AS_WRITTEN . ' ') ?> : '';
        try {
            human.textContent = '(' + prefix + cronstrue.toString(expr.textContent.trim(), { use24HourTimeFormat: true }) + ')';
        } catch (e) {}
    })();
    </script>

    <?php $extraVars = ScheduleSecrets::displayExtraVars($model); ?>
    <?php if ($extraVars !== null) : ?>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Extra vars override</div>
            <div class="card-body">
                <pre class="mb-0" data-testid="schedule-extra-vars"><code><?= Html::encode($extraVars) ?></code></pre>
                <div class="form-text">Password answers are stored encrypted and show as <code><?= Html::encode(SurveySecrets::PLACEHOLDER) ?></code>.</div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
