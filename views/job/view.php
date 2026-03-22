<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Job $job */
/** @var app\models\JobLog[] $logs */
/** @var app\models\JobTask[] $tasks */

use app\models\Job;
use app\models\JobLog;
use app\models\JobTask;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Job #' . $job->id;
$isLive = !$job->isFinished();
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><?= Html::a('Jobs', ['index']) ?></li>
        <li class="breadcrumb-item active">Job #<?= $job->id ?></li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h2 class="mb-0">
            Job #<?= $job->id ?>
            <span class="badge text-bg-<?= Job::statusCssClass($job->status) ?> ms-2 fs-6" id="status-badge">
                <?= Html::encode(Job::statusLabel($job->status)) ?>
            </span>
        </h2>
        <div class="text-muted small mt-1">
            <?= Html::encode($job->jobTemplate->name ?? '—') ?> &mdash;
            launched by <?= Html::encode($job->launcher->username ?? '—') ?>
            <?= $job->started_at ? ' at ' . date('Y-m-d H:i:s', $job->started_at) : '' ?>
        </div>
    </div>
    <div>
        <?php if ($job->isCancelable() && \Yii::$app->user->can('job.cancel')): ?>
            <?= Html::a('Cancel', ['cancel', 'id' => $job->id], [
                'class' => 'btn btn-outline-danger',
                'data'  => ['method' => 'post', 'confirm' => 'Cancel this job?'],
            ]) ?>
        <?php endif; ?>
        <?php if ($job->isFinished() && $job->jobTemplate && \Yii::$app->user->can('job.launch')): ?>
            <?= Html::a('Re-launch', ['relaunch', 'id' => $job->id], [
                'class' => 'btn btn-outline-success ms-1',
                'data'  => ['method' => 'post', 'confirm' => 'Re-launch this job with the same parameters?'],
            ]) ?>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Details</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5">Template</dt>
                    <dd class="col-7">
                        <?php if ($job->jobTemplate): ?>
                            <?= Html::a(Html::encode($job->jobTemplate->name), ['/job-template/view', 'id' => $job->job_template_id]) ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </dd>
                    <dt class="col-5">Status</dt>
                    <dd class="col-7" id="detail-status"><?= Html::encode(Job::statusLabel($job->status)) ?></dd>
                    <dt class="col-5">Queued</dt>
                    <dd class="col-7"><?= $job->queued_at ? date('Y-m-d H:i:s', $job->queued_at) : '—' ?></dd>
                    <dt class="col-5">Started</dt>
                    <dd class="col-7"><?= $job->started_at ? date('Y-m-d H:i:s', $job->started_at) : '—' ?></dd>
                    <dt class="col-5">Finished</dt>
                    <dd class="col-7" id="detail-finished"><?= $job->finished_at ? date('Y-m-d H:i:s', $job->finished_at) : '—' ?></dd>
                    <dt class="col-5">Exit code</dt>
                    <dd class="col-7" id="detail-exit-code"><?= $job->exit_code !== null ? $job->exit_code : '—' ?></dd>
                    <dt class="col-5">Worker</dt>
                    <dd class="col-7"><code><?= $job->worker_id ? Html::encode($job->worker_id) : '—' ?></code></dd>
                    <?php if ($job->limit): ?>
                        <dt class="col-5">Limit</dt>
                        <dd class="col-7"><code><?= Html::encode($job->limit) ?></code></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>

    <?php if ($job->extra_vars): ?>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Extra Vars (launch-time)</div>
            <div class="card-body p-0">
                <pre class="job-log m-0" style="max-height:150px;overflow-y:auto;"><?= Html::encode($job->extra_vars) ?></pre>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($tasks)): ?>
<?php
$counts = ['ok' => 0, 'changed' => 0, 'failed' => 0, 'skipped' => 0, 'unreachable' => 0];
foreach ($tasks as $t) { $counts[$t->status] = ($counts[$t->status] ?? 0) + 1; }
?>
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Tasks (<?= count($tasks) ?>)</span>
        <span class="d-flex gap-2">
            <?php foreach ($counts as $s => $n): if ($n === 0) continue; ?>
                <span class="badge text-bg-<?= JobTask::statusCssClass($s) ?>"><?= $n ?> <?= $s ?></span>
            <?php endforeach; ?>
        </span>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0 small">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Task</th>
                    <th>Action</th>
                    <th>Host</th>
                    <th>Status</th>
                    <th class="text-end">Duration</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tasks as $t): ?>
                <tr>
                    <td class="text-muted"><?= $t->sequence + 1 ?></td>
                    <td><?= Html::encode($t->task_name) ?></td>
                    <td><code><?= Html::encode($t->task_action) ?></code></td>
                    <td><?= Html::encode($t->host) ?></td>
                    <td>
                        <span class="badge text-bg-<?= JobTask::statusCssClass($t->status) ?>">
                            <?= $t->status ?>
                        </span>
                    </td>
                    <td class="text-end text-nowrap">
                        <?php
                        $ms = $t->duration_ms;
                        echo $ms >= 1000
                            ? number_format($ms / 1000, 2) . ' s'
                            : $ms . ' ms';
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Output</span>
        <?php if ($isLive): ?>
            <span class="badge text-bg-primary" id="live-indicator">Live</span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <pre class="job-log m-0" id="job-log" style="min-height:200px;max-height:70vh;overflow-y:auto;"></pre>
    </div>
</div>

<script src="/js/ansi_up.min.js"></script>
<script>
(function () {
    var au     = new AnsiUp();
    au.use_classes = false;
    var logEl  = document.getElementById('job-log');

    function appendAnsi(text) {
        var span = document.createElement('span');
        span.innerHTML = au.ansi_to_html(text);
        logEl.appendChild(span);
    }

    <?php foreach ($logs as $logEntry): ?>
    appendAnsi(<?= json_encode($logEntry->content) ?>);
    <?php endforeach; ?>

    if (logEl.scrollHeight > logEl.clientHeight) {
        logEl.scrollTop = logEl.scrollHeight;
    }
})();
</script>

<?php if ($isLive): ?>
<script>
(function () {
    var badgeEl   = document.getElementById('status-badge');
    var liveEl    = document.getElementById('live-indicator');
    var lastSeq   = <?= empty($logs) ? -1 : end($logs)->sequence ?>;
    var pollUrl   = <?= json_encode(Url::to(['/job/log-poll', 'id' => $job->id])) ?>;
    var statusClasses = {
        pending: 'secondary', queued: 'secondary', running: 'primary',
        succeeded: 'success', failed: 'danger', canceled: 'warning'
    };

    function poll() {
        fetch(pollUrl + '&after=' + lastSeq)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                data.chunks.forEach(function (chunk) {
                    appendAnsi(chunk.content);
                    lastSeq = chunk.sequence;
                });
                if (data.chunks.length) {
                    logEl.scrollTop = logEl.scrollHeight;
                }
                badgeEl.textContent = data.status.charAt(0).toUpperCase() + data.status.slice(1);
                badgeEl.className   = 'badge text-bg-' + (statusClasses[data.status] || 'secondary') + ' ms-2 fs-6';

                if (data.finished) {
                    if (liveEl) liveEl.remove();
                    return;
                }
                setTimeout(poll, 2500);
            })
            .catch(function () { setTimeout(poll, 5000); });
    }

    setTimeout(poll, 1500);
})();
</script>
<?php endif; ?>
