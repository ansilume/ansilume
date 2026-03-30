<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var array $stats  keys: projects, queued, running, jobs_today */
/** @var array $statusCounts     Status → count for last 7 days */
/** @var app\models\Job[] $recentJobs */
/** @var app\models\Job[] $runningJobs */
/** @var app\models\JobTemplate[] $templates */
/** @var int $onlineRunners */
/** @var int $totalRunners */

use app\models\Job;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Dashboard';
?>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card text-bg-secondary h-100">
            <div class="card-body">
                <div class="fs-2 fw-bold"><?= $stats['projects'] ?></div>
                <div><?= Html::a('Projects', Url::to(['/project/index']), ['class' => 'text-white text-decoration-none']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card <?= $stats['queued'] > 0 ? 'text-bg-warning' : 'text-bg-secondary' ?> h-100">
            <div class="card-body">
                <div class="fs-2 fw-bold"><?= $stats['queued'] // xss-ok: integer?></div>
                <div><?= Html::a('Queued', Url::to(['/job/index', 'status' => Job::STATUS_QUEUED]), ['class' => 'text-white text-decoration-none']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card <?= $stats['running'] > 0 ? 'text-bg-primary' : 'text-bg-secondary' ?> h-100">
            <div class="card-body">
                <div class="fs-2 fw-bold"><?= $stats['running'] ?></div>
                <div><?= Html::a('Running Now', Url::to(['/job/index', 'JobSearchForm[status]' => Job::STATUS_RUNNING]), ['class' => 'text-white text-decoration-none']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <?php
        $runnerBg = $totalRunners === 0 ? 'text-bg-secondary'
            : ($onlineRunners === 0 ? 'text-bg-danger' : 'text-bg-success');
        ?>
        <div class="card h-100 <?= $runnerBg // xss-ok: controller-computed CSS class?>">
            <div class="card-body">
                <div class="fs-2 fw-bold"><?= $onlineRunners // xss-ok: integer?>/<?= $totalRunners // xss-ok: integer?></div>
                <div><?= Html::a('Runners Online', Url::to(['/runner-group/index']), ['class' => 'text-white text-decoration-none']) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Charts -->
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Job Outcomes</span>
                <select id="chart-range" class="form-select form-select-sm w-auto">
                    <option value="7">7 days</option>
                    <option value="30" selected>30 days</option>
                    <option value="90">90 days</option>
                </select>
            </div>
            <div class="card-body" style="position:relative;height:220px;">
                <canvas id="chart-jobs"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Task Recap (PLAY RECAP)</div>
            <div class="card-body" style="position:relative;height:220px;">
                <canvas id="chart-tasks"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Quick Launch + Status summary -->
<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">Quick Launch</div>
            <div class="card-body">
                <?php if (empty($templates)) : ?>
                    <p class="text-muted mb-0 small">No templates yet.</p>
                <?php else : ?>
                    <form action="<?= Url::to(['/job-template/launch']) ?>" method="post">
                        <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                        <div class="d-flex gap-2">
                            <select name="id" class="form-select form-select-sm" required>
                                <option value="">— Select template —</option>
                                <?php foreach ($templates as $t) : ?>
                                    <option value="<?= $t->id ?>"><?= Html::encode($t->name) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm btn-success text-nowrap">Launch</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Status (7 days)</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tbody>
                    <?php foreach ($statusCounts as $status => $count) : ?>
                        <?php if ($count === 0) {
                            continue;
                        } ?>
                        <tr>
                            <td><span class="badge text-bg-<?= Job::statusCssClass($status) ?>"><?= Html::encode(Job::statusLabel($status)) ?></span></td>
                            <td class="text-end fw-bold"><?= $count // xss-ok: integer?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (array_sum($statusCounts) === 0) : ?>
                        <tr><td colspan="2" class="text-muted small p-3">No jobs in the last 7 days.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Running jobs -->
<?php if (!empty($runningJobs)) : ?>
<div class="card mb-3 border-primary">
    <div class="card-header text-bg-primary d-flex justify-content-between align-items-center">
        <strong>Currently Running (<?= count($runningJobs) ?>)</strong>
        <span class="spinner-border spinner-border-sm text-white" role="status"></span>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr><th>#</th><th>Template</th><th>Launched by</th><th>Runner</th><th>Started</th><th>Running for</th></tr>
            </thead>
            <tbody>
            <?php foreach ($runningJobs as $job) : ?>
                <tr>
                    <td><?= Html::a('#' . $job->id, Url::to(['/job/view', 'id' => $job->id])) ?></td>
                    <td><?= Html::encode($job->jobTemplate->name ?? '—') ?></td>
                    <td><?= Html::encode($job->launcher->username ?? '—') ?></td>
                    <td><code class="small"><?= $job->worker_id ? Html::encode($job->worker_id) : '—' ?></code></td>
                    <td><?= $job->started_at ? date('H:i:s', $job->started_at) : '—' ?></td>
                    <td><?= $job->started_at ? gmdate('H:i:s', time() - $job->started_at) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Recent jobs -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Recent Jobs</strong>
        <?= Html::a('All Jobs', Url::to(['/job/index']), ['class' => 'btn btn-sm btn-outline-secondary']) ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recentJobs)) : ?>
            <p class="text-muted p-3 mb-0">No jobs yet. <a href="<?= Url::to(['/job-template/index']) ?>">Create a template</a> to get started.</p>
        <?php else : ?>
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr><th>#</th><th>Template</th><th>Status</th><th>Launched by</th><th>Runner</th><th>Started</th><th>Duration</th></tr>
                </thead>
                <tbody>
                <?php foreach ($recentJobs as $job) : ?>
                    <tr>
                        <td><?= Html::a('#' . $job->id, Url::to(['/job/view', 'id' => $job->id])) ?></td>
                        <td><?= Html::encode($job->jobTemplate->name ?? '—') ?></td>
                        <td>
                            <span class="badge text-bg-<?= Job::statusCssClass($job->status) ?>">
                                <?= Html::encode(Job::statusLabel($job->status)) ?>
                            </span>
                            <?php if ($job->has_changes) : ?>
                                <span class="badge text-bg-warning ms-1">changed</span>
                            <?php endif; ?>
                        </td>
                        <td><?= Html::encode($job->launcher->username ?? '—') ?></td>
                        <td><code class="small"><?= $job->worker_id ? Html::encode($job->worker_id) : '—' ?></code></td>
                        <td class="text-nowrap"><?= $job->started_at ? date('Y-m-d H:i', $job->started_at) : '—' ?></td>
                        <td class="text-nowrap">
                            <?php
                            if ($job->started_at !== null && $job->finished_at !== null) {
                                $secs = $job->finished_at - $job->started_at;
                                echo gmdate($secs >= 3600 ? 'H:i:s' : 'i:s', $secs);
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script src="/js/chart.min.js"></script>
<script>
(function () {
    var chartDataUrl = <?= json_encode(Url::to(['/site/chart-data'])) ?>;

    Chart.defaults.color = '#8a9099';
    Chart.defaults.borderColor = 'rgba(255,255,255,0.07)';

    var jobChart  = null;
    var taskChart = null;

    function buildJobChart(labels, data) {
        var ctx = document.getElementById('chart-jobs').getContext('2d');
        if (jobChart) jobChart.destroy();
        jobChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Succeeded', data: data.ok,     backgroundColor: '#198754', stack: 'jobs' },
                    { label: 'Failed',    data: data.failed,  backgroundColor: '#dc3545', stack: 'jobs' },
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 10 } } },
                scales: {
                    x: { stacked: true, ticks: { maxRotation: 45 } },
                    y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1 } }
                }
            }
        });
    }

    function buildTaskChart(labels, data) {
        var ctx = document.getElementById('chart-tasks').getContext('2d');
        if (taskChart) taskChart.destroy();
        taskChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: 'ok',          data: data.ok,          backgroundColor: '#198754', stack: 'tasks' },
                    { label: 'changed',     data: data.changed,     backgroundColor: '#ffc107', stack: 'tasks' },
                    { label: 'failed',      data: data.failed,      backgroundColor: '#dc3545', stack: 'tasks' },
                    { label: 'unreachable', data: data.unreachable, backgroundColor: '#343a40', stack: 'tasks' },
                    { label: 'skipped',     data: data.skipped,     backgroundColor: '#6c757d', stack: 'tasks' },
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 8 } } },
                scales: {
                    x: { stacked: true, ticks: { maxRotation: 45 } },
                    y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1 } }
                }
            }
        });
    }

    function loadCharts(days) {
        fetch(chartDataUrl + '?days=' + days)
            .then(function (r) { return r.json(); })
            .then(function (d) {
                buildJobChart(d.labels, d.jobs);
                buildTaskChart(d.labels, d.tasks);
            });
    }

    loadCharts(30);

    document.getElementById('chart-range').addEventListener('change', function () {
        loadCharts(parseInt(this.value));
    });
})();
</script>
