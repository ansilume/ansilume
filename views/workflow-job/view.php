<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\WorkflowJob $model */

use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use yii\helpers\Html;

$this->title = 'Workflow Job #' . $model->id;
$steps = $model->stepExecutions;
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><?= Html::encode($this->title) ?></h2>
    <div>
        <?php
        // Check if there is a currently running pause step
        $hasPausedStep = false;
        if (!$model->isFinished()) {
            foreach ($steps as $wjs) {
                if (
                    $wjs->status === WorkflowJobStep::STATUS_RUNNING
                    && $wjs->workflowStep?->step_type === WorkflowStep::TYPE_PAUSE
                ) {
                    $hasPausedStep = true;
                    break;
                }
            }
        }
        ?>
        <?php if ($hasPausedStep && Yii::$app->user->can('workflow.launch')) : ?>
            <form action="<?= \yii\helpers\Url::to(['resume', 'id' => $model->id]) ?>" method="post" style="display:inline"
                  onsubmit="return confirm('Resume this workflow?')">
                <input type="hidden" name="<?= Yii::$app->request->csrfParam ?>" value="<?= Yii::$app->request->csrfToken ?>">
                <button type="submit" class="btn btn-success btn-sm">Resume</button>
            </form>
        <?php endif; ?>
        <?php if (!$model->isFinished() && Yii::$app->user->can('workflow.cancel')) : ?>
            <form action="<?= \yii\helpers\Url::to(['cancel', 'id' => $model->id]) ?>" method="post" style="display:inline"
                  onsubmit="return confirm('Cancel this workflow?')">
                <input type="hidden" name="<?= Yii::$app->request->csrfParam ?>" value="<?= Yii::$app->request->csrfToken ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm ms-1">Cancel</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-lg-10">
        <table class="table table-bordered mb-4">
            <tr>
                <th style="width:180px">Status</th>
                <td>
                    <span id="wj-status" class="badge text-bg-<?= WorkflowJob::statusCssClass($model->status) ?>">
                        <?= Html::encode(WorkflowJob::statusLabel($model->status)) ?>
                    </span>
                    <?php if (!$model->isFinished()) : ?>
                        <span id="wj-live" class="badge text-bg-primary ms-2">Live</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Workflow</th>
                <td><?= Html::a(Html::encode($model->workflowTemplate?->name ?? '—'), ['/workflow-template/view', 'id' => $model->workflow_template_id]) ?></td>
            </tr>
            <tr>
                <th>Launched By</th>
                <td><?= Html::encode($model->launcher?->username ?? '—') ?></td>
            </tr>
            <tr>
                <th>Started</th>
                <td><?= $model->started_at ? Html::encode(date('Y-m-d H:i:s', (int)$model->started_at)) : '—' ?></td>
            </tr>
            <tr>
                <th>Finished</th>
                <td id="wj-finished"><?= $model->finished_at ? Html::encode(date('Y-m-d H:i:s', (int)$model->finished_at)) : '—' ?></td>
            </tr>
        </table>

        <h4>Steps</h4>
        <?php if (empty($steps)) : ?>
            <div class="text-muted">No steps executed.</div>
        <?php else : ?>
            <table class="table table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Step</th>
                        <th>Status</th>
                        <th>Child Job</th>
                        <th>Started</th>
                        <th>Duration</th>
                        <th>Finished</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $renderDuration = static function (WorkflowJobStep $wjs): string {
                        if ($wjs->started_at === null) {
                            return '—';
                        }
                        $end = $wjs->finished_at !== null ? (int)$wjs->finished_at : time();
                        $seconds = max(0, $end - (int)$wjs->started_at);
                        $minutes = intdiv($seconds, 60);
                        $remaining = $seconds % 60;
                        $core = $minutes > 0 ? sprintf('%dm %02ds', $minutes, $remaining) : sprintf('%ds', $remaining);
                        return $wjs->finished_at !== null ? $core : 'running ' . $core;
                    };
    foreach ($steps as $i => $wjs) :
        $isCurrent = $model->current_step_id === $wjs->workflow_step_id;
        $cssClass = WorkflowJobStep::statusCssClass($wjs->status);
        $label = WorkflowJobStep::statusLabel($wjs->status);
        ?>
                        <tr data-wjs-step-id="<?= (int)$wjs->workflow_step_id // xss-ok: int?>" class="<?= Html::encode($isCurrent ? 'table-active' : '') ?>">
                            <td><?= Html::encode((string)($i + 1)) ?></td>
                            <td><?= Html::encode($wjs->workflowStep?->name ?? '—') ?></td>
                            <td data-wjs-status-cell>
                                <span class="badge text-bg-<?= Html::encode($cssClass) ?>">
                    <?= Html::encode($label) ?>
                                </span>
                            </td>
                            <td data-wjs-job-cell>
                <?php if ($wjs->job_id) : ?>
                                    <?= Html::a(
                                        '#' . Html::encode((string)$wjs->job_id),
                                        ['/job/view', 'id' => $wjs->job_id]
                                    ) ?>
                <?php else : ?>
                                    —
                <?php endif; ?>
                            </td>
                            <td data-wjs-started-cell>
                                <?= $wjs->started_at
                                ? Html::encode(date('H:i:s', (int)$wjs->started_at))
                                : '—' ?>
                            </td>
                            <td data-wjs-duration-cell><?= Html::encode($renderDuration($wjs)) ?></td>
                            <td data-wjs-finished-cell>
                                <?= $wjs->finished_at
                                ? Html::encode(date('H:i:s', (int)$wjs->finished_at))
                                : '—' ?>
                            </td>
                        </tr>
    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php if (!$model->isFinished()) : ?>
<script>
(function () {
    // Poll /workflow-job/status every 3s while the workflow is running,
    // updating the overall status badge, finished timestamp, and per-step
    // status / started / finished / job-link / current-row highlight in
    // place. Stops polling when the status becomes terminal.
    var pollUrl = <?= json_encode(\yii\helpers\Url::to(['status', 'id' => $model->id])) ?>;
    var jobViewUrl = <?= json_encode(\yii\helpers\Url::to(['/job/view', 'id' => 'JOBID'])) ?>;
    var statusEl = document.getElementById('wj-status');
    var liveEl = document.getElementById('wj-live');
    var finishedEl = document.getElementById('wj-finished');

    function updateRow(step) {
        var row = document.querySelector('tr[data-wjs-step-id="' + step.workflow_step_id + '"]');
        if (!row) { return; }
        row.classList.toggle('table-active', !!step.is_current);

        var badge = row.querySelector('[data-wjs-status-cell] .badge');
        if (badge) {
            badge.textContent = step.status_label;
            badge.className = 'badge text-bg-' + step.status_css;
        }

        var jobCell = row.querySelector('[data-wjs-job-cell]');
        if (jobCell) {
            if (step.job_id !== null) {
                var href = jobViewUrl.replace('JOBID', step.job_id);
                jobCell.innerHTML = '';
                var a = document.createElement('a');
                a.href = href;
                a.textContent = '#' + step.job_id;
                jobCell.appendChild(a);
            } else {
                jobCell.textContent = '—';
            }
        }

        var startedCell = row.querySelector('[data-wjs-started-cell]');
        if (startedCell) {
            startedCell.textContent = step.started_label || '—';
        }
        var durationCell = row.querySelector('[data-wjs-duration-cell]');
        if (durationCell) {
            durationCell.textContent = step.duration_label || '—';
        }
        var finishedCell = row.querySelector('[data-wjs-finished-cell]');
        if (finishedCell) {
            finishedCell.textContent = step.finished_label || '—';
        }
    }

    function tick() {
        fetch(pollUrl, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) { return; }

                if (statusEl) {
                    statusEl.textContent = data.status_label;
                    statusEl.className = 'badge text-bg-' + data.status_css;
                }
                if (finishedEl && data.finished_label) {
                    finishedEl.textContent = data.finished_label;
                }

                (data.steps || []).forEach(updateRow);

                if (data.is_finished) {
                    // Stop polling and drop the live badge — the page will
                    // now show the final state; operators can refresh to
                    // see resume buttons / flash messages rendered server-
                    // side.
                    if (liveEl) { liveEl.remove(); }
                    clearInterval(timer);
                }
            })
            .catch(function () { /* swallow transient network errors */ });
    }

    var timer = setInterval(tick, 3000);
    // One immediate tick so the first refresh lands faster than 3s.
    tick();
})();
</script>
<?php endif; ?>
