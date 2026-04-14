<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var array $stats keys: jobs_today, jobs_today_failed, queued, running, pending_approvals */
/** @var array $statusCounts Status => count for last 7 days */
/** @var app\models\Job[] $recentJobs */
/** @var app\models\Job[] $runningJobs */
/** @var app\models\Job[] $failedJobs */
/** @var app\models\JobTemplate[] $templates */
/** @var app\models\WorkflowTemplate[] $workflowTemplates */
/** @var int $onlineRunners */
/** @var int $totalRunners */
/** @var app\models\ApprovalRequest[] $pendingApprovals */
/** @var app\models\WorkflowJob[] $runningWorkflows */
/** @var app\models\Schedule[] $upcomingSchedules */
/** @var app\models\Project[] $syncErrors */
/** @var bool $hasSchedules */

use app\helpers\TimeHelper;
use app\models\ApprovalRequest;
use app\models\Job;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Dashboard';
?>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <?php
        if ($stats['jobs_today'] === 0) {
            $jobsTodayBg = 'text-bg-secondary';
        } elseif ($stats['jobs_today_failed'] > 0) {
            $jobsTodayBg = 'text-bg-danger';
        } else {
            $jobsTodayBg = 'text-bg-success';
        }
        ?>
        <div class="card <?= $jobsTodayBg // xss-ok: computed CSS class?> h-100">
            <div class="card-body">
                <div class="fs-2 fw-bold"><?= $stats['jobs_today'] // xss-ok: integer?></div>
                <div><?= Html::a('Jobs Today', Url::to(['/job/index']), ['class' => 'text-white text-decoration-none']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <?php $queuedCss = $stats['queued'] > 0 ? 'text-white' : 'text-bg-secondary'; ?>
        <?php $queuedStyle = $stats['queued'] > 0 ? ' style="background-color:#c57400"' : ''; ?>
        <div class="card <?= $queuedCss // xss-ok: computed CSS class?> h-100"<?= $queuedStyle // xss-ok: computed style?>>
            <div class="card-body">
                <div class="fs-2 fw-bold"><?= $stats['queued'] // xss-ok: integer?></div>
                <div><?= Html::a('Queued', Url::to(['/job/index', 'status' => Job::STATUS_QUEUED]), ['class' => 'text-white text-decoration-none']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card <?= $stats['running'] > 0 ? 'text-bg-primary' : 'text-bg-secondary' ?> h-100">
            <div class="card-body">
                <div class="fs-2 fw-bold"><?= $stats['running'] // xss-ok: integer?></div>
                <div><?= Html::a('Running Now', Url::to(['/job/index', 'status' => Job::STATUS_RUNNING]), ['class' => 'text-white text-decoration-none']) ?></div>
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

<!-- System Health Alerts -->
<?php
$alerts = [];
if (!empty($syncErrors)) {
    $count = count($syncErrors);
    $names = array_map(fn ($p) => Html::encode($p->name), array_slice($syncErrors, 0, 3));
    $label = implode(', ', $names) . ($count > 3 ? " (+{$count} more)" : '');
    $alerts[] = [
        'type' => 'danger',
        'message' => "Project sync failed: {$label}",
        'link' => Url::to(['/project/index']),
        'linkText' => 'View Projects',
    ];
}
if ($totalRunners > 0 && $onlineRunners === 0) {
    $alerts[] = [
        'type' => 'danger',
        'message' => 'No runners are online. Jobs cannot be executed.',
        'link' => Url::to(['/runner-group/index']),
        'linkText' => 'View Runners',
    ];
}
if ($hasSchedules && $totalRunners > 0 && $onlineRunners === 0) {
    $alerts[] = [
        'type' => 'warning',
        'message' => 'Active schedules exist but no runners are online.',
        'link' => Url::to(['/schedule/index']),
        'linkText' => 'View Schedules',
    ];
}
?>
<?php foreach ($alerts as $alert) : ?>
<div class="alert alert-<?= Html::encode($alert['type']) ?> d-flex justify-content-between align-items-center mb-3" role="alert">
    <span><?= Html::encode($alert['message']) ?></span>
    <?= Html::a($alert['linkText'], $alert['link'], ['class' => 'btn btn-sm btn-outline-' . $alert['type']]) ?>
</div>
<?php endforeach; ?>

<!-- Pending Approvals -->
<?php if (!empty($pendingApprovals) && \Yii::$app->user->can('approval.view')) : ?>
<div class="card mb-3 border-warning">
    <div class="card-header text-bg-warning d-flex justify-content-between align-items-center">
        <strong>Pending Approvals (<?= count($pendingApprovals) ?>)</strong>
        <?= Html::a('All Approvals', Url::to(['/approval/index']), ['class' => 'btn btn-sm btn-outline-dark']) ?>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr><th>Rule</th><th>Job</th><th>Requested</th><th>Expires</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($pendingApprovals as $ar) : ?>
                <tr>
                    <td><?= Html::encode($ar->approvalRule->name ?? '—') ?></td>
                    <td>
                        <?php if ($ar->job !== null) : ?>
                            <?= Html::a(
                                '#' . $ar->job->id . ' ' . Html::encode($ar->job->jobTemplate->name ?? ''),
                                Url::to(['/job/view', 'id' => $ar->job->id])
                            ) ?>
                        <?php else : ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap"><?= date('Y-m-d H:i', $ar->requested_at) ?></td>
                    <td class="text-nowrap">
                        <?php if ($ar->expires_at !== null) : ?>
                            <?= date('Y-m-d H:i', $ar->expires_at) ?>
                        <?php else : ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?= Html::a('Review', Url::to(['/approval/view', 'id' => $ar->id]), ['class' => 'btn btn-sm btn-outline-warning']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

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
                <?php if (empty($templates) && empty($workflowTemplates)) : ?>
                    <p class="text-muted mb-0 small">No templates yet.</p>
                <?php else : ?>
                    <div class="row g-2">
                        <?php if (!empty($templates)) : ?>
                        <div class="<?= !empty($workflowTemplates) ? 'col-md-6' : 'col-12' ?>">
                            <form action="<?= Url::to(['/job-template/launch']) ?>" method="post">
                                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                                <div class="d-flex gap-2">
                                    <select name="id" class="form-select form-select-sm" required>
                                        <option value="">— Job Template —</option>
                                        <?php foreach ($templates as $t) : ?>
                                            <option value="<?= $t->id ?>"><?= Html::encode($t->name) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-success text-nowrap">Launch</button>
                                </div>
                            </form>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($workflowTemplates)) : ?>
                        <div class="<?= !empty($templates) ? 'col-md-6' : 'col-12' ?>">
                            <form action="<?= Url::to(['/workflow-template/launch']) ?>" method="post">
                                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                                <div class="d-flex gap-2">
                                    <select name="id" class="form-select form-select-sm" required>
                                        <option value="">— Workflow Template —</option>
                                        <?php foreach ($workflowTemplates as $wt) : ?>
                                            <option value="<?= $wt->id ?>"><?= Html::encode($wt->name) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-success text-nowrap">Launch</button>
                                </div>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
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
                    <td><span class="small text-muted"><?= $job->worker_id ? Html::encode($job->worker_id) : '—' ?></span></td>
                    <td><?= $job->started_at ? date('H:i:s', $job->started_at) : '—' ?></td>
                    <td><?= $job->started_at ? gmdate('H:i:s', time() - $job->started_at) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Running Workflows -->
<?php if (!empty($runningWorkflows)) : ?>
<div class="card mb-3 border-info">
    <div class="card-header text-bg-info d-flex justify-content-between align-items-center">
        <strong>Running Workflows (<?= count($runningWorkflows) ?>)</strong>
        <?= Html::a('All Executions', Url::to(['/workflow-job/index']), ['class' => 'btn btn-sm btn-outline-dark']) ?>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr><th>#</th><th>Workflow</th><th>Current Step</th><th>Launched by</th><th>Started</th><th>Running for</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($runningWorkflows as $wf) : ?>
                <?php
                $isPaused = $wf->currentStep !== null
                    && $wf->currentStep->step_type === WorkflowStep::TYPE_PAUSE;
                ?>
                <tr>
                    <td><?= Html::a('#' . $wf->id, Url::to(['/workflow-job/view', 'id' => $wf->id])) ?></td>
                    <td><?= Html::encode($wf->workflowTemplate->name ?? '—') ?></td>
                    <td>
                        <?= Html::encode($wf->currentStep->name ?? '—') ?>
                        <?php if ($isPaused) : ?>
                            <span class="badge text-bg-warning ms-1">paused</span>
                        <?php endif; ?>
                    </td>
                    <td><?= Html::encode($wf->launcher->username ?? '—') ?></td>
                    <td class="text-nowrap"><?= $wf->started_at ? date('H:i:s', $wf->started_at) : '—' ?></td>
                    <td><?= $wf->started_at ? gmdate('H:i:s', time() - $wf->started_at) : '—' ?></td>
                    <td>
                        <?php if ($isPaused && \Yii::$app->user->can('workflow.launch')) : ?>
                            <form action="<?= Url::to(['/workflow-job/resume', 'id' => $wf->id]) ?>" method="post" class="d-inline">
                                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                                <button type="submit" class="btn btn-sm btn-success">Resume</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Failed Jobs (last 24h) -->
<?php if (!empty($failedJobs)) : ?>
<div class="card mb-3 border-danger">
    <div class="card-header text-bg-danger d-flex justify-content-between align-items-center">
        <strong>Failed Jobs (last 24h)</strong>
        <?= Html::a('All Failed', Url::to(['/job/index', 'status' => Job::STATUS_FAILED]), ['class' => 'btn btn-sm btn-outline-light']) ?>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr><th>#</th><th>Template</th><th>Launched by</th><th>Failed at</th><th>Duration</th></tr>
            </thead>
            <tbody>
            <?php foreach ($failedJobs as $job) : ?>
                <tr>
                    <td><?= Html::a('#' . $job->id, Url::to(['/job/view', 'id' => $job->id])) ?></td>
                    <td><?= Html::encode($job->jobTemplate->name ?? '—') ?></td>
                    <td><?= Html::encode($job->launcher->username ?? '—') ?></td>
                    <td class="text-nowrap"><?= $job->finished_at ? date('Y-m-d H:i', $job->finished_at) : '—' ?></td>
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
    </div>
</div>
<?php endif; ?>

<!-- Upcoming Schedules -->
<?php if (!empty($upcomingSchedules)) : ?>
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Upcoming Schedules</strong>
                <?= Html::a('All Schedules', Url::to(['/schedule/index']), ['class' => 'btn btn-sm btn-outline-secondary']) ?>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Schedule</th><th>Template</th><th>Cron</th><th>Next Run</th><th>Time Until</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($upcomingSchedules as $schedule) : ?>
                        <tr>
                            <td><?= Html::a(Html::encode($schedule->name), Url::to(['/schedule/view', 'id' => $schedule->id])) ?></td>
                            <td><?= Html::encode($schedule->jobTemplate->name ?? '—') ?></td>
                            <td><code><?= Html::encode($schedule->cron_expression) ?></code></td>
                            <td class="text-nowrap"><?= date('Y-m-d H:i', $schedule->next_run_at) ?></td>
                            <td class="text-nowrap">
                                <?php
                                $diff = $schedule->next_run_at - time();
                                if ($diff <= 0) {
                                    echo '<span class="badge text-bg-warning">due now</span>';
                                } elseif ($diff < 3600) {
                                    echo gmdate('i', $diff) . 'min';
                                } elseif ($diff < 86400) {
                                    echo gmdate('G\h i\m', $diff);
                                } else {
                                    echo floor($diff / 86400) . 'd ' . gmdate('G\h', $diff);
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
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
                        <td><span class="small text-muted"><?= $job->worker_id ? Html::encode($job->worker_id) : '—' ?></span></td>
                        <td class="text-nowrap"><?= TimeHelper::relative($job->started_at) ?></td>
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
