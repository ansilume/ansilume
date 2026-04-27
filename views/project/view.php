<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Project $model */
/** @var string[] $playbooks  Detected root-level playbook files */
/** @var array    $tree       Directory tree nodes */

use app\models\Project;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = $model->name;
?>
<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><?= Html::a('Projects', ['index']) ?></li>
                <li class="breadcrumb-item active"><?= Html::encode($model->name) ?></li>
            </ol>
        </nav>
        <h2 class="mb-0"><?= Html::encode($model->name) ?></h2>
    </div>
    <div class="btn-group">
        <?php if (\Yii::$app->user?->can('project.update')) : ?>
            <?= Html::a('Edit', ['update', 'id' => $model->id], ['class' => 'btn btn-outline-secondary']) ?>
            <?php if ($model->scm_type === Project::SCM_TYPE_GIT) : ?>
            <form method="post" action="<?= \yii\helpers\Url::to(['sync', 'id' => $model->id]) ?>" style="display:inline" onsubmit="return confirm('Queue a sync for this project?')">
                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                <button type="submit" class="btn btn-outline-primary ms-1">Sync</button>
            </form>
            <?php endif; ?>
            <form method="post" action="<?= \yii\helpers\Url::to(['lint', 'id' => $model->id]) ?>" style="display:inline">
                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                <button type="submit" class="btn btn-outline-secondary ms-1">Run Lint</button>
            </form>
        <?php endif; ?>
        <?php if (\Yii::$app->user?->can('project.delete')) : ?>
            <form method="post" action="<?= \yii\helpers\Url::to(['delete', 'id' => $model->id]) ?>" style="display:inline" onsubmit="return confirm('Delete this project?')">
                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                <button type="submit" class="btn btn-outline-danger ms-1">Delete</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($model->status === Project::STATUS_ERROR && $model->last_sync_error) : ?>
<div class="alert alert-danger d-flex align-items-start gap-2">
    <div>
        <strong>Last sync failed</strong><br>
        <code class="user-select-all" style="white-space:pre-wrap;"><?= Html::encode($model->last_sync_error) ?></code>
    </div>
</div>
<?php endif; ?>

<?php
// Sync log panel: shown whenever there is captured output OR a sync is in
// flight. The JS poller below keeps it in sync without a page reload so
// operators no longer have to guess whether the worker is alive.
$initialLogs = \app\models\ProjectSyncLog::find()
    ->where(['project_id' => $model->id])
    ->orderBy(['sequence' => SORT_ASC])
    ->limit(500)
    ->all();
$showSyncPanel = $model->status === Project::STATUS_SYNCING
    || $model->status === Project::STATUS_ERROR
    || !empty($initialLogs);
?>
<?php if ($showSyncPanel) : ?>
<div class="card mb-3" id="sync-log-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            Sync output
            <span id="sync-status-badge" class="badge text-bg-<?= match ($model->status) {
                Project::STATUS_SYNCED => 'success',
                Project::STATUS_SYNCING => 'primary',
                Project::STATUS_ERROR => 'danger',
                default => 'secondary',
                                                              } ?> ms-2"><?= Html::encode(Project::statusLabel($model->status)) ?></span>
        </span>
        <small class="text-muted" id="sync-started-at">
            <?php if ($model->sync_started_at) : ?>
                running since <?= date('H:i:s', $model->sync_started_at) ?>
            <?php elseif ($model->last_synced_at) : ?>
                last synced <?= date('Y-m-d H:i:s', $model->last_synced_at) ?>
            <?php endif; ?>
        </small>
    </div>
    <div class="card-body p-0">
        <pre class="job-log m-0" id="sync-log-output" style="max-height:300px;overflow-y:auto;"><?php
        foreach ($initialLogs as $logRow) {
            echo Html::encode($logRow->content);
        }
        ?></pre>
    </div>
    <!-- Worker liveness indicator: empty until the first poll lands. Populated
         from the JSON snapshot's `worker` block so a dead/stale worker is
         visually obvious next to a sync that's been queued but never picked up. -->
    <div class="card-footer py-1 px-2 small text-muted" id="sync-worker-indicator" style="display:none;"></div>
</div>
<script>
(function () {
    var statusUrl = <?= json_encode(\yii\helpers\Url::to(['sync-status', 'id' => $model->id])) // xss-ok: json_encode escapes?>;
    var output = document.getElementById('sync-log-output');
    var badge = document.getElementById('sync-status-badge');
    var startedAt = document.getElementById('sync-started-at');
    var workerEl = document.getElementById('sync-worker-indicator');
    var lastSeq = <?= json_encode((int)($initialLogs ? end($initialLogs)->sequence : 0)) // xss-ok: int?>;
    var initialStatus = <?= json_encode($model->status) // xss-ok: json_encode escapes?>;
    var pollIntervalMs = 2000;
    var stopOnTerminal = function () { /* set after first poll resolves */ };

    function statusBadge(status) {
        var map = {synced: 'success', syncing: 'primary', error: 'danger', new: 'secondary'};
        return map[status] || 'secondary';
    }

    function statusLabel(status) {
        return status.charAt(0).toUpperCase() + status.slice(1);
    }

    function humanSeconds(s) {
        if (s === null || s === undefined) return 'never';
        if (s < 60) return s + 's';
        if (s < 3600) return Math.floor(s / 60) + 'm';
        if (s < 86400) return Math.floor(s / 3600) + 'h';
        return Math.floor(s / 86400) + 'd';
    }

    function renderWorker(w) {
        if (!w) return;
        workerEl.style.display = '';
        if (!w.alive) {
            workerEl.className = 'card-footer py-1 px-2 small text-danger';
            workerEl.innerHTML = '\u26A0 No queue worker is running. Start one with: '
                + '<code>docker compose up -d queue-worker</code>';
            return;
        }
        // Stale-code banner only fires when a worker's stamped app_version
        // differs from the version on disk — i.e. the worker process is
        // running an older revision than the code. A long-running worker
        // that's still on the right version is silent.
        var parts = [
            'Workers: ' + w.count + ' alive',
            'last heartbeat ' + humanSeconds(w.last_seen_seconds_ago) + ' ago',
        ];
        if (w.oldest_started_seconds_ago !== null) {
            parts.push('oldest started ' + humanSeconds(w.oldest_started_seconds_ago) + ' ago');
        }
        var className = 'card-footer py-1 px-2 small text-muted';
        var line = parts.join(' \u00B7 ');
        if (w.stale_code) {
            className = 'card-footer py-1 px-2 small text-warning';
            var current = w.current_app_version || 'unknown';
            var oldest = w.oldest_app_version || null;
            var staleSuffix = oldest === null
                ? ' \u26A0 worker started before this feature shipped \u2014 restart it to clear this banner'
                : ' \u26A0 worker is on app v' + oldest + ', current is v' + current
                    + ' \u2014 restart the worker to pick up new code';
            line += staleSuffix;
        }
        workerEl.className = className;
        workerEl.textContent = line;
    }

    function poll() {
        fetch(statusUrl + '&since=' + lastSeq, {credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (Array.isArray(data.logs) && data.logs.length > 0) {
                    var atBottom = output.scrollTop + output.clientHeight >= output.scrollHeight - 4;
                    data.logs.forEach(function (line) {
                        output.appendChild(document.createTextNode(line.content));
                        if (line.sequence > lastSeq) lastSeq = line.sequence;
                    });
                    if (atBottom) output.scrollTop = output.scrollHeight;
                }
                badge.className = 'badge text-bg-' + statusBadge(data.status) + ' ms-2';
                badge.textContent = statusLabel(data.status);
                if (data.is_syncing && data.sync_started_at) {
                    startedAt.textContent = 'running since '
                        + new Date(data.sync_started_at * 1000).toLocaleTimeString();
                } else if (data.last_synced_at) {
                    startedAt.textContent = 'last synced '
                        + new Date(data.last_synced_at * 1000).toLocaleString();
                }
                renderWorker(data.worker);
                if (!data.is_syncing) stopOnTerminal();
            })
            .catch(function () { /* network blip — try again */ });
    }

    // Always run one poll so the worker indicator shows up even when the
    // initial render isn't a SYNCING state — operators want to see worker
    // health at a glance regardless of the current sync.
    poll();

    var timer = null;
    if (initialStatus === 'syncing') {
        timer = setInterval(poll, pollIntervalMs);
        stopOnTerminal = function () {
            if (timer) { clearInterval(timer); timer = null; }
        };
    }
})();
</script>
<?php endif; ?>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Details</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Status</dt>
                    <dd class="col-sm-8">
                        <?php
                        $badge = match ($model->status) {
                            Project::STATUS_SYNCED => 'success',
                            Project::STATUS_SYNCING => 'primary',
                            Project::STATUS_ERROR => 'danger',
                            default => 'secondary',
                        };
                        ?>
                        <span class="badge text-bg-<?= $badge ?>"><?= Html::encode(Project::statusLabel($model->status)) ?></span>
                    </dd>
                    <dt class="col-sm-4">SCM Type</dt>
                    <dd class="col-sm-8"><?= Html::encode(strtoupper($model->scm_type)) ?></dd>
                    <?php if ($model->scm_url) : ?>
                        <dt class="col-sm-4">URL</dt>
                        <dd class="col-sm-8"><code><?= Html::encode($model->scm_url) ?></code></dd>
                        <dt class="col-sm-4">Branch</dt>
                        <dd class="col-sm-8"><code><?= Html::encode($model->scm_branch) ?></code></dd>
                    <?php endif; ?>
                    <?php if ($model->local_path) : ?>
                        <dt class="col-sm-4">Local path</dt>
                        <dd class="col-sm-8"><code><?= Html::encode($model->local_path) ?></code></dd>
                    <?php endif; ?>
                    <dt class="col-sm-4">Last synced</dt>
                    <dd class="col-sm-8"><?= $model->last_synced_at ? date('Y-m-d H:i:s', $model->last_synced_at) : '—' ?></dd>
                    <dt class="col-sm-4">Created by</dt>
                    <dd class="col-sm-8"><?= Html::encode($model->creator->username ?? '—') ?></dd>
                    <dt class="col-sm-4">Created at</dt>
                    <dd class="col-sm-8"><?= date('Y-m-d H:i', $model->created_at) ?></dd>
                </dl>
            </div>
        </div>

        <?php if ($model->description) : ?>
        <div class="card mt-3">
            <div class="card-header">Description</div>
            <div class="card-body"><?= nl2br(Html::encode($model->description)) ?></div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-md-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Job Templates</span>
                <?php if (\Yii::$app->user?->can('job-template.create')) : ?>
                    <?= Html::a('New Template', ['/job-template/create', 'project_id' => $model->id], ['class' => 'btn btn-sm btn-outline-primary']) ?>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php $templates = $model->jobTemplates; ?>
                <?php if (empty($templates)) : ?>
                    <p class="text-muted p-3 mb-0">No templates yet.</p>
                <?php else : ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($templates as $tpl) : ?>
                            <li class="list-group-item d-flex justify-content-between">
                                <?= Html::a(Html::encode($tpl->name), ['/job-template/view', 'id' => $tpl->id]) ?>
                                <code class="text-muted small"><?= Html::encode($tpl->playbook) ?></code>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-12">
        <div class="card">
            <?php
            $lintBadge = '';
            if ($model->lint_exit_code === null) {
                $lintBadge = '<span class="badge text-bg-secondary">not run</span>';
            } elseif ($model->lint_exit_code === 0) {
                $lintBadge = '<span class="badge text-bg-success">clean</span>';
            } else {
                $lintBadge = '<span class="badge text-bg-warning">issues found</span>';
            }
            ?>
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Ansible Lint <small class="text-muted fw-normal">(--profile production, full project)</small></span>
                <span>
                    <?= $lintBadge // xss-ok: hardcoded badge HTML?>
                    <?php if ($model->lint_at !== null) : ?>
                        <small class="text-muted ms-2"><?= date('Y-m-d H:i', $model->lint_at) // xss-ok: date() output?></small>
                    <?php endif; ?>
                </span>
            </div>
            <div class="card-body p-0">
                <?php if ($model->lint_output) : ?>
                    <pre class="job-log m-0" id="lint-output" style="max-height:400px;overflow-y:auto;"></pre>
                    <script src="<?= \Yii::$app->request->baseUrl ?>/js/ansi_up.min.js"></script>
                    <script>
                    (function () {
                        var au = new AnsiUp();
                        au.use_classes = false;
                        au.ansi_colors[0][0].rgb = [118, 118, 118];
                        au.ansi_colors[0][4].rgb = [77, 159, 236];
                        au.ansi_colors[0][5].rgb = [198, 120, 221];
                        var el = document.getElementById('lint-output');
                        el.innerHTML = au.ansi_to_html(<?= json_encode($model->lint_output) // xss-ok: json_encode escapes?>);
                    })();
                    </script>
                <?php else : ?>
                    <p class="text-muted p-3 mb-0">No lint output yet. Click "Run Lint" or sync the project to generate a report.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (empty($playbooks) && empty($tree) && $model->status !== \app\models\Project::STATUS_SYNCED) : ?>
<div class="row g-3 mt-1">
    <div class="col-12">
        <p class="text-muted">Sync the project to detect playbooks and browse the repository structure.</p>
    </div>
</div>
<?php elseif (!empty($playbooks) || !empty($tree)) : ?>
<div class="row g-3 mt-1">

    <?php if (!empty($playbooks)) : ?>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Detected Playbooks</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($playbooks as $pb) : ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <code><?= Html::encode($pb) ?></code>
                    <?php if (\Yii::$app->user?->can('job-template.create')) : ?>
                        <?= Html::a('Create Template', ['/job-template/create', 'project_id' => $model->id, 'playbook' => $pb], ['class' => 'btn btn-sm btn-outline-success']) ?>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($tree)) : ?>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Repository Structure</div>
            <div class="card-body p-2">
                <pre class="mb-0 small" style="line-height:1.6;"><?php

                function renderTree(array $nodes, string $prefix = ''): void
                {
                    $last = count($nodes) - 1;
                    foreach ($nodes as $i => $node) {
                        $isLast = ($i === $last);
                        $connector = $isLast ? '└── ' : '├── ';
                        $childPfx = $prefix . ($isLast ? '    ' : '│   ');
                        $icon = $node['type'] === 'dir' ? '📁 ' : '';
                        echo Html::encode($prefix . $connector . $icon . $node['name']) . "\n";
                        if (!empty($node['children'])) {
                            renderTree($node['children'], $childPfx);
                        }
                    }
                }
                renderTree($tree);

                ?></pre>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>
<?php endif; ?>
