<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Job $job */
/** @var app\models\JobLog[] $logs */
/** @var app\models\JobTask[] $tasks */
/** @var app\models\JobHostSummary[] $hostSummaries */
/** @var app\models\JobArtifact[] $artifacts */

use app\models\Job;
use app\models\JobHostSummary;
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
            <form method="post" action="<?= \yii\helpers\Url::to(['cancel', 'id' => $job->id]) ?>" style="display:inline" onsubmit="return confirm('Cancel this job?')">
                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                <button type="submit" class="btn btn-outline-danger">Cancel</button>
            </form>
        <?php endif; ?>
        <?php if ($job->isFinished() && $job->jobTemplate && !$job->jobTemplate->isDeleted() && \Yii::$app->user->can('job.launch')): ?>
            <form method="post" action="<?= \yii\helpers\Url::to(['relaunch', 'id' => $job->id]) ?>" style="display:inline" onsubmit="return confirm('Re-launch this job with the same parameters?')">
                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                <button type="submit" class="btn btn-outline-success ms-1">Re-launch</button>
            </form>
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
                            <?php if ($job->jobTemplate->isDeleted()): ?>
                                <?= Html::encode($job->jobTemplate->name) ?> <span class="text-muted">(deleted)</span>
                            <?php else: ?>
                                <?= Html::a(Html::encode($job->jobTemplate->name), ['/job-template/view', 'id' => $job->job_template_id]) ?>
                            <?php endif; ?>
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
                    <dd class="col-7" id="detail-exit-code"><?= $job->exit_code !== null ? $job->exit_code : '—' // xss-ok: integer or hardcoded string ?></dd>
                    <dt class="col-5">Runner</dt>
                    <dd class="col-7">
                        <?php if ($job->runner): ?>
                            <?= Html::a(Html::encode($job->runner->name), ['/runner-group/view', 'id' => $job->runner->runner_group_id]) ?>
                            <span class="text-muted small">(<?= Html::encode($job->runner->group->name ?? '') ?>)</span>
                        <?php else: ?>
                            <code><?= $job->worker_id ? Html::encode($job->worker_id) : '—' ?></code>
                        <?php endif; ?>
                    </dd>
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

<?php if (!empty($hostSummaries)): ?>
<div class="card mb-3">
    <div class="card-header">PLAY RECAP</div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0 small" style="font-family:monospace;">
            <thead>
                <tr>
                    <th>Host</th>
                    <th class="text-center text-success">ok</th>
                    <th class="text-center text-warning">changed</th>
                    <th class="text-center text-danger">failed</th>
                    <th class="text-center text-secondary">skipped</th>
                    <th class="text-center">unreachable</th>
                    <th class="text-center text-info">rescued</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($hostSummaries as $hs): ?>
                <?php
                $rowClass = '';
                if ($hs->unreachable > 0 || $hs->failed > 0) $rowClass = 'table-danger';
                elseif ($hs->changed > 0) $rowClass = 'table-warning';
                ?>
                <tr class="<?= $rowClass // xss-ok: controller-computed CSS class ?>">
                    <td><?= Html::encode($hs->host) ?></td>
                    <td class="text-center"><?= $hs->ok // xss-ok: integer ?></td>
                    <td class="text-center"><?= $hs->changed // xss-ok: integer ?></td>
                    <td class="text-center"><?= $hs->failed // xss-ok: integer ?></td>
                    <td class="text-center"><?= $hs->skipped // xss-ok: integer ?></td>
                    <td class="text-center"><?= $hs->unreachable // xss-ok: integer ?></td>
                    <td class="text-center"><?= $hs->rescued // xss-ok: integer ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

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
                <span class="badge text-bg-<?= JobTask::statusCssClass($s) // xss-ok: hardcoded CSS class from enum ?>"><?= $n // xss-ok: integer ?> <?= Html::encode($s) ?></span>
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
                    <td class="text-muted"><?= $t->sequence + 1 // xss-ok: integer ?></td>
                    <td><?= Html::encode($t->task_name) ?></td>
                    <td><code><?= Html::encode($t->task_action) ?></code></td>
                    <td><?= Html::encode($t->host) ?></td>
                    <td>
                        <span class="badge text-bg-<?= JobTask::statusCssClass($t->status) // xss-ok: hardcoded CSS class from enum ?>">
                            <?= Html::encode($t->status) ?>
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

<?php if (!empty($artifacts)): ?>
<div class="card mb-3">
    <div class="card-header">Artifacts <span class="badge text-bg-secondary"><?= count($artifacts) ?></span></div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0 small">
            <thead>
                <tr>
                    <th>File</th>
                    <th>Type</th>
                    <th class="text-end">Size</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($artifacts as $artifact): ?>
                <tr>
                    <td><code><?= Html::encode($artifact->display_name) ?></code></td>
                    <td class="text-muted"><?= Html::encode($artifact->mime_type) ?></td>
                    <td class="text-end text-nowrap"><?php
                        $bytes = $artifact->size_bytes;
                        if ($bytes >= 1048576) {
                            echo number_format($bytes / 1048576, 1) . ' MB';
                        } elseif ($bytes >= 1024) {
                            echo number_format($bytes / 1024, 1) . ' KB';
                        } else {
                            echo $bytes . ' B'; // xss-ok: integer
                        }
                    ?></td>
                    <td class="text-end">
                        <?= Html::a('Download', ['download-artifact', 'id' => $job->id, 'artifact_id' => $artifact->id], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
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
var _au    = new AnsiUp();
_au.use_classes = false;
// Remap the dim 8-color palette entries that are unreadable on a dark
// background. Index layout: ansi_colors[0] = normal, [1] = bright.
// Normal blue (34) is rgb(0,0,187) — near-black on #1e1e1e.
_au.ansi_colors[0][0].rgb = [118, 118, 118]; // black  → visible grey
_au.ansi_colors[0][4].rgb = [77,  159, 236]; // blue   → readable blue
_au.ansi_colors[0][5].rgb = [198, 120, 221]; // magenta → softer purple
var logEl  = document.getElementById('job-log');

function appendAnsi(text) {
    var span = document.createElement('span');
    span.innerHTML = _au.ansi_to_html(text);
    logEl.appendChild(span);
}

<?php foreach ($logs as $logEntry): ?>
appendAnsi(<?= json_encode($logEntry->content) ?>);
<?php endforeach; ?>

if (logEl.scrollHeight > logEl.clientHeight) {
    logEl.scrollTop = logEl.scrollHeight;
}
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
                    // Reload to show PLAY RECAP, tasks, and artifacts
                    window.location.reload();
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
