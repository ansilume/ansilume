<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Job $job */
/** @var app\models\JobLog[] $logs */
/** @var app\models\JobTask[] $tasks */
/** @var app\models\JobHostSummary[] $hostSummaries */
/** @var app\models\JobArtifact[] $artifacts */
/** @var list<array{id: int, name: string|null, credential_type: string|null, role: string, deleted: bool}> $jobCredentials  as launched, primary first */
/** @var app\components\JobInventory $jobInventory the inventory the job was launched with, as its runner payload records it */
/** @var app\services\runstate\JobContext $context the workflow run and step the job was created for, and its approval request, as the viewer may see them */

use app\models\Job;
use app\models\JobHostSummary;
use app\models\JobLog;
use app\models\JobTask;
use app\services\runstate\Banner;
use app\services\runstate\Texts;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;

$this->title = 'Job #' . $job->id;
$isLive = !$job->isFinished();
$origin = $context->origin;
$approval = $context->approval;
// The placeholder of an approval step only holds the step's approval request and never runs.
$placeholderOrigin = $context->placeholder ? $origin : null;
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
            <?php if ($job->check_mode) : ?>
                <span class="badge text-bg-info ms-2 fs-6">Dry Run</span>
            <?php endif; ?>
        </h2>
        <div class="text-muted small mt-1">
            <?= Html::encode($placeholderOrigin === null
                ? ($job->jobTemplate->name ?? '—')
                : sprintf(Texts::PLACEHOLDER_SUBTITLE, $placeholderOrigin->stepName, $placeholderOrigin->workflowJobId)) ?> &mdash;
            launched by <?= Html::encode($job->launcher->username ?? '—') ?>
            <?= $job->started_at ? ' at ' . date('Y-m-d H:i:s', $job->started_at) : '' ?>
        </div>
    </div>
    <div>
        <?php if ($job->isCancelable() && \Yii::$app->user?->can('job.cancel')) : ?>
            <form method="post" action="<?= \yii\helpers\Url::to(['cancel', 'id' => $job->id]) ?>" style="display:inline" onsubmit="return confirm('Cancel this job?')">
                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                <button type="submit" class="btn btn-outline-danger">Cancel</button>
            </form>
        <?php endif; ?>
        <?php if ($job->isFinished() && $job->jobTemplate && !$job->jobTemplate->isDeleted() && \Yii::$app->user?->can('job.launch')) : ?>
            <form method="post" action="<?= \yii\helpers\Url::to(['relaunch', 'id' => $job->id]) ?>" style="display:inline" onsubmit="return confirm('Re-launch this job with the same parameters?')">
                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                <button type="submit" class="btn btn-outline-success ms-1">Re-launch</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($approval !== null && $job->status === Job::STATUS_PENDING_APPROVAL) : ?>
    <?php // The page's poll hides the note once the job no longer waits.?>
<div class="alert alert-info" id="job-approval-note" role="status">
    <div class="fw-semibold"><?= Html::encode(sprintf(Texts::JOB_APPROVAL_NOTE, $approval->requestId)) ?></div>
    <ul class="small mt-1 mb-0">
        <?php foreach (Banner::approvalFacts($approval, $approval->requestedAt, time()) as $fact) : ?>
        <li><?= Html::encode($fact['text']) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($approval->linkable) : ?>
        <?= Html::a(Html::encode(Banner::REVIEW_LINK), Banner::requestUrl($approval->requestId), ['id' => 'job-approval-review', 'class' => 'btn btn-sm btn-primary mt-2']) ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Details</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5">Template</dt>
                    <dd class="col-7">
                        <?php if ($job->jobTemplate) : ?>
                            <?php if ($job->jobTemplate->isDeleted()) : ?>
                                <?= Html::encode($job->jobTemplate->name) ?> <span class="text-muted">(deleted)</span>
                            <?php else : ?>
                                <?= Html::a(Html::encode($job->jobTemplate->name), ['/job-template/view', 'id' => $job->job_template_id]) ?>
                            <?php endif; ?>
                        <?php elseif ($context->placeholder) : ?>
                            <?= Html::encode(Texts::PLACEHOLDER_TEMPLATE) ?>
                        <?php else : ?>
                            —
                        <?php endif; ?>
                    </dd>
                    <dt class="col-5">Inventory</dt>
                    <dd class="col-7" id="detail-inventory"><?= $this->render('_inventory', ['inventory' => $jobInventory]) ?></dd>
                    <dt class="col-5">Credentials</dt>
                    <dd class="col-7"><?= $this->render('/credential/_credential-list', ['credentials' => $jobCredentials, 'listId' => 'job-credentials']) ?></dd>
                    <dt class="col-5">Status</dt>
                    <dd class="col-7" id="detail-status"><?= Html::encode(Job::statusLabel($job->status)) ?></dd>
                    <?= $this->render('_workflow-context', ['context' => $context]) ?>
                    <dt class="col-5">Queued</dt>
                    <dd class="col-7"><?= $job->queued_at ? date('Y-m-d H:i:s', $job->queued_at) : '—' ?></dd>
                    <dt class="col-5">Started</dt>
                    <dd class="col-7"><?= $job->started_at ? date('Y-m-d H:i:s', $job->started_at) : '—' ?></dd>
                    <dt class="col-5">Finished</dt>
                    <dd class="col-7" id="detail-finished"><?= $job->finished_at ? date('Y-m-d H:i:s', $job->finished_at) : '—' ?></dd>
                    <dt class="col-5">Exit code</dt>
                    <dd class="col-7" id="detail-exit-code"><?= $job->exit_code !== null ? $job->exit_code : '—' // xss-ok: integer or hardcoded string?></dd>
                    <dt class="col-5">Runner</dt>
                    <dd class="col-7">
                        <?php if ($job->runner) : ?>
                            <?= Html::a(Html::encode($job->runner->name), ['/runner-group/view', 'id' => $job->runner->runner_group_id]) ?>
                            <span class="text-muted small">(<?= Html::encode($job->runner->group->name ?? '') ?>)</span>
                        <?php else : ?>
                            <code><?= $job->worker_id ? Html::encode($job->worker_id) : '—' ?></code>
                        <?php endif; ?>
                    </dd>
                    <?php if ($job->limit) : ?>
                        <dt class="col-5">Limit</dt>
                        <dd class="col-7"><code><?= Html::encode($job->limit) ?></code></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-md-6" id="exec-cmd-target">
        <?php if ($job->execution_command) : ?>
        <div class="card <?= $job->extra_vars ? 'mb-3' : '' // xss-ok: hardcoded CSS class?>">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Execution Command</span>
                <button type="button" id="btn-copy-cmd" class="btn btn-sm btn-outline-secondary"
                        onclick="copyToClipboard(document.getElementById('exec-cmd-display').textContent).then(function(){
                            var btn = document.getElementById('btn-copy-cmd');
                            btn.textContent = 'Copied!';
                            btn.classList.replace('btn-outline-secondary','btn-success');
                            setTimeout(function(){ btn.textContent = 'Copy'; btn.classList.replace('btn-success','btn-outline-secondary'); }, 2000);
                        }).catch(function(){ alert('Copy failed — please copy manually.'); })">Copy</button>
            </div>
            <div class="card-body p-0">
                <pre class="job-log m-0" id="exec-cmd-display" style="max-height:100px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;"><?= Html::encode($job->execution_command) ?></pre>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($job->extra_vars) : ?>
        <div class="card">
            <div class="card-header">Extra Vars (launch-time)</div>
            <div class="card-body p-0">
                <pre class="job-log m-0" style="max-height:150px;overflow-y:auto;"><?= Html::encode($job->extra_vars) ?></pre>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($hostSummaries)) : ?>
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
            <?php foreach ($hostSummaries as $hs) : ?>
                <?php
                $rowClass = '';
                if ($hs->unreachable > 0 || $hs->failed > 0) {
                    $rowClass = 'table-danger';
                } elseif ($hs->changed > 0) {
                    $rowClass = 'table-warning';
                }
                ?>
                <tr class="<?= $rowClass // xss-ok: controller-computed CSS class?>">
                    <td><?= Html::encode($hs->host) ?></td>
                    <td class="text-center"><?= $hs->ok // xss-ok: integer?></td>
                    <td class="text-center"><?= $hs->changed // xss-ok: integer?></td>
                    <td class="text-center"><?= $hs->failed // xss-ok: integer?></td>
                    <td class="text-center"><?= $hs->skipped // xss-ok: integer?></td>
                    <td class="text-center"><?= $hs->unreachable // xss-ok: integer?></td>
                    <td class="text-center"><?= $hs->rescued // xss-ok: integer?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($tasks)) : ?>
    <?php
    $counts = ['ok' => 0, 'changed' => 0, 'failed' => 0, 'skipped' => 0, 'unreachable' => 0];
    foreach ($tasks as $t) {
        $counts[$t->status] = ($counts[$t->status] ?? 0) + 1;
    }
    ?>
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Tasks (<?= count($tasks) ?>)</span>
        <span class="d-flex gap-2">
            <?php foreach ($counts as $s => $n) :
                if ($n === 0) {
                    continue;
                } ?>
                <span class="badge text-bg-<?= JobTask::statusCssClass($s) // xss-ok: hardcoded CSS class from enum?>"><?= $n // xss-ok: integer?> <?= Html::encode($s) ?></span>
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
            <?php foreach ($tasks as $t) : ?>
                <tr>
                    <td class="text-muted"><?= $t->sequence + 1 // xss-ok: integer?></td>
                    <td><?= Html::encode($t->task_name) ?></td>
                    <td><code><?= Html::encode($t->task_action) ?></code></td>
                    <td><?= Html::encode($t->host) ?></td>
                    <td>
                        <span class="badge text-bg-<?= JobTask::statusCssClass($t->status) // xss-ok: hardcoded CSS class from enum?>">
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

<?php if (!empty($artifacts)) :
    /** @var \app\services\ArtifactService $artifactService */
    $artifactService = \Yii::$app->get('artifactService'); ?>
    <div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Artifacts <span class="badge text-bg-secondary"><?= count($artifacts) ?></span></span>
        <?php if (count($artifacts) > 1) : ?>
            <?= Html::a('Download All (zip)', ['download-all-artifacts', 'id' => $job->id], ['class' => 'btn btn-sm btn-outline-primary']) ?>
        <?php endif; ?>
    </div>
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
            <?php foreach ($artifacts as $artifact) : ?>
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
                    <td class="text-end text-nowrap">
                        <?php
                        $isImage = $artifactService->isImageType($artifact->mime_type);
                        $isText = $artifactService->isPreviewable($artifact->mime_type);
                        $isFrame = $artifactService->isInlineFrameType($artifact->mime_type);
                        if ($isImage || $isText || $isFrame) :
                            if ($isImage) {
                                $previewUrl = Url::to(['download-artifact', 'id' => $job->id, 'artifact_id' => $artifact->id, 'inline' => 1]);
                                $previewKind = 'image';
                            } elseif ($isFrame) {
                                $previewUrl = Url::to(['download-artifact', 'id' => $job->id, 'artifact_id' => $artifact->id, 'inline' => 1]);
                                $previewKind = 'frame';
                            } else {
                                $previewUrl = Url::to(['artifact-content', 'id' => $job->id, 'artifact_id' => $artifact->id]);
                                $previewKind = 'text';
                            }
                            ?>
                            <button type="button"
                                    class="btn btn-sm btn-outline-info artifact-preview-btn"
                                    data-url="<?= Html::encode($previewUrl) ?>"
                                    data-artifact-id="<?= $artifact->id ?>"
                                    data-preview-kind="<?= Html::encode($previewKind) ?>">Preview</button>
                        <?php endif; ?>
                        <?= Html::a('Download', ['download-artifact', 'id' => $job->id, 'artifact_id' => $artifact->id], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
                    </td>
                </tr>
                <tr class="artifact-preview-row d-none" id="artifact-preview-<?= $artifact->id ?>">
                    <td colspan="4" class="p-0 artifact-preview-cell">
                        <pre class="mb-0 p-2 bg-dark text-light artifact-preview-text d-none" style="max-height:400px;overflow:auto;font-size:0.8rem"><code class="artifact-preview-content"></code></pre>
                        <div class="p-2 bg-dark text-center artifact-preview-image d-none">
                            <img alt="" loading="lazy" style="max-width:100%;max-height:600px;">
                        </div>
                        <div class="p-2 bg-dark artifact-preview-frame d-none">
                            <iframe sandbox="" style="width:100%;height:600px;border:0;background:white;" loading="lazy"></iframe>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($placeholderOrigin !== null) : ?>
    <?php // A placeholder never runs, so it has no output: the note says what it is instead.?>
<div class="alert alert-secondary" id="job-placeholder-note">
    <?= Html::encode(sprintf(Texts::PLACEHOLDER_NOTE, $placeholderOrigin->stepName, $placeholderOrigin->workflowJobId)) ?>
</div>
<?php else : ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Output</span>
        <?php if ($isLive) : ?>
            <span class="badge text-bg-primary" id="live-indicator">Live</span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <pre class="job-log m-0" id="job-log" style="min-height:200px;max-height:70vh;overflow-y:auto;"></pre>
    </div>
</div>
<?php endif; ?>

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
// The page of a placeholder job has no Output card, and so no log element.
var logEl  = document.getElementById('job-log');

function appendAnsi(text) {
    if (!logEl) {
        return;
    }
    var span = document.createElement('span');
    span.innerHTML = _au.ansi_to_html(text);
    logEl.appendChild(span);
}

<?php foreach ($logs as $logEntry) : ?>
appendAnsi(<?= Json::htmlEncode($logEntry->content) ?>);
<?php endforeach; ?>

if (logEl && logEl.scrollHeight > logEl.clientHeight) {
    logEl.scrollTop = logEl.scrollHeight;
}
</script>

<?php if ($isLive) : ?>
    <?php
    // The poll shows a status as the page renders it: its label and colour
    // for every job status, from the Job model.
    $statusLabels = [];
    $statusClasses = [];
    foreach (Job::statuses() as $status) {
        $statusLabels[$status] = Job::statusLabel($status);
        $statusClasses[$status] = Job::statusCssClass($status);
    }
    ?>
<script>
(function () {
    var badgeEl   = document.getElementById('status-badge');
    var detailStatusEl = document.getElementById('detail-status');
    var approvalNoteEl = document.getElementById('job-approval-note');
    var awaitingApproval = <?= Json::htmlEncode(Job::STATUS_PENDING_APPROVAL) ?>;
    var liveEl    = document.getElementById('live-indicator');
    var lastSeq   = <?= empty($logs) ? -1 : end($logs)->sequence ?>;
    var pollUrl   = <?= Json::htmlEncode(Url::to(['/job/log-poll', 'id' => $job->id])) ?>;
    var statusLabels = <?= Json::htmlEncode($statusLabels) ?>;
    var statusClasses = <?= Json::htmlEncode($statusClasses) ?>;
    // A refused poll (401 or 403: signed out, or no access any more; 404:
    // the job is gone) would be refused again: polling stops, the Live
    // badge goes, and a note under the title asks for a reload.
    var stopStatuses = [401, 403, 404];
    var stoppedNote = <?= Json::htmlEncode(Texts::LIVE_STOPPED_JOB) ?>;
    // The pace of the polls (pace()). The first poll comes 1.5 seconds after
    // the page loaded. While the job runs, the next poll comes 2.5 seconds
    // after each answer, so the log follows the run. While the job waits
    // (for an approval, to be queued or for a runner: the status of the
    // answer), an answer the same as the one before it (the same status and
    // command, no new output) doubles the wait before the next poll, up to
    // 30 seconds, and an answer that changed something brings it back to
    // 2.5 seconds. A poll that failed (an error answer other than a refusal,
    // a network error, an answer that is no JSON) doubles the wait as well,
    // up to 30 seconds, as on a workflow run's page
    // (web/js/workflow-run-live.js). While the page is hidden (another tab,
    // a minimized window) it does not poll; shown again, it polls at once
    // and starts again from 2.5 seconds.
    var FIRST_POLL = 1500;
    var INTERVAL = 2500;
    var MAX_INTERVAL = 30000;
    var waitingStatuses = <?= Json::htmlEncode(Job::waitingStatuses()) ?>;
    // Whether polling stopped for good, the next poll's timer, whether a
    // poll is on its way, the wait before the next poll, and the last answer.
    var stopped = false;
    var timer = null;
    var polling = false;
    var interval = INTERVAL;
    var lastAnswer = null;

    function stop() {
        stopped = true;
        if (liveEl && liveEl.parentNode) {
            liveEl.parentNode.removeChild(liveEl);
        }
        var heading = badgeEl ? badgeEl.parentNode : null;
        if (heading && heading.parentNode) {
            var note = document.createElement('div');
            note.id = 'live-stopped';
            note.className = 'small text-body-secondary mt-1';
            note.setAttribute('role', 'status');
            note.textContent = stoppedNote;
            heading.parentNode.appendChild(note);
        }
    }

    // One poll, unless polling stopped or the page is hidden (shown() polls once it is shown again).
    function poll() {
        timer = null;
        if (stopped || document.hidden) {
            return;
        }
        polling = true;
        // Asked for JSON, a signed-out poll is answered 403, not sent to the
        // login page, which would keep the poll as the address to return to.
        fetch(pollUrl + '&after=' + lastSeq, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) {
                if (stopStatuses.indexOf(r.status) !== -1) {
                    stop();
                    return null;
                }
                if (!r.ok) {
                    throw new Error('poll failed: ' + r.status);
                }
                return r.json();
            })
            .then(function (data) {
                if (data !== null) {
                    show(data);
                    pace(data);
                }
            })
            .catch(function () {
                // A failed poll counts as an answer that changed nothing.
                pace(null);
            })
            .then(function () {
                polling = false;
                schedule();
            });
    }

    // The next poll, after the current wait: none once polling stopped, and none while the page is hidden.
    function schedule() {
        if (stopped || document.hidden || timer !== null) {
            return;
        }
        timer = setTimeout(poll, interval);
    }

    // The wait after an answer (data), or after a failed poll (null): 2.5
    // seconds while the job runs or after a change, otherwise twice the last
    // wait, at most MAX_INTERVAL.
    function pace(data) {
        var answer = data === null ? null : JSON.stringify([data.status, data.execution_command, data.chunks]);
        if (answer !== null && (answer !== lastAnswer || waitingStatuses.indexOf(data.status) === -1)) {
            lastAnswer = answer;
            interval = INTERVAL;
            return;
        }
        interval = Math.min(interval * 2, MAX_INTERVAL);
    }

    // The page is shown again (hidden, it does nothing): a poll at once, and from there as after a change.
    function shown() {
        if (stopped || document.hidden) {
            return;
        }
        interval = INTERVAL;
        lastAnswer = null;
        if (polling) {
            // The poll on its way schedules the next one.
            return;
        }
        if (timer !== null) {
            clearTimeout(timer);
        }
        poll();
    }

    // The answer on the page: the new output, the status and the execution command.
    function show(data) {
        data.chunks.forEach(function (chunk) {
            appendAnsi(chunk.content);
            lastSeq = chunk.sequence;
        });
        if (data.chunks.length && logEl) {
            logEl.scrollTop = logEl.scrollHeight;
        }
        badgeEl.textContent = statusLabels[data.status] || data.status;
        badgeEl.className   = 'badge text-bg-' + (statusClasses[data.status] || 'secondary') + ' ms-2 fs-6';
        // The Details card shows the same status as the badge.
        if (detailStatusEl) {
            detailStatusEl.textContent = badgeEl.textContent;
        }
        // Approved, rejected or canceled: the job no longer waits for its request.
        if (approvalNoteEl && data.status !== awaitingApproval) {
            approvalNoteEl.hidden = true;
        }

        if (data.execution_command && !document.getElementById('exec-cmd-display')) {
            var card = document.createElement('div');
            card.className = 'card mb-3';
            card.innerHTML = '<div class="card-header d-flex justify-content-between align-items-center">'
                + '<span>Execution Command</span>'
                + '<button type="button" id="btn-copy-cmd" class="btn btn-sm btn-outline-secondary"'
                + ' onclick="copyToClipboard(document.getElementById(\'exec-cmd-display\').textContent).then(function(){'
                + 'var btn=document.getElementById(\'btn-copy-cmd\');btn.textContent=\'Copied!\';btn.classList.replace(\'btn-outline-secondary\',\'btn-success\');'
                + 'setTimeout(function(){btn.textContent=\'Copy\';btn.classList.replace(\'btn-success\',\'btn-outline-secondary\');},2000);'
                + '}).catch(function(){alert(\'Copy failed — please copy manually.\');})">Copy</button></div>'
                + '<div class="card-body p-0">'
                + '<pre class="job-log m-0" id="exec-cmd-display" style="max-height:100px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;"></pre>'
                + '</div>';
            card.querySelector('#exec-cmd-display').textContent = data.execution_command;
            var cmdTarget = document.getElementById('exec-cmd-target');
            if (cmdTarget) {
                cmdTarget.appendChild(card);
            }
        }

        if (data.finished) {
            // Reload to show PLAY RECAP, tasks, and artifacts
            stopped = true;
            window.location.reload();
        }
    }

    document.addEventListener('visibilitychange', shown);
    timer = setTimeout(poll, FIRST_POLL);
})();
</script>
<?php endif; ?>
<?php if (!empty($artifacts)) : ?>
<script>
document.querySelectorAll('.artifact-preview-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var id      = btn.getAttribute('data-artifact-id');
        var kind    = btn.getAttribute('data-preview-kind');
        var url     = btn.getAttribute('data-url');
        var row     = document.getElementById('artifact-preview-' + id);
        if (!row) return;

        // Toggle off if already visible.
        if (!row.classList.contains('d-none')) {
            row.classList.add('d-none');
            return;
        }

        if (kind === 'image') {
            var imgWrap = row.querySelector('.artifact-preview-image');
            var img     = imgWrap.querySelector('img');
            if (!img.dataset.loaded) {
                img.src = url;
                img.dataset.loaded = '1';
            }
            imgWrap.classList.remove('d-none');
            row.classList.remove('d-none');
            return;
        }

        if (kind === 'frame') {
            var frameWrap = row.querySelector('.artifact-preview-frame');
            var frame     = frameWrap.querySelector('iframe');
            if (!frame.dataset.loaded) {
                frame.src = url;
                frame.dataset.loaded = '1';
            }
            frameWrap.classList.remove('d-none');
            row.classList.remove('d-none');
            return;
        }

        // text/JSON/XML/YAML — fetched as JSON, dropped into <pre>.
        var textWrap = row.querySelector('.artifact-preview-text');
        var content  = textWrap.querySelector('.artifact-preview-content');
        if (content.dataset.loaded) {
            textWrap.classList.remove('d-none');
            row.classList.remove('d-none');
            return;
        }
        btn.disabled = true;
        btn.textContent = 'Loading\u2026';
        // Asked for JSON, a signed-out preview is answered 403, not sent to
        // the login page, which would keep the artifact as the address to
        // return to after the next sign-in. An answer that is not OK (signed
        // out, no access any more, the artifact or its file gone) fails the
        // preview; opened again, the preview is fetched again.
        fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) {
                    throw new Error('preview failed: ' + r.status);
                }
                return r.json();
            })
            .then(function (data) {
                content.textContent = data.content || '';
                content.dataset.loaded = '1';
                textWrap.classList.remove('d-none');
                row.classList.remove('d-none');
            })
            .catch(function () {
                content.textContent = 'Failed to load preview.';
                textWrap.classList.remove('d-none');
                row.classList.remove('d-none');
            })
            .finally(function () { btn.disabled = false; btn.textContent = 'Preview'; });
    });
});
</script>
<?php endif; ?>
