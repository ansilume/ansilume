<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var array $stats */
/** @var array $dailyCounts      7-day per-day counts: [{date, succeeded, failed}] */
/** @var array $statusCounts     Status → count for last 7 days */
/** @var app\models\Job[] $recentJobs */
/** @var app\models\Job[] $runningJobs */
/** @var app\models\JobTemplate[] $templates */
/** @var int $workerCount */

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
        <div class="card text-bg-secondary h-100">
            <div class="card-body">
                <div class="fs-2 fw-bold"><?= $stats['templates'] ?></div>
                <div><?= Html::a('Templates', Url::to(['/job-template/index']), ['class' => 'text-white text-decoration-none']) ?></div>
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
        <div class="card h-100 <?= $workerCount > 0 ? 'text-bg-success' : 'text-bg-danger' ?>">
            <div class="card-body">
                <div class="fs-2 fw-bold"><?= $workerCount ?></div>
                <div>Worker<?= $workerCount !== 1 ? 's' : '' ?> Active</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- 7-day sparkline -->
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header">Jobs — last 7 days</div>
            <div class="card-body">
                <?php
                $maxVal = max(1, max(array_map(fn($d) => $d['succeeded'] + $d['failed'], $dailyCounts)));
                $chartH = 60;
                $barW   = 28;
                $gap    = 6;
                $totalW = count($dailyCounts) * ($barW + $gap);
                ?>
                <svg viewBox="0 0 <?= $totalW ?> <?= $chartH + 24 ?>" style="width:100%;max-width:420px;overflow:visible">
                    <?php foreach ($dailyCounts as $i => $day):
                        $x        = $i * ($barW + $gap);
                        $succH    = (int)round($day['succeeded'] / $maxVal * $chartH);
                        $failH    = (int)round($day['failed']    / $maxVal * $chartH);
                        $totalH   = $succH + $failH;
                    ?>
                        <?php if ($succH > 0): ?>
                        <rect x="<?= $x ?>" y="<?= $chartH - $totalH ?>" width="<?= $barW ?>" height="<?= $succH ?>" fill="#198754" rx="2"/>
                        <?php endif; ?>
                        <?php if ($failH > 0): ?>
                        <rect x="<?= $x ?>" y="<?= $chartH - $failH ?>" width="<?= $barW ?>" height="<?= $failH ?>" fill="#dc3545" rx="2"/>
                        <?php endif; ?>
                        <?php if ($totalH === 0): ?>
                        <rect x="<?= $x ?>" y="<?= $chartH - 2 ?>" width="<?= $barW ?>" height="2" fill="#dee2e6" rx="1"/>
                        <?php endif; ?>
                        <text x="<?= $x + $barW / 2 ?>" y="<?= $chartH + 14 ?>" text-anchor="middle" font-size="9" fill="#6c757d"><?= Html::encode($day['date']) ?></text>
                    <?php endforeach; ?>
                </svg>
                <div class="mt-2 small">
                    <span class="me-3"><span style="display:inline-block;width:12px;height:12px;background:#198754;border-radius:2px"></span> Succeeded</span>
                    <span><span style="display:inline-block;width:12px;height:12px;background:#dc3545;border-radius:2px"></span> Failed</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Launch + Status summary -->
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header">Quick Launch</div>
            <div class="card-body">
                <?php if (empty($templates)): ?>
                    <p class="text-muted mb-0 small">No templates yet.</p>
                <?php else: ?>
                    <form action="<?= Url::to(['/job-template/launch', 'id' => 0]) ?>" method="get" id="quick-launch-form">
                        <div class="d-flex gap-2">
                            <select id="quick-launch-id" name="id" class="form-select form-select-sm" required>
                                <option value="">— Select template —</option>
                                <?php foreach ($templates as $t): ?>
                                    <option value="<?= $t->id ?>"><?= Html::encode($t->name) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm btn-success">Launch</button>
                        </div>
                    </form>
                    <script>
                    document.getElementById('quick-launch-form').addEventListener('submit', function(e) {
                        e.preventDefault();
                        const id = document.getElementById('quick-launch-id').value;
                        if (id) window.location = '<?= Url::to(['/job-template/launch', 'id' => '']) ?>' + id;
                    });
                    </script>
                <?php endif; ?>
            </div>
        </div>
        <div class="card">
            <div class="card-header">Status (7 days)</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tbody>
                    <?php foreach ($statusCounts as $status => $count): ?>
                        <?php if ($count === 0) continue; ?>
                        <tr>
                            <td><span class="badge text-bg-<?= Job::statusCssClass($status) ?>"><?= Html::encode(Job::statusLabel($status)) ?></span></td>
                            <td class="text-end fw-bold"><?= $count ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (array_sum($statusCounts) === 0): ?>
                        <tr><td colspan="2" class="text-muted small p-3">No jobs in the last 7 days.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Running jobs -->
<?php if (!empty($runningJobs)): ?>
<div class="card mb-3 border-primary">
    <div class="card-header text-bg-primary d-flex justify-content-between align-items-center">
        <strong>Currently Running (<?= count($runningJobs) ?>)</strong>
        <span class="spinner-border spinner-border-sm text-white" role="status"></span>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr><th>#</th><th>Template</th><th>Launched by</th><th>Started</th><th>Running for</th></tr>
            </thead>
            <tbody>
            <?php foreach ($runningJobs as $job): ?>
                <tr>
                    <td><?= Html::a('#' . $job->id, Url::to(['/job/view', 'id' => $job->id])) ?></td>
                    <td><?= Html::encode($job->jobTemplate->name ?? '—') ?></td>
                    <td><?= Html::encode($job->launcher->username ?? '—') ?></td>
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
        <?php if (empty($recentJobs)): ?>
            <p class="text-muted p-3 mb-0">No jobs yet. <a href="<?= Url::to(['/job-template/index']) ?>">Create a template</a> to get started.</p>
        <?php else: ?>
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr><th>#</th><th>Template</th><th>Status</th><th>Launched by</th><th>Started</th><th>Duration</th></tr>
                </thead>
                <tbody>
                <?php foreach ($recentJobs as $job): ?>
                    <tr>
                        <td><?= Html::a('#' . $job->id, Url::to(['/job/view', 'id' => $job->id])) ?></td>
                        <td><?= Html::encode($job->jobTemplate->name ?? '—') ?></td>
                        <td>
                            <span class="badge text-bg-<?= Job::statusCssClass($job->status) ?>">
                                <?= Html::encode(Job::statusLabel($job->status)) ?>
                            </span>
                        </td>
                        <td><?= Html::encode($job->launcher->username ?? '—') ?></td>
                        <td class="text-nowrap"><?= $job->started_at ? date('Y-m-d H:i', $job->started_at) : '—' ?></td>
                        <td class="text-nowrap">
                            <?php
                            if ($job->started_at && $job->finished_at) {
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
